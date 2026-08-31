#!/usr/bin/env bash
set -euo pipefail

readonly REPOSITORY=${1:-}
readonly DEPLOYMENT_USER=${2:-}
readonly WEB_USER=${3:-www-data}
readonly HELPER_PATH=/usr/local/sbin/accounts-application-git
readonly CONFIG_PATH=/etc/accounts-application-updater.conf
readonly SUDOERS_PATH=/etc/sudoers.d/accounts-application-updater
readonly SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)

[[ $EUID -eq 0 ]] || { echo "Run this installer with sudo." >&2; exit 1; }
[[ "$REPOSITORY" == /* && -d "$REPOSITORY/.git" ]] || { echo "Provide the absolute application checkout path." >&2; exit 1; }
[[ "$REPOSITORY" =~ ^/[A-Za-z0-9._/-]+$ ]] || { echo "Repository path contains unsupported characters." >&2; exit 1; }
[[ "$DEPLOYMENT_USER" =~ ^[a-z_][a-z0-9_-]*[$]?$ ]] || { echo "Provide the deployment user that owns the checkout and GitHub credentials." >&2; exit 1; }
[[ "$WEB_USER" =~ ^[a-z_][a-z0-9_-]*[$]?$ ]] || { echo "Web user is invalid." >&2; exit 1; }
id "$DEPLOYMENT_USER" >/dev/null 2>&1 || { echo "Deployment user does not exist." >&2; exit 1; }
id "$WEB_USER" >/dev/null 2>&1 || { echo "Web user does not exist." >&2; exit 1; }
command -v runuser >/dev/null 2>&1 || { echo "runuser is required." >&2; exit 1; }
command -v visudo >/dev/null 2>&1 || { echo "visudo is required." >&2; exit 1; }

install -o root -g root -m 0755 "$SCRIPT_DIR/accounts-application-git" "$HELPER_PATH"
config_temp=$(mktemp)
sudoers_temp=$(mktemp)
trap 'rm -f "$config_temp" "$sudoers_temp"' EXIT
printf 'APPLICATION_REPOSITORY=%q\nAPPLICATION_OWNER=%q\n' "$REPOSITORY" "$DEPLOYMENT_USER" >"$config_temp"
install -o root -g root -m 0600 "$config_temp" "$CONFIG_PATH"
printf '%s ALL=(root) NOPASSWD: %s\n' "$WEB_USER" "$HELPER_PATH" >"$sudoers_temp"
visudo -cf "$sudoers_temp" >/dev/null
install -o root -g root -m 0440 "$sudoers_temp" "$SUDOERS_PATH"
visudo -cf "$SUDOERS_PATH" >/dev/null

sudo -u "$WEB_USER" sudo -n "$HELPER_PATH" --version >/dev/null
sudo -u "$WEB_USER" sudo -n "$HELPER_PATH" status --porcelain --untracked-files=normal >/dev/null
printf 'Application update helper installed for %s using deployment identity %s.\n' "$REPOSITORY" "$DEPLOYMENT_USER"
