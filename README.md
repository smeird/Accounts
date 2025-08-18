# Accounts

This repository now provides a simple PHP implementation for managing accounts and transactions.

## Authentication

A basic login page is available at the project root (`index.php`). Users are stored in a `users` table. After logging in, visit `users.php` to add new users or change your password.


## Specifications

- Highcharts is used for graphs, while Tabulator renders interactive tables.
- Display all monetary values using the pound symbol (£) instead of the dollar sign ($).
- Tailwind CSS provides the styling and Font Awesome supplies icons. Sections are wrapped in card components.
- Headings use bold Montserrat, body text uses Inter, and buttons or highlights use light Source Sans Pro.

- Tabulator tables apply Tailwind utility classes for a consistent look and use the Simple theme.

- Form inputs may include a `data-help` attribute to show popover guidance.
- Transactions identified as transfers are flagged and ignored in totals.
- The interface is responsive. Each page includes a viewport meta tag and uses Tailwind's responsive utilities so the site works
  on mobile devices. The navigation menu collapses to a toggle button on small screens.

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
curl -F "ofx_files[]=@first.ofx" -F "ofx_files[]=@second.ofx" https://localhost/path/to/php_backend/public/upload_ofx.php
```
You can try this using the included sample file `sample_data/test.ofx` which
contains two transactions for a checking account.

Account names you've customised in the UI are preserved. Uploading new OFX files will not overwrite renamed accounts.


The importer normalises line endings, strips control characters and converts
character encoding to UTF-8, falling back to iconv when the mbstring extension
is unavailable.


## Running a Local Server

To use the upload page the frontend must be served over HTTPS so the PHP parser
can receive the request. From the repository root run:

```bash
php -S localhost:8000
```

Then open `https://localhost:8000/frontend/index.html` in your browser.


## Backup and Recovery

Back up and restore your data through the web interface. From the navigation

menu open **Backup & Restore** under *Administration*. User accounts and
account information are always included in backups. You can additionally choose
which other parts of the database to download—transactions, categories, tags,
groups, or budgets. The downloaded file contains gzipped JSON and is named after
your site's hostname, the current date, and the selected sections (for example,
`example.com-2024-05-15-transactions-categories.json.gz`). To restore a backup,
choose the compressed file on the same page and click **Restore**; any included
sections are imported.

The same page also lets you export all transactions to a single OFX file for
use in other financial tools.


## Frontend


A Tailwind-styled frontend can be opened directly from `frontend/index.html`. It provides a navigation menu with Font Awesome icons to upload OFX files, view statements, run reports, or explore graphs.

The yearly dashboard page (`frontend/yearly_dashboard.html`) lets you select a year and view total spending by tag, category, and group with tables and bar charts.

The monthly dashboard page (`frontend/monthly_dashboard.html`) shows totals by tag, category, and group for a selected month along with overall income, outgoings, and delta.

The graphs page (`frontend/graphs.html`) displays additional Highcharts visualisations and includes a year selector.

The monthly statement page (`frontend/monthly_statement.html`) allows selecting a month and year to list transactions for that period.

Many form inputs include popover help that appears when fields with a `data-help` attribute are focused or hovered.

## Reports

The frontend also includes a simple reporting page available at `frontend/report.html`.
It allows running a report to list all transactions filtered by category, tag or group.
Reports can be saved in the browser for reuse and removed when no longer needed.
The page sends requests to `php_backend/public/report.php` which returns matching
transactions as JSON.

## Running Tests

The repository includes a small test script that exercises the user model using
an in-memory SQLite database. It does not require a MySQL server, making it
suitable for environments where a database is unavailable.

Run the tests with:

```bash
php tests/run_tests.php
```



## Automated Deployment


This project uses GitHub Actions to trigger deployments. On pushes to the `master` branch, the workflow sends a request to your deployment server:


```
curl https://your.web.server.com/automated_deployment.php
```


Create `.github/workflows/deploy.yml` with:

```yaml
name: Deploy
on:
  push:
    branches: [ master ]
jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - name: Trigger automated deployment
        run: curl https://newaccounts.smeird.com/automated_deployment.php
```


On the server, `automated_deployment.php` should pull the latest code:

```
<?php
shell_exec('cd /var/www/myproject && git pull');
```

