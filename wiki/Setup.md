# Setup

## Requirements

- PHP 7.0 or later
- PDO MySQL extension
- MySQL
- Apache for production (the PHP development server is sufficient locally)
- Node.js for frontend syntax/regression tests

## Raspberry Pi production installation

The supported one-command production target is a fresh 64-bit Raspberry Pi OS Bookworm or Trixie system. Before running it:

1. Create a public DNS record for the application hostname pointing to the internet connection used by the Pi.
2. Forward inbound TCP ports 80 and 443 to the Pi. Keep working SSH access available.
3. Confirm the Pi has outbound HTTPS access and at least 2 GiB free under `/var`.
4. Do not pre-create `/var/www/accounts` or a MariaDB database named `accounts`.

Run from an interactive SSH or local terminal:

```bash
curl -fsSL https://raw.githubusercontent.com/smeird/Accounts/main/deploy.sh | sudo bash
```

Prompts are read directly from the terminal, including when the script itself arrives through a pipe. Submitted passwords are never written to the installer log. The installer generates the application database password, stores Apache environment settings in the root-owned `/etc/apache2/conf-available/accounts-env.conf`, and leaves the Git checkout free of generated configuration so Application Updates can perform its guarded fast-forward checks.

The temporary HTTP virtual host exposes only the Let's Encrypt challenge path. The application is enabled after certificate issuance, schema creation, initial-user creation and Apache validation; normal HTTP requests then redirect to HTTPS. Certificate renewal retains the challenge exception and reloads Apache after deployment.

### Installer safety and retrying

- An existing application directory or `accounts` database is never overwritten. Preserve and inspect it before attempting another installation.
- A DNS or certificate failure leaves only the challenge host active, so password login is not exposed over HTTP. Correct DNS/port forwarding and rerun the same command.
- If a later phase fails, data created by that incomplete run is removed before the script exits when it is safe to do so. The certificate and installed operating-system packages may remain and are reused on the next run.
- Detailed output is written to `/var/log/accounts-installer.log`; secrets are excluded.
- The firewall permits the SSH port detected from `sshd`, plus ports 80 and 443. Check custom network policy before installing on a machine that is not new.

After success, open the printed HTTPS URL, sign in, create a backup, and store it away from the Pi. To migrate an earlier installation, use **System → Backup & Restore** after this clean setup. PDF email delivery is not configured by the installer and requires a separate mail transport.

Useful diagnostics are:

```bash
sudo apache2ctl configtest
sudo systemctl status apache2 mariadb fail2ban
sudo ufw status
sudo certbot renew --dry-run
sudo -u www-data git -C /var/www/accounts status --short
```

## Database configuration

Accounts reads:

- `DB_HOST`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`
- optional `DB_DSN` for tests or a custom PDO connection
- optional `PASSKEY_ORIGIN` and `PASSKEY_RP_ID` when the public HTTPS origin cannot be inferred behind a reverse proxy

For Apache, keep credentials outside the repository:

```apache
<VirtualHost *:443>
    ServerName accounts.example.test
    DocumentRoot /var/www/accounts

    <Directory /var/www/accounts>
        Require all granted
        AllowOverride All
    </Directory>

    SetEnv DB_HOST localhost
    SetEnv DB_NAME accounts
    SetEnv DB_USER accounts_user
    SetEnv DB_PASS replace_this
    SetEnv PASSKEY_ORIGIN https://accounts.example.test
    SetEnv PASSKEY_RP_ID accounts.example.test
</VirtualHost>
```

Apache `SetEnv` values exist inside web requests but are not automatically present in an interactive shell. If a CLI migration needs them, export the same values securely for that command or run the equivalent reviewed SQL through the database client.

## Initial installation

```bash
git clone https://github.com/smeird/Accounts.git
cd Accounts
php php_backend/create_tables.php
php -S localhost:8000
```

Open `http://localhost:8000/` and create/sign in with the initial user flow provided by the deployment setup.

## Upgrades

1. Back up the database and any uploaded/configuration assets outside Git.
2. Update from `main` using a normal fast-forward pull.
3. Run any release-specific migration documented in the README.
4. Open **System → Database Health** and review the audit.
5. Apply only the safe structural repairs you understand, including the tag migration run, snapshot, taxonomy proposal, pattern and transaction-staging tables before using **System → Tag Rebuild Safety** or **System → Taxonomy Studio**.
6. Reload Apache/PHP as required and verify the production site.

### Passkey migration

Passkeys require HTTPS in production and the `passkeys` table. Database Health can create the missing structure, or run:

```bash
php php_backend/migrations/20260831_passkeys.php
```

Afterward, sign in with the existing password, open **System → Users**, and add the first passkey. Keep password access available as a recovery route.

Do not use `git pull --allow-unrelated-histories` to repair a deployment directory. If the directory is not descended from this repository, preserve its configuration and uploads, then deploy a clean clone.

### Transaction identity migration

Installations predating the current importer must run:

```bash
php php_backend/migrations/20260814_transaction_identity.php
```

This removes the legacy uniqueness rule that could reject legitimate matching same-day transactions while retaining per-account bank-FITID protection.

## Cache behaviour

Production Apache must allow `frontend/.htaccess`. Page shells use `no-store`; local CSS, JavaScript and JSON are revalidated. Avoid compensating for deployment problems with permanent query-string proliferation—update the shared cache policy when asset behaviour changes.

### In-application updates for a private repository

The web server should not receive a developer or deployment SSH private key. On an existing installation, first confirm that the checkout is clean and that its normal deployment user can fetch the matching branch. Then install the supplied root-owned command allowlist once:

```bash
sudo -u www-data git -C /var/www/newaccounts status --short
sudo -u ubuntu git -C /var/www/newaccounts fetch --prune origin main
sudo bash /var/www/newaccounts/scripts/install-application-update-helper.sh /var/www/newaccounts ubuntu
```

The first command must print nothing. The installer creates `/usr/local/sbin/accounts-application-git`, a root-only configuration file, and one narrow sudoers entry. The helper runs GitHub access as `ubuntu` but accepts only the exact inspection, fetch and fast-forward commands generated by **System → Application Updates**. It never accepts a repository path, remote URL, reset, checkout, arbitrary shell command or non-fast-forward merge from the browser.

After installing it, open **System → Application Updates** and run **Check now**. If either helper source changes in a future release, deploy that release normally and re-run the installer so the protected system copy receives the reviewed update.

## Testing

The main suite uses SQLite in memory:

```bash
php tests/run_tests.php
```

Run all frontend regression tests:

```bash
for test_file in frontend/js/*.test.js; do node "$test_file"; done
```

Run syntax and whitespace checks:

```bash
find . -path './vendor' -prune -o -path './node_modules' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
git diff --check
```

Check installer validation and, when ShellCheck is installed, its static analysis:

```bash
bash tests/deploy_installer_test.sh
shellcheck deploy.sh
```

UI changes also require browser verification at desktop and mobile widths. Confirm loading, populated, empty and error states; filters; chart rendering; scrolling; and absence of horizontal document overflow.
