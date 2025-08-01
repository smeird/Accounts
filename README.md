# Accounts

This repository now provides a simple PHP implementation for managing accounts and transactions.

## PHP Development Setup

1. Ensure PHP and MySQL are installed.
2. Configure database credentials using the environment variables `DB_HOST`, `DB_NAME`, `DB_USER` and `DB_PASS`.
3. Create the database tables:
   ```bash
   php php_backend/create_tables.php
   ```
4. Run the example script which inserts a sample account:
   ```bash
   php php_backend/public/index.php
   ```

To import transactions from an OFX file, use the upload script:
```bash
curl -F ofx_file=@yourfile.ofx http://localhost/path/to/php_backend/public/upload_ofx.php
```

## Frontend

A simple static frontend can be opened directly from `frontend/index.html`. It provides a navigation menu to upload OFX files or view monthly statements.

### Category Administration

The navigation now includes a *Manage Categories* link which opens `frontend/categories.html`.
From this page you can create new categories, rename them and associate tags with a category.
The forms submit to `php_backend/public/categories.php` which performs the requested action.
