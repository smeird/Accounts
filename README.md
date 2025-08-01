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

A simple static frontend can be opened directly from `frontend/index.html`. It provides a navigation menu to upload OFX files or view monthly statements. The monthly statement page (`frontend/monthly_statement.html`) allows selecting a month and year to list transactions for that period.
