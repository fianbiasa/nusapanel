#!/usr/bin/env bash
#
# nusapanel installer for a fresh Ubuntu 24.04 server. Run as root:
#
#   wget https://<release-host>/setup.sh
#   bash setup.sh --release-url https://<release-host>/nusapanel-<version>-linux-amd64.tar.gz \
#                 --admin-email you@example.com
#
# or with a release package already copied to the server:
#
#   bash setup.sh --package nusapanel-<version>-linux-amd64.tar.gz --admin-email you@example.com
#
# Safe to run again: every step checks what is already done, so a run that
# stopped halfway can simply be repeated.

set -Eeuo pipefail
umask 022

readonly PHP_VERSION="8.4"
readonly PHP_BIN="/usr/bin/php$PHP_VERSION"
readonly PANEL_USER="nusapanel"
readonly PANEL_PORT="2222"
readonly INSTALL_DIR="/usr/local/nusapanel"
readonly CONFIG_DIR="/etc/nusapanel"
readonly DATA_DIR="/var/lib/nusapanel"
readonly LOG_DIR="/var/log/nusapanel"
readonly ENV_FILE="$CONFIG_DIR/panel.env"
readonly AGENT_SOCKET="/run/nusapanel/agent.sock"
readonly INSTALL_LOG="/var/log/nusapanel-install.log"
readonly ACME_DIR="/var/www/nusapanel-acme"
readonly DKIM_SELECTOR="nusapanel"
readonly DNS_REPORT="$CONFIG_DIR/dns-records.txt"
readonly RELEASE_REPO="fianbiasa/nusapanel"
readonly POLICY_RC="/usr/sbin/policy-rc.d"
readonly POLICY_RC_MARKER="# nusapanel setup.sh: temporary"

# Stamped by installer/build-release.sh into the setup.sh published with each
# release, so that script installs its own version by default. Empty in the
# repository, where setup.sh installs the latest release instead.
NUSAPANEL_VERSION=""

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

# Filled in while installing.
arch=""
server_ip=""
work_dir=""
release_dir=""
version=""
db_password=""
admin_password=""
stale_release=""
ssl_status=""
dkim_public_key=""
dns_report_lines=()
# Set while our temporary policy-rc.d is in place.
policy_rc_created=0

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

Sumber paket (default: rilis dari github.com/fianbiasa/nusapanel):
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
            --force) force=1; shift ;;
            --public-ip) need_value "$1" "${2:-}"; public_ip="$2"; public_ip_given=1; shift 2 ;;
            --skip-dns-check) skip_dns_check=1; shift ;;
            --version) need_value "$1" "${2:-}"; requested_version="${2#v}"; shift 2 ;;
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
    local wanted="${requested_version:-$NUSAPANEL_VERSION}"

    if [[ -z "$wanted" ]]; then
        wanted="$(latest_release_version)" ||
            die "tidak bisa mengambil rilis terbaru dari github.com/$RELEASE_REPO; coba lagi, atau pakai --version / --package"
    fi

    local version_re='^[0-9A-Za-z][0-9A-Za-z._-]*$'
    [[ "$wanted" =~ $version_re ]] || die "versi tidak valid: $wanted"

    release_url="https://github.com/$RELEASE_REPO/releases/download/v$wanted/nusapanel-$wanted-linux-$arch.tar.gz"
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
        die "nusapanel saat ini hanya mendukung Ubuntu 24.04 LTS (terdeteksi: $os_name)"
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
            die "panel lain terdeteksi di $other_panel; pasang nusapanel di server baru"
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
    info "Dipasang : Nginx + Apache, MariaDB, PHP $PHP_VERSION (repo ondrej/php), Postfix + DKIM (kirim-saja), agent nusad"
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

create_layout() {
    step "Menyiapkan user dan folder"

    if ! id -u "$PANEL_USER" >/dev/null 2>&1; then
        useradd --system --user-group --home-dir "$DATA_DIR" --no-create-home \
            --shell /usr/sbin/nologin "$PANEL_USER"
        info "User sistem $PANEL_USER dibuat"
    fi

    # Hosting homes are 0710 <user>:nusa-access, so only the web server can
    # enter them. Created before any web server starts, so its workers get
    # the group without a restart.
    if ! getent group nusa-access >/dev/null; then
        groupadd --system nusa-access
    fi
    usermod -aG nusa-access www-data

    install -d -m 0755 -o root -g root "$INSTALL_DIR" "$INSTALL_DIR/releases"
    install -d -m 0750 -o root -g "$PANEL_USER" "$CONFIG_DIR"
    install -d -m 0700 -o root -g root "$CONFIG_DIR/ssl"
    install -d -m 0750 -o root -g adm "$LOG_DIR"

    # Directories that only hold other directories stay root-owned, so the
    # panel user cannot replace what is inside them with symlinks.
    install -d -m 0750 -o root -g "$PANEL_USER" "$DATA_DIR" "$DATA_DIR/storage" \
        "$DATA_DIR/storage/app" "$DATA_DIR/storage/framework" "$DATA_DIR/storage/framework/cache"

    local dir
    for dir in storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs; do
        ensure_dir "$DATA_DIR/$dir"
    done

    touch "$CONFIG_DIR/.setup-started"
}

install_packages() {
    step "Memasang paket sistem (beberapa menit; detail di $INSTALL_LOG)"

    export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a
    local apt=(apt-get -y -q -o DPkg::Lock::Timeout=300)

    quietly "${apt[@]}" update
    quietly "${apt[@]}" install ca-certificates curl gnupg openssl software-properties-common tar

    if ! grep -rqs "ondrej/php" /etc/apt/sources.list.d/; then
        info "Menambahkan repo PHP ondrej/php"
        # -n: skip its own apt update, which would not wait for the dpkg lock.
        quietly add-apt-repository -y -n ppa:ondrej/php
        quietly "${apt[@]}" update
    fi

    # Postfix asks questions while installing; answer them up front.
    debconf-set-selections >>"$INSTALL_LOG" 2>&1 <<DEBCONF
postfix postfix/main_mailer_type select Internet Site
postfix postfix/mailname string $server_hostname
DEBCONF

    quietly "${apt[@]}" install nginx mariadb-server postfix opendkim opendkim-tools certbot python3 \
        "php$PHP_VERSION-fpm" "php$PHP_VERSION-cli" "php$PHP_VERSION-common" "php$PHP_VERSION-mysql" \
        "php$PHP_VERSION-mbstring" "php$PHP_VERSION-xml" "php$PHP_VERSION-curl" "php$PHP_VERSION-intl" \
        "php$PHP_VERSION-zip" "php$PHP_VERSION-bcmath" "php$PHP_VERSION-opcache" "php$PHP_VERSION-readline" \
        "php$PHP_VERSION-gd"

    # Apache would start on port 80, which Nginx owns, and fail the install.
    # It stays stopped until configure_web has moved it to 127.0.0.1:8080.
    block_service_starts
    quietly "${apt[@]}" install apache2
    remove_policy_rc

    quietly systemctl enable --now mariadb nginx "php$PHP_VERSION-fpm"
    info "Nginx, Apache, MariaDB, Postfix dan PHP $PHP_VERSION terpasang"
}

fetch_release() {
    step "Mengambil paket nusapanel"

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

    if [[ ${#entries[@]} -ne 1 || ! -f "$top/VERSION" || ! -x "$top/bin/nusad" || ! -f "$top/panel/artisan" ]]; then
        die "isi paket tidak dikenali; pastikan ini file rilis nusapanel"
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

    info "nusapanel $version disalin ke $release_dir"
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
CREATE DATABASE IF NOT EXISTS \`nusapanel\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'nusapanel'@'localhost' IDENTIFIED BY '$db_password';
CREATE USER IF NOT EXISTS 'nusapanel'@'127.0.0.1' IDENTIFIED BY '$db_password';
ALTER USER 'nusapanel'@'localhost' IDENTIFIED BY '$db_password';
ALTER USER 'nusapanel'@'127.0.0.1' IDENTIFIED BY '$db_password';
GRANT ALL PRIVILEGES ON \`nusapanel\`.* TO 'nusapanel'@'localhost';
GRANT ALL PRIVILEGES ON \`nusapanel\`.* TO 'nusapanel'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

    info "Database dan user MariaDB \"nusapanel\" siap"
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

    add_env_value NUSAPANEL_PUBLIC_IP "$public_ip"
}

write_env() {
    if [[ -f "$ENV_FILE" ]]; then
        info "Memakai konfigurasi yang sudah ada: $ENV_FILE"
        # Installs from before the panel was translated have no locale yet.
        add_env_value APP_LOCALE id
        add_env_value APP_FALLBACK_LOCALE en
        # Installs from before SSL for customer domains have no public IP yet.
        add_public_ip_env
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
APP_NAME=nusapanel
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
DB_DATABASE=nusapanel
DB_USERNAME=nusapanel
DB_PASSWORD=$db_password

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database

MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=25
MAIL_FROM_ADDRESS=nusapanel@$server_hostname
MAIL_FROM_NAME=nusapanel

AGENT_DRIVER=socket
AGENT_SOCKET=$AGENT_SOCKET
AGENT_TIMEOUT=30
ENV
    )

    chown "root:$PANEL_USER" "$ENV_FILE"
    chmod 0640 "$ENV_FILE"
    add_public_ip_env
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
# Managed by nusapanel setup.sh: signs mail sent from $server_hostname.
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
        set_env_value MAIL_FROM_ADDRESS "nusapanel@$server_hostname"
        set_env_value MAIL_FROM_NAME nusapanel
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
        export NUSAPANEL_ADMIN_PASSWORD="$admin_password"
        artisan nusapanel:create-admin "$admin_user" "$admin_email" --no-ansi 2>&1
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
    ln -sfn "$INSTALL_DIR/bin/nusactl" /usr/local/bin/nusactl
    info "$INSTALL_DIR/current -> releases/$version"
}

# Port 80 for the hostname: Let's Encrypt challenges, and a redirect to the
# panel for everything else.
configure_acme_site() {
    step "Menyiapkan $server_hostname di port 80"

    install -d -m 0755 -o root -g root "$ACME_DIR"

    local site=/etc/nginx/sites-available/nusapanel-hostname.conf
    render "$release_dir/config/nginx-hostname.conf" "$site"

    if ! grep -q . /proc/net/if_inet6 2>/dev/null; then
        sed -i '/listen \[::\]/d' "$site"
    fi

    ln -sfn "$site" /etc/nginx/sites-enabled/nusapanel-hostname.conf
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
    cat >/etc/letsencrypt/renewal-hooks/deploy/nusapanel-reload.sh <<'HOOK'
#!/bin/sh
# Installed by nusapanel setup.sh: reload Nginx, which serves the hostname
# certificate and those of customer websites.
systemctl reload nginx
HOOK
    chmod 0755 /etc/letsencrypt/renewal-hooks/deploy/nusapanel-reload.sh
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
    render "$release_dir/config/php-fpm-panel.conf" "$pool_dir/nusapanel.conf"

    if [[ -f "$pool_dir/www.conf" ]]; then
        mv "$pool_dir/www.conf" "$pool_dir/www.conf.disabled"
        info "Pool bawaan \"www\" dimatikan"
    fi

    quietly "php-fpm$PHP_VERSION" -t
    systemctl restart "php$PHP_VERSION-fpm"
    info "Pool \"nusapanel\" aktif (fungsi exec dimatikan)"
}

configure_nginx() {
    step "Mengatur Nginx untuk panel (port $PANEL_PORT)"

    local site=/etc/nginx/sites-available/nusapanel.conf
    render "$release_dir/config/nginx-panel.conf" "$site"

    # procfs files always report size 0, so check for content instead.
    if ! grep -q . /proc/net/if_inet6 2>/dev/null; then
        sed -i '/listen \[::\]/d' "$site"
        info "IPv6 tidak aktif; panel hanya mendengar di IPv4"
    fi

    ln -sfn "$site" /etc/nginx/sites-enabled/nusapanel.conf
    rm -f /etc/nginx/sites-enabled/default

    quietly nginx -t
    systemctl reload nginx
    info "Panel dilayani di port $PANEL_PORT"
}

# Customer websites: Nginx in front (ports 80/443, static files), Apache on
# 127.0.0.1:8080 behind it so .htaccess works, PHP in a pool per account.
# The agent writes one Nginx and one Apache file per domain into the
# directories created here.
configure_web() {
    step "Mengatur web server untuk website customer (Nginx -> Apache 127.0.0.1:8080)"

    install -d -m 0755 -o root -g root /etc/nginx/nusapanel /etc/apache2/nusapanel
    install -d -m 0750 -o root -g adm "$LOG_DIR/domains"

    cat >/etc/apache2/ports.conf <<'CONF'
# Managed by nusapanel setup.sh. Apache only serves Nginx, on loopback;
# Nginx owns ports 80 and 443.
Listen 127.0.0.1:8080
CONF

    render "$release_dir/config/apache-nusapanel.conf" /etc/apache2/conf-available/nusapanel.conf

    if [[ -e /etc/apache2/sites-enabled/000-default.conf ]]; then
        quietly a2dissite -q 000-default
    fi

    # security: loads after nusapanel.conf and would undo its ServerTokens
    # and ServerSignature (nusapanel.conf carries its settings instead).
    # other-vhosts-access-log: Nginx already logs every request.
    # php-fpm: PHP only runs in the per-account pools, never in a shared one.
    local conf
    for conf in security other-vhosts-access-log "php$PHP_VERSION-fpm"; do
        if [[ -e "/etc/apache2/conf-enabled/$conf.conf" ]]; then
            quietly a2disconf -q "$conf"
        fi
    done

    quietly a2dismod -q mpm_prefork mpm_worker
    quietly a2enmod -q mpm_event proxy_fcgi remoteip rewrite headers setenvif
    quietly a2enconf -q nusapanel

    {
        printf '# Managed by nusapanel setup.sh: customer websites, one file per domain,\n'
        printf '# written by the agent (nusad).\n'
        printf 'include /etc/nginx/nusapanel/*/*.conf;\n'

        # Room for long domain names (253 characters plus "www.") and many
        # websites; with the defaults, nginx -t fails on a long name.
        if ! grep -Eqs '^[[:space:]]*server_names_hash_bucket_size' /etc/nginx/nginx.conf; then
            printf '\nserver_names_hash_bucket_size 512;\n'
        fi
        if ! grep -Eqs '^[[:space:]]*server_names_hash_max_size' /etc/nginx/nginx.conf; then
            printf 'server_names_hash_max_size 4096;\n'
        fi
    } >/etc/nginx/conf.d/nusapanel-sites.conf

    # Host names no website uses get a 404, never a customer's site.
    local site=/etc/nginx/sites-available/nusapanel-default.conf
    render "$release_dir/config/nginx-default.conf" "$site"

    if ! grep -q . /proc/net/if_inet6 2>/dev/null; then
        sed -i '/listen \[::\]/d' "$site"
    fi

    ln -sfn "$site" /etc/nginx/sites-enabled/nusapanel-default.conf

    quietly apache2ctl configtest
    quietly nginx -t
    quietly systemctl enable apache2
    # Starts Apache on a fresh install; applies config changes on a re-run.
    quietly systemctl reload-or-restart apache2
    systemctl reload nginx
    info "Apache hanya mendengar di 127.0.0.1:8080, di belakang Nginx"
}

configure_logrotate() {
    render "$release_dir/config/logrotate.conf" /etc/logrotate.d/nusapanel
    info "Rotasi log panel diatur (/etc/logrotate.d/nusapanel)"
}

install_services() {
    step "Memasang service systemd"

    install -m 0644 "$release_dir"/systemd/*.service "$release_dir"/systemd/*.timer /etc/systemd/system/
    systemctl daemon-reload
    quietly systemctl enable nusad.service nusapanel-queue.service nusapanel-scheduler.timer
    systemctl restart nusad.service nusapanel-queue.service
    systemctl start nusapanel-scheduler.timer
    info "nusad, queue worker dan scheduler berjalan"
}

# Websites set up by an older release keep their old web server config until
# it is written again. Queue that for every website; the queue worker and the
# agent do the rest in the background.
rebuild_websites() {
    step "Memperbarui konfigurasi website customer"

    if artisan nusapanel:rebuild-web >>"$INSTALL_LOG" 2>&1; then
        info "Konfigurasi semua website diperbarui di latar belakang (lihat Tasks di panel)"
    else
        warn "konfigurasi website tidak bisa diperbarui; jalankan lagi: runuser -u $PANEL_USER -- $PHP_BIN $INSTALL_DIR/current/panel/artisan nusapanel:rebuild-web"
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

    [[ -S "$AGENT_SOCKET" ]] || die "agent nusad tidak berjalan (cek: journalctl -u nusad)"

    # Call the agent as the panel user, exactly like the panel will.
    if ! runuser -u "$PANEL_USER" -- "$INSTALL_DIR/bin/nusactl" -socket "$AGENT_SOCKET" ping >>"$INSTALL_LOG" 2>&1; then
        die "user $PANEL_USER tidak bisa memanggil agent (cek: journalctl -u nusad)"
    fi
    info "Agent nusad menjawab panggilan dari panel"

    systemctl is-active --quiet nusapanel-queue.service ||
        die "queue worker tidak berjalan (cek: journalctl -u nusapanel-queue)"

    local status
    status="$(curl -sk -o /dev/null -w '%{http_code}' "https://127.0.0.1:$PANEL_PORT/login" || true)"
    [[ "$status" == 200 ]] ||
        die "halaman login panel mengembalikan HTTP $status (cek: $LOG_DIR/panel-error.log dan $DATA_DIR/storage/logs/)"
    info "Halaman login panel bisa dibuka"

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

    # With a real certificate, check it the way a browser would: by name, with
    # full chain verification (no -k).
    if [[ "$ssl_status" == letsencrypt ]]; then
        status="$(curl -s -o /dev/null -w '%{http_code}' --resolve "$server_hostname:$PANEL_PORT:127.0.0.1" \
            "https://$server_hostname:$PANEL_PORT/login" || true)"
        [[ "$status" == 200 ]] ||
            die "panel tidak bisa dibuka dengan sertifikat Let's Encrypt (HTTP $status)"
        info "https://$server_hostname:$PANEL_PORT bisa dibuka dengan sertifikat yang valid"
    fi

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
    printf '\n%s✔ nusapanel %s terpasang%s\n\n' "$green" "$version" "$reset"
    info "Panel    : https://$server_hostname:$PANEL_PORT"
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
    info "- Pastikan port 80, 443 dan $PANEL_PORT terbuka di firewall / security group provider VPS."
    info "- Cek agent: nusactl ping"
    info "- Lupa password admin: runuser -u $PANEL_USER -- php$PHP_VERSION $INSTALL_DIR/current/panel/artisan nusapanel:reset-password $admin_user"
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

    printf '\n%snusapanel installer%s (%s)\n' "$bold" "$reset" "$(date -Is)"

    preflight
    check_dns
    confirm
    create_layout
    install_packages
    fetch_release
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
    configure_web
    configure_logrotate
    install_services
    verify_install
    rebuild_websites
    # Last, right before the summary, so a failure in an earlier step can never
    # leave an admin whose generated password was not shown.
    create_admin
    build_dns_report
    summary
}

main "$@"
