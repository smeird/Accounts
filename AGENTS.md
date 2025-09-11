# AGENTS Instructions

- Record any additional project decisions or conventions in this file.
- AI integrations use the OpenAI Responses API with JSON text via `text.format` responses.
- Set `text.format.type` to `json_object` when requesting JSON responses.
- AI model and temperature are configurable via `ai_model` and `ai_temperature` settings.
- AI debug output can be toggled with `ai_debug` to expose request and response details.
- When AI debug is enabled, AI pages show a card displaying the submitted prompt followed by the AI response.

- Frontend pages query `php_backend/public/ai_debug.php` to determine whether to display the debug card.

- Static pages must prevent caching via `<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">` tags or equivalent PHP headers.

- Projects support archiving via an `archived` flag and can be restored from the Archived Projects page.

- Projects view visualises benefits using a bubble chart plotting cost vs quality with bubble size representing score, displaying each project as its own series for distinct colours.
- The bubble chart now includes selectors to choose the horizontal and vertical axes.
- Projects board page presents each active project as an individual card with key details and actions.
- Projects board cards display post-description details in a compact table with a smaller font to minimise card size.
- Colour palettes are generated in OKLCH with an HSL fallback. Palette settings are stored using hue, lightness and chroma parameters rather than hex values.
- Palette generation spaces segment hues using the golden angle without a seed colour.


## Environment
- Target PHP version: 7.0 and above.
- Ensure MySQL is available and configure credentials using the environment variables `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS`.
- Create database tables with `php php_backend/create_tables.php` and optionally insert a sample account with `php php_backend/public/index.php`.
- Serve the project locally with `php -S localhost:8000` and open `frontend/index.html` in your browser.

## Code Style
- No formal coding standard is enforced; keep code clear and consistent with existing files.
- Use Highcharts for graphs and Tabulator for tables.
- Display monetary values with the pound symbol (£).
- Style the frontend with Tailwind CSS. Wrap primary sections in white card components (`bg-white p-6 rounded shadow`).
- Use Font Awesome for interface icons.
- Headings should use bold Roboto, body text should use Inter, and buttons or highlights should use light Source Sans Pro.
- Tabulator tables should apply Tailwind utility classes.
- Provide popover help for form inputs using `data-help` attributes handled by `frontend/js/input_help.js`.
- Ensure the site remains mobile-friendly: include `<meta name="viewport" content="width=device-width, initial-scale=1.0">` on
  every page and use Tailwind's responsive utilities so navigation and layouts work on small screens.
- Interactive icons and buttons must include meaningful `aria-label` attributes. A global script copies these to `data-tooltip` attributes and displays Tailwind-styled tooltips.

## Features
- Flag transactions recognised as transfers and exclude them from income and expense totals.
- Each page must provide a self-help overlay with a brief description of its purpose.
- Automatically tag transactions and suggest budgets using AI.
- Analyse recurring expenses and break down spending by segments and categories.
- Support backups, restores, and exporting transactions to OFX, CSV, or XLSX.
- Backups and restores can include project data.
- Secure accounts with two-factor authentication and offer detailed search and reporting.
- Transaction groups include an `active` flag. Inactive groups are hidden from selection and projects set to archived automatically deactivate their associated group.
- PDF reports are named using a timestamped `transactions_report_YYYYMMDD_HHMMSS.pdf` format to ensure uniqueness and clarity.
- Saved reports persist in a `saved_reports` table storing each report's name, description, and filter criteria.
- Transaction reports display a pie chart of totals by category alongside the column chart.

## Testing
- Run `php tests/run_tests.php` to execute the test suite.
- Manually verify functionality as needed.

## Commit Guidelines
- There is no specific commit message format; write concise, descriptive messages.

## Documentation
- README includes Mermaid diagrams to illustrate application architecture and request flow.

## Decisions
- Sections use scroll-based fade-in; apply `opacity-0` initially and `frontend/js/scroll_animations.js` adds a `fade-in` class when in view.
- Settings include an accent font weight option offering light (300) and very thin (100) styles.
- Settings provide a table font option applied to all Tabulator tables.
