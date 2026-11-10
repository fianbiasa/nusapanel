#!/usr/bin/env bash
#
# Build rayxo release packages for servers.
#
#   installer/build-release.sh [version]
#
# Output in dist/:
#   rayxo-<version>-linux-amd64.tar.gz (+ .sha256)
#   rayxo-<version>-linux-arm64.tar.gz (+ .sha256)
#   setup.sh
#
# The package contains everything a server needs to run the panel: Laravel
# with production dependencies already installed, the React front end already
# built, the Go agent already compiled, and phpMyAdmin. Servers never need
# Composer, Node.js or Go.
#
# Builds from the working tree, minus anything ignored by Git. Runs on macOS
# (bash 3.2) and Linux.

set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${1:-$(git -C "$ROOT" describe --tags --always --dirty 2>/dev/null || echo dev)}"
DIST="$ROOT/dist"
ARCHES="amd64 arm64"

# phpMyAdmin is pinned: a new version needs its SHA-256 from
# https://files.phpmyadmin.net/phpMyAdmin/<version>/ here too. Downloads are
# kept in a cache, so builds do not fetch it every time.
PMA_VERSION="5.2.3"
PMA_SHA256="12ba1c425fa4071abbd4e7668c9ebdeac0b0755a467a6d6d5026122bb47c102b"
PMA_NAME="phpMyAdmin-$PMA_VERSION-all-languages"
PMA_CACHE="${XDG_CACHE_HOME:-$HOME/.cache}/rayxo"

if [[ ! "$VERSION" =~ ^[0-9A-Za-z][0-9A-Za-z._-]*$ ]]; then
    echo "Invalid version: $VERSION" >&2
    exit 1
fi

for tool in git composer pnpm php go make curl; do
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

echo "==> rayxo $VERSION"

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

echo "==> Preparing phpMyAdmin $PMA_VERSION"
PMA_TARBALL="$PMA_CACHE/$PMA_NAME.tar.gz"
mkdir -p "$PMA_CACHE"
if [[ ! -f "$PMA_TARBALL" || "$(sha256 "$PMA_TARBALL")" != "$PMA_SHA256" ]]; then
    curl -fsSL --proto '=https' --tlsv1.2 --retry 3 -o "$PMA_TARBALL.part" \
        "https://files.phpmyadmin.net/phpMyAdmin/$PMA_VERSION/$PMA_NAME.tar.gz"
    mv "$PMA_TARBALL.part" "$PMA_TARBALL"
fi
if [[ "$(sha256 "$PMA_TARBALL")" != "$PMA_SHA256" ]]; then
    echo "phpMyAdmin $PMA_VERSION does not match its pinned SHA-256" >&2
    exit 1
fi

PMA="$WORK/phpmyadmin"
mkdir -p "$PMA"
tar -xzf "$PMA_TARBALL" -C "$PMA" --strip-components 1
# Not needed to run it: the setup wizard (which writes configuration), the
# examples and the development files.
rm -rf "$PMA/setup" "$PMA/examples" "$PMA/config.sample.inc.php" "$PMA/show_config_errors.php" \
    "$PMA/composer.json" "$PMA/composer.lock" "$PMA/package.json" "$PMA/yarn.lock" \
    "$PMA/babel.config.json" "$PMA/CONTRIBUTING.md"
# setup.sh writes the real configuration outside the release.
printf '%s\n' '<?php' '' '// rayxo: setup.sh writes the configuration there.' \
    "require '/etc/rayxo-pma/config.inc.php';" >"$PMA/config.inc.php"
cp "$SRC/installer/files/phpmyadmin-sso.php" "$PMA/rayxo-sso.php"

# The published setup.sh installs exactly this version by default.
stamp_setup() {
    sed -e "s/^RAYXO_VERSION=\"\"\$/RAYXO_VERSION=\"$VERSION\"/" "$SRC/installer/setup.sh" >"$1"
    chmod 0755 "$1"

    if ! grep -q "^RAYXO_VERSION=\"$VERSION\"\$" "$1"; then
        echo "Could not stamp the version into setup.sh" >&2
        exit 1
    fi
}

mkdir -p "$DIST"
stamp_setup "$DIST/setup.sh"

for arch in $ARCHES; do
    name="rayxo-$VERSION-linux-$arch"
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

    cp -R "$PMA" "$stage/phpmyadmin"
    cp "$SRC/agent/bin/linux-$arch/rayxod" "$SRC/agent/bin/linux-$arch/rayxo" "$stage/bin/"
    cp "$SRC/agent/packaging/rayxod.service" "$stage/systemd/"
    cp "$SRC"/installer/files/*.service "$SRC"/installer/files/*.timer "$stage/systemd/"
    cp "$SRC/installer/files/nginx-panel.conf" "$SRC/installer/files/nginx-hostname.conf" \
        "$SRC/installer/files/nginx-default.conf" "$SRC/installer/files/apache-rayxo.conf" \
        "$SRC/installer/files/php-fpm-panel.conf" "$SRC/installer/files/php-fpm-phpmyadmin.conf" \
        "$SRC/installer/files/nginx-phpmyadmin.conf" "$SRC/installer/files/phpmyadmin-config.inc.php" \
        "$SRC/installer/files/pure-ftpd-mysql.conf" "$SRC/installer/files/sshd-sftp.conf" \
        "$SRC/installer/files/logrotate.conf" "$stage/config/"
    stamp_setup "$stage/setup.sh"
    printf '%s\n' "$VERSION" >"$stage/VERSION"
    # AGPL-3.0 asks that the license text travel with every copy.
    cp "$SRC/LICENSE" "$stage/LICENSE"

    make_tarball "$WORK" "$name" "$DIST/$name.tar.gz"
    printf '%s  %s\n' "$(sha256 "$DIST/$name.tar.gz")" "$name.tar.gz" >"$DIST/$name.tar.gz.sha256"
done

echo "==> Done"
ls -lh "$DIST"
