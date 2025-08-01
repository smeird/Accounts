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

## Frontend

A simple static frontend can be opened directly from `frontend/index.html`. It provides a navigation menu to upload OFX files or view monthly statements.

## Reports

The frontend also includes a simple reporting page available at `frontend/report.html`.
It allows running a report to list all transactions filtered by category, tag or group.
The page sends requests to `php_backend/public/report.php` which returns matching
transactions as JSON.
