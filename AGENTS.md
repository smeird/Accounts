# AGENTS Instructions

## Environment
- Target PHP version: 7.0 and above.
- Ensure MySQL is available and configure credentials using the environment variables `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS`.
- Create database tables with `php php_backend/create_tables.php` and optionally insert a sample account with `php php_backend/public/index.php`.
- Serve the project locally with `php -S localhost:8000` and open `frontend/index.html` in your browser.

## Code Style
- No formal coding standard is enforced; keep code clear and consistent with existing files.
- Use Highcharts for graphs and Tabulator for tables.
- Display monetary values with the pound symbol (£).

## Testing
- There are currently no automated test scripts. Manually verify functionality.

## Commit Guidelines
- There is no specific commit message format; write concise, descriptive messages.
