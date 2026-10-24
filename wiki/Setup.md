# Setup

## Requirements

- PHP 8.5 or later
- PDO PostgreSQL extension
- PostgreSQL
- Apache for the automated Pi installer; manually configured Apache or Nginx for production (the PHP development server is local-only)
- cURL, mbstring, ZIP, XML, OpenSSL, JSON, sessions and zlib in PHP; `pdo_sqlite` for the isolated tests
- Node.js/npm only for development asset builds and JavaScript tests; committed assets need no production build

Use the [README quick install](../README.md#simple-raspberry-pi-installation) for the short path. This page supplies configuration, recovery and verification details.

## Raspberry Pi production installation

The supported one-command production target is a fresh 64-bit Raspberry Pi OS Bookworm or Trixie system. Before running it:

1. Create a public DNS record for the application hostname pointing to the internet connection used by the Pi.
2. Forward inbound TCP ports 80 and 443 to the Pi. Keep working SSH access available.
3. Confirm the Pi has outbound HTTPS access and at least 2 GiB free under `/var`.
4. Do not pre-create `/var/www/accounts`, a PostgreSQL database named `accounts`, or its role `accounts_app`. The installer refuses these existing resources.
5. Use a fresh host: the script runs a full OS package upgrade and configures Apache, UFW, fail2ban and unattended security updates. It installs PHP 8.5 from the Sury package repository. Package and certificate services must be reachable.

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

After success, open the printed HTTPS URL, sign in with the user created by the installer, check **System → Database Health**, create a backup, and store it away from the Pi. Backups are gzip-compressed JSON, not encrypted; use secure or encrypted off-device storage. Reboot if the installer reports it is required. To migrate an earlier installation, use **System → Backup & Restore** after this clean setup. PDF email delivery is not configured by the installer and requires a separate mail transport.

Useful diagnostics are:

```bash
sudo apache2ctl configtest
sudo systemctl status apache2 postgresql fail2ban
sudo ufw status
sudo certbot renew --dry-run
sudo -u www-data git -C /var/www/accounts status --short
```

## Database configuration

Accounts reads:

- `DB_HOST`
- `DB_PORT` (normally `5432`)
- `DB_NAME`
- `DB_USER`
- `DB_PASS`
- `DB_SSLMODE` (defaults to `prefer`)
- `DB_DSN=sqlite::memory:` for tests only; a PostgreSQL PDO DSN may override the production connection fields. MySQL is not a supported runtime.
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
    SetEnv DB_PORT 5432
    SetEnv DB_NAME accounts
    SetEnv DB_USER accounts_user
    SetEnv DB_PASS replace_this
    SetEnv DB_SSLMODE prefer
    SetEnv PASSKEY_ORIGIN https://accounts.example.test
    SetEnv PASSKEY_RP_ID accounts.example.test
</VirtualHost>
```

Apache `SetEnv` values exist inside web requests but are not automatically present in an interactive shell. If a CLI migration needs them, export the same values securely for that command or run the equivalent reviewed SQL through the database client.

## Migrating an existing MySQL installation

Use an application-level logical backup, never a data-directory conversion. Current `main` uses PostgreSQL-specific SQL: do not upgrade the source MySQL checkout or point current code at MySQL to produce the export.

1. While the old MySQL-compatible application is still running, create a complete backup using its **Backup & Restore** page. Stop further writes and take the final export before switching over. Preserve its code, database and configuration until verification succeeds.
2. Create a separate fresh PostgreSQL installation through the Pi installer or the manual instructions below. Do not reset or reuse the source database.
3. Restore the application backup into the new installation through **System → Backup & Restore**. Current restore also supports a local CLI file when the destination PostgreSQL environment is configured:

   ```bash
   php php_backend/public/restore.php /secure/path/accounts-final.json.gz
   ```

4. Legacy JSON backups without version metadata are accepted; versioned backups must have the recognised application format and version no greater than 8. Older backups may omit newer feature sections. Validate the actual export on a disposable destination first; an arbitrary MySQL SQL dump is not an application backup.
5. Check Database Health, users, account totals and balance dates, transaction counts and amounts, transfer/IGNORE exclusions, taxonomy, projects, budgets and any saved planners. Keep passwords available as recovery and verify passkeys separately; their original HTTPS hostname/RP identity must remain valid.
6. Move traffic only after those checks. Keep the old installation offline but recoverable and retain the export off-device. Do not allow writes to both copies.

## Manual installation and local development

Install PostgreSQL and PHP 8.5 with the modules listed above. The following database-creation commands assume a Debian-style PostgreSQL administrator named `postgres`; adapt the administrator command for your platform:

```bash
sudo -u postgres createuser --pwprompt accounts_app
sudo -u postgres createdb --owner=accounts_app accounts

git clone https://github.com/smeird/Accounts.git
cd Accounts
export DB_HOST=localhost DB_PORT=5432 DB_NAME=accounts DB_USER=accounts_app DB_SSLMODE=prefer
unset DB_DSN
printf 'Database password: '
IFS= read -r -s DB_PASS
printf '\n'
export DB_PASS
```

These shell exports are for this terminal only. For production, configure the same connection for the web server separately, outside the checkout. Confirm the destination is new and empty before running the next command: **it drops and recreates all application tables**. Use Database Health for an existing installation.

```bash
php php_backend/create_tables.php
```

Create the first user explicitly; `index.php` is a sign-in page, not a registration wizard. From the repository root, in the same terminal:

```bash
printf 'Initial username: '
IFS= read -r ACCOUNTS_ADMIN_USER
printf 'Initial password (use at least 12 characters): '
IFS= read -r -s ACCOUNTS_ADMIN_PASSWORD
printf '\n'
export ACCOUNTS_ADMIN_USER ACCOUNTS_ADMIN_PASSWORD
php -r 'require "php_backend/models/User.php"; User::create(getenv("ACCOUNTS_ADMIN_USER"), getenv("ACCOUNTS_ADMIN_PASSWORD"));'
unset ACCOUNTS_ADMIN_USER ACCOUNTS_ADMIN_PASSWORD
php -S localhost:8000
```

Open `http://localhost:8000/` and sign in. Do not open frontend files directly from disk. Localhost is the development exception for passkeys; production needs trusted HTTPS. Add further users under **System → Users**. The development server is not a production deployment.

For production Apache, the virtual-host fragment above illustrates environment and directory settings only: it is not a complete TLS configuration. Provision a trusted certificate, enable the required modules, set the PHP upload limits for your statements/backups, and validate the host before exposing it. The Pi installer handles this for its supported target. For Nginx retain a working PHP-FPM handler and apply the cache policy below. Both CLI and web PHP must be 8.5+.

## Upgrades

1. Back up the database and any uploaded/configuration assets outside Git.
2. Prefer **System → Application Updates**: check, review the offered change, then install. It fetches and rechecks before a clean fast-forward of the currently checked-out branch. Keep production on `main`. For a manual deployment, fetch `origin`, verify the checkout is clean, and merge `origin/main` with `--ff-only`; never reset over local changes.
3. Run any release-specific migration documented in the README.
4. Open **System → Database Health** and review the audit.
5. Review and apply the catalogue-generated structural repairs required by your release. The one-off taxonomy recovery tools are direct administrative URLs, not normal sidebar tasks; see [Tag Taxonomy Rebuild](TagTaxonomyRebuild.md). Do not run the fresh-install schema reset during an upgrade.
6. Reload Apache/PHP as required and verify the production site.

### Passkeys after migration

Passkeys require HTTPS in production and are included in a complete logical backup. After migration, verify an existing passkey and keep password access available as a recovery route.

Do not use `git pull --allow-unrelated-histories` to repair a deployment directory. If the directory is not descended from this repository, preserve its configuration and uploads, then deploy a clean clone.

### Transaction identity migration

Installations predating the current importer must run:

```bash
php php_backend/migrations/20260814_transaction_identity.php
```

This removes the legacy uniqueness rule that could reject legitimate matching same-day transactions while retaining per-account bank-FITID protection.

## Cache behaviour

Production Apache must allow `frontend/.htaccess`. Page shells use `no-store`; local CSS, JavaScript and JSON are revalidated. Avoid compensating for deployment problems with permanent query-string proliferation—update the shared cache policy when asset behaviour changes.

For a root-hosted Nginx installation, copy `scripts/nginx-frontend-cache.conf` to `/etc/nginx/snippets/accounts-frontend-cache.conf` and include it inside each Accounts server block **after** its dot-file deny location. Keep the existing PHP/FastCGI handler. Adapt `/frontend/` and `/sw.js` only when hosting under a path prefix. Back up the site configuration, run `nginx -t`, then reload Nginx. Verify that frontend HTML responses contain `Cache-Control: no-store` and local CSS/JavaScript/manifest/service-worker responses require revalidation. Nginx does not read `.htaccess`; HTML meta tags alone do not provide the equivalent HTTP policy.

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


## iPhone web app

Use the installation’s existing HTTPS hostname, with a trusted certificate. The iPhone must be able to reach that server through your network or VPN. In Safari choose **Share → Add to Home Screen** (possibly under **More**), leave **Open as Web App** enabled if offered, then launch the Accounts icon. Installation does not move the database onto the phone. Sign in using a registered passkey or the password/TOTP fallback; manage passkeys in **System → Users**.

Phones route to the dedicated **Overview / This month / Search** product. Choose **Options → Full website** for imports, planners or administration; installation is optional and ordinary Safari works too. The full website retains its responsive desktop navigation. See [Phone Interface](PhoneInterface.md).

The dedicated phone manifest is `frontend/phone.webmanifest`; the shared sign-in/full-site manifest is `frontend/manifest.webmanifest`. Its relative paths support root and subdirectory installations. `sw.js` lives at the installation root so its scope includes login and frontend pages. Apache must serve it as JavaScript and the manifest as `application/manifest+json`. Both must be same-origin and reachable without authentication. Existing API authentication remains enforced by PHP.

The service worker stores only `frontend/offline.html`, which contains no account data. All application navigations remain network-first with `no-store`; APIs, uploads, downloads and non-GET requests bypass the worker. No financial records, session tokens or form drafts are written to Cache API, IndexedDB or browser storage. If you lose connection during a write, inspect its current server state before retrying. Requests are never queued or automatically replayed.

On session expiry, keep the original app window open. Choose **Sign in here** and complete authentication in the recovery panel. Its same-origin sign-in frame shares the installed app’s cookie store; a separate Safari window may not, because [WebKit isolates installed web-app storage](https://webkit.org/blog/14787/webkit-features-in-safari-17-2/). The app checks the restored session before returning to your edit. **Continue after signing in** also lets you check an already restored session. **Start again at sign-in** leaves the current page and may discard unfinished edits. The original form is retained in memory, and recovery requires the same account. iOS may discard a suspended app at any time, so this is not a durable draft backup. Save before closing the app. Existing passkey and one-time-code semantics are unchanged.

Returning after a background absence exceeding 30 seconds, restoring a browser history page, or reconnecting checks the session. A statement-import revision determines whether data changed: unchanged sessions stay quiet. An import that creates transactions/accounts or changes the stored balance/date advances this revision atomically; failed or unchanged imports do not. When a changed revision is detected, the dedicated phone view refreshes, and full-site pages opted into `data-resume-refresh` reload. Other pages offer an explicit refresh action. Unfinished tracked edits or a busy phone classification editor defer the refresh until it is safe. The revision is kept in memory, not device storage. Other kinds of edits are not covered by this import signal; use manual refresh for them. Transaction, Budget, Pension and Mortgage forms protect unfinished edits; use `data-preserve-edits` for additional editable forms and call `AppWeb.markSaved(form)` only after a successful save. The shared inactivity timer locks on its original deadline when iOS resumes a suspended window.

App updates are offered explicitly when a new worker is ready; save unfinished edits before choosing **Update**. Increment the worker’s public-cache version when changing the offline screen. This refreshes the browser experience only; the existing guarded Application Updates page remains the deployment authority. To remove a broken worker during development, use Safari’s Web Inspector service worker controls or clear website data, then reopen the site online.

### Importing a statement from iPhone Files

In the dedicated phone interface, choose **Options → Full website**, then **Transactions → Import Transactions**. Browse to the downloaded `.ofx` or `.qfx` statement and submit it. Up to 20 files can be selected. Extension checks are case-insensitive; the native picker is deliberately unfiltered, because iOS type recognition can otherwise grey out valid statements. JavaScript and the server still reject unsupported extensions. The user confirmed this fix on a physical iPhone on 24 October 2026.

If a file is still unavailable, make sure it is downloaded locally in Files. Copy a cloud-provider file to **On My iPhone** and retry. Refresh an already open import page after an upgrade. Do not rename a PDF/CSV to OFX; obtain the bank’s actual OFX/QFX export. After import, use the exact imported-entry links; their session-owned evidence expires after two hours and never expands to the full ledger silently.

### Physical iPhone verification

Automated Chromium and WebKit checks cover phone layouts, navigation, the classification sheet, session recovery, selected OFX files, CSV/Excel downloads and transaction evidence. Chromium also exercises actual offline navigation; Playwright’s WebKit offline emulation can fail before dispatching to the service worker, so verify that path on the device.

Before production rollout, check on your iPhone:

1. Install from Safari, confirm the icon and standalone window, then relaunch from the Home Screen.
2. Sign in using Face ID/passkeys, and separately test password plus six-digit verification-code AutoFill.
3. Check top/bottom safe areas, text enlargement, keyboard dismissal, portrait/landscape and VoiceOver navigation.
4. Edit a transaction, background the app past the configured session timeout, sign in again and confirm the edit is still present. Verify a different user cannot continue it.
5. Select an OFX/QFX file from Files, import it once, and inspect the result. Check CSV, Excel and timestamped PDF downloads in Files or the share/open interface offered by iOS.
6. Follow a dashboard amount into its exact transaction evidence, then use Back.
7. Disable connectivity, attempt navigation and confirm the public offline screen. Reconnect and retry; verify no write or import is repeated automatically.
8. Deploy an update, save edits, and accept the update offer. Check Glass/Paper on the full website and light/dark on the dedicated phone interface.
9. Return without importing and confirm no refresh prompt. Import a changed statement in another window, return after 30 seconds and confirm the figures refresh. Repeat with an unsaved classification and confirm the draft is preserved. An unchanged duplicate import should not trigger a refresh.

Installer shell syntax, validation/self-tests and static policy checks can be run locally. They do not prove a fresh Bookworm/Trixie deployment, DNS/port forwarding, package availability, HTTPS issuance or reboot recovery. Complete those target-device checks before declaring a release verified; see [Documentation and release readiness](Documentation.md).
