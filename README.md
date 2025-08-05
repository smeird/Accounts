# Accounts

This repository now provides a simple PHP implementation for managing accounts and transactions.

## Authentication

A basic login page is available at the project root (`index.php`). Users are stored in a `users` table. After logging in, visit `users.php` to add new users or change your password.


## Specifications

- Highcharts is used for graphs, while Tabulator renders interactive tables.
- Display all monetary values using the pound symbol (£) instead of the dollar sign ($).

## PHP Development Setup

1. Ensure PHP and MySQL are installed.
2. Configure database credentials using the environment variables `DB_HOST`, `DB_NAME`, `DB_USER` and `DB_PASS`.
3. Create the database tables:
   ```bash
   php php_backend/create_tables.php
   ```
   Re-running this script after upgrading will also add any new columns.
4. Run the example script which inserts a sample account:
   ```bash
   php php_backend/public/index.php
   ```

Any errors during upload or other operations are stored in a `logs` table.

You can view these entries by opening `frontend/logs.html` which calls the
`php_backend/public/logs.php` endpoint.


To import transactions from one or more OFX files, use the upload script:
```bash
curl -F "ofx_files[]=@first.ofx" -F "ofx_files[]=@second.ofx" http://localhost/path/to/php_backend/public/upload_ofx.php
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

The yearly dashboard page (`frontend/yearly_dashboard.html`) lets you select a year and view total spending by tag, category, and group with tables and bar charts.

The monthly dashboard page (`frontend/monthly_dashboard.html`) shows totals by tag, category, and group for a selected month along with overall income, outgoings, and delta.

The monthly statement page (`frontend/monthly_statement.html`) allows selecting a month and year to list transactions for that period.

## Reports

The frontend also includes a simple reporting page available at `frontend/report.html`.
It allows running a report to list all transactions filtered by category, tag or group.
Reports can be saved in the browser for reuse and removed when no longer needed.
The page sends requests to `php_backend/public/report.php` which returns matching
transactions as JSON.


