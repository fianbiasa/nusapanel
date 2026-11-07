#!/usr/bin/env bash
#
# Build nusapanel release packages for servers.
#
#   installer/build-release.sh [version]
#
# Output in dist/:
#   nusapanel-<version>-linux-amd64.tar.gz (+ .sha256)
#   nusapanel-<version>-linux-arm64.tar.gz (+ .sha256)
#   setup.sh
#
# The package contains everything a server needs to run the panel: Laravel
# with production dependencies already installed, the React front end already
# built, and the Go agent already compiled. Servers never need Composer,
# Node.js or Go.
#
# Builds from the working tree, minus anything ignored by Git. Runs on macOS
# (bash 3.2) and Linux.

set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${1:-$(git -C "$ROOT" describe --tags --always --dirty 2>/dev/null || echo dev)}"
DIST="$ROOT/dist"
ARCHES="amd64 arm64"

if [[ ! "$VERSION" =~ ^[0-9A-Za-z][0-9A-Za-z._-]*$ ]]; then
    echo "Invalid version: $VERSION" >&2
    exit 1
fi

for tool in git composer pnpm php go make; do
    command -v "$tool" >/dev/null || { echo "Missing required tool: $tool" >&2; exit 1; }
done

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

sha256() {
    if command -v sha256sum >/dev/null; then
        sha256sum "$1" | awk '{print $1}'
    else
        shasum -a 256 "$1" | awk '{print $1}'
    fi
}

# Archive as root:root without macOS metadata, so extracting on a server never
# assigns files to whatever user happens to have the build machine's UID.
make_tarball() {
    local source_dir="$1" name="$2" output="$3"

    if tar --version 2>/dev/null | grep -q bsdtar; then
        COPYFILE_DISABLE=1 tar --uid 0 --gid 0 --uname root --gname root --no-mac-metadata --no-xattrs \
            -czf "$output" -C "$source_dir" "$name"
    else
        tar --owner=0 --group=0 --numeric-owner -czf "$output" -C "$source_dir" "$name"
    fi
}

echo "==> nusapanel $VERSION"

echo "==> Copying the working tree (without Git-ignored files)"
SRC="$WORK/src"
mkdir -p "$SRC"
(
    cd "$ROOT"
    git ls-files -z --cached --others --exclude-standard |
        while IFS= read -r -d '' file; do
            if [[ -e "$file" ]]; then printf '%s\0' "$file"; fi
        done |
        tar --null -T - -cf - |
        tar -xf - -C "$SRC"
)

echo "==> Building the front end"
(
    cd "$SRC"
    composer install --no-interaction --no-progress --quiet
    cp .env.example .env
    php artisan key:generate --no-interaction --quiet
    pnpm install --frozen-lockfile --silent
    pnpm run build >/dev/null
)

echo "==> Installing production PHP dependencies"
(
    cd "$SRC"
    composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet
    rm -f bootstrap/cache/*.php
)

echo "==> Compiling the agent"
(
    cd "$SRC/agent"
    make linux VERSION="$VERSION" >/dev/null
)

# The published setup.sh installs exactly this version by default.
stamp_setup() {
    sed -e "s/^NUSAPANEL_VERSION=\"\"\$/NUSAPANEL_VERSION=\"$VERSION\"/" "$SRC/installer/setup.sh" >"$1"
    chmod 0755 "$1"

    if ! grep -q "^NUSAPANEL_VERSION=\"$VERSION\"\$" "$1"; then
        echo "Could not stamp the version into setup.sh" >&2
        exit 1
    fi
}

mkdir -p "$DIST"
stamp_setup "$DIST/setup.sh"

for arch in $ARCHES; do
    name="nusapanel-$VERSION-linux-$arch"
    stage="$WORK/$name"
    echo "==> Packaging $name"

    mkdir -p "$stage/panel" "$stage/bin" "$stage/systemd" "$stage/config"

    # Only what the panel needs at runtime: no tests, sources for the front
    # end, seeders, local .env, storage contents or development tooling.
    for path in app artisan bootstrap composer.json composer.lock config routes vendor; do
        cp -R "$SRC/$path" "$stage/panel/"
    done
    mkdir -p "$stage/panel/database" "$stage/panel/resources"
    cp -R "$SRC/database/migrations" "$stage/panel/database/"
    cp -R "$SRC/resources/views" "$stage/panel/resources/"
    cp -R "$SRC/public" "$stage/panel/"
    rm -f "$stage/panel/public/hot"

    cp "$SRC/agent/bin/linux-$arch/nusad" "$SRC/agent/bin/linux-$arch/nusactl" "$stage/bin/"
    cp "$SRC/agent/packaging/nusad.service" "$stage/systemd/"
    cp "$SRC"/installer/files/*.service "$SRC"/installer/files/*.timer "$stage/systemd/"
    cp "$SRC/installer/files/nginx-panel.conf" "$SRC/installer/files/nginx-hostname.conf" \
        "$SRC/installer/files/nginx-default.conf" "$SRC/installer/files/apache-nusapanel.conf" \
        "$SRC/installer/files/php-fpm-panel.conf" \
        "$SRC/installer/files/logrotate.conf" "$stage/config/"
    stamp_setup "$stage/setup.sh"
    printf '%s\n' "$VERSION" >"$stage/VERSION"

    make_tarball "$WORK" "$name" "$DIST/$name.tar.gz"
    printf '%s  %s\n' "$(sha256 "$DIST/$name.tar.gz")" "$name.tar.gz" >"$DIST/$name.tar.gz.sha256"
done

echo "==> Done"
ls -lh "$DIST"
