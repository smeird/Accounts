#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
INSTALLER="$ROOT_DIR/deploy.sh"
FAILURES=0

assert_contains() {
    local needle=$1
    if ! grep -Fq -- "$needle" "$INSTALLER"; then
        printf 'FAIL: installer is missing required text: %s\n' "$needle" >&2
        FAILURES=$((FAILURES + 1))
    fi
}

assert_not_contains() {
    local needle=$1
    if grep -Fq -- "$needle" "$INSTALLER"; then
        printf 'FAIL: installer contains forbidden text: %s\n' "$needle" >&2
        FAILURES=$((FAILURES + 1))
    fi
}

bash -n "$INSTALLER"
bash "$INSTALLER" --self-test

assert_contains 'readonly INSTALL_DIR="/var/www/accounts"'
assert_contains 'readonly ENV_CONF="/etc/apache2/conf-available/accounts-env.conf"'
assert_contains 'libapache2-mod-php php-cli php-common php-mysql php-curl php-mbstring php-zip php-xml'
assert_contains 'bind-address = 127.0.0.1'
assert_contains 'PASSKEY_ORIGIN https://${DOMAIN}'
assert_contains 'RewriteCond %{REQUEST_URI} !^/\\.well-known/acme-challenge/'
assert_contains 'certbot renew --dry-run'
assert_contains 'status --porcelain --untracked-files=normal'
assert_not_contains 'cat > .env'
assert_not_contains 'DB_PASS=replace'

if ! grep -Fq 'php_backend/uploads/' "$ROOT_DIR/.gitignore"; then
    printf 'FAIL: runtime uploads are not ignored by Git.\n' >&2
    FAILURES=$((FAILURES + 1))
fi

if (( EUID != 0 )); then
    ROOT_OUTPUT=$(bash "$INSTALLER" 2>&1 || true)
    if [[ "$ROOT_OUTPUT" != *"Run as root"* ]]; then
        printf 'FAIL: non-root execution was not rejected before provisioning.\n' >&2
        FAILURES=$((FAILURES + 1))
    fi
fi

if (( FAILURES > 0 )); then
    printf 'Installer regression tests failed: %d\n' "$FAILURES" >&2
    exit 1
fi

printf 'Installer regression tests passed.\n'
