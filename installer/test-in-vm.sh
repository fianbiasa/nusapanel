#!/usr/bin/env bash
#
# Install RAYXO on a clean Ubuntu 24.04 server and check it end to end:
#
#   installer/test-in-vm.sh [--package FILE] [--keep] [--name NAME]
#   installer/test-in-vm.sh --ssh root@HOST [--hostname NAME] [--admin-email EMAIL] [--package FILE]
#
# 1. setup.sh installs the panel on the clean server;
# 2. the admin signs in to the panel and the dashboard shows the server's
#    information from the agent;
# 3. the agent's isolation test proves that accounts cannot read each
#    other's files.
#
# By default the server is a new virtual machine made with Lima
# (https://lima-vm.io; on macOS: brew install lima) from Ubuntu's cloud image,
# in plain mode: no shared folders, no port forwards, nothing installed beyond
# the image. An earlier machine of the same name is deleted first, so every
# run starts clean. It is deleted again when everything passes, unless --keep
# is given; after a failure it stays for a look (limactl shell --workdir / NAME).
# Without public DNS it installs with --skip-dns-check (self-signed SSL).
#
# With --ssh the server is a fresh VPS reached as root with an SSH key: a full
# install with the DNS checks (A and PTR of --hostname, default: the server's
# own) and a real Let's Encrypt certificate, which the check then requires.
# The VPS keeps the install; reinstall its OS before the next clean run.
#
# Without --package the release package is built from the working tree with
# build-release.sh. Runs on macOS (bash 3.2) and Linux; Lima on Linux needs KVM.

set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
NAME="rayxo-test"
PANEL_PORT=2222
package=""
keep=0
ssh_target=""
server_hostname=""
admin_email=""

usage() {
    sed -n '3,28p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

die() {
    printf '\nFAILED: %s\n' "$*" >&2
    exit 1
}

step() { printf '\n==> %s\n' "$*"; }

while [[ $# -gt 0 ]]; do
    case "$1" in
        --package)
            [[ -n "${2:-}" ]] || die "--package needs a file"
            package="$2"
            shift 2
            ;;
        --name)
            [[ "${2:-}" =~ ^[a-z][a-z0-9-]*$ ]] || die "--name needs a name of lowercase letters, digits and dashes"
            NAME="$2"
            shift 2
            ;;
        --ssh)
            [[ "${2:-}" =~ ^root@[A-Za-z0-9.:-]+$ ]] || die "--ssh needs root@HOST"
            ssh_target="$2"
            shift 2
            ;;
        --hostname)
            [[ "${2:-}" =~ ^[a-z0-9.-]+\.[a-z]+$ ]] || die "--hostname needs a fully qualified name"
            server_hostname="$2"
            shift 2
            ;;
        --admin-email)
            [[ "${2:-}" == *@*.* ]] || die "--admin-email needs an email address"
            admin_email="$2"
            shift 2
            ;;
        --keep) keep=1; shift ;;
        -h | --help) usage; exit 0 ;;
        *) die "unknown option: $1 (see --help)" ;;
    esac
done

ssh_options=(-o BatchMode=yes -o ConnectTimeout=20 -o ServerAliveInterval=15 -o ServerAliveCountMax=8)

# Run a command on the server as root, and copy files into its work folder.
if [[ -n "$ssh_target" ]]; then
    remote_dir=/root/rayxo-test
    as_root() { ssh "${ssh_options[@]}" "$ssh_target" "$(printf '%q ' "$@")"; }
    copy_in() { scp -q "${ssh_options[@]}" "$@" "$ssh_target:$remote_dir/"; }
else
    command -v limactl >/dev/null || die "limactl not found; install Lima first (macOS: brew install lima)"
    remote_dir=/tmp
    as_root() { limactl shell --workdir / "$NAME" sudo "$@"; }
    copy_in() { limactl copy "$@" "$NAME:$remote_dir/"; }
fi

work="$(mktemp -d)"
passed=0
vm_made=0

finish() {
    if ((vm_made)); then
        if ((passed && !keep)); then
            limactl delete --force "$NAME" >/dev/null 2>&1 || true
        else
            printf '\nThe VM is still there: limactl shell --workdir / %s (remove it with limactl delete --force %s)\n' "$NAME" "$NAME"
        fi
    fi
    rm -rf "$work"
}
trap finish EXIT

if [[ -n "$ssh_target" ]]; then
    step "Checking $ssh_target"
    server_arch="$(as_root uname -m)" || die "cannot reach $ssh_target as root with an SSH key"
    as_root bash -c '. /etc/os-release && echo "    $PRETTY_NAME, $(nproc) CPU, $(free -m | awk "/^Mem:/ {print \$2}") MB RAM"'
    # A server where an earlier setup.sh stopped partway may go on, as
    # setup.sh itself allows; one with RAYXO installed is no clean server.
    if as_root test -e /etc/rayxo/installed; then
        die "$ssh_target already has RAYXO installed; reinstall its OS for a clean run"
    fi
    [[ -n "$server_hostname" ]] || server_hostname="$(as_root hostname -f)"
else
    server_arch="$(uname -m)"
    server_hostname="${server_hostname:-server.rayxo-vm.test}"
fi
admin_email="${admin_email:-admin@$server_hostname}"

case "$server_arch" in
    x86_64 | amd64) arch=amd64 ;;
    arm64 | aarch64) arch=arm64 ;;
    *) die "unsupported architecture: $server_arch" ;;
esac

if [[ -z "$package" ]]; then
    latest_tag="$(git -C "$ROOT" describe --tags --abbrev=0 2>/dev/null || echo v0.0.0)"
    version="${latest_tag#v}-vm.$(git -C "$ROOT" rev-parse --short HEAD)"
    step "Building release $version"
    "$ROOT/installer/build-release.sh" "$version"
    package="$ROOT/dist/rayxo-$version-linux-$arch.tar.gz"
fi
[[ -f "$package" ]] || die "package not found: $package"
[[ "$(basename "$package")" == *"-linux-$arch.tar.gz" ]] || die "$package is not for linux-$arch, the server's architecture"

step "Building the isolation test for linux-$arch"
make -C "$ROOT/agent" isolation ARCH="$arch" >/dev/null
isolation_test="$ROOT/agent/bin/linux-$arch/isolation.test"

if [[ -z "$ssh_target" ]]; then
    step "Creating a clean Ubuntu 24.04 VM ($NAME)"
    limactl delete --force "$NAME" >/dev/null 2>&1 || true
    vm_made=1
    limactl create --tty=false --plain --name "$NAME" --cpus 4 --memory 4 --disk 30 template:ubuntu-24.04 >/dev/null 2>&1 ||
        die "limactl create failed (run it again without the redirect to see why)"
    limactl start --tty=false "$NAME" >"$work/start.log" 2>&1 || {
        tail -n 30 "$work/start.log" >&2
        die "the VM did not start"
    }
    as_root grep '^PRETTY_NAME=' /etc/os-release
fi

# The check that runs on the server as root once RAYXO is installed: it signs
# the admin in with a fresh password (read from stdin, never on a command
# line) and asks the dashboard for the server information it loads from the
# agent. With verify_tls=1 the panel's certificate must be valid.
cat >"$work/check.sh" <<'CHECK'
#!/usr/bin/env bash
set -Eeuo pipefail
server_hostname="$1"
panel_port="$2"
verify_tls="$3"
RAYXO_TEST_PASSWORD="$(cat)"
export RAYXO_TEST_PASSWORD

getent hosts "$server_hostname" >/dev/null || printf '127.0.1.1 %s\n' "$server_hostname" >>/etc/hosts

cd /usr/local/rayxo/current/panel
runuser -u rayxo -- env HOME=/tmp php8.4 artisan rayxo:reset-password admin --password="$RAYXO_TEST_PASSWORD" --no-ansi >/dev/null

python3 - "$server_hostname" "$panel_port" "$verify_tls" <<'PY'
import html, http.cookiejar, json, os, re, ssl, sys, urllib.parse, urllib.request

host, port, verify_tls = sys.argv[1], sys.argv[2], sys.argv[3] == "1"
base = f"https://{host}:{port}"
tls = ssl.create_default_context()
if not verify_tls:  # self-signed: the server has no public DNS
    tls.check_hostname = False
    tls.verify_mode = ssl.CERT_NONE
jar = http.cookiejar.CookieJar()
web = urllib.request.build_opener(urllib.request.HTTPSHandler(context=tls), urllib.request.HTTPCookieProcessor(jar))


def xsrf():
    return urllib.parse.unquote(next(c.value for c in jar if c.name == "XSRF-TOKEN"))


def page_of(body):
    found = re.search(r'<script data-page="app" type="application/json">(.*?)</script>', body, re.S)
    if found:
        return json.loads(found.group(1))
    found = re.search(r'data-page="([^"]*)"', body)
    if found:
        return json.loads(html.unescape(found.group(1)))
    sys.exit("the response has no Inertia page")


web.open(base + "/login", timeout=30).read()
if verify_tls:
    print(f"    {base}: certificate valid for {host}")
form = urllib.parse.urlencode({"username": "admin", "password": os.environ["RAYXO_TEST_PASSWORD"]}).encode()
response = web.open(urllib.request.Request(base + "/login", data=form, headers={"X-XSRF-TOKEN": xsrf()}), timeout=30)
page = page_of(response.read().decode())
if page["component"] != "dashboard":
    sys.exit(f"signing in led to {response.geturl()} ({page['component']}), not the dashboard")
print(f"    admin signed in: {response.geturl()}")

partial = urllib.request.Request(base + "/dashboard", headers={
    "X-Inertia": "true",
    "X-Inertia-Version": page.get("version") or "",
    "X-Inertia-Partial-Component": "dashboard",
    "X-Inertia-Partial-Data": "systemInfo",
    "X-Requested-With": "XMLHttpRequest",
    "X-XSRF-TOKEN": xsrf(),
})
info = json.loads(web.open(partial, timeout=60).read())["props"]["systemInfo"]
if info.get("error") or not info.get("data"):
    sys.exit(f"the dashboard has no server information: {info}")
data = info["data"]
print(f"    dashboard, from the agent: {data['hostname']}, {data['os']['name']} {data['os']['version']}, "
      f"{data['cpu']['cores']} CPU, {data['memory']['total_mb']} MB RAM, agent {data['agent_version']}")
PY
CHECK

as_root mkdir -p "$remote_dir"
# The package's .sha256 goes along, so setup.sh verifies it as on a real install.
files=("$package")
[[ -f "$package.sha256" ]] && files+=("$package.sha256")
copy_in "${files[@]}" "$ROOT/installer/setup.sh" "$isolation_test" "$work/check.sh" ||
    die "copying the files to the server failed"
as_root chmod 0755 "$remote_dir/isolation.test"

setup_options=(--package "$remote_dir/$(basename "$package")" --hostname "$server_hostname" --admin-email "$admin_email" --yes)
verify_tls=1
if [[ -z "$ssh_target" ]]; then
    setup_options+=(--skip-dns-check)
    verify_tls=0
fi

step "Installing RAYXO with setup.sh on $server_hostname"
# The admin password setup.sh shows once is left out of the output.
if ! as_root bash "$remote_dir/setup.sh" "${setup_options[@]}" 2>&1 |
    grep --line-buffered -v 'Password *:'; then
    die "setup.sh failed on a clean server"
fi

step "Signing in as admin and loading the dashboard"
password="Vm!7$(openssl rand -hex 12)"
printf '%s' "$password" | as_root bash "$remote_dir/check.sh" "$server_hostname" "$PANEL_PORT" "$verify_tls" ||
    die "the admin could not sign in, or the dashboard has no information from the agent"

step "Isolation test: accounts cannot read each other's files"
as_root "$remote_dir/isolation.test" -test.v 2>&1 | tail -n 25
[[ "${PIPESTATUS[0]}" -eq 0 ]] || die "the isolation test failed"

passed=1
step "Passed: a clean install works end to end"
