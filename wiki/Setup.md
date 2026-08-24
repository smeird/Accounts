# Setup

## Requirements

- PHP 7.0 or later
- PDO MySQL extension
- MySQL
- Apache for production (the PHP development server is sufficient locally)
- Node.js for frontend syntax/regression tests

## Database configuration

Accounts reads:

- `DB_HOST`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`
- optional `DB_DSN` for tests or a custom PDO connection

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

Do not use `git pull --allow-unrelated-histories` to repair a deployment directory. If the directory is not descended from this repository, preserve its configuration and uploads, then deploy a clean clone.

### Transaction identity migration

Installations predating the current importer must run:

```bash
php php_backend/migrations/20260814_transaction_identity.php
```

This removes the legacy uniqueness rule that could reject legitimate matching same-day transactions while retaining per-account bank-FITID protection.

## Cache behaviour

Production Apache must allow `frontend/.htaccess`. Page shells use `no-store`; local CSS, JavaScript and JSON are revalidated. Avoid compensating for deployment problems with permanent query-string proliferation—update the shared cache policy when asset behaviour changes.

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

UI changes also require browser verification at desktop and mobile widths. Confirm loading, populated, empty and error states; filters; chart rendering; scrolling; and absence of horizontal document overflow.
