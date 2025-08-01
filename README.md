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
You can try this using the included sample file `sample_data/test.ofx` which
contains two transactions for a checking account.

## Running a Local Server

To use the upload page the frontend must be served over HTTP so the PHP parser
can receive the request. From the repository root run:

```bash
php -S localhost:8000
```

Then open `http://localhost:8000/frontend/index.html` in your browser.

## Frontend


A simple static frontend can be opened directly from `frontend/index.html`. It provides a navigation menu to upload OFX files or view monthly statements.

The monthly statement page (`frontend/monthly_statement.html`) allows selecting a month and year to list transactions for that period.

## Reports

The frontend also includes a simple reporting page available at `frontend/report.html`.
It allows running a report to list all transactions filtered by category, tag or group.
The page sends requests to `php_backend/public/report.php` which returns matching
transactions as JSON.


