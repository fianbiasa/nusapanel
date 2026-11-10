#!/usr/bin/env bash
#
# RAYXO installer for a fresh Ubuntu 24.04 server. Run as root:
#
#   wget https://<release-host>/setup.sh
#   bash setup.sh --release-url https://<release-host>/rayxo-<version>-linux-amd64.tar.gz \
#                 --admin-email you@example.com
#
# or with a release package already copied to the server:
#
#   bash setup.sh --package rayxo-<version>-linux-amd64.tar.gz --admin-email you@example.com
#
# Safe to run again: every step checks what is already done, so a run that
# stopped halfway can simply be repeated.

set -Eeuo pipefail
umask 022

# The panel's own PHP. Websites use it by default, and may pick any of the
# site versions below.
readonly PHP_VERSION="8.4"
readonly PHP_BIN="/usr/bin/php$PHP_VERSION"
# PHP versions installed for websites (ondrej/php) unless --php-versions or
# an earlier install says otherwise, and the packages each one gets. A
# package a version does not have (8.5 has opcache built in) is skipped.
readonly DEFAULT_SITE_PHP_VERSIONS="7.4 8.0 8.1 8.2 8.3 8.4 8.5"
readonly SITE_PHP_PACKAGES="fpm cli common mysql mbstring xml curl intl zip bcmath opcache readline gd imagick soap"
readonly PANEL_USER="rayxo"
# The panel's port: 2222 unless chosen otherwise (--panel-port, or from the
# panel), remembered in PANEL_PORT_FILE for later runs.
readonly DEFAULT_PANEL_PORT="2222"
PANEL_PORT="$DEFAULT_PANEL_PORT"
readonly INSTALL_DIR="/usr/local/rayxo"
readonly CONFIG_DIR="/etc/rayxo"
readonly DATA_DIR="/var/lib/rayxo"
readonly LOG_DIR="/var/log/rayxo"
readonly ENV_FILE="$CONFIG_DIR/panel.env"
readonly PANEL_PORT_FILE="$CONFIG_DIR/panel-port"
# Left by the panel while a new port is tried; configure_nginx removes them.
readonly PANEL_PORT_TRIAL_SITE="/etc/nginx/sites-enabled/rayxo-panel-trial.conf"
readonly PANEL_PORT_TRIAL_STATE="$DATA_DIR/panel-port-trial.json"
readonly AGENT_SOCKET="/run/rayxo/agent.sock"
readonly INSTALL_LOG="/var/log/rayxo-install.log"
readonly ACME_DIR="/var/www/rayxo-acme"
# phpMyAdmin runs as its own user, which can read neither the panel's
# configuration nor customer files. These paths are also in
# installer/files/php-fpm-phpmyadmin.conf, phpmyadmin-config.inc.php and
# phpmyadmin-sso.php.
readonly PMA_USER="rayxo-pma"
readonly PMA_CONFIG_DIR="/etc/rayxo-pma"
readonly PMA_DATA_DIR="/var/lib/rayxo-pma"
# Pure-FTPd reads FTP logins from the panel's database as this MariaDB user,
# which may only read the v_ftp_users view. The hyphen keeps it out of the
# names customers can give their database users (<username>_<name>).
readonly FTP_DB_USER="rayxo-ftp"
readonly FTP_PASSIVE_PORTS="35000 35999"
# Pure-FTPd reads its key and certificate from this one file, and only at start.
readonly FTP_CERTIFICATE="/etc/ssl/private/pure-ftpd.pem"
# Every hosting account is in this group; sshd gives its members SFTP only
# (installer/files/sshd-sftp.conf). The agent adds new accounts.
readonly SFTP_GROUP="rayxo-sftp"
readonly SFTP_SSHD_CONF="/etc/ssh/sshd_config.d/rayxo-sftp.conf"
readonly DKIM_SELECTOR="rayxo"
readonly DNS_REPORT="$CONFIG_DIR/dns-records.txt"
readonly RELEASE_REPO="fianbiasa/rayxo"
readonly POLICY_RC="/usr/sbin/policy-rc.d"
readonly POLICY_RC_MARKER="# rayxo setup.sh: temporary"
# The site PHP versions installed so far, one per line, so an upgrade keeps
# installing (and configuring) the same ones.
readonly PHP_VERSIONS_FILE="$CONFIG_DIR/php-versions"
# Journaled user quota options (vfsv1, in aquota.user) that configure_quota
# adds to the /etc/fstab line of the filesystem holding /home.
readonly QUOTA_OPTIONS="usrjquota=aquota.user,jqfmt=vfsv1"

# Stamped by installer/build-release.sh into the setup.sh published with each
# release, so that script installs its own version by default. Empty in the
# repository, where setup.sh installs the latest release instead.
RAYXO_VERSION=""

package_file=""
release_url=""
server_hostname=""
admin_user="admin"
admin_email=""
assume_yes=0
force=0
public_ip=""
public_ip_given=0
skip_dns_check=0
requested_version=""
requested_php_versions=""
reconfigure=0
requested_panel_port=""

# Filled in while installing.
arch=""
server_ip=""
work_dir=""
release_dir=""
version=""
db_password=""
admin_password=""
stale_release=""
previous_hostname=""
previous_panel_port=""
ssl_status=""
dkim_public_key=""
dns_report_lines=()
# Mount point whose user quotas configure_quota turned on or found on; empty
# when disk quotas are not enforced on this server.
quota_mount=""
# Set while our temporary policy-rc.d is in place.
policy_rc_created=0
# The PHP versions websites can use, resolved by resolve_php_versions.
site_php_versions=()

if [[ -t 1 ]]; then
    bold=$'\033[1m' green=$'\033[32m' yellow=$'\033[33m' red=$'\033[31m' reset=$'\033[0m'
else
    bold="" green="" yellow="" red="" reset=""
fi

step() { printf '\n%s==> %s%s\n' "$bold" "$*" "$reset"; }
info() { printf '    %s\n' "$*"; }
warn() { printf '%s    ! %s%s\n' "$yellow" "$*" "$reset" >&2; }
die() {
    printf '\n%sGAGAL: %s%s\n' "$red" "$*" "$reset" >&2
    exit 1
}

usage() {
    cat <<'USAGE'
Pemakaian: bash setup.sh [opsi]

Sumber paket (default: rilis dari github.com/fianbiasa/rayxo):
  --version X           pasang versi tertentu, misalnya 0.2.0 (default: versi rilis
                        setup.sh ini, atau rilis terbaru)
  --package FILE        file rilis .tar.gz yang sudah ada di server
  --release-url URL     unduh file rilis (HTTPS); checksum diambil dari URL.sha256

Admin pertama:
  --admin-email EMAIL   email admin (ditanyakan kalau tidak diisi)
  --admin-user NAME     username admin (default: admin)

Syarat sebelum instalasi:
  - Ubuntu 24.04 LTS yang masih bersih, akses root
  - Hostname berupa FQDN, misalnya server1.domainmu.com
  - A record hostname -> IP publik server ini (kalau pakai Cloudflare: DNS only, bukan proxy)
  - PTR (reverse DNS) IP publik -> hostname, diatur lewat provider VPS
  - Port 80, 443 dan 2222 terbuka dari internet (untuk SSL Let's Encrypt, website, dan panel)

Lainnya:
  --hostname NAME       hostname server (default: hasil `hostname -f`)
  --public-ip IP        IP publik server, kalau server di belakang NAT (default: terdeteksi)
  --skip-dns-check      lewati syarat A record & PTR (hanya untuk uji; SSL jadi self-signed)
  --php-versions LIST   versi PHP untuk website, misalnya "8.2 8.3 8.4" (default saat instal
                        pertama: 7.4 8.0 8.1 8.2 8.3 8.4 8.5); 8.4 selalu ikut, dan versi yang
                        sudah terpasang tidak dihapus
  --panel-port PORT     port panel (default: 2222, atau port yang dipilih sebelumnya);
                        1024-65535, bukan port layanan lain di server ini
  --reconfigure         jalankan ulang semua langkah konfigurasi dengan rilis yang sudah
                        terpasang, tanpa mengunduh paket atau memasang paket sistem;
                        dipakai panel untuk mengganti hostname (bersama --hostname)
  --yes                 jangan minta konfirmasi
  --force               lewati pengecekan "server harus bersih" (tidak disarankan)
  -h, --help            tampilkan bantuan ini
USAGE
}

need_value() {
    if [[ -z "${2:-}" || "$2" == --* ]]; then
        die "opsi $1 butuh nilai"
    fi
}

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --package) need_value "$1" "${2:-}"; package_file="$2"; shift 2 ;;
            --release-url) need_value "$1" "${2:-}"; release_url="$2"; shift 2 ;;
            --admin-email) need_value "$1" "${2:-}"; admin_email="$2"; shift 2 ;;
            --admin-user) need_value "$1" "${2:-}"; admin_user="$2"; shift 2 ;;
            --hostname) need_value "$1" "${2:-}"; server_hostname="$2"; shift 2 ;;
            -y | --yes) assume_yes=1; shift ;;
            --reconfigure) reconfigure=1; shift ;;
            --panel-port) need_value "$1" "${2:-}"; requested_panel_port="$2"; shift 2 ;;
            --force) force=1; shift ;;
            --public-ip) need_value "$1" "${2:-}"; public_ip="$2"; public_ip_given=1; shift 2 ;;
            --skip-dns-check) skip_dns_check=1; shift ;;
            --version) need_value "$1" "${2:-}"; requested_version="${2#v}"; shift 2 ;;
            --php-versions) need_value "$1" "${2:-}"; requested_php_versions="$2"; shift 2 ;;
            -h | --help) usage; exit 0 ;;
            *) usage >&2; die "opsi tidak dikenal: $1" ;;
        esac
    done
}

on_error() {
    printf '\n%sInstalasi berhenti karena error di baris %s.%s\n' "$red" "$1" "$reset" >&2
    printf 'Log lengkap: %s\n' "$INSTALL_LOG" >&2
    printf 'Perbaiki masalahnya lalu jalankan setup.sh lagi; langkah yang sudah selesai akan dilewati.\n' >&2
}

cleanup() {
    remove_policy_rc

    if [[ -n "$work_dir" && -d "$work_dir" ]]; then
        rm -rf "$work_dir"
    fi
}

# Stop packages from starting services while they install, by answering
# every request with 101 ("action not allowed"). One left behind by an
# interrupted earlier run is recognized by its marker and reused.
block_service_starts() {
    if [[ ! -e "$POLICY_RC" ]]; then
        printf '#!/bin/sh\n%s, removed when it finishes.\nexit 101\n' "$POLICY_RC_MARKER" >"$POLICY_RC"
        chmod 0755 "$POLICY_RC"
    elif ! grep -qF "$POLICY_RC_MARKER" "$POLICY_RC"; then
        warn "$POLICY_RC sudah ada dan bukan buatan setup.sh; dibiarkan apa adanya"
        return
    fi

    policy_rc_created=1
}

# Remove our policy-rc.d (never someone else's). Also runs from cleanup, so
# a failed install never leaves services unable to start.
remove_policy_rc() {
    if ((policy_rc_created)); then
        rm -f "$POLICY_RC"
        policy_rc_created=0
    fi
}

# Run a command with its output going only to the install log, and stop with
# a message on the terminal if it fails.
quietly() {
    if ! "$@" >>"$INSTALL_LOG" 2>&1; then
        die "langkah gagal: $* (detail di $INSTALL_LOG)"
    fi
}

# Set KEY=VALUE in the panel's .env, replacing the line or appending it.
set_env_value() {
    if grep -q "^$1=" "$ENV_FILE"; then
        sed -i "s|^$1=.*|$1=$2|" "$ENV_FILE"
    else
        printf '%s=%s\n' "$1" "$2" >>"$ENV_FILE"
    fi

    chown "root:$PANEL_USER" "$ENV_FILE"
    chmod 0640 "$ENV_FILE"
}

# Add KEY=VALUE to the panel's .env only when KEY is missing, so a value
# someone changed by hand is kept.
add_env_value() {
    if ! grep -q "^$1=" "$ENV_FILE"; then
        set_env_value "$1" "$2"
    fi
}

# Value of KEY in the panel's .env, or nothing.
env_value() {
    if [[ -f "$ENV_FILE" ]]; then
        sed -n "/^$1=/{s/^$1=//p;q;}" "$ENV_FILE"
    fi
}

# Replace KEY's value when it is still the one setup.sh wrote for the
# previous hostname or port; a value someone changed by hand is kept.
follow_env_value() {
    if [[ "$(env_value "$1")" == "$2" ]]; then
        set_env_value "$1" "$3"
    fi
}

# A random password with upper case, lower case, a digit and a symbol, as
# required by the panel's production password rules.
generate_password() {
    local password upper='[A-Z]' lower='[a-z]' digit='[0-9]' symbol='[#%]'

    while true; do
        password="$(openssl rand -base64 18 | tr '+/' '#%')"
        if [[ "$password" =~ $upper && "$password" =~ $lower && "$password" =~ $digit && "$password" =~ $symbol ]]; then
            printf '%s' "$password"
            return
        fi
    done
}

# Copy a config template, filling in {{NAME}} placeholders.
render() {
    sed \
        -e "s|{{PANEL_PORT}}|$PANEL_PORT|g" \
        -e "s|{{INSTALL_DIR}}|$INSTALL_DIR|g" \
        -e "s|{{CONFIG_DIR}}|$CONFIG_DIR|g" \
        -e "s|{{DATA_DIR}}|$DATA_DIR|g" \
        -e "s|{{LOG_DIR}}|$LOG_DIR|g" \
        -e "s|{{HOSTNAME}}|$server_hostname|g" \
        -e "s|{{ACME_DIR}}|$ACME_DIR|g" \
        "$1" >"$2"

    if grep -q '{{[A-Z_]*}}' "$2"; then
        die "ada placeholder yang tidak terisi di $2"
    fi
}

# Run an artisan command as the panel user from the release being installed.
# umask 027 keeps the files it writes (such as the config cache, which holds
# APP_KEY and the database password) away from other local users.
artisan() {
    (
        umask 027
        cd "$release_dir/panel"
        runuser -u "$PANEL_USER" -- "$PHP_BIN" artisan "$@" --no-interaction
    )
}

# Create a directory owned by the panel user if it is missing. Existing paths
# are never chowned and symlinks are refused, so a compromised panel cannot
# trick a later run into handing a system directory over to it.
ensure_dir() {
    if [[ -L "$1" ]]; then
        die "$1 adalah symlink, padahal seharusnya folder; hapus symlink itu lalu ulangi"
    fi

    if [[ ! -d "$1" ]]; then
        install -d -m 0750 -o "$PANEL_USER" -g "$PANEL_USER" "$1"
    fi
}

# Ask public DNS (Cloudflare, then Google, over HTTPS) so the answer matches
# what Let's Encrypt and remote mail servers see, not this server's /etc/hosts
# or resolver cache. Prints one value per line: TXT strings joined and
# unquoted, trailing dots removed. Fails only if neither resolver answers.
dns_lookup() {
    local name="$1" type="$2" url response

    for url in "https://cloudflare-dns.com/dns-query" "https://dns.google/resolve"; do
        if response="$(curl -fsS --max-time 10 -H 'accept: application/dns-json' \
            "$url?name=$name&type=$type" 2>/dev/null)"; then
            DNS_RESPONSE="$response" DNS_TYPE="$type" python3 - <<'PY'
import json, os, re

codes = {"A": 1, "AAAA": 28, "TXT": 16, "PTR": 12}
wanted = codes[os.environ["DNS_TYPE"]]

for answer in json.loads(os.environ["DNS_RESPONSE"]).get("Answer", []):
    if answer.get("type") != wanted:
        continue
    value = answer.get("data", "")
    if wanted == 16:
        parts = re.findall(r'"((?:[^"\\]|\\.)*)"', value)
        value = "".join(parts) if parts else value
    print(value.rstrip("."))
PY
            return 0
        fi
    done

    return 1
}

normalize_ipv6() {
    python3 -c 'import ipaddress, sys; print(ipaddress.ip_address(sys.argv[1]).compressed)' "$1" 2>/dev/null || true
}

# Without --package or --release-url, install from GitHub releases: the
# version given with --version, the version this setup.sh was released with,
# or else the latest release.
resolve_github_release() {
    local wanted="${requested_version:-$RAYXO_VERSION}"

    if [[ -z "$wanted" ]]; then
        wanted="$(latest_release_version)" ||
            die "tidak bisa mengambil rilis terbaru dari github.com/$RELEASE_REPO; coba lagi, atau pakai --version / --package"
    fi

    local version_re='^[0-9A-Za-z][0-9A-Za-z._-]*$'
    [[ "$wanted" =~ $version_re ]] || die "versi tidak valid: $wanted"

    release_url="https://github.com/$RELEASE_REPO/releases/download/v$wanted/rayxo-$wanted-linux-$arch.tar.gz"
}

latest_release_version() {
    local response
    response="$(curl -fsSL --proto '=https' --max-time 20 -H 'Accept: application/vnd.github+json' \
        "https://api.github.com/repos/$RELEASE_REPO/releases/latest")" || return 1

    RELEASE_JSON="$response" python3 -c \
        'import json, os; print(json.loads(os.environ["RELEASE_JSON"])["tag_name"].lstrip("v"))'
}

cert_expiry() {
    openssl x509 -enddate -noout -in "$1" | cut -d= -f2
}

is_private_ip() {
    local private_re='^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.|100\.(6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\.)'
    [[ "$1" =~ $private_re ]]
}

preflight() {
    step "Memeriksa server"

    [[ -r /etc/os-release ]] || die "tidak bisa membaca /etc/os-release"

    local os_id os_version os_name
    os_id="$(. /etc/os-release && printf '%s' "${ID:-}")"
    os_version="$(. /etc/os-release && printf '%s' "${VERSION_ID:-}")"
    os_name="$(. /etc/os-release && printf '%s' "${PRETTY_NAME:-tidak diketahui}")"

    if [[ "$os_id" != ubuntu || "$os_version" != 24.04 ]]; then
        die "RAYXO saat ini hanya mendukung Ubuntu 24.04 LTS (terdeteksi: $os_name)"
    fi

    case "$(uname -m)" in
        x86_64) arch=amd64 ;;
        aarch64 | arm64) arch=arm64 ;;
        *) die "arsitektur $(uname -m) tidak didukung" ;;
    esac

    command -v systemctl >/dev/null || die "systemd tidak ditemukan"

    local tool
    for tool in curl python3; do
        command -v "$tool" >/dev/null || die "$tool tidak ditemukan; pasang dulu dengan: apt install $tool"
    done

    local memory_mb disk_free_mb
    memory_mb="$(awk '/^MemTotal:/ {print int($2 / 1024)}' /proc/meminfo)"
    disk_free_mb="$(df -Pm / | awk 'NR == 2 {print $4}')"

    if ((memory_mb < 900)); then
        die "RAM minimal 1 GB (terdeteksi ${memory_mb} MB)"
    fi

    if ((memory_mb < 1900)); then
        warn "RAM ${memory_mb} MB; untuk server hosting disarankan minimal 2 GB"
    fi

    if ((disk_free_mb < 5120)); then
        die "ruang disk kosong minimal 5 GB (tersisa ${disk_free_mb} MB)"
    fi

    local other_panel
    for other_panel in /usr/local/cpanel /usr/local/directadmin /usr/local/hestia /usr/local/vesta \
        /usr/local/CyberCP /usr/local/ispconfig /www/server/panel /etc/webmin; do
        if [[ -e "$other_panel" ]]; then
            die "panel lain terdeteksi di $other_panel; pasang RAYXO di server baru"
        fi
    done

    # A server we already started installing on is allowed to have our own packages.
    if [[ ! -f "$CONFIG_DIR/.setup-started" && $force -eq 0 ]]; then
        local package
        for package in apache2 nginx mysql-server mariadb-server "php$PHP_VERSION-fpm"; do
            if dpkg-query -W -f='${Status}' "$package" 2>/dev/null | grep -q "install ok installed"; then
                die "server tidak bersih: paket $package sudah terpasang. Pakai server baru (disarankan) atau tambahkan --force"
            fi
        done
    fi

    # The hostname everything was configured for last time, to follow a
    # change of hostname (--hostname).
    previous_hostname="$(head -n 1 /etc/mailname 2>/dev/null || true)"

    resolve_panel_port

    if [[ -z "$server_hostname" ]]; then
        server_hostname="$(hostname -f 2>/dev/null || hostname)"
    fi

    server_hostname="$(printf '%s' "$server_hostname" | tr '[:upper:]' '[:lower:]')"

    local hostname_re='^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$'
    [[ "$server_hostname" =~ $hostname_re ]] || die "hostname tidak valid: $server_hostname (atur dengan --hostname)"

    if [[ "$server_hostname" != *.* ]]; then
        warn "hostname \"$server_hostname\" bukan FQDN; sebaiknya seperti server1.domainmu.com (atur dengan --hostname)"
    fi

    # Only a routing lookup: nothing is sent to 1.1.1.1.
    server_ip="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i = 1; i < NF; i++) if ($i == "src") {print $(i + 1); exit}}' || true)"

    local username_re='^[a-z][a-z0-9]{2,15}$'
    [[ "$admin_user" =~ $username_re ]] || die "username admin harus 3-16 huruf kecil/angka dan diawali huruf"

    if [[ -z "$admin_email" && ! -f "$CONFIG_DIR/admin-created" ]]; then
        if { : </dev/tty; } 2>/dev/null; then
            read -r -p "Email admin pertama: " admin_email </dev/tty
        else
            die "isi email admin dengan --admin-email"
        fi
    fi

    local email_re='^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'
    if [[ ! -f "$CONFIG_DIR/admin-created" && ! "$admin_email" =~ $email_re ]]; then
        die "email admin tidak valid: $admin_email"
    fi

    if ((reconfigure)); then
        [[ -f "$CONFIG_DIR/installed" && -L "$INSTALL_DIR/current" ]] ||
            die "--reconfigure hanya untuk server yang RAYXO-nya sudah terpasang"
        [[ -z "$package_file$release_url$requested_version" ]] ||
            die "--reconfigure memakai rilis yang terpasang; jangan digabung dengan --package, --release-url atau --version"
        return
    fi

    if [[ -n "$package_file" && -n "$release_url" ]]; then
        die "pilih salah satu: --package atau --release-url"
    fi

    if [[ -n "$requested_version" && (-n "$package_file" || -n "$release_url") ]]; then
        die "--version tidak bisa digabung dengan --package atau --release-url"
    fi

    if [[ -z "$package_file" && -z "$release_url" ]]; then
        resolve_github_release
    fi

    if [[ -n "$package_file" ]]; then
        [[ -f "$package_file" ]] || die "file paket tidak ditemukan: $package_file"
        package_file="$(cd "$(dirname "$package_file")" && pwd)/$(basename "$package_file")"
    fi

    if [[ -n "$release_url" && "$release_url" != https://* ]]; then
        die "--release-url harus memakai https://"
    fi
}

# Hard requirements: the hostname must resolve to this server (Let's Encrypt
# checks it) and the IP must resolve back to the hostname (mail servers check
# it). Both are read from public DNS, so a stale local cache cannot fool us.
check_dns() {
    step "Memeriksa syarat DNS (A record & PTR)"

    if ((skip_dns_check)); then
        warn "pengecekan DNS dilewati (--skip-dns-check): SSL akan self-signed dan email panel kemungkinan masuk spam"
        public_ip="${public_ip:-$server_ip}"
        return
    fi

    [[ "$server_hostname" == *.* ]] ||
        die "hostname harus FQDN, misalnya server1.domainmu.com (atur dengan --hostname)"

    public_ip="${public_ip:-$server_ip}"
    local ipv4_re='^([0-9]{1,3}\.){3}[0-9]{1,3}$'
    [[ "$public_ip" =~ $ipv4_re ]] || die "IP publik server tidak terdeteksi; isi dengan --public-ip"

    local a_records
    a_records="$(dns_lookup "$server_hostname" A)" ||
        die "tidak bisa menghubungi DNS publik (cloudflare-dns.com / dns.google) untuk memeriksa A record"

    if [[ -z "$a_records" ]]; then
        die "A record $server_hostname belum ada. Tambahkan A record $server_hostname -> $public_ip, tunggu beberapa menit, lalu ulangi"
    fi

    if [[ "$a_records" != "$public_ip" ]]; then
        local hint=" (kalau pakai Cloudflare, set A record ke \"DNS only\", bukan proxy)"
        if is_private_ip "$server_ip" && ((public_ip_given == 0)); then
            hint=" (server ini di belakang NAT? isi IP publiknya dengan --public-ip)"
        fi
        die "A record $server_hostname harus mengarah hanya ke $public_ip, sekarang: $(tr '\n' ' ' <<<"$a_records")$hint"
    fi
    info "A record  $server_hostname -> $public_ip  ✔"

    # Let's Encrypt and mail servers prefer IPv6, so an AAAA record pointing
    # elsewhere would break both.
    local aaaa_records record local_ipv6
    aaaa_records="$(dns_lookup "$server_hostname" AAAA || true)"
    local_ipv6="$(ip -6 addr show scope global 2>/dev/null | awk '/inet6/ {split($2, a, "/"); print a[1]}' |
        while IFS= read -r record; do normalize_ipv6 "$record"; done)"

    while IFS= read -r record; do
        if [[ -n "$record" ]] && ! grep -qx "$(normalize_ipv6 "$record")" <<<"$local_ipv6"; then
            die "AAAA record $server_hostname mengarah ke $record, yang bukan milik server ini. Hapus AAAA record itu lalu ulangi"
        fi
    done <<<"$aaaa_records"

    local reverse_name ptr_records
    reverse_name="$(awk -F. '{print $4 "." $3 "." $2 "." $1 ".in-addr.arpa"}' <<<"$public_ip")"
    ptr_records="$(dns_lookup "$reverse_name" PTR)" ||
        die "tidak bisa menghubungi DNS publik untuk memeriksa PTR"

    if ! grep -qx "$server_hostname" <<<"$ptr_records"; then
        die "PTR (reverse DNS) $public_ip harus $server_hostname, sekarang: ${ptr_records:-belum diatur}. Atur lewat panel/support provider VPS, lalu ulangi"
    fi
    info "PTR       $public_ip -> $server_hostname  ✔"
}

confirm() {
    step "Ringkasan"
    info "Hostname : $server_hostname"
    info "IP       : ${public_ip:-${server_ip:-tidak terdeteksi}}"
    info "Paket    : ${package_file:-$release_url}"
    info "Admin    : $admin_user ${admin_email:+<$admin_email>}"
    info "Panel    : https://$server_hostname:$PANEL_PORT"
    info "Dipasang : Nginx + Apache, MariaDB, PHP ${site_php_versions[*]} (repo ondrej/php), Postfix + DKIM (kirim-saja), agent rayxod"
    if ((skip_dns_check == 0)); then
        info "SSL      : Let's Encrypt untuk $server_hostname, diperpanjang otomatis"
        info "           (dengan melanjutkan, kamu menyetujui Let's Encrypt Subscriber Agreement)"
    fi

    if ((assume_yes)); then
        return
    fi

    local answer=""
    if ! { : </dev/tty; } 2>/dev/null; then
        die "tidak ada terminal untuk konfirmasi; tambahkan --yes"
    fi

    read -r -p "Lanjutkan instalasi? [y/N] " answer </dev/tty
    [[ "$answer" == y || "$answer" == Y ]] || die "dibatalkan"
}

# Give the server the hostname everything is configured for, when another
# one was asked for (--hostname): the kernel hostname, its names in
# /etc/hosts, and, on cloud servers, cloud-init, which would put the old
# name back at the next boot.
apply_hostname() {
    local current
    current="$(hostname -f 2>/dev/null || hostname)"

    if [[ "$current" == "$server_hostname" ]]; then
        return
    fi

    step "Mengganti hostname server menjadi $server_hostname"
    quietly hostnamectl set-hostname "$server_hostname"

    local old
    for old in "$current" "$previous_hostname"; do
        if [[ -n "$old" && "$old" != "$server_hostname" ]]; then
            rename_in_hosts "$old" "$server_hostname"
            if [[ "${old%%.*}" != "$old" && "${old%%.*}" != "${server_hostname%%.*}" ]]; then
                rename_in_hosts "${old%%.*}" "${server_hostname%%.*}"
            fi
        fi
    done

    if [[ -d /etc/cloud/cloud.cfg.d ]]; then
        printf '%s\n' "# Managed by rayxo setup.sh: the panel sets the hostname." \
            "preserve_hostname: true" "manage_etc_hosts: false" >/etc/cloud/cloud.cfg.d/99-rayxo-hostname.cfg
    fi

    info "Hostname $current -> $server_hostname"
}

# Replace the whole name $1 with $2 in /etc/hosts. Both are validated
# hostnames: only letters, digits, dots and hyphens.
rename_in_hosts() {
    sed -i -E "s/(^|[[:space:]])${1//./\\.}([[:space:]]|$)/\1$2\2/g" /etc/hosts
}

# The panel's port: --panel-port, else the one chosen before, else 2222. A
# new port may not be another service's on this server, nor in use, except
# by Nginx while the panel tries it.
resolve_panel_port() {
    previous_panel_port="$(head -n 1 "$PANEL_PORT_FILE" 2>/dev/null || true)"
    [[ "$previous_panel_port" =~ ^[0-9]+$ ]] || previous_panel_port="$DEFAULT_PANEL_PORT"
    PANEL_PORT="${requested_panel_port:-$previous_panel_port}"

    [[ "$PANEL_PORT" =~ ^[0-9]+$ ]] && ((PANEL_PORT >= 1024 && PANEL_PORT <= 65535)) ||
        die "port panel harus angka 1024-65535: $PANEL_PORT"

    local passive_low passive_high
    read -r passive_low passive_high <<<"$FTP_PASSIVE_PORTS"
    case "$PANEL_PORT" in
        3306 | 8080 | 8891) die "port $PANEL_PORT dipakai layanan lain di server ini; pilih port lain" ;;
    esac
    if ((PANEL_PORT >= passive_low && PANEL_PORT <= passive_high)); then
        die "port $PANEL_PORT termasuk port passive FTP ($passive_low-$passive_high); pilih port lain"
    fi

    if [[ "$PANEL_PORT" != "$previous_panel_port" ]] &&
        ss -Hltnp "sport = :$PANEL_PORT" 2>/dev/null | grep -v '"nginx"' | grep -q .; then
        die "port $PANEL_PORT sudah dipakai program lain; pilih port lain"
    fi
}

# --reconfigure: the release that is installed is the one to configure.
use_installed_release() {
    release_dir="$(readlink -f "$INSTALL_DIR/current")"
    version="$(head -n 1 "$release_dir/VERSION")"
    info "Memakai rilis yang terpasang: $version"
}

create_layout() {
    step "Menyiapkan user dan folder"

    if ! id -u "$PANEL_USER" >/dev/null 2>&1; then
        useradd --system --user-group --home-dir "$DATA_DIR" --no-create-home \
            --shell /usr/sbin/nologin "$PANEL_USER"
        info "User sistem $PANEL_USER dibuat"
    fi

    # Hosting homes are 0710 <user>:rayxo-access, so only the web server can
    # enter them. Created before any web server starts, so its workers get
    # the group without a restart.
    if ! getent group rayxo-access >/dev/null; then
        groupadd --system rayxo-access
    fi
    usermod -aG rayxo-access www-data

    install -d -m 0755 -o root -g root "$INSTALL_DIR" "$INSTALL_DIR/releases"
    install -d -m 0750 -o root -g "$PANEL_USER" "$CONFIG_DIR"
    install -d -m 0700 -o root -g root "$CONFIG_DIR/ssl"
    install -d -m 0750 -o root -g adm "$LOG_DIR"

    # Directories that only hold other directories stay root-owned, so the
    # panel user cannot replace what is inside them with symlinks.
    install -d -m 0750 -o root -g "$PANEL_USER" "$DATA_DIR" "$DATA_DIR/storage" \
        "$DATA_DIR/storage/app" "$DATA_DIR/storage/framework" "$DATA_DIR/storage/framework/cache"

    # File manager uploads and downloads pass through here: the panel user
    # may add and remove files, but only root can replace the folder, and
    # the agent opens nothing in it that the panel user could redirect.
    install -d -m 0770 -o root -g "$PANEL_USER" "$DATA_DIR/transfer"

    local dir
    for dir in storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs; do
        ensure_dir "$DATA_DIR/$dir"
    done

    if ! id -u "$PMA_USER" >/dev/null 2>&1; then
        useradd --system --user-group --home-dir "$PMA_DATA_DIR" --no-create-home \
            --shell /usr/sbin/nologin "$PMA_USER"
        info "User sistem $PMA_USER (phpMyAdmin) dibuat"
    fi

    # Root owns the directories holding phpMyAdmin's, so it cannot swap them
    # for symlinks; it may only write its temporary files, sessions and log.
    install -d -m 0750 -o root -g "$PMA_USER" "$PMA_CONFIG_DIR" "$PMA_DATA_DIR"
    install -d -m 0700 -o "$PMA_USER" -g "$PMA_USER" "$PMA_DATA_DIR/tmp" "$PMA_DATA_DIR/sessions" "$PMA_DATA_DIR/log"

    touch "$CONFIG_DIR/.setup-started"
}

# Run "<apt-get ...> update", tried again a few times: Ubuntu's mirrors fail
# now and then while they sync ("File has unexpected size").
apt_update() {
    local attempt
    for attempt in 1 2 3; do
        if "$@" update >>"$INSTALL_LOG" 2>&1; then
            return 0
        fi

        if ((attempt < 3)); then
            warn "apt-get update gagal (percobaan $attempt dari 3); dicoba lagi 20 detik lagi"
            sleep 20
        fi
    done

    die "langkah gagal: apt-get update (detail di $INSTALL_LOG)"
}

install_packages() {
    step "Memasang paket sistem (beberapa menit; detail di $INSTALL_LOG)"

    export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a
    local apt=(apt-get -y -q -o DPkg::Lock::Timeout=300)

    apt_update "${apt[@]}"
    quietly "${apt[@]}" install ca-certificates curl gnupg openssl software-properties-common tar

    if ! grep -rqs "ondrej/php" /etc/apt/sources.list.d/; then
        info "Menambahkan repo PHP ondrej/php"
        # -n: skip its own apt update, which would not wait for the dpkg lock.
        quietly add-apt-repository -y -n ppa:ondrej/php
        apt_update "${apt[@]}"
    fi

    # Postfix asks questions while installing; answer them up front.
    debconf-set-selections >>"$INSTALL_LOG" 2>&1 <<DEBCONF
postfix postfix/main_mailer_type select Internet Site
postfix postfix/mailname string $server_hostname
DEBCONF

    quietly "${apt[@]}" install nginx mariadb-server postfix opendkim opendkim-tools certbot python3 quota cron \
        "php$PHP_VERSION-fpm" "php$PHP_VERSION-cli" "php$PHP_VERSION-common" "php$PHP_VERSION-mysql" \
        "php$PHP_VERSION-mbstring" "php$PHP_VERSION-xml" "php$PHP_VERSION-curl" "php$PHP_VERSION-intl" \
        "php$PHP_VERSION-zip" "php$PHP_VERSION-bcmath" "php$PHP_VERSION-opcache" "php$PHP_VERSION-readline" \
        "php$PHP_VERSION-gd"

    # Apache would start on port 80, which Nginx owns, and fail the install.
    # It stays stopped until configure_web has moved it to 127.0.0.1:8080.
    # Pure-FTPd would start with Debian's defaults (PAM logins, no TLS); it
    # stays stopped until configure_ftp has set it up.
    block_service_starts
    quietly "${apt[@]}" install apache2 pure-ftpd-mysql
    remove_policy_rc

    # cron runs the cron jobs of hosting accounts (the agent writes their crontabs).
    quietly systemctl enable --now mariadb nginx cron "php$PHP_VERSION-fpm"
    info "Nginx, Apache, MariaDB, Postfix, Pure-FTPd dan PHP $PHP_VERSION terpasang"
}

# The site PHP versions: those of an earlier install plus --php-versions, or
# the defaults on a first install without the option. The panel's own
# version is always one of them. Versions are never removed here.
resolve_php_versions() {
    local list="" v
    local -A seen=()

    if [[ -s "$PHP_VERSIONS_FILE" ]]; then
        list="$(tr '\n' ' ' <"$PHP_VERSIONS_FILE")"
    elif [[ -z "$requested_php_versions" ]]; then
        list="$DEFAULT_SITE_PHP_VERSIONS"
    fi

    list="$list ${requested_php_versions//,/ } $PHP_VERSION"
    site_php_versions=()

    for v in $list; do
        [[ "$v" =~ ^[5-9]\.[0-9]$ ]] || die "versi PHP tidak valid: $v (contoh: 8.3)"
        if [[ -z "${seen[$v]:-}" ]]; then
            seen[$v]=1
            site_php_versions+=("$v")
        fi
    done

    mapfile -t site_php_versions < <(printf '%s\n' "${site_php_versions[@]}" | sort -V)
}

# Every site PHP version with the extensions WordPress and most PHP apps
# need. Websites run in the agent's pools per account (rayxo-php<ver>@<user>),
# so the distribution's own PHP-FPM service of each version stays off; only
# that of the panel's version runs, with the panel's pool.
install_site_php() {
    step "Memasang PHP untuk website (${site_php_versions[*]})"

    export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a
    local apt=(apt-get -y -q -o DPkg::Lock::Timeout=300)
    local missing=() v package name

    for v in "${site_php_versions[@]}"; do
        for name in $SITE_PHP_PACKAGES; do
            package="php$v-$name"

            if dpkg-query -W -f='${Status}' "$package" 2>/dev/null | grep -q 'ok installed'; then
                continue
            fi

            if apt-cache show "$package" >/dev/null 2>&1; then
                missing+=("$package")
            elif [[ "$name" == fpm ]]; then
                die "PHP $v tidak tersedia di repo ondrej/php"
            fi
        done
    done

    if ((${#missing[@]} > 0)); then
        info "Memasang ${#missing[@]} paket PHP"
        block_service_starts
        quietly "${apt[@]}" install "${missing[@]}"
        remove_policy_rc

        # Running pools load newly installed extensions; stopped ones (of
        # suspended accounts) stay stopped.
        systemctl try-reload-or-restart 'rayxo-php*@*.service' >>"$INSTALL_LOG" 2>&1 ||
            warn "pool PHP website tidak bisa dimuat ulang; ekstensi baru aktif setelah pool di-restart"
    fi

    for v in "${site_php_versions[@]}"; do
        if [[ "$v" != "$PHP_VERSION" ]] && systemctl is-enabled --quiet "php$v-fpm" 2>/dev/null; then
            quietly systemctl disable --now "php$v-fpm"
        fi
    done

    printf '%s\n' "${site_php_versions[@]}" >"$PHP_VERSIONS_FILE"
    info "PHP ${site_php_versions[*]} siap untuk website (bawaan: $PHP_VERSION)"
}

fetch_release() {
    step "Mengambil paket RAYXO"

    work_dir="$(mktemp -d "$INSTALL_DIR/.setup.XXXXXX")"

    local tarball checksum_file
    if [[ -n "$release_url" ]]; then
        tarball="$work_dir/release.tar.gz"
        checksum_file="$tarball.sha256"
        info "Mengunduh $release_url"
        curl -fsSL --proto '=https' --tlsv1.2 --retry 3 -o "$tarball" "$release_url"
        curl -fsSL --proto '=https' --tlsv1.2 --retry 3 -o "$checksum_file" "$release_url.sha256" ||
            die "checksum $release_url.sha256 tidak bisa diunduh"
    else
        tarball="$package_file"
        checksum_file="$package_file.sha256"
    fi

    if [[ -f "$checksum_file" ]]; then
        local expected actual
        expected="$(awk 'NR == 1 {print $1}' "$checksum_file")"
        actual="$(sha256sum "$tarball" | awk '{print $1}')"
        [[ "$expected" == "$actual" ]] || die "checksum paket tidak cocok; file rusak atau sudah diubah"
        info "Checksum SHA-256 cocok"
    else
        warn "tidak ada $checksum_file; checksum paket tidak diverifikasi"
    fi

    local extract_dir="$work_dir/extract"
    mkdir -p "$extract_dir"
    tar -xzf "$tarball" -C "$extract_dir" --no-same-owner --warning=no-unknown-keyword

    local entries=("$extract_dir"/*)
    local top="${entries[0]}"

    if [[ ${#entries[@]} -ne 1 || ! -f "$top/VERSION" || ! -x "$top/bin/rayxod" || ! -f "$top/panel/artisan" ||
        ! -f "$top/phpmyadmin/index.php" ]]; then
        die "isi paket tidak dikenali; pastikan ini file rilis RAYXO"
    fi

    if [[ "$(basename "$top")" != *"-linux-$arch" ]]; then
        die "paket $(basename "$top") bukan untuk server ini (arsitektur $arch)"
    fi

    version="$(head -n 1 "$top/VERSION")"
    local version_re='^[0-9A-Za-z][0-9A-Za-z._-]*$'
    [[ "$version" =~ $version_re ]] || die "versi paket tidak valid: $version"

    release_dir="$INSTALL_DIR/releases/$version"
    if [[ -e "$release_dir" ]]; then
        # Move the old copy aside rather than deleting it while PHP-FPM and the
        # queue worker may still run from it; it is removed once all is well.
        stale_release="$release_dir.replaced-$(date +%s)"
        mv "$release_dir" "$stale_release"
        info "Rilis $version sudah ada; diganti dengan isi paket ini"
    fi

    mv "$top" "$release_dir"
    chown -R root:root "$release_dir"
    chmod -R go-w "$release_dir"

    # Code stays read-only for the panel user; only these are writable.
    local panel="$release_dir/panel"
    rm -rf "$panel/storage"
    ln -sfn "$DATA_DIR/storage" "$panel/storage"
    ln -sfn "$ENV_FILE" "$panel/.env"
    # The config cache written here holds APP_KEY and the database password,
    # so only the panel user may enter this directory.
    install -d -m 0750 -o "$PANEL_USER" -g "$PANEL_USER" "$panel/bootstrap/cache"
    chown -R "$PANEL_USER:$PANEL_USER" "$panel/bootstrap/cache"
    chmod 0750 "$panel/bootstrap/cache"

    info "RAYXO $version disalin ke $release_dir"
}

setup_database() {
    step "Menyiapkan database panel"

    db_password="$(env_value DB_PASSWORD)"
    if [[ -z "$db_password" ]]; then
        db_password="$(openssl rand -hex 24)"
    fi

    # Remove the anonymous users and test database (as mysql_secure_installation
    # does), then create the panel's own database and user.
    mariadb <<SQL
DELETE FROM mysql.global_priv WHERE User = '';
DROP DATABASE IF EXISTS test;
CREATE DATABASE IF NOT EXISTS \`rayxo\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'rayxo'@'localhost' IDENTIFIED BY '$db_password';
CREATE USER IF NOT EXISTS 'rayxo'@'127.0.0.1' IDENTIFIED BY '$db_password';
ALTER USER 'rayxo'@'localhost' IDENTIFIED BY '$db_password';
ALTER USER 'rayxo'@'127.0.0.1' IDENTIFIED BY '$db_password';
GRANT ALL PRIVILEGES ON \`rayxo\`.* TO 'rayxo'@'localhost';
GRANT ALL PRIVILEGES ON \`rayxo\`.* TO 'rayxo'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

    info "Database dan user MariaDB \"rayxo\" siap"
}

# The panel tells users to point their domains' A records to this address
# (config('hosting.public_ip')). Only a public IPv4 address is written (or
# one given with --public-ip), and a value already there is kept.
add_public_ip_env() {
    local ipv4_re='^([0-9]{1,3}\.){3}[0-9]{1,3}$'

    if [[ ! "${public_ip:-}" =~ $ipv4_re ]]; then
        return
    fi

    if is_private_ip "$public_ip" && ((public_ip_given == 0)); then
        return
    fi

    add_env_value RAYXO_PUBLIC_IP "$public_ip"
}

# Where phpMyAdmin is, and the key the panel seals its sign-on tickets with
# (configure_phpmyadmin gives phpMyAdmin the same key). The key is generated
# here, in a variable, so it never shows up on a command line.
add_phpmyadmin_env() {
    add_env_value PHPMYADMIN_URL "https://$server_hostname/phpmyadmin/"

    if [[ -z "$(env_value PHPMYADMIN_SSO_KEY)" ]]; then
        local key
        key="$(openssl rand -base64 32)"
        add_env_value PHPMYADMIN_SSO_KEY "$key"
    fi
}

write_env() {
    if [[ -f "$ENV_FILE" ]]; then
        info "Memakai konfigurasi yang sudah ada: $ENV_FILE"
        # Installs from before the panel was translated have no locale yet.
        add_env_value APP_LOCALE id
        add_env_value APP_FALLBACK_LOCALE en
        # Installs from before SSL for customer domains have no public IP yet.
        add_public_ip_env
        # Installs from before phpMyAdmin.
        add_phpmyadmin_env
        # Installs from before the file manager.
        add_env_value RAYXO_TRANSFER_DIR "$DATA_DIR/transfer"
        # The panel's own addresses follow a new hostname or port.
        local old_host="${previous_hostname:-$server_hostname}"
        follow_env_value APP_URL "https://$old_host:$previous_panel_port" "https://$server_hostname:$PANEL_PORT"
        if [[ "$old_host" != "$server_hostname" ]]; then
            follow_env_value PHPMYADMIN_URL "https://$old_host/phpmyadmin/" "https://$server_hostname/phpmyadmin/"
            follow_env_value MAIL_FROM_ADDRESS "rayxo@$old_host" "rayxo@$server_hostname"
        fi
        return
    fi

    step "Menulis konfigurasi panel"

    local app_key
    app_key="base64:$(openssl rand -base64 32)"

    # Queue, cache and sessions use MariaDB on purpose: customer PHP runs on
    # this same server, and a password-less Redis on localhost would let it
    # read panel sessions.
    (
        umask 027
        cat >"$ENV_FILE" <<ENV
APP_NAME=RAYXO
APP_ENV=production
APP_KEY=$app_key
APP_DEBUG=false
APP_URL=https://$server_hostname:$PANEL_PORT

APP_LOCALE=id
APP_FALLBACK_LOCALE=en

LOG_CHANNEL=daily
LOG_LEVEL=info

DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=rayxo
DB_USERNAME=rayxo
DB_PASSWORD=$db_password

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database

MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=25
MAIL_FROM_ADDRESS=rayxo@$server_hostname
MAIL_FROM_NAME=RAYXO

AGENT_DRIVER=socket
AGENT_SOCKET=$AGENT_SOCKET
AGENT_TIMEOUT=30
ENV
    )

    chown "root:$PANEL_USER" "$ENV_FILE"
    chmod 0640 "$ENV_FILE"
    add_public_ip_env
    add_phpmyadmin_env
    add_env_value RAYXO_TRANSFER_DIR "$DATA_DIR/transfer"
    info "Konfigurasi ditulis ke $ENV_FILE"
}

configure_dkim() {
    step "Mengatur DKIM (tanda tangan untuk email panel)"

    local key_dir="/etc/opendkim/keys/$server_hostname"
    local private_key="$key_dir/$DKIM_SELECTOR.private"

    if [[ ! -f "$private_key" ]]; then
        install -d -m 0750 -o opendkim -g opendkim /etc/opendkim/keys "$key_dir"
        quietly opendkim-genkey -b 2048 -h sha256 -d "$server_hostname" -D "$key_dir" -s "$DKIM_SELECTOR"
        info "Kunci DKIM 2048-bit dibuat"
    fi

    chown -R opendkim:opendkim /etc/opendkim/keys
    chmod 0600 "$private_key"

    cat >/etc/opendkim.conf <<CONF
# Managed by rayxo setup.sh: signs mail sent from $server_hostname.
Syslog                  yes
SyslogSuccess           yes
UMask                   007
UserID                  opendkim
Mode                    s
Canonicalization        relaxed/simple
OversignHeaders         From
Domain                  $server_hostname
Selector                $DKIM_SELECTOR
KeyFile                 $private_key
Socket                  inet:8891@127.0.0.1
PidFile                 /run/opendkim/opendkim.pid
CONF

    quietly systemctl enable opendkim
    systemctl restart opendkim

    # The public key for the DNS record, derived from the private key itself.
    dkim_public_key="$(openssl rsa -in "$private_key" -pubout -outform DER 2>/dev/null | base64 -w0)"
    info "OpenDKIM aktif (selector \"$DKIM_SELECTOR\")"
}

configure_mail() {
    step "Mengatur email keluar (Postfix, hanya untuk server ini)"

    printf '%s\n' "$server_hostname" >/etc/mailname

    # Send-only: Postfix listens on loopback, so nothing outside this server
    # can use it to send mail. IPv4 only, because the big mail providers reject
    # mail sent over IPv6 from an address without a matching PTR record.
    # No TLS on the loopback listener: there is nothing to protect on 127.0.0.1.
    quietly postconf -e \
        "myhostname = $server_hostname" \
        "myorigin = /etc/mailname" \
        "mydestination = \$myhostname, localhost.localdomain, localhost" \
        "inet_interfaces = loopback-only" \
        "inet_protocols = ipv4" \
        "mynetworks = 127.0.0.0/8" \
        "smtpd_relay_restrictions = permit_mynetworks, reject" \
        "smtpd_tls_security_level = none" \
        "smtp_tls_security_level = may" \
        "disable_vrfy_command = yes" \
        "smtpd_banner = \$myhostname ESMTP" \
        "smtpd_milters = inet:127.0.0.1:8891" \
        "non_smtpd_milters = \$smtpd_milters" \
        "milter_default_action = accept"
    quietly postfix check
    quietly systemctl enable postfix
    systemctl restart postfix
    info "Postfix hanya menerima email dari server ini (127.0.0.1)"

    # Older installs logged mail instead of sending it. Switch those over, but
    # never touch mail settings someone changed by hand (e.g. external SMTP).
    if [[ "$(env_value MAIL_MAILER)" == log ]]; then
        set_env_value MAIL_MAILER smtp
        set_env_value MAIL_HOST 127.0.0.1
        set_env_value MAIL_PORT 25
        set_env_value MAIL_FROM_ADDRESS "rayxo@$server_hostname"
        set_env_value MAIL_FROM_NAME RAYXO
        info "Panel dipindah dari MAIL_MAILER=log ke Postfix lokal"
    fi
}

prepare_panel() {
    step "Menyiapkan aplikasi panel"
    quietly artisan migrate --force
    quietly artisan optimize
    info "Tabel database dibuat dan cache aplikasi disiapkan"
}

create_admin() {
    if [[ -f "$CONFIG_DIR/admin-created" ]]; then
        info "Admin pertama sudah pernah dibuat; dilewati"
        return
    fi

    step "Membuat admin pertama"
    admin_password="$(generate_password)"

    # The password travels in the environment, never on a command line where
    # other processes could see it.
    local output
    output="$(
        export RAYXO_ADMIN_PASSWORD="$admin_password"
        artisan rayxo:create-admin "$admin_user" "$admin_email" --no-ansi 2>&1
    )" || {
        printf '%s\n' "$output" >&2
        die "admin pertama gagal dibuat"
    }

    # If the environment did not reach the command, it generated its own.
    if [[ "$output" == *"Generated password (shown only once): "* ]]; then
        admin_password="$(printf '%s\n' "$output" | sed -n 's/.*Generated password (shown only once): //p' | tr -d '[:space:]')"
    fi

    touch "$CONFIG_DIR/admin-created"
    info "Admin \"$admin_user\" dibuat"
}

activate_release() {
    step "Mengaktifkan rilis $version"
    ln -sfn "releases/$version" "$INSTALL_DIR/current.new"
    mv -Tf "$INSTALL_DIR/current.new" "$INSTALL_DIR/current"
    ln -sfn current/bin "$INSTALL_DIR/bin"
    ln -sfn "$INSTALL_DIR/bin/rayxo" /usr/local/bin/rayxo
    info "$INSTALL_DIR/current -> releases/$version"
}

# Port 80 for the hostname: Let's Encrypt challenges, and a redirect to the
# panel for everything else.
configure_acme_site() {
    step "Menyiapkan $server_hostname di port 80"

    install -d -m 0755 -o root -g root "$ACME_DIR"

    local site=/etc/nginx/sites-available/rayxo-hostname.conf
    render "$release_dir/config/nginx-hostname.conf" "$site"

    if ! grep -q . /proc/net/if_inet6 2>/dev/null; then
        sed -i '/listen \[::\]/d' "$site"
    fi

    ln -sfn "$site" /etc/nginx/sites-enabled/rayxo-hostname.conf
    rm -f /etc/nginx/sites-enabled/default

    quietly nginx -t
    systemctl reload nginx
    info "http://$server_hostname diarahkan ke panel; dipakai juga untuk verifikasi Let's Encrypt"
}

# Get (or renew) the hostname certificate from Let's Encrypt through the port
# 80 site above. Returns non-zero instead of stopping the install, so the
# panel still comes up with a temporary certificate.
issue_certificate() {
    local live="/etc/letsencrypt/live/$server_hostname"

    # Still valid for more than 30 days: nothing to do.
    if [[ -f "$live/fullchain.pem" ]] &&
        openssl x509 -checkend 2592000 -noout -in "$live/fullchain.pem" >/dev/null 2>&1; then
        return 0
    fi

    local account=(--register-unsafely-without-email)
    if [[ -n "$admin_email" ]]; then
        account=(--email "$admin_email" --no-eff-email)
    fi

    if certbot certonly --webroot -w "$ACME_DIR" -d "$server_hostname" \
        --non-interactive --agree-tos --keep-until-expiring "${account[@]}" >>"$INSTALL_LOG" 2>&1; then
        return 0
    fi

    warn "Let's Encrypt gagal menerbitkan sertifikat (detail di $INSTALL_LOG); pastikan port 80 terbuka dari internet"
    return 1
}

# certbot.timer renews every certificate (the hostname's and those the agent
# gets for customer domains); this hook makes Nginx pick up a renewed one.
# Installed even while the panel uses a self-signed certificate, because
# customer domains can still get theirs.
install_renewal_hook() {
    install -d -m 0755 /etc/letsencrypt/renewal-hooks/deploy
    cat >/etc/letsencrypt/renewal-hooks/deploy/rayxo-reload.sh <<HOOK
#!/bin/sh
# Installed by rayxo setup.sh: reload Nginx, which serves the hostname
# certificate and those of customer websites. A renewed hostname certificate
# also goes to Pure-FTPd, which reads key and certificate from one file, and
# only at start.
systemctl reload nginx

if [ "\$RENEWED_LINEAGE" = "/etc/letsencrypt/live/$server_hostname" ] && [ -f $FTP_CERTIFICATE ]; then
    umask 077
    cat "\$RENEWED_LINEAGE/privkey.pem" "\$RENEWED_LINEAGE/fullchain.pem" >$FTP_CERTIFICATE.new &&
        mv -f $FTP_CERTIFICATE.new $FTP_CERTIFICATE &&
        systemctl try-restart pure-ftpd-mysql
fi
HOOK
    chmod 0755 /etc/letsencrypt/renewal-hooks/deploy/rayxo-reload.sh
}

configure_ssl() {
    step "Memasang SSL untuk $server_hostname"

    local key="$CONFIG_DIR/ssl/panel.key" cert="$CONFIG_DIR/ssl/panel.crt"
    local live="/etc/letsencrypt/live/$server_hostname"

    install_renewal_hook

    if ((skip_dns_check == 0)) && issue_certificate; then
        ln -sfn "$live/fullchain.pem" "$cert"
        ln -sfn "$live/privkey.pem" "$key"

        ssl_status="letsencrypt"
        info "Sertifikat Let's Encrypt aktif sampai $(cert_expiry "$live/fullchain.pem"), diperpanjang otomatis"

        # certbot.timer would keep renewing the old hostname's certificate,
        # and fail once its DNS is gone.
        if [[ -n "$previous_hostname" && "$previous_hostname" != "$server_hostname" &&
            -d "/etc/letsencrypt/live/$previous_hostname" ]]; then
            if certbot delete --cert-name "$previous_hostname" --non-interactive >>"$INSTALL_LOG" 2>&1; then
                info "Sertifikat hostname lama ($previous_hostname) dihapus"
            else
                warn "sertifikat lama $previous_hostname tidak bisa dihapus; hapus dengan: certbot delete --cert-name $previous_hostname"
            fi
        fi
        return
    fi

    ssl_status="self-signed"

    if [[ -f "$cert" && -f "$key" && ! -L "$cert" ]]; then
        warn "memakai sertifikat self-signed yang sudah ada"
        return
    fi

    rm -f "$cert" "$key"

    local san="DNS:$server_hostname"
    if [[ -n "${public_ip:-$server_ip}" ]]; then
        san+=",IP:${public_ip:-$server_ip}"
    fi

    quietly openssl req -x509 -nodes -newkey rsa:2048 -days 825 \
        -keyout "$key" -out "$cert" -subj "/CN=$server_hostname" -addext "subjectAltName=$san"
    chmod 0600 "$key"
    warn "panel memakai sertifikat self-signed sementara; jalankan setup.sh lagi setelah masalahnya beres"
}

configure_php_fpm() {
    step "Mengatur PHP-FPM untuk panel"

    local pool_dir="/etc/php/$PHP_VERSION/fpm/pool.d"
    render "$release_dir/config/php-fpm-panel.conf" "$pool_dir/rayxo.conf"
    render "$release_dir/config/php-fpm-phpmyadmin.conf" "$pool_dir/rayxo-phpmyadmin.conf"

    if [[ -f "$pool_dir/www.conf" ]]; then
        mv "$pool_dir/www.conf" "$pool_dir/www.conf.disabled"
        info "Pool bawaan \"www\" dimatikan"
    fi

    quietly "php-fpm$PHP_VERSION" -t
    systemctl restart "php$PHP_VERSION-fpm"
    info "Pool \"rayxo\" dan \"rayxo-phpmyadmin\" aktif (fungsi exec dimatikan)"
}

configure_nginx() {
    step "Mengatur Nginx untuk panel (port $PANEL_PORT)"

    local site=/etc/nginx/sites-available/rayxo.conf
    render "$release_dir/config/nginx-panel.conf" "$site"

    # procfs files always report size 0, so check for content instead.
    if ! grep -q . /proc/net/if_inet6 2>/dev/null; then
        sed -i '/listen \[::\]/d' "$site"
        info "IPv6 tidak aktif; panel hanya mendengar di IPv4"
    fi

    ln -sfn "$site" /etc/nginx/sites-enabled/rayxo.conf
    rm -f /etc/nginx/sites-enabled/default
    # A port tried from the panel is now the panel's port, or given up.
    rm -f "$PANEL_PORT_TRIAL_SITE"

    quietly nginx -t
    systemctl reload nginx
    printf '%s\n' "$PANEL_PORT" >"$PANEL_PORT_FILE"
    rm -f "$PANEL_PORT_TRIAL_STATE"
    info "Panel dilayani di port $PANEL_PORT"
}

# phpMyAdmin at https://<hostname>/phpmyadmin/ (port 443, so not the panel's
# origin), in the release's phpmyadmin/ folder. Its configuration and keys
# live in $PMA_CONFIG_DIR, readable by rayxo-pma only.
configure_phpmyadmin() {
    step "Mengatur phpMyAdmin (https://$server_hostname/phpmyadmin/)"

    local sso_key
    sso_key="$(env_value PHPMYADMIN_SSO_KEY)"
    [[ -n "$sso_key" ]] || die "PHPMYADMIN_SSO_KEY tidak ada di $ENV_FILE"

    # Written by the shell itself (printf is a builtin), so the keys never
    # show up on a command line.
    (
        umask 027
        if [[ ! -s "$PMA_CONFIG_DIR/blowfish.key" ]]; then
            openssl rand -base64 32 >"$PMA_CONFIG_DIR/blowfish.key"
        fi
        printf '%s\n' "$sso_key" >"$PMA_CONFIG_DIR/sso.key"
    )
    render "$release_dir/config/phpmyadmin-config.inc.php" "$PMA_CONFIG_DIR/config.inc.php"
    chown "root:$PMA_USER" "$PMA_CONFIG_DIR/blowfish.key" "$PMA_CONFIG_DIR/sso.key" "$PMA_CONFIG_DIR/config.inc.php"
    chmod 0640 "$PMA_CONFIG_DIR/blowfish.key" "$PMA_CONFIG_DIR/sso.key" "$PMA_CONFIG_DIR/config.inc.php"

    local site=/etc/nginx/sites-available/rayxo-phpmyadmin.conf
    render "$release_dir/config/nginx-phpmyadmin.conf" "$site"

    if ! grep -q . /proc/net/if_inet6 2>/dev/null; then
        sed -i '/listen \[::\]/d' "$site"
    fi

    ln -sfn "$site" /etc/nginx/sites-enabled/rayxo-phpmyadmin.conf

    quietly nginx -t
    systemctl reload nginx
    info "phpMyAdmin siap; customer membukanya dari halaman Databases di panel (login otomatis)"
}

# FTP for hosting accounts. Pure-FTPd signs logins in from the panel's
# database (v_ftp_users, through a MariaDB user that may only read that
# view), shuts each login in its folder acting as the account's Linux user,
# and refuses sessions that do not switch to TLS. Logins of suspended
# accounts drop out of the view, so nothing here changes when they do.
configure_ftp() {
    step "Mengatur FTP (Pure-FTPd, wajib TLS)"

    # A new password on every run; it only lives in Pure-FTPd's config. The
    # view comes from the panel's migrations (prepare_panel).
    local password
    password="$(openssl rand -hex 24)"

    mariadb <<SQL
CREATE USER IF NOT EXISTS '$FTP_DB_USER'@'localhost' IDENTIFIED BY '$password';
ALTER USER '$FTP_DB_USER'@'localhost' IDENTIFIED BY '$password';
GRANT SELECT ON \`rayxo\`.\`v_ftp_users\` TO '$FTP_DB_USER'@'localhost';
SQL

    local db_conf=/etc/pure-ftpd/db/mysql.conf
    (
        umask 077
        render "$release_dir/config/pure-ftpd-mysql.conf" "$db_conf.new"
        printf 'MYSQLPassword    %s\n' "$password" >>"$db_conf.new"
    )
    mv -f "$db_conf.new" "$db_conf"

    # Debian's wrapper turns each file in conf/ into an option. Logins come
    # only from MariaDB: PAM and system accounts are switched off.
    local conf=/etc/pure-ftpd/conf option value
    while read -r option value; do
        printf '%s\n' "$value" >"$conf/$option"
    done <<OPTIONS
MySQLConfigFile $db_conf
PAMAuthentication no
UnixAuthentication no
NoAnonymous yes
MinUID 1000
ChrootEveryone yes
TLS 2
TLSCipherSuite HIGH
PassivePortRange $FTP_PASSIVE_PORTS
DontResolve yes
DisplayDotFiles yes
Umask 133 022
MaxClientsNumber 50
MaxClientsPerIP 8
MaxIdleTime 15
LimitRecursion 10000 8
CustomerProof yes
OPTIONS

    # Behind NAT, passive connections must be sent to the public address.
    if [[ -n "${public_ip:-}" && "$public_ip" != "${server_ip:-}" ]]; then
        printf '%s\n' "$public_ip" >"$conf/ForcePassiveIP"
    else
        rm -f "$conf/ForcePassiveIP"
    fi

    # The wrapper only uses the backends linked in auth/.
    find /etc/pure-ftpd/auth -mindepth 1 -maxdepth 1 -type l -delete
    ln -s ../conf/MySQLConfigFile /etc/pure-ftpd/auth/30mysql

    # The hostname certificate, as the panel uses it (self-signed until Let's
    # Encrypt works); the renewal hook keeps it current.
    mkdir -p "$(dirname "$FTP_CERTIFICATE")"
    (
        umask 077
        cat "$CONFIG_DIR/ssl/panel.key" "$CONFIG_DIR/ssl/panel.crt" >"$FTP_CERTIFICATE.new"
    )
    mv -f "$FTP_CERTIFICATE.new" "$FTP_CERTIFICATE"

    quietly systemctl enable pure-ftpd-mysql
    systemctl restart pure-ftpd-mysql
    info "FTP aktif di port 21: wajib TLS, passive ${FTP_PASSIVE_PORTS/ /-}, login dibuat customer di panel"
}

# SFTP for hosting accounts, as their own Linux user with the password they
# set in the panel. sshd gives the members of rayxo-sftp SFTP only; nothing
# changes for root or other users of the server. If sshd refuses the
# configuration, it is removed again, so SSH always keeps working.
configure_sftp() {
    step "Mengatur SFTP untuk akun hosting"

    if ! getent group "$SFTP_GROUP" >/dev/null; then
        quietly groupadd --system "$SFTP_GROUP"
    fi

    # Accounts created before SFTP join the group too (the panel's accounts
    # carry "rayxo" as their GECOS comment).
    local name
    while read -r name; do
        if ! id -nG "$name" | tr ' ' '\n' | grep -qx "$SFTP_GROUP"; then
            quietly gpasswd --add "$name" "$SFTP_GROUP"
        fi
    done < <(getent passwd | awk -F: '$3 >= 1000 && $5 ~ /^rayxo(,|$)/ {print $1}')

    render "$release_dir/config/sshd-sftp.conf" "$SFTP_SSHD_CONF"
    chmod 0644 "$SFTP_SSHD_CONF"

    # sshd -t needs the privilege separation directory, which only exists
    # once ssh.service has run (Ubuntu 24.04 starts it on the first connection).
    install -d -m 0755 /run/sshd
    if ! /usr/sbin/sshd -t >>"$INSTALL_LOG" 2>&1; then
        rm -f "$SFTP_SSHD_CONF"
        die "sshd menolak konfigurasi SFTP; file-nya dihapus lagi supaya SSH tetap normal (detail di $INSTALL_LOG)"
    fi

    quietly systemctl try-reload-or-restart ssh.service
    info "SFTP aktif di port 22 untuk akun hosting (hanya SFTP, tanpa shell); password SFTP diatur customer di halaman FTP"
}

# Customer websites: Nginx in front (ports 80/443, static files), Apache on
# 127.0.0.1:8080 behind it so .htaccess works, PHP in a pool per account.
# The agent writes one Nginx and one Apache file per domain into the
# directories created here.
configure_web() {
    step "Mengatur web server untuk website customer (Nginx -> Apache 127.0.0.1:8080)"

    install -d -m 0755 -o root -g root /etc/nginx/rayxo /etc/apache2/rayxo
    install -d -m 0750 -o root -g adm "$LOG_DIR/domains"

    cat >/etc/apache2/ports.conf <<'CONF'
# Managed by rayxo setup.sh. Apache only serves Nginx, on loopback;
# Nginx owns ports 80 and 443.
Listen 127.0.0.1:8080
CONF

    render "$release_dir/config/apache-rayxo.conf" /etc/apache2/conf-available/rayxo.conf

    if [[ -e /etc/apache2/sites-enabled/000-default.conf ]]; then
        quietly a2dissite -q 000-default
    fi

    # security: loads after rayxo.conf and would undo its ServerTokens
    # and ServerSignature (rayxo.conf carries its settings instead).
    # other-vhosts-access-log: Nginx already logs every request.
    # php-fpm: PHP only runs in the per-account pools, never in a shared one.
    local conf v php_confs=()
    for v in "${site_php_versions[@]}"; do
        php_confs+=("php$v-fpm")
    done

    for conf in security other-vhosts-access-log "${php_confs[@]}"; do
        if [[ -e "/etc/apache2/conf-enabled/$conf.conf" ]]; then
            quietly a2disconf -q "$conf"
        fi
    done

    quietly a2dismod -q mpm_prefork mpm_worker
    quietly a2enmod -q mpm_event proxy_fcgi remoteip rewrite headers setenvif
    quietly a2enconf -q rayxo

    {
        printf '# Managed by rayxo setup.sh: customer websites, one file per domain,\n'
        printf '# written by the agent (rayxod).\n'
        printf 'include /etc/nginx/rayxo/*/*.conf;\n'

        # Room for long domain names (253 characters plus "www.") and many
        # websites; with the defaults, nginx -t fails on a long name.
        if ! grep -Eqs '^[[:space:]]*server_names_hash_bucket_size' /etc/nginx/nginx.conf; then
            printf '\nserver_names_hash_bucket_size 512;\n'
        fi
        if ! grep -Eqs '^[[:space:]]*server_names_hash_max_size' /etc/nginx/nginx.conf; then
            printf 'server_names_hash_max_size 4096;\n'
        fi
    } >/etc/nginx/conf.d/rayxo-sites.conf

    # Host names no website uses get a 404, never a customer's site.
    local site=/etc/nginx/sites-available/rayxo-default.conf
    render "$release_dir/config/nginx-default.conf" "$site"

    if ! grep -q . /proc/net/if_inet6 2>/dev/null; then
        sed -i '/listen \[::\]/d' "$site"
    fi

    ln -sfn "$site" /etc/nginx/sites-enabled/rayxo-default.conf

    quietly apache2ctl configtest
    quietly nginx -t
    quietly systemctl enable apache2
    # Starts Apache on a fresh install; applies config changes on a re-run.
    quietly systemctl reload-or-restart apache2
    systemctl reload nginx
    info "Apache hanya mendengar di 127.0.0.1:8080, di belakang Nginx"
}

# Whether the mount options in $1 (comma separated) turn on user quotas, on
# ext3/ext4 or XFS.
has_user_quota_option() {
    local option options
    IFS=, read -ra options <<<"$1"

    for option in "${options[@]}"; do
        case "$option" in
            usrquota | uquota | quota | usrjquota=?*) return 0 ;;
        esac
    done

    return 1
}

# Whether user quotas are on for the filesystem mounted at $1. quotaon -p
# exits with the number of quota types that are on, so only its output
# tells "on" from a failure.
user_quota_on() {
    local line
    while IFS= read -r line; do
        if [[ "$line" == "user quota on $1 ("*") is on" ]]; then
            return 0
        fi
    done < <(LC_ALL=C quotaon -p -u "$1" 2>/dev/null || true)

    return 1
}

# Append $QUOTA_OPTIONS to the options (4th field) of the one /etc/fstab
# line mounted at $1, after saving the file as $2. Every other line stays
# byte for byte the same. Fails, with /etc/fstab unchanged, when the file
# does not verify before or after the change. Every step is checked by hand:
# callers test the result, which turns off set -e in here.
add_fstab_quota_options() {
    local target="$1" backup="$2" candidate=/etc/fstab.rayxo-new

    if ! findmnt --verify --tab-file /etc/fstab >>"$INSTALL_LOG" 2>&1; then
        warn "/etc/fstab sudah bermasalah menurut findmnt --verify; tidak diubah, kuota disk dilewati"
        return 1
    fi

    # The candidate is a copy first, so it gets the owner and mode of
    # /etc/fstab.
    if ! cp -p /etc/fstab "$backup" || ! cp -p /etc/fstab "$candidate" ||
        ! awk -v target="$target" -v add=",$QUOTA_OPTIONS" '
        $1 !~ /^#/ && $2 == target {
            rest = $0; line = ""; field = 0
            while (rest != "") {
                match(rest, /^[ \t]*/)
                line = line substr(rest, 1, RLENGTH)
                rest = substr(rest, RLENGTH + 1)
                if (rest == "") break
                match(rest, /^[^ \t]+/)
                word = substr(rest, 1, RLENGTH)
                rest = substr(rest, RLENGTH + 1)
                if (++field == 4) word = word add
                line = line word
            }
            print line
            next
        }
        { print }
    ' /etc/fstab >"$candidate"; then
        rm -f "$candidate"
        warn "/etc/fstab tidak bisa disalin atau diubah; tidak diubah, kuota disk dilewati"
        return 1
    fi

    if ! findmnt --verify --tab-file "$candidate" >>"$INSTALL_LOG" 2>&1; then
        rm -f "$candidate"
        warn "/etc/fstab dengan opsi kuota tidak lolos findmnt --verify; tidak diubah, kuota disk dilewati"
        return 1
    fi

    if ! mv -f "$candidate" /etc/fstab; then
        rm -f "$candidate"
        warn "/etc/fstab tidak bisa diganti; tidak diubah, kuota disk dilewati"
        return 1
    fi

    if ! findmnt --verify --tab-file /etc/fstab >>"$INSTALL_LOG" 2>&1; then
        restore_fstab "$backup"
        warn "/etc/fstab baru tidak lolos findmnt --verify; dikembalikan dari $backup, kuota disk dilewati"
        return 1
    fi

    info "Opsi $QUOTA_OPTIONS ditambahkan untuk $target di /etc/fstab (cadangan: $backup)"
}

# Put back /etc/fstab from the backup $1. A failure is shouted about, since
# the server would boot with the changed file.
restore_fstab() {
    if ! cp -p "$1" /etc/fstab; then
        warn "/etc/fstab TIDAK bisa dikembalikan; kembalikan manual sebelum reboot: cp -p $1 /etc/fstab"
    fi
}

# Undo a failed attempt to turn on quotas for the filesystem mounted at $1:
# quotas off and, when /etc/fstab was changed (saved as $2), the old fstab
# back and the filesystem remounted with it. $3 says what went wrong.
undo_quota() {
    local target="$1" backup="$2" reason="$3"

    quotaoff -u "$target" >>"$INSTALL_LOG" 2>&1 || true

    if [[ -n "$backup" ]]; then
        restore_fstab "$backup"
        if ! mount -o remount "$target" >>"$INSTALL_LOG" 2>&1; then
            warn "$target tidak bisa di-remount dengan /etc/fstab lama; opsi lama berlaku lagi setelah reboot"
        fi
        systemctl daemon-reload >>"$INSTALL_LOG" 2>&1 || true
        reason="$reason; /etc/fstab dikembalikan seperti semula"
    fi

    warn "$reason. Kuota disk tidak aktif: panel tetap jalan, tapi ukuran akun tidak dibatasi (detail di $INSTALL_LOG)"
}

# Turn on journaled user quotas for the ext3/ext4 filesystem mounted at $1,
# whose current mount options are $2: add the options to its /etc/fstab line
# when missing, remount it, build aquota.user and turn quotas on. Anything
# that fails is undone.
enable_journaled_quota() {
    local target="$1" active_options="$2"
    local lines fstab_options backup=""

    lines="$(awk -v target="$target" '$1 !~ /^#/ && $2 == target {n++} END {print n + 0}' /etc/fstab)"
    if ((lines != 1)); then
        warn "/etc/fstab punya $lines baris untuk $target (seharusnya 1); kuota disk dilewati, ukuran akun tidak dibatasi"
        return
    fi

    fstab_options="$(awk -v target="$target" '$1 !~ /^#/ && $2 == target {print $4}' /etc/fstab)"
    if [[ -z "$fstab_options" ]]; then
        warn "baris $target di /etc/fstab tidak punya kolom opsi; kuota disk dilewati, ukuran akun tidak dibatasi"
        return
    fi

    if ! has_user_quota_option "$fstab_options"; then
        backup="/etc/fstab.rayxo-$(date +%Y%m%d-%H%M%S)"
        add_fstab_quota_options "$target" "$backup" || return 0
        systemctl daemon-reload >>"$INSTALL_LOG" 2>&1 || true
    fi

    # Without a source, mount takes the options of $target from /etc/fstab.
    if ! has_user_quota_option "$active_options" && ! mount -o remount "$target" >>"$INSTALL_LOG" 2>&1; then
        undo_quota "$target" "$backup" "kernel menolak opsi kuota saat remount $target"
        return
    fi

    # quotacheck without -c keeps the limits in an aquota.user left by an
    # earlier attempt; -m scans without remounting read-only.
    if ! { [[ -s "${target%/}/aquota.user" ]] && quotacheck -um "$target" >>"$INSTALL_LOG" 2>&1; } &&
        ! quotacheck -cum "$target" >>"$INSTALL_LOG" 2>&1; then
        undo_quota "$target" "$backup" "quotacheck gagal untuk $target"
        return
    fi

    if ! quotaon -u "$target" >>"$INSTALL_LOG" 2>&1 || ! user_quota_on "$target"; then
        undo_quota "$target" "$backup" "kuota user tidak bisa dinyalakan di $target (kernel tanpa modul quota_v2?)"
        return
    fi

    quota_mount="$target"
    info "Kuota disk aktif di $target (journaled, aquota.user); tetap aktif setelah reboot"
}

# Disk quotas for hosting accounts: journaled user quotas on the filesystem
# that holds /home, which the agent sets per account (account.set_quota).
# Never fails the install: where quotas cannot be turned on safely, the
# panel keeps working without them and the agent reports them as off. Only
# the /etc/fstab line of that filesystem is changed, after a backup; nothing
# is rebooted and group quotas are never used.
configure_quota() {
    step "Mengatur kuota disk akun hosting"

    quota_mount=""

    # Tested directly: a failing command inside $(...) would fire the ERR
    # trap even here.
    if systemd-detect-virt --container --quiet; then
        warn "server ini container ($(systemd-detect-virt --container || true)), yang tidak bisa memakai kuota disk;" \
            "ukuran akun tidak dibatasi"
        return
    fi

    local target source fstype options
    target="$(findmnt --noheadings --output TARGET --target /home 2>/dev/null || true)"
    source="$(findmnt --noheadings --output SOURCE --target /home 2>/dev/null || true)"
    fstype="$(findmnt --noheadings --output FSTYPE --target /home 2>/dev/null || true)"
    options="$(findmnt --noheadings --output OPTIONS --target /home 2>/dev/null || true)"
    printf 'configure_quota: /home is on %s (%s, %s, %s)\n' "$target" "$source" "$fstype" "$options" >>"$INSTALL_LOG"

    if [[ -z "$target" || -z "$fstype" ]]; then
        warn "filesystem /home tidak ditemukan; kuota disk dilewati, ukuran akun tidak dibatasi"
        return
    fi

    if user_quota_on "$target"; then
        quota_mount="$target"
        info "Kuota disk sudah aktif di $target"
    else
        case "$fstype" in
            ext4 | ext3)
                enable_journaled_quota "$target" "$options"
                ;;
            xfs)
                if has_user_quota_option "$options"; then
                    quota_mount="$target"
                    info "Kuota disk XFS sudah aktif di $target"
                else
                    warn "/home ada di XFS ($target): kuota XFS hanya bisa dinyalakan saat boot dengan opsi mount uquota" \
                        "(untuk root filesystem: rootflags=uquota di kernel command line). Atur itu, reboot, lalu jalankan setup.sh lagi;" \
                        "sampai saat itu ukuran akun tidak dibatasi"
                fi
                ;;
            *)
                warn "filesystem $fstype di $target belum didukung untuk kuota disk; ukuran akun tidak dibatasi"
                ;;
        esac
    fi

    # The quota package asks for a quotacheck -c of every filesystem at the
    # next boot (quota.service), which would rebuild aquota.user without the
    # limits the agent sets; the quota files are already checked.
    if [[ -n "$quota_mount" ]]; then
        rm -f /var/lib/quota/new
    fi
}

configure_logrotate() {
    render "$release_dir/config/logrotate.conf" /etc/logrotate.d/rayxo
    info "Rotasi log panel diatur (/etc/logrotate.d/rayxo)"
}

install_services() {
    step "Memasang service systemd"

    install -m 0644 "$release_dir"/systemd/*.service "$release_dir"/systemd/*.timer /etc/systemd/system/
    systemctl daemon-reload
    quietly systemctl enable rayxod.service rayxo-queue.service rayxo-scheduler.timer
    systemctl restart rayxod.service rayxo-queue.service
    systemctl start rayxo-scheduler.timer
    info "rayxod, queue worker dan scheduler berjalan"
}

# Websites set up by an older release keep their old web server config until
# it is written again. Queue that for every website; the queue worker and the
# agent do the rest in the background.
rebuild_websites() {
    step "Memperbarui konfigurasi website customer"

    if artisan rayxo:rebuild-web >>"$INSTALL_LOG" 2>&1; then
        info "Konfigurasi semua website diperbarui di latar belakang (lihat Tasks di panel)"
    else
        warn "konfigurasi website tidak bisa diperbarui; jalankan lagi: runuser -u $PANEL_USER -- $PHP_BIN $INSTALL_DIR/current/panel/artisan rayxo:rebuild-web"
    fi
}

# Accounts created before quotas existed, or before they were turned on
# here, get the limits of their package. Queued like rebuild_websites; when
# quotas are off the agent reports that they could not be applied.
apply_quotas() {
    step "Menerapkan kuota disk akun hosting"

    if artisan rayxo:apply-quotas >>"$INSTALL_LOG" 2>&1; then
        info "Kuota disk semua akun diterapkan di latar belakang (lihat Tasks di panel)"
    else
        warn "kuota disk akun tidak bisa diterapkan; jalankan lagi: runuser -u $PANEL_USER -- $PHP_BIN $INSTALL_DIR/current/panel/artisan rayxo:apply-quotas"
    fi
}

verify_install() {
    step "Memeriksa hasil instalasi"

    local attempt
    for attempt in $(seq 1 20); do
        if [[ -S "$AGENT_SOCKET" ]]; then
            break
        fi
        sleep 0.5
    done

    [[ -S "$AGENT_SOCKET" ]] || die "agent rayxod tidak berjalan (cek: journalctl -u rayxod)"

    # Call the agent as the panel user, exactly like the panel will.
    if ! runuser -u "$PANEL_USER" -- "$INSTALL_DIR/bin/rayxo" -socket "$AGENT_SOCKET" ping >>"$INSTALL_LOG" 2>&1; then
        die "user $PANEL_USER tidak bisa memanggil agent (cek: journalctl -u rayxod)"
    fi
    info "Agent rayxod menjawab panggilan dari panel"

    systemctl is-active --quiet rayxo-queue.service ||
        die "queue worker tidak berjalan (cek: journalctl -u rayxo-queue)"

    local status
    status="$(curl -sk -o /dev/null -w '%{http_code}' "https://127.0.0.1:$PANEL_PORT/login" || true)"
    [[ "$status" == 200 ]] ||
        die "halaman login panel mengembalikan HTTP $status (cek: $LOG_DIR/panel-error.log dan $DATA_DIR/storage/logs/)"
    info "Halaman login panel bisa dibuka"

    local v
    for v in "${site_php_versions[@]}"; do
        "/usr/sbin/php-fpm$v" -v >>"$INSTALL_LOG" 2>&1 || die "PHP-FPM $v tidak bisa dijalankan (cek: /usr/sbin/php-fpm$v -v)"
    done
    info "PHP untuk website: ${site_php_versions[*]}"

    systemctl is-active --quiet apache2 || die "Apache tidak berjalan (cek: journalctl -u apache2)"
    local apache_listen
    apache_listen="$(ss -ltnpH | awk '/"apache2"/ {print $4}' | sort -u | paste -sd ' ' -)"
    [[ "$apache_listen" == "127.0.0.1:8080" ]] ||
        die "Apache harus hanya mendengar di 127.0.0.1:8080, sekarang: ${apache_listen:-tidak mendengar di port mana pun}"
    info "Apache aktif dan hanya mendengar di 127.0.0.1:8080"

    # nginx -t does not bind ports, so a reload that could not take port 443
    # would only show up here.
    local port listeners
    for port in 80 443; do
        listeners="$(ss -ltnpH "sport = :$port")"
        [[ "$listeners" == *'"nginx"'* ]] ||
            die "Nginx tidak mendengar di port $port (cek: journalctl -u nginx dan ss -ltnp)"
    done
    info "Nginx mendengar di port 80 dan 443"

    systemctl is-active --quiet postfix || die "Postfix tidak berjalan (cek: journalctl -u postfix)"
    if ss -ltnH 'sport = :25' | grep -vqE '127\.0\.0\.1:25'; then
        die "Postfix mendengar di alamat selain 127.0.0.1; server bisa dipakai untuk spam"
    fi
    info "Postfix aktif dan hanya mendengar di 127.0.0.1"

    systemctl is-active --quiet opendkim || die "OpenDKIM tidak berjalan (cek: journalctl -u opendkim)"
    info "OpenDKIM aktif"

    # Quotas are optional, so losing them only warns.
    if [[ -n "$quota_mount" ]]; then
        if user_quota_on "$quota_mount"; then
            info "Kuota disk aktif di $quota_mount"
        else
            warn "kuota disk di $quota_mount tidak aktif lagi (cek: quotaon -p -u $quota_mount); jalankan setup.sh lagi"
        fi
    fi

    # With a real certificate, check it the way a browser would: by name, with
    # full chain verification (no -k).
    if [[ "$ssl_status" == letsencrypt ]]; then
        status="$(curl -s -o /dev/null -w '%{http_code}' --resolve "$server_hostname:$PANEL_PORT:127.0.0.1" \
            "https://$server_hostname:$PANEL_PORT/login" || true)"
        [[ "$status" == 200 ]] ||
            die "panel tidak bisa dibuka dengan sertifikat Let's Encrypt (HTTP $status)"
        info "https://$server_hostname:$PANEL_PORT bisa dibuka dengan sertifikat yang valid"
    fi

    # SFTP only applies to hosting accounts: root keeps its own settings.
    local sshd_root
    sshd_root="$(/usr/sbin/sshd -T -C "user=root,host=localhost,addr=127.0.0.1" 2>/dev/null | grep '^forcecommand ' || true)"
    [[ "$sshd_root" == "forcecommand none" ]] ||
        die "konfigurasi SSH untuk root berubah ($sshd_root); cek $SFTP_SSHD_CONF"
    info "SFTP untuk akun hosting aktif; SSH untuk root tidak berubah"

    # FTP must switch to TLS with the hostname certificate, and refuse a
    # login sent before that. fd 4: fd 3 is the terminal.
    systemctl is-active --quiet pure-ftpd-mysql || die "Pure-FTPd tidak berjalan (cek: journalctl -u pure-ftpd-mysql)"
    if ! timeout 15 openssl s_client -connect 127.0.0.1:21 -starttls ftp -servername "$server_hostname" \
        </dev/null 2>/dev/null | grep -q "BEGIN CERTIFICATE"; then
        die "FTP di port 21 tidak bisa beralih ke TLS (cek: journalctl -u pure-ftpd-mysql)"
    fi
    local reply
    reply="$(
        (
            exec 4<>/dev/tcp/127.0.0.1/21
            while read -r -t 5 line <&4 && [[ "$line" != "220 "* ]]; do :; done
            printf 'USER rayxo-check\r\n' >&4
            read -r -t 5 line <&4 && printf '%s' "$line"
        ) 2>/dev/null || true
    )"
    # Pure-FTPd answers 421 and hangs up; 530 would refuse the login too.
    [[ "$reply" == 421* || "$reply" == 530* ]] || die "FTP menerima login tanpa TLS (balasan: ${reply:-tidak ada})"
    info "FTP aktif di port 21 dan hanya menerima login lewat TLS"

    # Without a sign-on session phpMyAdmin sends the browser to the panel.
    status="$(curl -sk -o /dev/null -w '%{http_code} %{redirect_url}' --resolve "$server_hostname:443:127.0.0.1" \
        "https://$server_hostname/phpmyadmin/" || true)"
    [[ "$status" == "302 https://$server_hostname:$PANEL_PORT/databases" ]] ||
        die "phpMyAdmin tidak berjalan seperti seharusnya (HTTP $status; cek: $LOG_DIR/phpmyadmin-error.log dan $PMA_DATA_DIR/log/)"
    info "phpMyAdmin aktif di https://$server_hostname/phpmyadmin/"

    printf '%s\n' "$version" >"$CONFIG_DIR/installed"

    if [[ -n "$stale_release" ]]; then
        rm -rf "$stale_release"
    fi
}

# Compare the DNS records this server needs with what public DNS has now,
# and write the full list to $DNS_REPORT so it can be looked up later.
build_dns_report() {
    local ip="${public_ip:-$server_ip}"
    local dkim_name="$DKIM_SELECTOR._domainkey.$server_hostname"
    local dmarc_name="_dmarc.$server_hostname"
    local spf_value="v=spf1 a ~all"
    local dkim_value="v=DKIM1; k=rsa; p=$dkim_public_key"
    local dmarc_value="v=DMARC1; p=none; adkim=r; aspf=r"

    local spf_ok="✘" dkim_ok="✘" dmarc_ok="✘" spf_now dkim_now dmarc_now
    spf_now="$(dns_lookup "$server_hostname" TXT | grep '^v=spf1' || true)"
    dkim_now="$(dns_lookup "$dkim_name" TXT | tr -d ' ' || true)"
    dmarc_now="$(dns_lookup "$dmarc_name" TXT | grep -i '^v=DMARC1' || true)"

    local spf_re="(^| )\+?a( |$)|ip4:${ip//./\\.}( |$)"
    if [[ -n "$spf_now" && "$spf_now" =~ $spf_re ]]; then spf_ok="✔"; fi
    if [[ -n "$dkim_public_key" && "$dkim_now" == *"p=$dkim_public_key"* ]]; then dkim_ok="✔"; fi
    if [[ -n "$dmarc_now" ]]; then dmarc_ok="✔"; fi

    dns_report_lines=(
        "[✔] A      $server_hostname -> $ip"
        "[✔] PTR    $ip -> $server_hostname   (diatur di provider VPS)"
        "[$spf_ok] SPF    TXT  $server_hostname"
        "           $spf_value"
        "[$dkim_ok] DKIM   TXT  $dkim_name"
        "           $dkim_value"
        "[$dmarc_ok] DMARC  TXT  $dmarc_name"
        "           $dmarc_value"
    )

    if ((skip_dns_check)); then
        dns_report_lines[0]="[?] A      $server_hostname -> $ip   (tidak diperiksa: --skip-dns-check)"
        dns_report_lines[1]="[?] PTR    $ip -> $server_hostname   (tidak diperiksa: --skip-dns-check)"
    fi

    {
        printf '# Rekaman DNS untuk %s (dibuat setup.sh %s)\n' "$server_hostname" "$(date -Is)"
        printf '# [✔] sudah benar di DNS publik, [✘] belum ada atau berbeda.\n'
        printf '# Nilai DKIM panjang: kebanyakan panel DNS (termasuk Cloudflare) menerimanya utuh.\n\n'
        printf '%s\n' "${dns_report_lines[@]}"
    } >"$DNS_REPORT"
    chmod 0644 "$DNS_REPORT"
}

summary() {
    printf '\n%s✔ RAYXO %s terpasang%s\n\n' "$green" "$version" "$reset"
    info "Panel    : https://$server_hostname:$PANEL_PORT"
    info "phpMyAdmin: dibuka customer dari halaman Databases (https://$server_hostname/phpmyadmin/)"
    info "Username : $admin_user"

    # Shown on the terminal only (file descriptor 3), never written to the log.
    # The pause lets tee print the lines above first.
    if [[ -n "$admin_password" ]]; then
        sleep 0.3
        printf '    Password : %s%s%s  (catat sekarang; hanya ditampilkan sekali)\n' \
            "$bold" "$admin_password" "$reset" >&3
    fi

    printf '\n'
    if [[ "$ssl_status" == letsencrypt ]]; then
        info "SSL      : Let's Encrypt, valid untuk $server_hostname (buka panel lewat hostname, bukan IP)"
    else
        info "SSL      : self-signed sementara; browser akan memberi peringatan"
    fi

    if [[ -n "$quota_mount" ]]; then
        info "Kuota    : disk & inode tiap akun dibatasi sesuai paketnya (kuota user di $quota_mount)"
    else
        info "Kuota    : tidak aktif di server ini; ukuran akun tidak dibatasi (alasan di $INSTALL_LOG)"
    fi

    printf '\n'
    info "Rekaman DNS untuk email panel (disimpan juga di $DNS_REPORT):"

    local line missing=0
    for line in "${dns_report_lines[@]}"; do
        info "  $line"
        if [[ "$line" == "[✘]"* ]]; then
            missing=1
        fi
    done

    if ((missing)); then
        info "  Tambahkan rekaman bertanda [✘] di DNS domainmu supaya email panel tidak masuk spam,"
        info "  lalu cek ulang kapan saja dengan menjalankan setup.sh lagi."
    fi

    printf '\n'
    info "Catatan:"
    info "- Pastikan port 80, 443, $PANEL_PORT, 21 dan ${FTP_PASSIVE_PORTS/ /-} (FTP passive) terbuka di firewall / security group provider VPS."
    info "- Cek agent: rayxo ping"
    info "- Lupa password admin: runuser -u $PANEL_USER -- php$PHP_VERSION $INSTALL_DIR/current/panel/artisan rayxo:reset-password $admin_user"
    info "- Log instalasi: $INSTALL_LOG"
}

main() {
    parse_args "$@"

    [[ $EUID -eq 0 ]] || die "jalankan sebagai root, misalnya: sudo bash setup.sh ..."

    if [[ ! -f "$INSTALL_LOG" ]]; then
        install -m 0600 /dev/null "$INSTALL_LOG"
    fi

    # Keep the real terminal on fd 3, and copy everything else to the log.
    exec 3>&1
    exec > >(tee -a "$INSTALL_LOG") 2>&1

    trap 'on_error $LINENO' ERR
    trap cleanup EXIT

    printf '\n%sRAYXO installer%s (%s)\n' "$bold" "$reset" "$(date -Is)"

    preflight
    resolve_php_versions
    check_dns
    confirm
    apply_hostname
    create_layout
    if ((reconfigure)); then
        use_installed_release
    else
        install_packages
        install_site_php
        fetch_release
    fi
    setup_database
    write_env
    configure_dkim
    configure_mail
    prepare_panel
    activate_release
    configure_acme_site
    configure_ssl
    configure_php_fpm
    configure_nginx
    configure_phpmyadmin
    configure_ftp
    configure_sftp
    configure_web
    configure_quota
    configure_logrotate
    install_services
    verify_install
    rebuild_websites
    apply_quotas
    # Last, right before the summary, so a failure in an earlier step can never
    # leave an admin whose generated password was not shown.
    create_admin
    build_dns_report
    summary
}

main "$@"
