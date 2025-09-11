# Setup

Follow these steps to get the project running locally.

## Requirements
- PHP 7.0 or later
- MySQL server

## Configuration
1. Set database credentials with the environment variables `DB_HOST`, `DB_NAME`, `DB_USER` and `DB_PASS`.
2. Create the database tables:
   ```bash
   php php_backend/create_tables.php
   ```
3. Optionally insert a sample account:
   ```bash
   php php_backend/public/index.php
   ```
4. Serve the project locally:
   ```bash
   php -S localhost:8000
   ```
   Then open `frontend/index.html` in your browser.

## Testing
Run the test suite with:
```bash
php tests/run_tests.php
```

