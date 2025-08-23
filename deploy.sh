#!/usr/bin/env bash
set -euo pipefail

REPO_URL="https://github.com/smeird/Accounts.git"

for cmd in git php mysql; do
  if ! command -v "$cmd" >/dev/null 2>&1; then
    echo "Error: $cmd is required but not installed." >&2
    exit 1
  fi
done

default_dir="$PWD/accounts"
read -p "Installation directory [$default_dir]: " INSTALL_DIR
INSTALL_DIR="${INSTALL_DIR:-$default_dir}"

if [ -d "$INSTALL_DIR/.git" ]; then
  echo "Directory already contains a git repository." >&2
  exit 1
fi

echo "Cloning repository..."
git clone "$REPO_URL" "$INSTALL_DIR"
cd "$INSTALL_DIR"

echo "Database configuration"
read -p "MySQL host [localhost]: " DB_HOST
DB_HOST=${DB_HOST:-localhost}
read -p "Database name [accounts]: " DB_NAME
DB_NAME=${DB_NAME:-accounts}
read -p "Database user [accounts]: " DB_USER
DB_USER=${DB_USER:-accounts}
read -s -p "Database password: " DB_PASS; echo

read -p "MySQL root user [root]: " MYSQL_ROOT
MYSQL_ROOT=${MYSQL_ROOT:-root}
read -s -p "MySQL root password: " MYSQL_ROOT_PASS; echo

echo "Creating database and user..."
mysql -h "$DB_HOST" -u "$MYSQL_ROOT" -p"$MYSQL_ROOT_PASS" <<MYSQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`;
CREATE USER IF NOT EXISTS '$DB_USER'@'%' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'%';
FLUSH PRIVILEGES;
MYSQL

cat > .env <<ENV
DB_HOST=$DB_HOST
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASS=$DB_PASS
ENV

export DB_HOST DB_NAME DB_USER DB_PASS

echo "Creating database tables..."
php php_backend/create_tables.php

read -p "Initial application username: " ADMIN_USER
read -s -p "Initial password: " ADMIN_PASS; echo

export ADMIN_USER ADMIN_PASS
php <<'PHP'
<?php
require 'php_backend/models/User.php';
$id = User::create(getenv('ADMIN_USER'), getenv('ADMIN_PASS'));
// Use stderr? but simply echo
echo "Created user with ID: $id\n";
?>
PHP

echo "Deployment complete."
echo "To start the server, run:"
echo "  cd '$INSTALL_DIR' && source .env && php -S localhost:8000"
