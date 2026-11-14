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
# PHP versions installed for websites (Ondřej Surý's packages, see
# add_php_repository) unless --php-versions or
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
# Webmail (Roundcube) runs as its own user too, with its own MariaDB user and
# database. These paths are also in installer/files/php-fpm-webmail.conf,
# roundcube-config.inc.php and roundcube-rayxo_sso.php.
readonly WEBMAIL_USER="rayxo-webmail"
readonly WEBMAIL_CONFIG_DIR="/etc/rayxo-webmail"
readonly WEBMAIL_DATA_DIR="/var/lib/rayxo-webmail"
readonly WEBMAIL_DB="roundcube"
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
# Accounts whose package allows SSH are in this group too; sshd gives them
# a shell in their jail ($DATA_DIR/jail/<user>, built by the agent).
readonly SSH_GROUP="rayxo-ssh"
readonly SFTP_SSHD_CONF="/etc/ssh/sshd_config.d/rayxo-sftp.conf"
readonly DKIM_SELECTOR="rayxo"
# PowerDNS serves the zones of hosting accounts from its own database; the
# agent changes them through PowerDNS's API with this key (root only).
readonly DNS_API_KEY_FILE="$CONFIG_DIR/pdns-api-key"
readonly DNS_API_PORT="8081"
readonly PDNS_CONF="/etc/powerdns/pdns.d/rayxo.conf"
# Postfix and Dovecot read mail domains and mailboxes from the panel's
# database as this MariaDB user, which may only read the v_mail_* views.
readonly MAIL_DB_USER="rayxo-mail"
readonly POSTFIX_MAPS_DIR="/etc/postfix/rayxo"
# Forwarded mail gets an SRS envelope sender from rayxod srs, which runs as
# this user and answers Postfix on a socket in its spool (path relative to
# the spool, where Postfix's daemons run).
readonly SRS_USER="rayxo-srs"
readonly SRS_SOCKET="rayxo-srs/srs.sock"
# Rspamd signs the mail of hosting accounts' domains with these DKIM keys
# (<domain>.<selector>.key); the agent writes them and the selector map.
readonly RSPAMD_DKIM_DIR="/var/lib/rspamd/dkim"
readonly RSPAMD_MAP_DIR="/etc/rspamd/rayxo"
readonly DNS_REPORT="$CONFIG_DIR/dns-records.txt"
readonly RELEASE_REPO="fianbiasa/rayxo"
# Public DNS over HTTPS, asked in this order. A resolver's cache can still
# hold a record that just changed (a Cloudflare node kept an old PTR for its
# whole TTL), so a check that fails with one asks the next before failing.
readonly DNS_RESOLVERS=("https://cloudflare-dns.com/dns-query" "https://dns.google/resolve")
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
allow_downgrade=0
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
  --allow-downgrade     izinkan memasang versi yang lebih lama dari rilis yang terpasang
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
            --allow-downgrade) allow_downgrade=1; shift ;;
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

# Ask public DNS (DNS_RESOLVERS, or only resolver $3) so the answer matches
# what Let's Encrypt and remote mail servers see, not this server's /etc/hosts
# or resolver cache. Prints one value per line: TXT strings joined and
# unquoted, trailing dots removed. Fails only if no resolver answers.
dns_lookup() {
    local name="$1" type="$2" url response
    local resolvers=("${DNS_RESOLVERS[@]}")

    if [[ -n "${3:-}" ]]; then
        resolvers=("$3")
    fi

    for url in "${resolvers[@]}"; do
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

# Ask each resolver for name/type until the answer passes the function
# $3; dns_answer is that answer, or else the first one, for the message.
# Returns 1 when no answer passes, 2 when no resolver answered at all.
dns_agrees() {
    local name="$1" type="$2" check="$3" url answer reached=0
    dns_answer=""

    for url in "${DNS_RESOLVERS[@]}"; do
        answer="$(dns_lookup "$name" "$type" "$url")" || continue

        if ((reached == 0)); then
            dns_answer="$answer"
        elif "$check" "$answer"; then
            warn "$type $name: sebagian resolver DNS masih menyimpan nilai lama ($(paste -sd ' ' <<<"$dns_answer")); biasanya hilang sendiri dalam satu jam"
        fi

        if "$check" "$answer"; then
            dns_answer="$answer"
            return 0
        fi

        reached=1
    done

    if ((reached)); then
        return 1
    fi

    return 2
}

a_is_public_ip() {
    [[ "$1" == "$public_ip" ]]
}

ptr_is_hostname() {
    grep -qx "$server_hostname" <<<"$1"
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

    # Known before anything is installed: refuse an older release right away.
    guard_downgrade "$wanted"

    release_url="https://github.com/$RELEASE_REPO/releases/download/v$wanted/rayxo-$wanted-linux-$arch.tar.gz"
}

# Refuse to install release $1 over a newer release: its code would not know
# the database the newer one migrated (migrations only go forward), so an
# old setup.sh from the website could quietly break a server. Development
# builds (versions with a suffix, like 0.10.1-dev) have no order against
# releases, so only an installed release is protected.
guard_downgrade() {
    local wanted="$1" installed
    installed="$(head -n 1 "$INSTALL_DIR/current/VERSION" 2>/dev/null || true)"

    if ((allow_downgrade)) || [[ -z "$installed" || "$installed" == *-* || "$installed" == "$wanted" ]]; then
        return
    fi

    if [[ "$(printf '%s\n%s\n' "$wanted" "$installed" | sort -V | head -n 1)" == "$wanted" ]]; then
        die "versi $wanted lebih lama dari RAYXO yang terpasang ($installed). Pakai setup.sh dari versi terpasang ($INSTALL_DIR/current/setup.sh) atau yang lebih baru; tambahkan --allow-downgrade hanya kalau memang sengaja turun versi"
    fi
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
        for package in apache2 nginx mysql-server mariadb-server "php$PHP_VERSION-fpm" bind9 pdns-server; do
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

    local status=0
    dns_agrees "$server_hostname" A a_is_public_ip || status=$?

    if ((status == 2)); then
        die "tidak bisa menghubungi DNS publik (cloudflare-dns.com / dns.google) untuk memeriksa A record"
    fi

    if ((status == 1)) && [[ -z "$dns_answer" ]]; then
        die "A record $server_hostname belum ada. Tambahkan A record $server_hostname -> $public_ip, tunggu beberapa menit, lalu ulangi"
    fi

    if ((status == 1)); then
        local hint=" (kalau pakai Cloudflare, set A record ke \"DNS only\", bukan proxy)"
        if is_private_ip "$server_ip" && ((public_ip_given == 0)); then
            hint=" (server ini di belakang NAT? isi IP publiknya dengan --public-ip)"
        fi
        die "A record $server_hostname harus mengarah hanya ke $public_ip, sekarang: $(paste -sd ' ' <<<"$dns_answer")$hint"
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

    local reverse_name
    reverse_name="$(awk -F. '{print $4 "." $3 "." $2 "." $1 ".in-addr.arpa"}' <<<"$public_ip")"
    status=0
    dns_agrees "$reverse_name" PTR ptr_is_hostname || status=$?

    if ((status == 2)); then
        die "tidak bisa menghubungi DNS publik untuk memeriksa PTR"
    fi

    if ((status == 1)); then
        die "PTR (reverse DNS) $public_ip harus $server_hostname, sekarang: ${dns_answer:-belum diatur}. Atur lewat panel/support provider VPS, lalu ulangi"
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
    info "Dipasang : Nginx + Apache, MariaDB, PHP ${site_php_versions[*]} (paket Ondřej Surý), PowerDNS, email (Postfix, Dovecot, Rspamd, Roundcube), FTP, agent rayxod"
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
        3306 | 6379 | 8080 | 8081 | 8891 | 11332 | 11333 | 11334) die "port $PANEL_PORT dipakai layanan lain di server ini; pilih port lain" ;;
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

    if ! id -u "$WEBMAIL_USER" >/dev/null 2>&1; then
        useradd --system --user-group --home-dir "$WEBMAIL_DATA_DIR" --no-create-home \
            --shell /usr/sbin/nologin "$WEBMAIL_USER"
        info "User sistem $WEBMAIL_USER (webmail) dibuat"
    fi

    # The same for Roundcube: it may only write its temporary files and log.
    install -d -m 0750 -o root -g "$WEBMAIL_USER" "$WEBMAIL_CONFIG_DIR" "$WEBMAIL_DATA_DIR"
    install -d -m 0700 -o "$WEBMAIL_USER" -g "$WEBMAIL_USER" "$WEBMAIL_DATA_DIR/tmp" "$WEBMAIL_DATA_DIR/log"

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

# PHP for the panel and the websites (7.4 to 8.5) is not in Ubuntu 24.04,
# which has 8.3 only. Ondřej Surý builds it for Ubuntu and publishes the same
# packages in two places: his own repository, packages.sury.org, and the
# ondrej/php PPA on Launchpad. A new server takes packages.sury.org, or the
# PPA when packages.sury.org cannot be reached; afterwards it keeps the
# repository it got. Both signing keys are pinned below.

# Write the armored key on stdin to FILE, once it proves to be exactly one
# key: the one with FINGERPRINT.
install_apt_key() {
    local key="$1" expected="$2" gpg_home fingerprints

    install -d -m 0755 /etc/apt/keyrings
    cat >"$key.new"
    gpg_home="$(mktemp -d)"
    fingerprints="$(gpg --homedir "$gpg_home" --show-keys --with-colons "$key.new" 2>>"$INSTALL_LOG" |
        awk -F: '$1 == "pub" {primary = 1; next} primary && $1 == "fpr" {print $10; primary = 0}')"
    rm -rf "$gpg_home"
    if [[ "$fingerprints" != "$expected" ]]; then
        rm -f "$key.new"
        die "kunci repo $key tidak cocok (fingerprint: ${fingerprints:-tidak terbaca})"
    fi

    chmod 0644 "$key.new"
    mv -f "$key.new" "$key"
}

# packages.sury.org, signed by "DEB.SURY.ORG Automatic Signing Key"
# (1505 8500 A023 5D97 F5D1 0063 B188 E2B6 95BD 4743). Written again on every
# run, so upgrading RAYXO renews the key: it expires on 2028-02-04, so put
# the renewed one (same fingerprint, from
# https://packages.sury.org/debsuryorg-archive-keyring.deb) here before then.
write_sury_php_source() {
    install_apt_key /etc/apt/keyrings/sury-php.asc 15058500A0235D97F5D10063B188E2B695BD4743 <<'KEY'
-----BEGIN PGP PUBLIC KEY BLOCK-----

mQGNBFyPb58BDADTDlJLrGJktWDaUT0tFohjFxy/lL2GcVYp4zB981MWIDC0aIQZ
ERfUZRaq/ov/LG3F0UhkvouCNrnXiFaKRCeNG52pQM0P/p3gmIOoPO4/jF0o3SK1
Aapf/NaKTh3EgeYYCnVKuxdXGqyu1JT4qfztsmUGmODzxVr+/YJLP54jrCUgI3lj
4zEeTBDexQvnlVUF59U1/ipMq4iWqqth8/aMsoZl3Ztfcc87jBFbJIoeQMhZtNZk
Ik7L15aYIZXWY2byBy6LB42HPm9DwM99l2eY4EXGfAq/UQeYbDGonibBqrDURggH
rkLfG7ZfoexF67/9S2s6VYfS4npWVfw2SEPTfSBdibElbGncd+p9Wb6SovqapCPl
crkLgPhBAz/R9M7E/G3zedmiEhsV78pBF3bup+nQVvBVtV/NucN5N6LkAclT4O3F
flGZa1/mJcpgjVapT6duY0POXczfS6ts55x2BE0UfYtXfRnVnHtu2+j8kqYG3N1G
sfVnzRkwtTWBMxMAEQEAAbQxREVCLlNVUlkuT1JHIEF1dG9tYXRpYyBTaWduaW5n
IEtleSA8ZGViQHN1cnkub3JnPokB1AQTAQoAPgIbAwULCQgHAgYVCgkICwIEFgID
AQIeAQIXgBYhBBUFhQCgI12X9dEAY7GI4raVvUdDBQJpGyGcBQkQth4UAAoJELGI
4raVvUdDbegL/RZHdZftjlES+YGtwk1RJj08eH2C3NG/no4nBy9/BT5Pvo1bxWvA
bBw0FxmmHcHDJxR+lfVhro6pvU/jekxFgdQB19rtoQR6OXNiDtRb8UCeSKOB0WnO
IpwNfEkShZh23z5hANhdqDcbUH8LtriWUnpWZthHG5msQc30vbt9xw12qTVYWfjD
J5I8+yxSUilADiLhnVNm4aX1ve/42UvCL9JvkVF7nYxOhJpWZR0FgX/35rOamJ5z
zIqZOw5JgNbnl/9HXCU99DLEmc0vD53b0K5l1pcBAuNi1g3I26MmN7oNCYtHoEHU
uVlxdN/fm971uFJCTZXr48PWgeWIgfVA9gPzoXx/lBW0DoJZEjj6ofySKFvyHCHq
DhEbaFtB1Du2Xyhi+ixBee+mb6XPdOygfWxUH9SPbO/+82fno6p1imGa0hbNN9rN
8O8CYPfJ0tZ3k0WE1ApZctzrAXFmbV/2veBukXe9xhFngt/UTQAVz/MNtVf5cXPP
lo69QK8Ka/eiNIkB1AQTAQoAPgIbAwULCQgHAgYVCgkICwIEFgIDAQIeAQIXgBYh
BBUFhQCgI12X9dEAY7GI4raVvUdDBQJlwLeqBQkM868LAAoJELGI4raVvUdDxVgL
/jfrmoVVuux5l2bdXePhJZRRLaLcAT7OiZd2sVbpV8Wwnf1gL7hY0ti/PLZxtmoQ
P2G+tgwy8eBx4+y3zK+Khdjnra9rTFnG7wBd2gzvVsLhN3hnVsOQk0CUW7oicaNi
AB5OfeL2mdaSjhxVGxWYP7qzO0VtoO/MbzECpp/7ESHjTmnlwtu75Pvag9Fi2pIe
rwuKxtJRDS07zhbA/50uX/1p+6maW2iZlxxRYUMyNq3OWA188vKcuDZS/Gq9uXHs
OM3s2z8JXib46teLpJRld1xavHRlKY57T6q2j/oKtIZ81/KoZIFJqarvBYAaWlRG
wUo6uIzca9/gScX5VR4gphi051SAQUXFQIYKvmrKNdtzrefiXLRiwb8L+xfIy76H
855riPpf5PT617Rtdoh4/+nb11Gyag32o89LO8MscwpgkU8R1vr9ioHy5vb0DwbD
2nz4Tv03RpeHESs2q4BAppwYveIJDHCzDj4LPkLIay831oPjeV3snHdeJ5FrK2xG
z4kB1AQTAQoAPgIbAwULCQgHAgYVCgkICwIEFgIDAQIeAQIXgBYhBBUFhQCgI12X
9dEAY7GI4raVvUdDBQJgK4WHBQkJP7BoAAoJELGI4raVvUdDQ/QL+wa0KQ8o8ask
ks4elU1PSdUP/ywacroMtl6BV2d/di/PtquZl4zIp/qAhUmcSJhUJMJBdGQ5S4ux
Cn0rEy2CBO8LhSTFuS01UGVHhjZQLA+GZEMunpS8KbPH5lWuwWwY1bbx9eCwpIxz
z3Krctk8WGvja4EsqIWmRcaQ1z19JndbH8Ekfhf2U7noZNFZIhHIOHK51dOm4oaS
drJUhhd52zrwLf+lOtHh0kkOad+eCByah9XwmO9qSAuHLquSv9BWfnLKSHfwRW+Y
eAHlkELui0Zi6zD2PYqcBAebZWNmyxiJUz0oHJPJH6DoXXxI6OsCdFDkqW5hP/If
VI97fbKMGY9g4RyasJmb/18F7eSFC1S7fj6hHCRnHTKR5cO3PdzYndyICGfaQMUa
+n0HsWZAw8mgWPnKZd3xXt4n+Exx/LBV3ZkOwHT7L9nTPALsoqqEtn0zjOo/eOt9
fmaW9TcvL1V1oiRpEk3lejvF/Wt5zwkPOgys2ZCZTtefx/lGoxC2lokB1AQTAQoA
PhYhBBUFhQCgI12X9dEAY7GI4raVvUdDBQJcj2+fAhsDBQkDwmcABQsJCAcCBhUK
CQgLAgQWAgMBAh4BAheAAAoJELGI4raVvUdDROUMANJjLVGk6TLYZKyRc8HZXyMR
mw55nCQXsy8DHen6H7MuZHaxV9sf1tF1cQwPnv3HFlg7nZBFszyReW7s3LOcPuNX
S90Sk5o7WLqVMkE+t46iNNGuIt3sWxPdYqY4ueCnUHHEFDKhlwlJnNh3+yVNci4n
V+6SlzoasEjy5P82+pviop3viSlA8lgXdOYERRqZ1wh0Vip5gxcNIdm+TqWINI9+
7T/87GfZzn84Zlvd0GnfjT6aN6RCsIADIOqJUW/TPzvdytwlXc4oZvqk47P0YePS
6VFd+TrIRHHsmYxwTVPNjCT7eMz6AfEtPbTZHWI9oOWU+tiAogeR7k1yORknf+Hc
LZ1RnfDxyWgEh/p9eWuBeNCgLVSFtcYCJvRU8OFwRPz8B7rs3/tjrabrkV8iVaEe
iCZkqPpaqZF6QaY2Z2YmZEtFsUMbrVwLbfBj4+3CSY90rlRolZDJjea6oXtjWXPX
ANSJ5gJZI9qFbX0WBXJ7dilrfUfHwrsqkH97072OTbkBjQRcj2+fAQwA4McaM/y2
XQSHlJBSYR7yqZtHX/kZ8g9pnViqkCEADz8XKCroEzvY1gaWtR6obtjaq8pF0g4K
tAC65/gIOtsHvWg3OclrODPkXN+xOM1LpXZGV6kwk+LXOrybtPhVZe3FtvDMW0MV
ZeHYi+soZ4tTQHkKjZUPAXZs3ZoZrWfE5ft447sCxzX+jxDwwlckkKqZ9sHYD0TV
8Y5av3RsxiWBt+coch8jvw+1mDZ0zBjMO8ZRD8PuvP9UTKCNOIm0mW9A2cUfpkk/
uAwo5hCnw4iljS81/KKGM/scwc5Kx6G3WWoAb8kajt0VFG/wYN2qjfjdhXtdu3Zx
YtDdjA2UGGRbgkCsr+gRCnSTiuwvLzCVZCz9WNzZjUMg6LFP2IrHned4Kdy4KjJo
+g/weKJoxfKokZ/9vUYpw5OYx3UESUk3yHDN9r/JC4RJJ2tE2qkeggJ892RJGxUK
/Lw3/7jIQKalO3Qx2zYUqnCYMC9gPhQGH+F9kwSpGVwb0DKFT6gR9Pt3ABEBAAGJ
AbwEGAEKACYCGwwWIQQVBYUAoCNdl/XRAGOxiOK2lb1HQwUCaRsh0QUJELYeEgAK
CRCxiOK2lb1HQzI9DACglPZQupH5EldfbCpvre6pERnLMnDQjJnK2yXmF/Ji07Hp
nMn55ep/rD2Gvr7D6lMbYDv0sJ7BqS4K/nbcHykV41AUpZyushjAP3Cpk11c4RVj
qdAE1S+gdxNwoKddQ9J9JEvVSp+Pj83HiXVF76vQ9tl7mDrHOpqac0O++BTcOhwb
d3IscP1lK30np6ismgVM4xeuZ0VlvupjRb4UGQllt7BrQRbCrFUvBH8zca/lQDHM
R//o9+43bMKM81FdehmwlI9HFtIvdKaryRnLW91R+ehX+cb1zbrj7u+wO1MQha2q
t3RuoqqGQSswRMkF0mdFkhuasHJ0yTufCugGLVtVuD54yg8FRU9vYheSDyrPF5i7
8aLD3w7/3WwDpfBXIceMFj5S4USz04wErHRMDM2D4BTOM0gdGUPY4SrPAO5i3eZ3
RNe3Yt4oxcc3wh5v0IQwjmJ++ikBRrk0UHzh81fgIIkVQG8gxC7eriSplRZH0fOM
dIWqjhhcyJCHsdyUkP0=
=uUxL
-----END PGP PUBLIC KEY BLOCK-----
KEY
    cat >/etc/apt/sources.list.d/sury-php.sources <<'SOURCES'
# Written by RAYXO setup.sh on every run: PHP for the panel and the websites.
Types: deb
URIs: https://packages.sury.org/php/
Suites: noble
Components: main
Signed-By: /etc/apt/keyrings/sury-php.asc
SOURCES
}

# The ondrej/php PPA, written in the file add-apt-repository would write.
# add-apt-repository itself first asks Launchpad's API about the PPA, which
# drops connections or is down more often than the PPA. Signed by
# "Launchpad PPA for Ondřej Surý" (B8DC 7E53 9466 56EF BCE4 C1DD 71DA EAAB
# 4AD4 CAB6), a key that does not expire.
write_ppa_php_source() {
    install_apt_key /etc/apt/keyrings/ondrej-php.asc B8DC7E53946656EFBCE4C1DD71DAEAAB4AD4CAB6 <<'KEY'
-----BEGIN PGP PUBLIC KEY BLOCK-----

mQINBGYo0vEBEAC0Semxy5I2b8exRUxJfTKkHR4f5uyS0dTd9vYgMI5T3gsa7ypH
HtE+GiZC+T9m/F9h66+XJMxhuNsKRs7T2In5NSeso9H/ytlSTayUaBtCFfRp6y6b
6ozuRBfqYJGxhjAnIzvNF/Wpp2BvfQm3OrQ7uJJrt5IvzLDC4jPxl/Xs3sTT+Hbk
bkKKprZ3xmy2enuwBaNWR/CUtAz3hbkzL1kGbhX9m3QidFJagVVdDw3aNEwo8ush
djWfF+BajNvpDFYJKBGQbCeagB753Baa5yIN62x+THLnLiKTMDS1e7U0ZDiV9671
noTbtN5TeZeyfsEmeZ8X60x11JIP3yYHYZT70/DyTYX3WC9yQFyIgVOfRlGklMKI
k3TLMmtq8w5Hz1vovwzV7PzaQnmY+uNP2ZbAP4fJ3iFAj0L+u0i1nOFgTy0Lq058
O/FjRrQxuceDDCF+9ThspXMw3Puvz8giuBDCdEda84uC7XWMdqgz/maLfFQjAmyP
Ixi1EMxMlHYyZajpR1cdCfrAIQlnQjHSWmyeCFgXPPfRA71aCcJ7oSrDjogW6Ahd
HRkQRKf1FF9BFzycgSQotfR+7CKfPQh1kghufM9W/spARzA709nGZjXJzgEJLQd3
CDB6dIIxT/0YI36h3Qgfmiiw4twO24MMEqEEPIELz2WJKeWGkdQdcekpxQARAQAB
tB9MYXVuY2hwYWQgUFBBIGZvciBPbmTFmWVqIFN1csO9iQJOBBMBCgA4FiEEuNx+
U5RmVu+85MHdcdrqq0rUyrYFAmYo0vECGwMFCwkIBwIGFQoJCAsCBBYCAwECHgEC
F4AACgkQcdrqq0rUyrYOPQ/+IArA4s1J3op/w7cXek0ieFHWHFDrxPYS+78/LF/J
LoYZw0nIU5Ovr+LzehFMIQU6esgPXwbeCVgwLwat57augAkAYWT0UzH5dE6RKAGr
C2vsHWVfPhQn6UndfzwXc0mTLGQni25aQaZ6k60Dbm/vblejrTQrtAUWoMO3Z1cr
NDGJ3Z9DCxtr2o9gRYUI6HwLHJtobTIeI5xsr5x+GvXiIAVCPa3ZEuRL6jMQfqfS
C43mpuiS1kGgsnQLs2DbN7EFCfiJoNX1QzZu25zg+IS9PXbCJnheZWnH0rwUSb/N
hZPcSefGlNlhr824OfT30v79hQnw59XbsfV270O9jPbD4kttN+OiszbU66zsuiOh
BO46XCckQPqDkBMw56GPFuVrQgGb1thXvn67URJgPyJhwauBWKPNAJ9Ojuo+yVq/
hdR1VNWThXQbZgaGSWrbjt6FdYtQb9VX88uu5gFDmr180HogHNUDUcqNLLdnjfFs
4DyJlusQ5I/a7cQ7nlkNgxAmHszwO/mGLBuGljDUYkwZDW9nqP1Q5Q2jMtrhgXvR
2SOtufvecUbB7+eoRSaOnu7CNMATG6LocFEMzhKUde1uZTfWSqnYEcdqoFJMi46y
qaNxhiNLsQ5OBMbgSp2zCbQxRBdITMVvBR5YjCetUIGEs6T1yQ5wh5Xpoi34ShHn
v38=
=kFlZ
-----END PGP PUBLIC KEY BLOCK-----
KEY
    cat >/etc/apt/sources.list.d/ondrej-ubuntu-php-noble.sources <<'SOURCES'
# Written by RAYXO setup.sh: PHP for the panel and the websites (ppa:ondrej/php).
Types: deb
URIs: https://ppa.launchpadcontent.net/ondrej/php/ubuntu/
Suites: noble
Components: main
Signed-By: /etc/apt/keyrings/ondrej-php.asc
SOURCES
}

# Set up the PHP repository. Succeeds when it added one, so apt needs an
# update; fails when the server already had its repository.
add_php_repository() {
    if [[ -f /etc/apt/sources.list.d/sury-php.sources ]]; then
        write_sury_php_source
        return 1
    fi

    if grep -rqs "ondrej/php" /etc/apt/sources.list.d/; then
        return 1
    fi

    if curl -fsS --max-time 20 -o /dev/null https://packages.sury.org/php/dists/noble/InRelease 2>>"$INSTALL_LOG"; then
        info "Menambahkan repo PHP packages.sury.org"
        write_sury_php_source
    elif curl -fsS --max-time 20 -o /dev/null https://ppa.launchpadcontent.net/ondrej/php/ubuntu/dists/noble/InRelease 2>>"$INSTALL_LOG"; then
        warn "packages.sury.org tidak bisa dihubungi; PHP diambil dari cadangannya, ppa:ondrej/php di Launchpad"
        write_ppa_php_source
    else
        die "repo PHP tidak bisa dihubungi, baik packages.sury.org maupun cadangannya ppa.launchpadcontent.net (Launchpad). Cek koneksi server ke keduanya, lalu jalankan setup.sh lagi"
    fi
}

install_packages() {
    step "Memasang paket sistem (beberapa menit; detail di $INSTALL_LOG)"

    export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a
    local apt=(apt-get -y -q -o DPkg::Lock::Timeout=300)

    apt_update "${apt[@]}"
    quietly "${apt[@]}" install ca-certificates curl gnupg openssl tar

    if add_php_repository; then
        apt_update "${apt[@]}"
    fi

    # When the PHP repository cannot be reached, apt update merely warns, and
    # the install would fail later on a PHP package it cannot find; say what
    # is wrong instead. The output is read whole: grep -q in a pipe stops
    # reading at the match, apt-cache then dies of SIGPIPE, and pipefail
    # turns that into a failure.
    local php_policy
    php_policy="$(apt-cache policy "php$PHP_VERSION-fpm" 2>/dev/null || true)"
    if ! grep -q 'Candidate: [0-9]' <<<"$php_policy"; then
        local php_repository=ppa.launchpadcontent.net
        [[ -f /etc/apt/sources.list.d/sury-php.sources ]] && php_repository=packages.sury.org
        die "paket PHP $PHP_VERSION tidak tersedia: repo PHP $php_repository tidak bisa dihubungi. Cek dengan: curl -I https://$php_repository, lalu jalankan setup.sh lagi"
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
    # stays stopped until configure_ftp has set it up. PowerDNS would start
    # on every address of port 53, where systemd-resolved may already listen
    # on 127.0.0.53; it stays stopped until configure_dns has set it up.
    block_service_starts
    # apache2-suexec-custom: CGI scripts of websites run as their account,
    # with the document root set to /home (configure_web).
    quietly "${apt[@]}" install apache2 apache2-suexec-custom pure-ftpd-mysql
    # Without the bind backend PowerDNS recommends: its settings stop
    # PowerDNS from starting when only the MySQL backend is launched.
    quietly "${apt[@]}" install --no-install-recommends pdns-server pdns-backend-mysql
    # Dovecot would start with Debian's defaults (system users signing in to
    # IMAP); it stays stopped until configure_mailboxes has set it up.
    quietly "${apt[@]}" install dovecot-core dovecot-imapd dovecot-pop3d dovecot-lmtpd dovecot-mysql dovecot-sieve
    remove_policy_rc

    quietly "${apt[@]}" install postfix-mysql redis-server rspamd

    # GoAccess makes the visitor reports of websites (the agent's stats.report).
    quietly "${apt[@]}" install --no-install-recommends goaccess

    # cron runs the cron jobs of hosting accounts (the agent writes their crontabs).
    quietly systemctl enable --now mariadb nginx cron "php$PHP_VERSION-fpm"
    info "Nginx, Apache, MariaDB, Postfix, Dovecot, Rspamd, Pure-FTPd, PowerDNS dan PHP $PHP_VERSION terpasang"
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
                die "PHP $v tidak tersedia di repo PHP (packages.sury.org atau ppa:ondrej/php)"
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
    guard_downgrade "$version"

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

# Where webmail is, and the key the panel seals its sign-on tickets with
# (configure_webmail gives Roundcube the same key).
add_webmail_env() {
    add_env_value WEBMAIL_URL "https://$server_hostname/webmail/"

    if [[ -z "$(env_value WEBMAIL_SSO_KEY)" ]]; then
        local key
        key="$(openssl rand -base64 32)"
        add_env_value WEBMAIL_SSO_KEY "$key"
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
        # Installs from before webmail.
        add_webmail_env
        # Installs from before the file manager.
        add_env_value RAYXO_TRANSFER_DIR "$DATA_DIR/transfer"
        # The panel's own addresses follow a new hostname or port.
        local old_host="${previous_hostname:-$server_hostname}"
        follow_env_value APP_URL "https://$old_host:$previous_panel_port" "https://$server_hostname:$PANEL_PORT"
        if [[ "$old_host" != "$server_hostname" ]]; then
            follow_env_value PHPMYADMIN_URL "https://$old_host/phpmyadmin/" "https://$server_hostname/phpmyadmin/"
            follow_env_value WEBMAIL_URL "https://$old_host/webmail/" "https://$server_hostname/webmail/"
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
    add_webmail_env
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
Socket                  local:/var/spool/postfix/opendkim/opendkim.sock
PidFile                 /run/opendkim/opendkim.pid
# Only mail that signed in (ORIGINATING) or came through the local
# sendmail, whose senders Postfix limits per user (LOCAL), is signed; not
# whatever reaches 127.0.0.1:25, which hosting accounts can open too.
InternalHosts           192.0.2.1
MacroList               daemon_name=ORIGINATING|LOCAL
CONF

    # The socket lives in Postfix's spool (its processes run chrooted there)
    # and only the postfix group may open it: no other local user can have
    # mail signed for this server.
    install -d -m 0750 -o opendkim -g opendkim /var/spool/postfix/opendkim
    usermod -aG opendkim postfix

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
        "smtpd_milters = local:opendkim/opendkim.sock" \
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

    # The panel signs in to Postfix's submission port as rayxo@<hostname>, a
    # login Dovecot only accepts from this server (configure_mailboxes), so
    # mail that reaches 127.0.0.1:25 without signing in is never signed for
    # this server. Only the local Postfix setup setup.sh wrote is moved over;
    # the password is written by the shell itself, never on a command line.
    if [[ "$(env_value MAIL_MAILER)" == smtp && "$(env_value MAIL_HOST)" == 127.0.0.1 ]] &&
        [[ "$(env_value MAIL_PORT)" == 25 || "$(env_value MAIL_USERNAME)" == rayxo@* ]]; then
        set_env_value MAIL_PORT 587
        set_env_value MAIL_USERNAME "rayxo@$server_hostname"
        set_env_value MAIL_VERIFY_PEER false
        if [[ -z "$(env_value MAIL_PASSWORD)" ]]; then
            local mail_password
            mail_password="$(openssl rand -hex 24)"
            sed -i '/^MAIL_PASSWORD=/d' "$ENV_FILE"
            printf 'MAIL_PASSWORD=%s\n' "$mail_password" >>"$ENV_FILE"
        fi
        info "Panel mengirim email lewat port 587 dengan login rayxo@$server_hostname"
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

    # The panel generates the password (12 letters and digits, no symbols)
    # and prints it alone on the first non-empty line after the label; it
    # never appears on a command line or in the environment.
    local output
    output="$(
        unset RAYXO_ADMIN_PASSWORD
        artisan rayxo:create-admin "$admin_user" "$admin_email" --no-ansi 2>&1
    )" || {
        printf '%s\n' "$output" >&2
        die "admin pertama gagal dibuat"
    }

    admin_password="$(printf '%s\n' "$output" |
        awk 'found && NF {print; exit} /Generated password \(shown only once\):/ {found = 1}' | tr -d '[:space:]')"
    [[ -n "$admin_password" ]] || die "password admin pertama tidak terbaca; buat yang baru dengan: rayxo reset-password $admin_user"

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
# certificate and those of customer websites, and Dovecot and Postfix,
# which serve the hostname certificate to mail apps. mail.<domain> of the
# accounts' domains is served from copies of the certificates, which the
# agent makes again (mail.sni_refresh). A renewed hostname certificate
# also goes to Pure-FTPd, which reads key and certificate from one file,
# and only at start.
systemctl reload nginx
systemctl reload dovecot postfix || true
$INSTALL_DIR/bin/rayxo call mail.sni_refresh >/dev/null 2>&1 || true

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
    render "$release_dir/config/php-fpm-webmail.conf" "$pool_dir/rayxo-webmail.conf"

    if [[ -f "$pool_dir/www.conf" ]]; then
        mv "$pool_dir/www.conf" "$pool_dir/www.conf.disabled"
        info "Pool bawaan \"www\" dimatikan"
    fi

    quietly "php-fpm$PHP_VERSION" -t
    systemctl restart "php$PHP_VERSION-fpm"
    info "Pool \"rayxo\", \"rayxo-phpmyadmin\" dan \"rayxo-webmail\" aktif (fungsi exec dimatikan)"
}

configure_nginx() {
    step "Mengatur Nginx untuk panel (port $PANEL_PORT)"

    local site=/etc/nginx/sites-available/rayxo.conf
    # The panel itself, also included by the sites of resellers' sign-in
    # domains that rayxod writes.
    mkdir -p /etc/nginx/snippets
    render "$release_dir/config/nginx-panel-app.conf" /etc/nginx/snippets/rayxo-panel.conf
    # The administrator's additions for every website, edited in the panel
    # (File Konfigurasi) and never overwritten here.
    mkdir -p /etc/nginx/rayxo-custom /etc/apache2/rayxo-custom
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
# set in the panel. sshd gives the members of rayxo-sftp SFTP only, and those
# of rayxo-ssh a shell in their jail; nothing changes for root or other users
# of the server. If sshd refuses the configuration, it is removed again, so
# SSH always keeps working.
configure_sftp() {
    step "Mengatur SFTP untuk akun hosting"

    local group
    for group in "$SFTP_GROUP" "$SSH_GROUP"; do
        if ! getent group "$group" >/dev/null; then
            quietly groupadd --system "$group"
        fi
    done

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
    info "SFTP aktif di port 22 untuk akun hosting; SSH (shell dalam jail) untuk paket yang mengizinkannya; password diatur customer di halaman FTP"
}

# Customer websites: Nginx in front (ports 80/443, static files), Apache on
# 127.0.0.1:8080 behind it so .htaccess works, PHP in a pool per account.
# The agent writes one Nginx and one Apache file per domain into the
# directories created here.
# The addresses PowerDNS answers on: 127.0.0.1 and every global address of
# this server, one per line. Never the wildcard: systemd-resolved listens on
# 127.0.0.53:53 on many servers, and would keep PowerDNS from starting.
dns_listen_addresses() {
    printf '127.0.0.1\n'
    ip -o addr show scope global | awk '{split($4, a, "/"); print a[1]}'
}

# DNS for the zones of hosting accounts. PowerDNS Authoritative serves them
# from its own database, pdns, which only PowerDNS's system user can open
# (MariaDB's unix_socket login, no password). The panel's database stays the
# source of truth: the agent writes each zone through PowerDNS's HTTP API on
# 127.0.0.1 with a key only root can read, and the panel syncs every zone
# again after this step (sync_dns_zones).
configure_dns() {
    step "Mengatur DNS (PowerDNS)"

    [[ -x /usr/sbin/pdns_server ]] || die "PowerDNS tidak terpasang (paket pdns-server)"

    # The bind backend came along with an early build of this step; its
    # settings (bind.conf) stop PowerDNS from starting without it.
    if dpkg-query -W -f='${Status}' pdns-backend-bind 2>/dev/null | grep -q "install ok installed"; then
        quietly apt-get -y -q -o DPkg::Lock::Timeout=300 purge pdns-backend-bind
    fi

    mariadb <<SQL
CREATE DATABASE IF NOT EXISTS \`pdns\` CHARACTER SET utf8mb4;
CREATE USER IF NOT EXISTS 'pdns'@'localhost' IDENTIFIED VIA unix_socket;
GRANT SELECT, INSERT, UPDATE, DELETE ON \`pdns\`.* TO 'pdns'@'localhost';
SQL

    # PowerDNS's own schema, on a new database only.
    if [[ -z "$(mariadb -N -e "SELECT 1 FROM information_schema.tables WHERE table_schema = 'pdns' AND table_name = 'domains'")" ]]; then
        mariadb pdns </usr/share/pdns-backend-mysql/schema/schema.mysql.sql
        info "Database DNS (pdns) dibuat"
    fi

    # The API key is made once and kept, so the agent never loses it.
    if [[ ! -s "$DNS_API_KEY_FILE" ]]; then
        (
            umask 077
            openssl rand -hex 32 >"$DNS_API_KEY_FILE"
        )
    fi
    chmod 0600 "$DNS_API_KEY_FILE"

    local addresses
    addresses="$(dns_listen_addresses | paste -sd, -)"

    (
        umask 027
        cat >"$PDNS_CONF.new" <<CONF
# Written by RAYXO setup.sh on every run; changes made here are lost.
launch=gmysql
gmysql-socket=/run/mysqld/mysqld.sock
gmysql-user=pdns
gmysql-password=
gmysql-dbname=pdns
local-address=$addresses
local-port=53
api=yes
api-key=$(cat "$DNS_API_KEY_FILE")
webserver=yes
webserver-address=127.0.0.1
webserver-port=$DNS_API_PORT
webserver-allow-from=127.0.0.1/32
default-ttl=3600
version-string=anonymous
CONF
    )
    chown root:pdns "$PDNS_CONF.new"
    mv -f "$PDNS_CONF.new" "$PDNS_CONF"

    quietly systemctl enable pdns
    if ! systemctl restart pdns; then
        die "PowerDNS tidak bisa berjalan; mungkin port 53 dipakai program lain (cek: journalctl -u pdns, ss -lnup 'sport = :53')"
    fi

    local attempt
    for attempt in $(seq 1 20); do
        if curl -sf -o /dev/null -H "X-API-Key: $(cat "$DNS_API_KEY_FILE")" "http://127.0.0.1:$DNS_API_PORT/api/v1/servers/localhost"; then
            info "DNS aktif di port 53 (${addresses//,/, }); API hanya di 127.0.0.1:$DNS_API_PORT"
            return
        fi
        sleep 0.5
    done

    die "API PowerDNS tidak menjawab (cek: journalctl -u pdns)"
}

# Email for the domains of hosting accounts. Postfix takes mail for the
# domains in v_mail_domains (port 25, opportunistic TLS) and hands it to
# Dovecot over LMTP, which keeps it in /home/<account>/imap/<domain>/<name>
# as the account's Linux user (so it counts towards its disk quota).
# Mailboxes send through submission (587, STARTTLS) and smtps (465), always
# signed in and only as themselves. Dovecot serves IMAP and POP3 over TLS
# only, with the hostname certificate, and checks the passwords in
# v_mail_mailboxes. Rspamd scans mail and signs it with each domain's DKIM
# key; OpenDKIM keeps signing the server's own mail. Suspended accounts
# drop out of the views, so their mail stops at once.
#
# Mail this server sends on from other domains, such as forwarded mail, goes
# out with an SRS envelope sender of the hostname (SRS0=...@<hostname>), so
# it passes SPF where it goes; bounces to those addresses come back here and
# go on to the original sender. rayxod srs makes and checks the addresses
# with a secret key, as its own unprivileged service. The key stays across
# upgrades, so addresses already sent keep working.
configure_srs() {
    if ! id -u "$SRS_USER" >/dev/null 2>&1; then
        useradd --system --gid postfix --no-create-home --home-dir /nonexistent \
            --shell /usr/sbin/nologin "$SRS_USER"
        info "User sistem $SRS_USER (SRS) dibuat"
    fi

    if [[ ! -s "$CONFIG_DIR/srs.secret" ]]; then
        (
            umask 077
            openssl rand -base64 32 >"$CONFIG_DIR/srs.secret"
        )
    fi
    chown root:root "$CONFIG_DIR/srs.secret"
    chmod 0600 "$CONFIG_DIR/srs.secret"

    install -d -m 0750 -o "$SRS_USER" -g postfix "/var/spool/postfix/${SRS_SOCKET%/*}"

    cat >/etc/systemd/system/rayxo-srs.service.new <<UNIT
# Written by RAYXO setup.sh on every run; changes made here are lost.
[Unit]
Description=RAYXO SRS for forwarded mail (rayxod srs)
After=network.target
Before=postfix.service

[Service]
Type=simple
User=$SRS_USER
Group=postfix
LoadCredential=srs:$CONFIG_DIR/srs.secret
ExecStart=$INSTALL_DIR/bin/rayxod srs --socket /var/spool/postfix/$SRS_SOCKET --domain $server_hostname --secret-file %d/srs
Restart=always
RestartSec=1
NoNewPrivileges=yes
CapabilityBoundingSet=
ProtectSystem=strict
ReadWritePaths=/var/spool/postfix/${SRS_SOCKET%/*}
ProtectHome=yes
PrivateTmp=yes
PrivateDevices=yes
PrivateNetwork=yes
RestrictAddressFamilies=AF_UNIX
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectKernelLogs=yes
ProtectControlGroups=yes
ProtectClock=yes
ProtectHostname=yes
RestrictNamespaces=yes
RestrictRealtime=yes
RestrictSUIDSGID=yes
LockPersonality=yes
MemoryDenyWriteExecute=yes
SystemCallArchitectures=native
SystemCallFilter=@system-service
SystemCallFilter=~@privileged
SystemCallErrorNumber=EPERM

[Install]
WantedBy=multi-user.target
UNIT
    mv -f /etc/systemd/system/rayxo-srs.service.new /etc/systemd/system/rayxo-srs.service
    systemctl daemon-reload
    quietly systemctl enable rayxo-srs.service
    systemctl restart rayxo-srs.service

    local attempt
    for attempt in $(seq 1 20); do
        [[ -S "/var/spool/postfix/$SRS_SOCKET" ]] && break
        sleep 0.5
    done
    [[ -S "/var/spool/postfix/$SRS_SOCKET" ]] || die "SRS (rayxo-srs) tidak berjalan (cek: journalctl -u rayxo-srs)"
}

configure_mailboxes() {
    step "Mengatur email akun hosting (Postfix, Dovecot, Rspamd)"

    # A new password on every run; it only lives in Postfix's and Dovecot's
    # config. The views come from the panel's migrations (prepare_panel).
    local password
    password="$(openssl rand -hex 24)"

    mariadb <<SQL
CREATE USER IF NOT EXISTS '$MAIL_DB_USER'@'localhost' IDENTIFIED BY '$password';
ALTER USER '$MAIL_DB_USER'@'localhost' IDENTIFIED BY '$password';
GRANT SELECT ON \`rayxo\`.\`v_mail_domains\` TO '$MAIL_DB_USER'@'localhost';
GRANT SELECT ON \`rayxo\`.\`v_mail_mailboxes\` TO '$MAIL_DB_USER'@'localhost';
GRANT SELECT ON \`rayxo\`.\`v_mail_webmail_sessions\` TO '$MAIL_DB_USER'@'localhost';
GRANT SELECT ON \`rayxo\`.\`v_mail_forwarders\` TO '$MAIL_DB_USER'@'localhost';
GRANT SELECT ON \`rayxo\`.\`v_mail_catchalls\` TO '$MAIL_DB_USER'@'localhost';
GRANT SELECT ON \`rayxo\`.\`v_mail_local_senders\` TO '$MAIL_DB_USER'@'localhost';
GRANT SELECT ON \`rayxo\`.\`v_mail_hosted_domains\` TO '$MAIL_DB_USER'@'localhost';
SQL

    # Postfix's lookups, through proxymap (smtpd runs chrooted). Aliases
    # are looked up as forwarders, then mailboxes (an address that maps to
    # itself), then catch-alls: Postfix tries a full address in all of them
    # before "@<domain>", so a catch-all never takes an existing address.
    install -d -m 0750 -o root -g postfix "$POSTFIX_MAPS_DIR"
    local map query
    while read -r map query; do
        (
            umask 027
            cat >"$POSTFIX_MAPS_DIR/$map.cf.new" <<MAP
# Written by RAYXO setup.sh on every run; changes made here are lost.
hosts = unix:/run/mysqld/mysqld.sock
user = $MAIL_DB_USER
password = $password
dbname = rayxo
query = $query
MAP
        )
        chgrp postfix "$POSTFIX_MAPS_DIR/$map.cf.new"
        mv -f "$POSTFIX_MAPS_DIR/$map.cf.new" "$POSTFIX_MAPS_DIR/$map.cf"
    done <<'MAPS'
mail-domains SELECT 1 FROM v_mail_domains WHERE name = '%s'
mail-mailboxes SELECT 1 FROM v_mail_mailboxes WHERE address = '%s'
mail-senders SELECT address FROM v_mail_mailboxes WHERE address = '%s'
mail-forwarders SELECT destination FROM v_mail_forwarders WHERE source = '%s'
mail-addresses SELECT address FROM v_mail_mailboxes WHERE address = '%s'
mail-catchalls SELECT destination FROM v_mail_catchalls WHERE source = '%s'
local-senders SELECT senders FROM v_mail_local_senders WHERE login = '%s'
srs-local SELECT '%s' FROM v_mail_hosted_domains WHERE name = '%d'
MAPS

    # The local sendmail checks a user's envelope sender itself (postdrop,
    # setgid postdrop), without Postfix's proxymap: it gets its own copy of
    # the lookup, readable by the postdrop group only. Other system users may
    # send as themselves only; root and postfix as anyone.
    install -d -m 0750 -o root -g postdrop /etc/postfix/rayxo-postdrop
    install -m 0640 -o root -g postdrop "$POSTFIX_MAPS_DIR/local-senders.cf" /etc/postfix/rayxo-postdrop/local-senders.cf
    rm -f "$POSTFIX_MAPS_DIR/local-senders.cf"
    printf '# Written by RAYXO setup.sh on every run.\n/^(.+)$/ $1\n' >/etc/postfix/rayxo-postdrop/login-senders.regexp
    chown root:postdrop /etc/postfix/rayxo-postdrop/login-senders.regexp
    chmod 0640 /etc/postfix/rayxo-postdrop/login-senders.regexp

    # Running before Postfix asks it (below).
    configure_srs

    # SNI for mail.<domain>: rayxod (mail.sni_apply) keeps copies of the
    # certificates in $CONFIG_DIR/ssl/mail and builds this table from them
    # with postmap -F, which puts the keys into the table, so it is root's
    # only (Postfix opens it before dropping privileges). Started empty.
    install -d -m 0700 -o root -g root "$CONFIG_DIR/ssl/mail"
    if [[ ! -f "$POSTFIX_MAPS_DIR/sni" ]]; then
        printf '# Written by rayxod (mail.sni_apply); changes are overwritten.\n' >"$POSTFIX_MAPS_DIR/sni"
    fi
    chmod 0600 "$POSTFIX_MAPS_DIR/sni"
    if [[ ! -f "$POSTFIX_MAPS_DIR/sni.db" ]]; then
        (
            umask 077
            postmap -F "hash:$POSTFIX_MAPS_DIR/sni"
        )
    fi
    chmod 0600 "$POSTFIX_MAPS_DIR/sni.db"

    # SRS: an envelope sender of a domain hosted here stays (srs-local maps
    # it to itself); any other gets an SRS address. Envelope recipients that
    # are SRS addresses of this server are turned back. Headers stay as
    # they are, so the original sender's DKIM signature still holds.
    quietly postconf -e \
        "inet_interfaces = all" \
        "sender_canonical_maps = proxy:mysql:$POSTFIX_MAPS_DIR/srs-local.cf, socketmap:unix:$SRS_SOCKET:forward" \
        "sender_canonical_classes = envelope_sender" \
        "recipient_canonical_maps = socketmap:unix:$SRS_SOCKET:reverse" \
        "recipient_canonical_classes = envelope_recipient" \
        "tls_server_sni_maps = hash:$POSTFIX_MAPS_DIR/sni" \
        "virtual_mailbox_domains = proxy:mysql:$POSTFIX_MAPS_DIR/mail-domains.cf" \
        "virtual_mailbox_maps = proxy:mysql:$POSTFIX_MAPS_DIR/mail-mailboxes.cf" \
        "virtual_alias_maps = proxy:mysql:$POSTFIX_MAPS_DIR/mail-forwarders.cf, proxy:mysql:$POSTFIX_MAPS_DIR/mail-addresses.cf, proxy:mysql:$POSTFIX_MAPS_DIR/mail-catchalls.cf" \
        "virtual_alias_domains =" \
        "virtual_transport = lmtp:unix:private/dovecot-lmtp" \
        "smtpd_sender_login_maps = inline:{ { rayxo@$server_hostname = rayxo@$server_hostname } }, proxy:mysql:$POSTFIX_MAPS_DIR/mail-senders.cf" \
        "local_login_sender_maps = inline:{ { root = * }, { postfix = * } }, mysql:/etc/postfix/rayxo-postdrop/local-senders.cf, regexp:/etc/postfix/rayxo-postdrop/login-senders.regexp" \
        "smtpd_sasl_type = dovecot" \
        "smtpd_sasl_path = private/auth" \
        "smtpd_sasl_auth_enable = no" \
        "smtpd_tls_cert_file = $CONFIG_DIR/ssl/panel.crt" \
        "smtpd_tls_key_file = $CONFIG_DIR/ssl/panel.key" \
        "smtpd_tls_security_level = may" \
        "smtpd_tls_mandatory_protocols = >=TLSv1.2" \
        "smtpd_tls_protocols = >=TLSv1.2" \
        "smtpd_helo_required = yes" \
        "smtpd_relay_restrictions = permit_sasl_authenticated, reject_unauth_destination" \
        "local_header_rewrite_clients = permit_inet_interfaces, permit_sasl_authenticated" \
        "smtpd_milters = unix:rspamd/milter.sock, local:opendkim/opendkim.sock" \
        "non_smtpd_milters = \$smtpd_milters" \
        "milter_protocol = 6" \
        "milter_default_action = accept" \
        "message_size_limit = 52428800" \
        "mailbox_size_limit = 0"

    # Mailboxes send on 587 (STARTTLS) and 465 (TLS), signed in, as
    # themselves only. Signed-in senders count as local for header
    # rewriting, so a message without Message-ID or Date gets them.
    local service
    for service in submission smtps; do
        quietly postconf -M "$service/inet=$service inet n - y - - smtpd"
        quietly postconf -P \
            "$service/inet/syslog_name=postfix/$service" \
            "$service/inet/smtpd_sasl_auth_enable=yes" \
            "$service/inet/smtpd_tls_auth_only=yes" \
            "$service/inet/smtpd_client_restrictions=permit_sasl_authenticated,reject" \
            "$service/inet/smtpd_sender_restrictions=reject_sender_login_mismatch" \
            "$service/inet/smtpd_relay_restrictions=permit_sasl_authenticated,reject" \
            "$service/inet/milter_macro_daemon_name=ORIGINATING"
    done
    quietly postconf -P "submission/inet/smtpd_tls_security_level=encrypt" "smtps/inet/smtpd_tls_wrappermode=yes"

    # Tell the milters where mail came in: port 25 (INBOUND, never signed,
    # whether from the internet or a local process that did not sign in) or
    # the local sendmail (LOCAL, senders limited per user above).
    quietly postconf -P "smtp/inet/milter_macro_daemon_name=INBOUND" "cleanup/unix/milter_macro_daemon_name=LOCAL"

    (
        umask 027
        cat >/etc/dovecot/rayxo-sql.conf.ext.new <<SQLCONF
# Written by RAYXO setup.sh on every run; changes made here are lost.
driver = mysql
connect = host=/run/mysqld/mysqld.sock dbname=rayxo user=$MAIL_DB_USER password=$password
default_pass_scheme = SHA512-CRYPT
password_query = SELECT address AS user, password FROM v_mail_mailboxes WHERE address = '%u' AND can_login = 1
user_query = SELECT home, uid, gid, quota_rule, sieve_before FROM v_mail_mailboxes WHERE address = '%u'
iterate_query = SELECT address AS user FROM v_mail_mailboxes
SQLCONF
    )
    chgrp dovecot /etc/dovecot/rayxo-sql.conf.ext.new
    mv -f /etc/dovecot/rayxo-sql.conf.ext.new /etc/dovecot/rayxo-sql.conf.ext

    # Webmail sessions the panel opened: Roundcube signs in with the
    # session's token as the password. Only from this server, where
    # Roundcube runs; %w is the password as typed, compared by its SHA-256.
    (
        umask 027
        cat >/etc/dovecot/rayxo-webmail-sql.conf.ext.new <<SQLCONF
# Written by RAYXO setup.sh on every run; changes made here are lost.
driver = mysql
connect = host=/run/mysqld/mysqld.sock dbname=rayxo user=$MAIL_DB_USER password=$password
password_query = SELECT address AS user, NULL AS password, 'Y' AS nopassword, '127.0.0.1,::1' AS allow_nets FROM v_mail_webmail_sessions WHERE address = '%u' AND token_hash = SHA2('%w', 256) LIMIT 1
SQLCONF
    )
    chgrp dovecot /etc/dovecot/rayxo-webmail-sql.conf.ext.new
    mv -f /etc/dovecot/rayxo-webmail-sql.conf.ext.new /etc/dovecot/rayxo-webmail-sql.conf.ext

    # The panel's own login for sending (configure_mail), from this server
    # only. The password stays in the panel's .env; Dovecot gets its hash,
    # made through stdin so it never shows up on a command line.
    local panel_login panel_password panel_hash=""
    panel_login="$(env_value MAIL_USERNAME)"
    panel_password="$(env_value MAIL_PASSWORD)"
    if [[ "$panel_login" == "rayxo@$server_hostname" && -n "$panel_password" ]]; then
        panel_hash="$(printf '%s' "$panel_password" | openssl passwd -6 -stdin)"
    fi
    (
        umask 027
        printf '# Written by RAYXO setup.sh on every run; changes made here are lost.\n' >/etc/dovecot/rayxo-system.passwd.new
        if [[ -n "$panel_hash" ]]; then
            printf '%s:{SHA512-CRYPT}%s::::::allow_nets=127.0.0.1,::1\n' "$panel_login" "$panel_hash" >>/etc/dovecot/rayxo-system.passwd.new
        fi
    )
    chgrp dovecot /etc/dovecot/rayxo-system.passwd.new
    mv -f /etc/dovecot/rayxo-system.passwd.new /etc/dovecot/rayxo-system.passwd

    # The whole Dovecot configuration, without Debian's conf.d: system users
    # never sign in to IMAP, only the panel's mailboxes do.
    cat >/etc/dovecot/dovecot.conf <<CONF
# Written by RAYXO setup.sh on every run; changes made here are lost.
protocols = imap pop3 lmtp
listen = *
ssl = required
ssl_cert = <$CONFIG_DIR/ssl/panel.crt
ssl_key = <$CONFIG_DIR/ssl/panel.key
ssl_min_protocol = TLSv1.2
disable_plaintext_auth = yes
auth_mechanisms = plain login
auth_username_format = %Lu
first_valid_uid = 1000
mail_location = maildir:~/Maildir
mail_plugins = \$mail_plugins quota

# A webmail session's token first (cheap, and Roundcube signs in on every
# request), then the mailbox's own password.
passdb {
  driver = sql
  args = /etc/dovecot/rayxo-webmail-sql.conf.ext
}

passdb {
  driver = sql
  args = /etc/dovecot/rayxo-sql.conf.ext
}

# The panel's login for sending its own mail, from this server only.
passdb {
  driver = passwd-file
  args = /etc/dovecot/rayxo-system.passwd
}

userdb {
  driver = sql
  args = /etc/dovecot/rayxo-sql.conf.ext
}

namespace inbox {
  inbox = yes
  separator = /
  mailbox Drafts {
    special_use = \\Drafts
    auto = subscribe
  }
  mailbox Sent {
    special_use = \\Sent
    auto = subscribe
  }
  mailbox Junk {
    special_use = \\Junk
    auto = subscribe
  }
  mailbox Trash {
    special_use = \\Trash
    auto = subscribe
  }
}

service lmtp {
  unix_listener /var/spool/postfix/private/dovecot-lmtp {
    mode = 0600
    user = postfix
    group = postfix
  }
}

service auth {
  unix_listener /var/spool/postfix/private/auth {
    mode = 0660
    user = postfix
    group = postfix
  }
}

protocol imap {
  mail_plugins = \$mail_plugins imap_quota
}

protocol lmtp {
  postmaster_address = postmaster@$server_hostname
  mail_plugins = \$mail_plugins sieve
}

# Sieve on delivery: the server's spam script first (spam into Junk), then
# the mailbox's own (filters and automatic reply from the panel, written by
# the agent as the account). A mailbox with its own spam filter gets an
# empty script before instead (userdb field sieve_before), and its own
# script handles spam first. Forwarded copies and automatic replies go out from
# the mailbox's address: the local sendmail takes no empty sender from
# hosting accounts, since such mail would escape their daily send limit.
plugin {
  quota = maildir:User quota
  sieve = file:~/rayxo.sieve
  sieve_before = /etc/dovecot/sieve/rayxo-spam.sieve
  sieve_redirect_envelope_from = recipient
  sieve_vacation_send_from_recipient = yes
  sieve_max_redirects = 10
}

# mail.<domain> of the accounts' domains with its own certificate (SNI),
# written by rayxod (mail.sni_apply) from copies in $CONFIG_DIR/ssl/mail.
!include_try /etc/dovecot/rayxo-sni.conf
CONF
    if [[ ! -f /etc/dovecot/rayxo-sni.conf ]]; then
        printf '# Written by rayxod (mail.sni_apply); changes are overwritten.\n' >/etc/dovecot/rayxo-sni.conf
    fi

    install -d -m 0755 /etc/dovecot/sieve
    cat >/etc/dovecot/sieve/rayxo-spam.sieve <<'SIEVE'
# Written by RAYXO setup.sh on every run; changes made here are lost.
# Rspamd marks mail it would add a spam header to with "X-Spam: Yes".
require ["fileinto", "mailbox"];

if header :is "X-Spam" "Yes" {
    fileinto :create "Junk";
    stop;
}
SIEVE
    quietly sievec /etc/dovecot/sieve/rayxo-spam.sieve || die "skrip Sieve spam ditolak (cek: sievec /etc/dovecot/sieve/rayxo-spam.sieve)"
    # Run before the script of a mailbox with its own spam filter: nothing.
    printf '# Written by RAYXO setup.sh on every run: the mailbox handles its own spam.\n' >/etc/dovecot/sieve/rayxo-none.sieve
    quietly sievec /etc/dovecot/sieve/rayxo-none.sieve || die "skrip Sieve kosong ditolak (cek: sievec /etc/dovecot/sieve/rayxo-none.sieve)"

    # Rspamd: DKIM keys of the accounts' domains (written by the agent), no
    # greylisting (it delays first mail by minutes), Redis on localhost.
    install -d -m 0750 -o _rspamd -g _rspamd "$RSPAMD_DKIM_DIR"
    install -d -m 0755 "$RSPAMD_MAP_DIR"
    [[ -f "$RSPAMD_MAP_DIR/dkim_selectors.map" ]] || install -m 0644 /dev/null "$RSPAMD_MAP_DIR/dkim_selectors.map"

    cat >/etc/rspamd/local.d/dkim_signing.conf <<CONF
# Written by RAYXO setup.sh on every run; changes made here are lost.
path = "$RSPAMD_DKIM_DIR/\$domain.\$selector.key";
selector_map = "$RSPAMD_MAP_DIR/dkim_selectors.map";
allow_username_mismatch = false;
try_fallback = false;
use_esld = false;
CONF
    printf '# Written by RAYXO setup.sh on every run.\nenabled = false;\n' >/etc/rspamd/local.d/greylist.conf
    # Rspamd reads changed map files (send limits, DKIM selectors) within
    # 30 seconds instead of its default 5 minutes.
    printf '# Written by RAYXO setup.sh on every run.\nmap_watch_interval = 30s;\n' >/etc/rspamd/local.d/options.inc

    # Mail that came in on port 25 (Postfix tags it INBOUND; Rspamd's milter
    # passes Postfix's daemon name on as MTA-Name) is never signed:
    # senders sign in on 587/465, or use the local sendmail, whose senders
    # Postfix limits per user (LOCAL). Hosting accounts can open 127.0.0.1:25.
    cat >/etc/rspamd/local.d/settings.conf <<'CONF'
# Written by RAYXO setup.sh on every run; changes made here are lost.
inbound_is_not_signed {
  priority = high;
  request_header = {
    "MTA-Name" = "^INBOUND$";
  }
  apply {
    symbols_disabled = ["DKIM_SIGNED", "ARC_SIGNED"];
  }
}
CONF

    # Daily send limits of hosting accounts, through Rspamd's ratelimit
    # module: ratelimit.lua names the account that sends a message and its
    # limit, from send_limits.map, which the agent writes from the panel's
    # settings (rayxo:sync-send-limits). Recipients are counted per 24 hours;
    # over the limit, the message is refused for now (451) and tried again
    # later. The limits stay as set: no growth for senders of ham.
    [[ -f "$RSPAMD_MAP_DIR/send_limits.map" ]] ||
        install -m 0640 -o root -g _rspamd /dev/null "$RSPAMD_MAP_DIR/send_limits.map"
    cat >"$RSPAMD_MAP_DIR/ratelimit.lua" <<'LUA'
-- Written by RAYXO setup.sh on every run; changes made here are lost.
-- Custom keywords of Rspamd's ratelimit module: the daily send limits of
-- hosting accounts. send_limits.map (written by rayxod) holds
--   account <limit>               the limit of each account
--   account:<username> <limit>    one account's own limit, when it has one
--   server <limit>                all accounts together
--   mailbox:<address> <limit>     a mailbox's own limit, for what it sends signed in
--   sender:@<domain> <username>   and sender:<username>@<hostname> <username>
-- Mail counts for the account whose login sent it on 587/465 (a mailbox,
-- by its domain) or, from the local sendmail, whose address it comes
-- from, which Postfix limits to the account's own (local_login_sender_maps).
-- Mail that came in on port 25, and mail of no account (the panel, root),
-- is not limited.
local map = rspamd_config:add_map({
  type = 'map',
  url = '/etc/rspamd/rayxo/send_limits.map',
  description = 'RAYXO daily send limits',
})

local function account_of(task)
  local address = task:get_user()
  if not address then
    local daemon = task:get_request_header('MTA-Name')
    if not daemon or tostring(daemon) ~= 'LOCAL' then
      return nil
    end
    local from = task:get_from('smtp')
    address = from and from[1] and from[1].addr
  end
  if not address or address == '' then
    return nil
  end
  address = string.lower(address)
  local domain = address:match('@([^@]+)$')
  return map:get_key('sender:' .. address) or (domain and map:get_key('sender:@' .. domain)) or nil
end

local function bucket(limit)
  limit = tonumber(limit)
  if not limit or limit < 1 then
    return nil
  end
  return {
    burst = limit,
    rate = string.format('%d / 1d', limit),
    message = 'Daily sending limit reached; the message will be sent later',
  }
end

return {
  rayxo_account = function(task)
    local account = account_of(task)
    if not account then
      return nil
    end
    local limits = bucket(map:get_key('account:' .. account) or map:get_key('account'))
    if not limits then
      return nil
    end
    return 'rayxo_account:' .. account, limits
  end,
  rayxo_mailbox = function(task)
    local user = task:get_user()
    if not user then
      return nil
    end
    user = string.lower(user)
    local limits = bucket(map:get_key('mailbox:' .. user))
    if not limits then
      return nil
    end
    return 'rayxo_mailbox:' .. user, limits
  end,
  rayxo_server = function(task)
    if not account_of(task) then
      return nil
    end
    local limits = bucket(map:get_key('server'))
    if not limits then
      return nil
    end
    return 'rayxo_server', limits
  end,
}
LUA
    chmod 0644 "$RSPAMD_MAP_DIR/ratelimit.lua"
    cat >/etc/rspamd/local.d/ratelimit.conf <<CONF
# Written by RAYXO setup.sh on every run; changes made here are lost.
custom_keywords = "$RSPAMD_MAP_DIR/ratelimit.lua";
ham_factor_rate = 1;
ham_factor_burst = 1;
spam_factor_rate = 1;
spam_factor_burst = 1;
CONF

    # Rspamd and Redis listen on unix sockets only, so hosting accounts can
    # reach neither (their sites and cron jobs can open 127.0.0.1): the
    # milter in Postfix's spool for the postfix group, the scanner and the
    # web interface for root (rspamc -h /run/rspamd/controller.sock), and
    # Redis for Rspamd (the redis group). The milter scans by itself.
    install -d -m 0750 -o _rspamd -g postfix /var/spool/postfix/rspamd
    install -d -m 0755 /etc/rspamd/override.d
    printf '# Written by RAYXO setup.sh on every run.\nbind_socket = "/var/spool/postfix/rspamd/milter.sock mode=0666 owner=_rspamd";\nupstream "local" {\n  default = yes;\n  self_scan = yes;\n}\n' \
        >/etc/rspamd/override.d/worker-proxy.inc
    printf '# Written by RAYXO setup.sh on every run.\nbind_socket = "/run/rspamd/normal.sock mode=0600 owner=_rspamd";\n' \
        >/etc/rspamd/override.d/worker-normal.inc
    printf '# Written by RAYXO setup.sh on every run.\nbind_socket = "/run/rspamd/controller.sock mode=0600 owner=_rspamd";\n' \
        >/etc/rspamd/override.d/worker-controller.inc
    printf '# Written by RAYXO setup.sh on every run.\nservers = "/run/redis/redis-server.sock";\n' >/etc/rspamd/local.d/redis.conf

    printf '# Written by RAYXO setup.sh on every run: Redis is for Rspamd only.\nport 0\nunixsocket /run/redis/redis-server.sock\nunixsocketperm 770\n' \
        >/etc/redis/rayxo.conf
    chown root:redis /etc/redis/rayxo.conf
    chmod 0640 /etc/redis/rayxo.conf
    grep -qx 'include /etc/redis/rayxo.conf' /etc/redis/redis.conf ||
        printf '\n# RAYXO: last, so its settings win.\ninclude /etc/redis/rayxo.conf\n' >>/etc/redis/redis.conf
    usermod -aG redis _rspamd
    printf '# Written by RAYXO setup.sh on every run.\nuse = ["x-spamd-bar", "x-spam-level", "authentication-results"];\nskip_local = true;\nskip_authenticated = true;\n' \
        >/etc/rspamd/local.d/milter_headers.conf

    quietly systemctl enable redis-server rspamd dovecot
    systemctl restart redis-server
    quietly rspamadm configtest || die "konfigurasi Rspamd ditolak (cek: rspamadm configtest)"
    systemctl restart rspamd
    quietly doveconf -n || die "konfigurasi Dovecot ditolak (cek: doveconf -n)"
    systemctl restart dovecot
    quietly postfix check
    systemctl restart postfix
    info "Email akun hosting aktif: SMTP 25, kirim lewat 587/465, IMAP 993, POP3 995"
}

# Webmail at https://<hostname>/webmail/: Roundcube from the release's
# roundcube/ folder (served by configure_phpmyadmin's site), its own
# database and MariaDB user, and its configuration and keys in
# $WEBMAIL_CONFIG_DIR, readable by rayxo-webmail only. The panel may read
# and delete Roundcube's users, to clear what it kept of a deleted mailbox.
configure_webmail() {
    step "Mengatur webmail (https://$server_hostname/webmail/)"

    local sso_key password
    sso_key="$(env_value WEBMAIL_SSO_KEY)"
    [[ -n "$sso_key" ]] || die "WEBMAIL_SSO_KEY tidak ada di $ENV_FILE"

    # A new database password on every run; it only lives in db.key.
    password="$(openssl rand -hex 24)"

    mariadb <<SQL
CREATE DATABASE IF NOT EXISTS \`$WEBMAIL_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$WEBMAIL_USER'@'localhost' IDENTIFIED BY '$password';
ALTER USER '$WEBMAIL_USER'@'localhost' IDENTIFIED BY '$password';
GRANT ALL PRIVILEGES ON \`$WEBMAIL_DB\`.* TO '$WEBMAIL_USER'@'localhost';
SQL

    # Written by the shell itself (printf is a builtin), so the keys never
    # show up on a command line. Roundcube encrypts the IMAP password in
    # its sessions with des.key (24 characters), which therefore stays.
    (
        umask 027
        printf '%s\n' "$password" >"$WEBMAIL_CONFIG_DIR/db.key"
        if [[ ! -s "$WEBMAIL_CONFIG_DIR/des.key" ]]; then
            openssl rand -base64 18 >"$WEBMAIL_CONFIG_DIR/des.key"
        fi
        printf '%s\n' "$sso_key" >"$WEBMAIL_CONFIG_DIR/sso.key"
    )
    render "$release_dir/config/roundcube-config.inc.php" "$WEBMAIL_CONFIG_DIR/config.inc.php"
    local file
    for file in db.key des.key sso.key config.inc.php; do
        chown "root:$WEBMAIL_USER" "$WEBMAIL_CONFIG_DIR/$file"
        chmod 0640 "$WEBMAIL_CONFIG_DIR/$file"
    done

    # Roundcube's tables: made on the first run, brought up to the
    # release's version on later ones.
    if ! (cd / && runuser -u "$WEBMAIL_USER" -- "$PHP_BIN" "$release_dir/roundcube/bin/initdb.sh" \
        --dir="$release_dir/roundcube/SQL" --update) >>"$INSTALL_LOG" 2>&1; then
        die "database webmail tidak bisa disiapkan (cek: $INSTALL_LOG)"
    fi

    mariadb <<SQL
GRANT SELECT, DELETE ON \`$WEBMAIL_DB\`.\`users\` TO 'rayxo'@'localhost';
GRANT SELECT, DELETE ON \`$WEBMAIL_DB\`.\`users\` TO 'rayxo'@'127.0.0.1';
SQL

    systemctl reload "php$PHP_VERSION-fpm"
    info "Webmail siap; customer membukanya dari halaman Email di panel (login otomatis) atau dengan password"
}

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
    # serve-cgi-bin: with mod_cgid it maps /cgi-bin/ of every website to
    # /usr/lib/cgi-bin; a website's own cgi-bin runs through suEXEC instead.
    # other-vhosts-access-log: Nginx already logs every request.
    # php-fpm: PHP only runs in the per-account pools, never in a shared one.
    local conf v php_confs=()
    for v in "${site_php_versions[@]}"; do
        php_confs+=("php$v-fpm")
    done

    for conf in security other-vhosts-access-log serve-cgi-bin "${php_confs[@]}"; do
        if [[ -e "/etc/apache2/conf-enabled/$conf.conf" ]]; then
            quietly a2disconf -q "$conf"
        fi
    done

    # suEXEC runs CGI scripts as the account that owns the website, and only
    # inside /home; the second line (#) refuses ~user requests. A website
    # only runs CGI when the agent turns it on in its vhost.
    install -d -m 0755 /etc/apache2/suexec
    printf '%s\n' /home '#public_html/cgi-bin' \
        '# Managed by rayxo setup.sh: suEXEC document root, and no userdir.' \
        >/etc/apache2/suexec/www-data

    quietly a2dismod -q mpm_prefork mpm_worker
    quietly a2enmod -q mpm_event proxy_fcgi remoteip rewrite headers setenvif cgid suexec
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

# The PAM line through which cron jobs of hosting accounts move into their
# account's resource slice (rayxod cron-scope, run as root when cron opens
# the session of a job). It is optional: a job whose move fails still runs.
CRON_SCOPE_PAM_LINE="session optional pam_exec.so quiet $INSTALL_DIR/bin/rayxod cron-scope"

# cron opens a PAM session for every job; there rayxod moves the jobs of
# hosting accounts into rayxo-<user>.slice, so they get the account's
# resource limits and count in its CPU and memory history like its PHP.
configure_cron_slices() {
    local file=/etc/pam.d/cron

    [[ -f "$file" ]] || die "$file tidak ada (paket cron belum terpasang?)"

    if ! grep -qxF "$CRON_SCOPE_PAM_LINE" "$file"; then
        sed -i '/^# RAYXO: cron jobs of hosting accounts/d; /rayxod cron-scope/d' "$file"
        printf '# RAYXO: cron jobs of hosting accounts run in their resource slice.\n%s\n' "$CRON_SCOPE_PAM_LINE" >>"$file"
    fi
    info "Cron job akun hosting berjalan di slice resource akunnya ($file)"
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

# Every domain gets its DNS zone (from the template when it has none yet,
# e.g. after an upgrade from a release without DNS) and every zone is
# written to PowerDNS again. Queued like rebuild_websites.
sync_dns_zones() {
    step "Menyamakan zone DNS"

    if artisan rayxo:sync-dns >>"$INSTALL_LOG" 2>&1; then
        info "Semua zone DNS ditulis ulang di latar belakang (lihat Tasks di panel)"
    else
        warn "zone DNS tidak bisa disamakan; jalankan lagi: runuser -u $PANEL_USER -- $PHP_BIN $INSTALL_DIR/current/panel/artisan rayxo:sync-dns"
    fi
}

# Every mailbox's Sieve script is written again, so scripts written by an
# older release follow the current rules (such as handling spam first).
# Queued like rebuild_websites.
apply_mail_rules() {
    step "Menulis ulang filter email"

    if artisan rayxo:apply-mail-rules >>"$INSTALL_LOG" 2>&1; then
        info "Filter, balasan otomatis dan filter spam akun email ditulis ulang di latar belakang (lihat Tasks di panel)"
    else
        warn "filter email tidak bisa ditulis ulang; jalankan lagi: runuser -u $PANEL_USER -- $PHP_BIN $INSTALL_DIR/current/panel/artisan rayxo:apply-mail-rules"
    fi
}

# The mail server gets the daily send limits and which domains belong to
# which account (the scheduler keeps them current every five minutes).
# Queued like rebuild_websites.
sync_send_limits() {
    step "Menerapkan batas kirim email"

    if artisan rayxo:sync-send-limits >>"$INSTALL_LOG" 2>&1; then
        info "Batas kirim email diterapkan di latar belakang (lihat Tasks di panel)"
    else
        warn "batas kirim email tidak bisa diterapkan; jalankan lagi: runuser -u $PANEL_USER -- $PHP_BIN $INSTALL_DIR/current/panel/artisan rayxo:sync-send-limits"
    fi
}

# mail.<domain> of every domain that takes mail gets a certificate when it
# never had one asked (such as domains set up before mail certificates
# existed), and Dovecot and Postfix serve them all again. Queued like
# rebuild_websites; the scheduler does the same daily.
sync_mail_ssl() {
    step "Menyiapkan SSL mail.<domain>"

    if artisan rayxo:sync-mail-ssl >>"$INSTALL_LOG" 2>&1; then
        info "SSL mail.<domain> diminta dan dipasang di latar belakang (lihat Tasks di panel)"
    else
        warn "SSL mail.<domain> tidak bisa disiapkan; jalankan lagi: runuser -u $PANEL_USER -- $PHP_BIN $INSTALL_DIR/current/panel/artisan rayxo:sync-mail-ssl"
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

    grep -qxF "$CRON_SCOPE_PAM_LINE" /etc/pam.d/cron ||
        die "cron job akun hosting tidak masuk slice resource-nya (baris rayxod cron-scope tidak ada di /etc/pam.d/cron)"

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
    # Postfix takes mail from the internet for the accounts' domains, so it
    # must refuse to pass mail on to any other domain for anyone who has
    # not signed in: asked through the server's own public address (outside
    # mynetworks), it has to refuse a foreign recipient.
    if [[ -n "${server_ip:-}" ]] && ! python3 - "$server_ip" >>"$INSTALL_LOG" 2>&1 <<'PYTHON'
import smtplib, sys
smtp = smtplib.SMTP(sys.argv[1], 25, timeout=20)
smtp.ehlo("relay-check.invalid")
smtp.mail("check@example.org")
code, _ = smtp.rcpt("check@example.net")
smtp.quit()
sys.exit(0 if code >= 500 else 1)
PYTHON
    then
        die "Postfix meneruskan email ke domain lain tanpa login (open relay); server bisa dipakai untuk spam"
    fi
    # The same from 127.0.0.1, which hosting accounts can open: mail they
    # send goes through the local sendmail (sender checked, counted against
    # the daily limit, DKIM-signed) or a signed-in 587, never an unsigned
    # relay on port 25 under any sender.
    if ! runuser -u nobody -- python3 - >>"$INSTALL_LOG" 2>&1 <<'PYTHON'
import smtplib, sys
smtp = smtplib.SMTP("127.0.0.1", 25, timeout=20)
smtp.ehlo("relay-check.invalid")
smtp.mail("check@example.org")
code, _ = smtp.rcpt("check@example.net")
smtp.quit()
sys.exit(0 if code >= 500 else 1)
PYTHON
    then
        die "Postfix meneruskan email ke domain lain dari 127.0.0.1 tanpa login; akun hosting bisa mengirim spam tanpa batas kirim"
    fi
    info "Postfix aktif dan menolak meneruskan email tanpa login"

    # Hosting accounts can open 127.0.0.1, so the mail services that sign,
    # scan or keep data must not listen there.
    local open_ports
    open_ports="$(ss -Hltn '( sport = :6379 or sport = :8891 or sport = :11332 or sport = :11333 or sport = :11334 or sport = :11335 )' | awk '{print $4}' | tr '\n' ' ')"
    [[ -z "${open_ports// /}" ]] ||
        die "Redis, OpenDKIM atau Rspamd masih mendengar lewat TCP ($open_ports); akun hosting bisa menjangkaunya"
    info "Redis, OpenDKIM dan Rspamd hanya lewat socket yang terkunci"

    # The panel signs in to send its mail, and nobody may send through the
    # local sendmail as someone else.
    if [[ "$(env_value MAIL_HOST)" == 127.0.0.1 && "$(env_value MAIL_PORT)" == 587 ]]; then
        MAIL_USERNAME="$(env_value MAIL_USERNAME)" MAIL_PASSWORD="$(env_value MAIL_PASSWORD)" \
            python3 - >>"$INSTALL_LOG" 2>&1 <<'PYTHON' ||
import os, smtplib, ssl
context = ssl.create_default_context()
context.check_hostname = False
context.verify_mode = ssl.CERT_NONE
smtp = smtplib.SMTP("127.0.0.1", 587, timeout=20)
smtp.starttls(context=context)
smtp.login(os.environ["MAIL_USERNAME"], os.environ["MAIL_PASSWORD"])
smtp.quit()
PYTHON
            die "panel tidak bisa login ke Postfix di port 587 untuk mengirim email (cek: $INSTALL_LOG)"
        info "Panel mengirim email dengan login (port 587)"
    fi
    if printf 'Subject: RAYXO sender check\n\nThis should have been refused.\n' |
        runuser -u nobody -- /usr/sbin/sendmail -f check@example.org root >>"$INSTALL_LOG" 2>&1; then
        die "sendmail lokal menerima alamat pengirim sembarang dari user biasa (cek: postconf local_login_sender_maps)"
    fi
    info "Sendmail lokal hanya menerima alamat pengirim milik user itu sendiri"

    systemctl is-active --quiet opendkim || die "OpenDKIM tidak berjalan (cek: journalctl -u opendkim)"
    info "OpenDKIM aktif"

    # Forwarded mail gets an SRS sender that comes back as the original;
    # Postfix's daemons find the socket from the spool.
    local srs_address srs_back
    srs_address="$(cd /var/spool/postfix && postmap -q check@example.org "socketmap:unix:$SRS_SOCKET:forward" 2>>"$INSTALL_LOG" || true)"
    srs_back="$(cd /var/spool/postfix && postmap -q "$srs_address" "socketmap:unix:$SRS_SOCKET:reverse" 2>>"$INSTALL_LOG" || true)"
    [[ "$srs_address" == SRS0=*"@$server_hostname" && "$srs_back" == check@example.org ]] ||
        die "SRS untuk email yang diteruskan tidak bekerja (cek: journalctl -u rayxo-srs)"
    info "Email yang diteruskan dikirim dengan alamat SRS $server_hostname"

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
    systemctl is-active --quiet pdns || die "PowerDNS tidak berjalan (cek: journalctl -u pdns)"
    local unit
    for unit in dovecot rspamd redis-server; do
        systemctl is-active --quiet "$unit" || die "$unit tidak berjalan (cek: journalctl -u $unit)"
    done
    # Read whole, not piped into grep -q (SIGPIPE with pipefail).
    local ftp_tls
    ftp_tls="$(timeout 15 openssl s_client -connect 127.0.0.1:21 -starttls ftp -servername "$server_hostname" \
        </dev/null 2>/dev/null || true)"
    if ! grep -q "BEGIN CERTIFICATE" <<<"$ftp_tls"; then
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

    # Roundcube's login form, and its stylesheet through static.php.
    local login_page
    login_page="$(curl -sk --resolve "$server_hostname:443:127.0.0.1" "https://$server_hostname/webmail/" 2>/dev/null || true)"
    [[ "$login_page" == *rcmloginuser* ]] ||
        die "Webmail tidak berjalan seperti seharusnya (cek: $LOG_DIR/phpmyadmin-error.log, $WEBMAIL_DATA_DIR/log/ dan journalctl -t roundcube)"
    status="$(curl -sk -o /dev/null -w '%{http_code}' --resolve "$server_hostname:443:127.0.0.1" \
        "https://$server_hostname/webmail/static.php/skins/elastic/styles/styles.min.css" || true)"
    [[ "$status" == 200 ]] || die "Tampilan webmail tidak bisa dimuat (HTTP $status; cek: $LOG_DIR/phpmyadmin-error.log)"
    info "Webmail aktif di https://$server_hostname/webmail/"

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
    info "Webmail  : https://$server_hostname/webmail/ (juga dari halaman Email)"
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
    info "- Pastikan port 80, 443, $PANEL_PORT, 22 (SFTP/SSH), 21, ${FTP_PASSIVE_PORTS/ /-} (FTP passive), 53 TCP & UDP (DNS) dan 25, 110, 143, 465, 587, 993, 995 (email) terbuka di firewall / security group provider VPS."
    info "- Cek agent: rayxo ping"
    info "- Lupa password admin: rayxo reset-password $admin_user (sebagai root)"
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
    configure_dns
    configure_mailboxes
    configure_webmail
    configure_web
    configure_quota
    configure_logrotate
    configure_cron_slices
    install_services
    verify_install
    rebuild_websites
    apply_quotas
    sync_dns_zones
    sync_send_limits
    apply_mail_rules
    sync_mail_ssl
    # Last, right before the summary, so a failure in an earlier step can never
    # leave an admin whose generated password was not shown.
    create_admin
    build_dns_report
    summary
}

main "$@"
