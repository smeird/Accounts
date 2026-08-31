#!/usr/bin/env bash
set -Eeuo pipefail

# Accounts production installer for 64-bit Raspberry Pi OS (Bookworm/Trixie).
# Designed to run directly from curl, so prompts read from the controlling TTY.

readonly REPO_URL="https://github.com/smeird/Accounts.git"
readonly REPO_BRANCH="main"
readonly INSTALL_DIR="/var/www/accounts"
readonly DB_NAME="accounts"
readonly DB_USER="accounts_app"
readonly ENV_CONF="/etc/apache2/conf-available/accounts-env.conf"
readonly SITE_CONF="/etc/apache2/sites-available/accounts.conf"
readonly ACME_SITE_CONF="/etc/apache2/sites-available/accounts-acme.conf"
readonly ACME_ROOT="/var/lib/letsencrypt"
readonly LOG_FILE="/var/log/accounts-installer.log"
readonly MIN_FREE_KB=2097152

CURRENT_PHASE="startup checks"
STAGING_DIR=""
DB_CREATED=0
INSTALL_MOVED=0
TTY_PATH="/dev/tty"

log() {
    printf '[Accounts] %s\n' "$*" | tee -a "$LOG_FILE"
}

fail() {
    printf '[Accounts] ERROR: %s\n' "$*" >&2
    exit 1
}

cleanup_on_error() {
    local exit_code=$?
    if (( exit_code == 0 )); then
        return
    fi

    set +e
    if (( INSTALL_MOVED == 1 )) && [[ -d "$INSTALL_DIR/.git" ]]; then
        a2dissite accounts >/dev/null 2>&1
        a2ensite accounts-acme >/dev/null 2>&1
        systemctl reload apache2 >/dev/null 2>&1
        find "$INSTALL_DIR" -mindepth 1 -delete >/dev/null 2>&1
        rmdir "$INSTALL_DIR" >/dev/null 2>&1
        INSTALL_MOVED=0
    fi
    if (( DB_CREATED == 1 )) && command -v mariadb >/dev/null 2>&1; then
        mariadb --protocol=socket -e "DROP DATABASE IF EXISTS \`${DB_NAME}\`; DROP USER IF EXISTS '${DB_USER}'@'localhost'; FLUSH PRIVILEGES;" >/dev/null 2>&1
    fi
    if [[ -n "$STAGING_DIR" && -d "$STAGING_DIR" && "$STAGING_DIR" == /var/www/.accounts-install.* ]]; then
        find "$STAGING_DIR" -mindepth 1 -delete >/dev/null 2>&1
        rmdir "$STAGING_DIR" >/dev/null 2>&1
    fi

    printf '\n[Accounts] Installation stopped during: %s\n' "$CURRENT_PHASE" >&2
    printf '[Accounts] The application was not enabled over HTTP. Review %s, correct the problem, and rerun the same curl command.\n' "$LOG_FILE" >&2
    exit "$exit_code"
}
trap cleanup_on_error EXIT

is_valid_domain() {
    local domain=${1:-}
    [[ ${#domain} -le 253 ]] || return 1
    [[ "$domain" == *.* ]] || return 1
    [[ "$domain" =~ ^([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?$ ]]
}

is_valid_email() {
    local email=${1:-}
    [[ "$email" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]]
}

is_valid_admin() {
    local username=${1:-}
    [[ "$username" =~ ^[A-Za-z0-9_.-]{1,100}$ ]]
}

is_valid_password() {
    local password=${1:-}
    (( ${#password} >= 12 )) && [[ "$password" != *$'\n'* && "$password" != *$'\r'* ]]
}

passwords_match() {
    [[ ${1:-} == "${2:-}" ]]
}

self_test() {
    local failures=0
    is_valid_domain "accounts.example.com" || failures=$((failures + 1))
    ! is_valid_domain "localhost" || failures=$((failures + 1))
    ! is_valid_domain "bad_domain.example" || failures=$((failures + 1))
    is_valid_email "admin@example.com" || failures=$((failures + 1))
    ! is_valid_email "admin.example.com" || failures=$((failures + 1))
    is_valid_admin "finance-admin" || failures=$((failures + 1))
    ! is_valid_admin "finance admin" || failures=$((failures + 1))
    is_valid_password "twelve-chars!" || failures=$((failures + 1))
    ! is_valid_password "too-short" || failures=$((failures + 1))
    passwords_match "matching-password" "matching-password" || failures=$((failures + 1))
    ! passwords_match "matching-password" "different-password" || failures=$((failures + 1))
    if (( failures > 0 )); then
        printf 'Installer self-test failed: %d assertion(s)\n' "$failures" >&2
        return 1
    fi
    printf 'Installer self-test passed.\n'
}

if [[ ${1:-} == "--self-test" ]]; then
    trap - EXIT
    self_test
    exit
fi

prompt_value() {
    local prompt=$1 result
    printf '%s' "$prompt" >"$TTY_PATH"
    IFS= read -r result <"$TTY_PATH"
    printf '%s' "$result"
}

prompt_secret() {
    local prompt=$1 result
    printf '%s' "$prompt" >"$TTY_PATH"
    IFS= read -r -s result <"$TTY_PATH"
    printf '\n' >"$TTY_PATH"
    printf '%s' "$result"
}

require_confirmation() {
    local answer
    answer=$(prompt_value "$1 [type YES]: ")
    [[ "$answer" == "YES" ]] || fail "Confirmation was not provided. No application data was created."
}

write_file() {
    local path=$1 mode=$2 content=$3 temp
    temp=$(mktemp)
    printf '%s\n' "$content" >"$temp"
    install -o root -g root -m "$mode" "$temp" "$path"
    rm -f "$temp"
}

if (( EUID != 0 )); then
    fail "Run as root: curl -fsSL https://raw.githubusercontent.com/smeird/Accounts/main/deploy.sh | sudo bash"
fi

umask 077
touch "$LOG_FILE"
chmod 600 "$LOG_FILE"
[[ -r "$TTY_PATH" && -w "$TTY_PATH" ]] || fail "An interactive terminal is required. Run the curl command from SSH or a local terminal."
[[ ! -e "$INSTALL_DIR" ]] || fail "$INSTALL_DIR already exists. Preserve or remove it manually after confirming it contains no needed data."

CURRENT_PHASE="platform validation"
ARCH=$(dpkg --print-architecture 2>/dev/null || uname -m)
[[ "$ARCH" == "arm64" || "$ARCH" == "aarch64" ]] || fail "64-bit Raspberry Pi OS is required (detected: $ARCH)."
[[ -r /etc/os-release ]] || fail "Cannot identify the operating system."
# shellcheck disable=SC1091
source /etc/os-release
CODENAME=${VERSION_CODENAME:-}
[[ "$CODENAME" == "bookworm" || "$CODENAME" == "trixie" ]] || fail "Raspberry Pi OS Bookworm or Trixie is required (detected: ${CODENAME:-unknown})."
if [[ -r /proc/device-tree/model ]]; then
    MODEL=$(tr -d '\0' </proc/device-tree/model)
    [[ "$MODEL" == *"Raspberry Pi"* ]] || fail "This does not appear to be Raspberry Pi hardware (detected: $MODEL)."
elif [[ ${ID:-} != "raspbian" && ${ID:-} != "debian" ]]; then
    fail "This does not appear to be Raspberry Pi OS."
fi
FREE_KB=$(df -Pk /var | awk 'NR==2 {print $4}')
[[ "$FREE_KB" =~ ^[0-9]+$ ]] || fail "Could not determine free disk space under /var."
(( FREE_KB >= MIN_FREE_KB )) || fail "At least 2 GiB of free space under /var is required."

CURRENT_PHASE="interactive configuration"
while true; do
    DOMAIN=$(prompt_value "Public domain (for example accounts.example.com): ")
    DOMAIN=${DOMAIN,,}
    is_valid_domain "$DOMAIN" && break
    printf 'Enter a fully qualified DNS hostname, not an IP address or localhost.\n' >"$TTY_PATH"
done
while true; do
    CERT_EMAIL=$(prompt_value "Let’s Encrypt contact email: ")
    is_valid_email "$CERT_EMAIL" && break
    printf 'Enter a valid email address.\n' >"$TTY_PATH"
done
while true; do
    ADMIN_USER=$(prompt_value "Initial administrator username: ")
    is_valid_admin "$ADMIN_USER" && break
    printf 'Use 1-100 letters, numbers, dots, underscores, or hyphens.\n' >"$TTY_PATH"
done
while true; do
    ADMIN_PASS=$(prompt_secret "Initial administrator password (minimum 12 characters): ")
    is_valid_password "$ADMIN_PASS" || { printf 'The password must contain at least 12 characters.\n' >"$TTY_PATH"; continue; }
    ADMIN_PASS_CONFIRM=$(prompt_secret "Confirm administrator password: ")
    passwords_match "$ADMIN_PASS" "$ADMIN_PASS_CONFIRM" && break
    printf 'Passwords did not match. Try again.\n' >"$TTY_PATH"
done
unset ADMIN_PASS_CONFIRM
printf '\nBefore continuing, ensure:\n  - %s resolves publicly to this connection\n  - TCP ports 80 and 443 reach this Raspberry Pi\n  - SSH access is working\n\n' "$DOMAIN" >"$TTY_PATH"
require_confirmation "DNS and port forwarding are ready"

CURRENT_PHASE="network preflight"
curl -fsSI --connect-timeout 10 https://github.com/ >/dev/null || fail "GitHub is unreachable over HTTPS."
curl -fsS --connect-timeout 10 https://acme-v02.api.letsencrypt.org/directory >/dev/null || fail "Let’s Encrypt is unreachable over HTTPS."

CURRENT_PHASE="operating-system packages"
log "Updating Raspberry Pi OS packages. This can take several minutes."
export DEBIAN_FRONTEND=noninteractive
apt-get update >>"$LOG_FILE" 2>&1
apt-get -y full-upgrade >>"$LOG_FILE" 2>&1
apt-get install -y \
    apache2 mariadb-server git curl ca-certificates openssl certbot \
    ufw fail2ban unattended-upgrades \
    libapache2-mod-php php-cli php-common php-mysql php-curl php-mbstring php-zip php-xml \
    >>"$LOG_FILE" 2>&1

CURRENT_PHASE="host security"
systemctl enable --now mariadb apache2 fail2ban >/dev/null
a2enmod env headers expires rewrite ssl >/dev/null
SSH_PORT=$( { command -v sshd >/dev/null 2>&1 && sshd -T 2>/dev/null || true; } | awk '$1 == "port" { print $2; exit }')
SSH_PORT=${SSH_PORT:-22}
ufw default deny incoming >>"$LOG_FILE"
ufw default allow outgoing >>"$LOG_FILE"
ufw allow "${SSH_PORT}/tcp" comment 'SSH' >>"$LOG_FILE"
ufw allow 80/tcp comment 'HTTP / ACME' >>"$LOG_FILE"
ufw allow 443/tcp comment 'Accounts HTTPS' >>"$LOG_FILE"
ufw --force enable >>"$LOG_FILE"
write_file /etc/fail2ban/jail.d/accounts-ssh.local 0644 "[sshd]
enabled = true"
systemctl restart fail2ban
write_file /etc/mysql/mariadb.conf.d/60-accounts.cnf 0644 '[mysqld]
bind-address = 127.0.0.1
skip-name-resolve'
systemctl restart mariadb
write_file /etc/apt/apt.conf.d/52accounts-unattended-upgrades 0644 'APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";'
write_file /etc/apache2/conf-available/accounts-hardening.conf 0644 'ServerTokens Prod
ServerSignature Off
TraceEnable Off'
a2enconf accounts-hardening >/dev/null

CURRENT_PHASE="TLS certificate"
install -d -o www-data -g www-data -m 0755 "$ACME_ROOT/.well-known/acme-challenge"
write_file "$ACME_SITE_CONF" 0644 "<VirtualHost *:80>
    ServerName ${DOMAIN}
    DocumentRoot ${ACME_ROOT}
    <Directory ${ACME_ROOT}>
        AllowOverride None
        Options None
        Require all granted
    </Directory>
    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/\\.well-known/acme-challenge/
    RewriteRule ^ - [R=503,L]
</VirtualHost>"
a2dissite 000-default >/dev/null 2>&1 || true
a2ensite accounts-acme >/dev/null
apache2ctl configtest >>"$LOG_FILE" 2>&1
systemctl reload apache2
log "Requesting the HTTPS certificate for $DOMAIN."
certbot certonly --webroot -w "$ACME_ROOT" -d "$DOMAIN" \
    --email "$CERT_EMAIL" --agree-tos --no-eff-email --non-interactive \
    >>"$LOG_FILE" 2>&1 || fail "Let’s Encrypt validation failed. The application remains unavailable; verify DNS and ports 80/443."
write_file /etc/letsencrypt/renewal-hooks/deploy/reload-accounts-apache 0755 '#!/bin/sh
systemctl reload apache2'

CURRENT_PHASE="database provisioning"
if mariadb --protocol=socket -NBe "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME='${DB_NAME}'" | grep -qx "$DB_NAME"; then
    fail "Database '$DB_NAME' already exists. It has not been modified."
fi
DB_PASS=$(openssl rand -hex 24)
HOST_SQL=$(hostname | tr -cd 'A-Za-z0-9_.-')
DB_CREATED=1
mariadb --protocol=socket <<SQL
DROP USER IF EXISTS ''@'localhost';
DROP USER IF EXISTS ''@'${HOST_SQL}';
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db = 'test' OR Db LIKE 'test\\_%';
CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

CURRENT_PHASE="application checkout"
STAGING_DIR=$(mktemp -d /var/www/.accounts-install.XXXXXX)
chown www-data:www-data "$STAGING_DIR"
runuser -u www-data -- git clone --branch "$REPO_BRANCH" --single-branch "$REPO_URL" "$STAGING_DIR" >>"$LOG_FILE" 2>&1
[[ ! -e "$STAGING_DIR/.env" ]] || fail "The repository unexpectedly contains a .env file."
write_file "$ENV_CONF" 0640 "SetEnv DB_HOST localhost
SetEnv DB_NAME ${DB_NAME}
SetEnv DB_USER ${DB_USER}
SetEnv DB_PASS ${DB_PASS}
SetEnv PASSKEY_ORIGIN https://${DOMAIN}
SetEnv PASSKEY_RP_ID ${DOMAIN}"
chgrp www-data "$ENV_CONF"
a2enconf accounts-env >/dev/null

export DB_HOST=localhost DB_NAME DB_USER DB_PASS
php "$STAGING_DIR/php_backend/create_tables.php" >>"$LOG_FILE" 2>&1
export ADMIN_USER ADMIN_PASS
php -r 'require $argv[1]; User::create(getenv("ADMIN_USER"), getenv("ADMIN_PASS"));' \
    "$STAGING_DIR/php_backend/models/User.php" >>"$LOG_FILE" 2>&1
unset ADMIN_PASS
install -d -o www-data -g www-data -m 0750 "$STAGING_DIR/php_backend/uploads"
chown -R www-data:www-data "$STAGING_DIR"
[[ -z $(runuser -u www-data -- git -C "$STAGING_DIR" status --porcelain --untracked-files=normal) ]] || fail "The new checkout is unexpectedly dirty."

CURRENT_PHASE="Apache application configuration"
PHP_APACHE_DIR=$(find /etc/php -mindepth 2 -maxdepth 2 -type d -path '*/apache2' | sort -V | tail -n 1)
[[ -n "$PHP_APACHE_DIR" ]] || fail "Could not locate the Apache PHP configuration directory."
write_file "$PHP_APACHE_DIR/conf.d/99-accounts.ini" 0644 'upload_max_filesize = 128M
post_max_size = 132M
memory_limit = 256M
max_execution_time = 120
session.cookie_httponly = 1
session.cookie_secure = 1
session.cookie_samesite = Lax
expose_php = Off'
write_file "$SITE_CONF" 0644 "<VirtualHost *:80>
    ServerName ${DOMAIN}
    Alias /.well-known/acme-challenge/ ${ACME_ROOT}/.well-known/acme-challenge/
    <Directory ${ACME_ROOT}/.well-known/acme-challenge>
        AllowOverride None
        Options None
        Require all granted
    </Directory>
    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/\\.well-known/acme-challenge/
    RewriteRule ^ https://${DOMAIN}%{REQUEST_URI} [R=301,L,NE]
</VirtualHost>

<VirtualHost *:443>
    ServerName ${DOMAIN}
    DocumentRoot ${INSTALL_DIR}
    DirectoryIndex index.php
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/${DOMAIN}/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/${DOMAIN}/privkey.pem
    <Directory ${INSTALL_DIR}>
        AllowOverride All
        Options -Indexes +FollowSymLinks
        Require all granted
    </Directory>
    Header always set Strict-Transport-Security \"max-age=31536000; includeSubDomains\"
    Header always set X-Content-Type-Options \"nosniff\"
    Header always set X-Frame-Options \"SAMEORIGIN\"
    Header always set Referrer-Policy \"strict-origin-when-cross-origin\"
    ErrorLog \${APACHE_LOG_DIR}/accounts-error.log
    CustomLog \${APACHE_LOG_DIR}/accounts-access.log combined
</VirtualHost>"

mv "$STAGING_DIR" "$INSTALL_DIR"
STAGING_DIR=""
INSTALL_MOVED=1
a2dissite accounts-acme >/dev/null
a2ensite accounts >/dev/null
apache2ctl configtest >>"$LOG_FILE" 2>&1
systemctl reload apache2

CURRENT_PHASE="installation verification"
for module in pdo_mysql curl mbstring zip xml openssl json session; do
    php -m | grep -Eiq "^${module}$" || fail "Required PHP module is missing: $module"
done
mariadb --protocol=socket -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -NBe "SELECT 1 FROM users WHERE username='${ADMIN_USER}' LIMIT 1" | grep -qx 1 \
    || fail "The initial user or database connection could not be verified."
runuser -u www-data -- git -c safe.directory="$INSTALL_DIR" -C "$INSTALL_DIR" fetch --prune origin "$REPO_BRANCH" >>"$LOG_FILE" 2>&1
[[ -z $(runuser -u www-data -- git -c safe.directory="$INSTALL_DIR" -C "$INSTALL_DIR" status --porcelain --untracked-files=normal) ]] \
    || fail "The deployed checkout is dirty, so in-app updates would be blocked."

HTTPS_HEADERS=$(curl -fsSI --resolve "${DOMAIN}:443:127.0.0.1" "https://${DOMAIN}/")
grep -Eiq '^HTTP/.* 200' <<<"$HTTPS_HEADERS" || fail "The HTTPS login page did not return HTTP 200."
grep -Eiq '^set-cookie:.*secure' <<<"$HTTPS_HEADERS" || fail "The login page did not issue a secure session cookie."
HTTP_HEADERS=$(curl -sSI --resolve "${DOMAIN}:80:127.0.0.1" "http://${DOMAIN}/")
grep -Eiq '^location: https://' <<<"$HTTP_HEADERS" || fail "HTTP is not redirecting to HTTPS."
API_STATUS=$(curl -sS -o /dev/null -w '%{http_code}' --resolve "${DOMAIN}:443:127.0.0.1" "https://${DOMAIN}/php_backend/public/current_user.php")
[[ "$API_STATUS" == "401" ]] || fail "The unauthenticated API check returned HTTP $API_STATUS instead of 401."
certbot renew --dry-run >>"$LOG_FILE" 2>&1 || fail "Certificate renewal dry-run failed."
systemctl is-active --quiet apache2 mariadb fail2ban || fail "One or more required services are not active."
ufw status | grep -q 'Status: active' || fail "UFW is not active."

COMMIT=$(runuser -u www-data -- git -C "$INSTALL_DIR" rev-parse --short=7 HEAD)
trap - EXIT
printf '\nAccounts installation complete.\n'
printf 'URL: https://%s/\n' "$DOMAIN"
printf 'Administrator: %s\n' "$ADMIN_USER"
printf 'Installed commit: %s\n' "$COMMIT"
printf 'Installer log: %s (contains no submitted passwords)\n' "$LOG_FILE"
printf 'Next: sign in, create an encrypted backup, and store it away from this Raspberry Pi.\n'
if [[ -f /var/run/reboot-required ]]; then
    printf 'A system reboot is required: sudo reboot\n'
fi
