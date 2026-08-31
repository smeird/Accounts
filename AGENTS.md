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
- Project priority uses one portfolio-wide 0–100 model: consequence of delay 35%, urgency 25%, asset preservation 20%, financial impact 10%, and daily-life impact 10%. Keep cost separate from importance, retain the action tiers (Critical, Important, Preventive, Improvement, Nice to have), and do not reintroduce per-project weights.

- Projects view compares cost with a selectable priority signal; bubble size represents the fixed priority score and colour represents its action tier.
- The bubble chart now includes selectors to choose the horizontal and vertical axes.
- Projects board page presents each active project as an individual card with key details and actions.
- Projects board cards display post-description details in a compact table with a smaller font to minimise card size.
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
- Avoid hardcoding font families; rely on browser defaults.
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
- Support backups, restores, portable OFX/CSV/JSON extracts, and a curated multi-sheet Excel financial workbook.
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
- Project wiki pages are stored in the `wiki/` directory and mirror the GitHub wiki.

## Decisions
- Surface system standardised to `cards` (default glass) and `cards cards-solid` (solid) variants. Use `cards cards-tight` for comparable forms/tables/charts and reserve `cards cards-solid` for dense filter/control areas that need stronger contrast.
- Sections use scroll-based fade-in; apply `opacity-0` initially and `frontend/js/scroll_animations.js` adds a `fade-in` class when in view.
- Settings include an accent font weight option offering thin (100), light (300), and bold (700) styles.
- Settings provide a table font option applied to all Tabulator tables.
- Table font CSS variables (`--tabulator-*`) are set in the shared menu so Tabulator tables use the correct fonts during initial render.
- Settings page offers additional funky font options: Bangers, Caveat, Dancing Script, Fredoka, Pacifico.
- Settings allow selecting fonts for headings, body text, tables and charts with options ranging from modern to funky.
- Typography and accent choices come from the allowlisted catalogues exposed by `Setting::fontGroups()` and `Setting::colorPalettes()`. Keep font selectors grouped, load only the selected web fonts, and propagate palette primary/secondary values through shared CSS variables rather than assuming every palette is a Tailwind colour name.
- `frontend/js/fonts.js` is the shared typography authority: form controls inherit the selected body/table family, selected web fonts load the configured 100/300/400/700 faces, accent weight overrides component defaults, and choosing Default removes the corresponding CSS variable.

- Page headings use a shared pattern: `<header class="page-header">` with an `h1.page-title` and optional `.page-subtitle` / `.page-header-actions`; keep this header directly on the page canvas above the first content card.
- Page headers should be rendered via `frontend/js/page_header.js` using `renderPageHeader(main, { title, breadcrumb, subtitle, actions })`; `title` is required, other fields are optional, and output must keep `page-header`, `page-title`, `page-breadcrumb`, and `page-subtitle` classes.
- Project `spent` totals count outgoing transactions as positive expenses and exclude incoming transactions.
- The landing page uses a task-first structure (capture, understand, plan) and keeps live workspace totals in a compact hero snapshot; landing-specific styling belongs in `frontend/landing.css`.
- The Instant dashboard is the glanceable cross-feature overview. Its snapshot is assembled by `InstantDashboard`, and its page-specific presentation belongs in `frontend/instant.css` and `frontend/js/instant_dashboard.js`.
- Authenticated pages without a specialist page design use the shared Financial Pulse layer in `frontend/site_system.css`, with semantic styling hooks added by `frontend/js/site_system.js`. Landing, Instant, Projects, Transactions, Budgets, and Yearly pages retain their dedicated page-specific systems.
- The Account Dashboard uses a native semantic responsive table in `frontend/js/account_dashboard.js` rather than Tabulator. It acts as the reference design for future data-table modernisation: prioritised identity and value columns, explicit sorting/filtering, responsive card rows, and accessible inline actions.
- Monthly Statement follows the native responsive-table reference and makes transaction classification visually explicit. Segment, category, tag, and group appear as labelled, type-coloured pills; missing classifications use equally prominent gap pills rather than blank cells.
- `frontend/typography.css` is the shared readable type scale and is loaded last by `frontend/js/menu.js`; operational controls and body copy target 14px, table rows 13px, and table headings/captions 12px.
- Secondary table metadata (such as years, memos, account types and status qualifiers) uses the shared 11px regular-weight treatment in `frontend/typography.css`; keep primary values, amounts and classification pills visually prominent.
- Professional view uses borderless, shadow-free paper surfaces for most cards and panels plus denser desktop table rows; retain standard mobile table-card spacing and boundaries on interactive controls, warnings, hero treatments, and the sidebar.
- Saved OpenAI API tokens must never be returned to the browser. Token-management views expose only configured/not-configured state and accept explicit replacement or removal.
- Remaining data views use the shared modern-table adapter in `frontend/js/tabulator-tailwind.js`: conventional tables become labelled card rows on small screens, wide matrix/pivot tables retain horizontal navigation, and classification pills use consistent type colours and visible missing states.
- Large tag collections use bounded search endpoints and lazy typeahead controls; do not render the complete tag catalogue into native selects.
- Tabulator is self-hosted and pinned under `frontend/vendor/tabulator`; update every table page and the vendored licence together when changing versions.
- Keep frozen columns opt-in, decorate rows only via `rowFormatter`, use bounded-height virtual rendering for unpaginated large views, and prefer remote pagination/search for datasets that can grow without a practical bound.
- Monthly Statement must render its current transaction rows before secondary metrics, comparison data, or chart libraries are awaited.
- Closed accounts retain their transaction history but have a zero live balance, are excluded from portfolio balance calculations, and ignore imported OFX balance refreshes until reopened.
- AI tag categorisation may only link unassigned tags to existing categories; existing mappings are preserved and only allowlisted high-confidence suggestions are applied.
- Run Processes is an operations hub: recommend the ordered tags-to-categories-to-segments refresh, keep individual processes available for fine control, and isolate assignment clearing behind an explicit confirmation without deleting rules.
- Use `frontend/js/overlay.js` and `window.showMessage()` for transient site notifications; do not introduce page-specific toast implementations. The shared notification supports success, error, warning, info, and loading tones.
- Recurring Spend loads its read-only analysis automatically, describes its detection rules, separates trailing twelve-month totals from next-month estimates, and links each detected pattern to its transaction history.
- Recurring Spend detects a tolerant monthly cadence by stable merchant wording rather than exact description/day pairs. Allow ordinary weekday, weekend and bank-processing date shifts, consolidate changing reference numbers, and reject irregular high-frequency merchants.
- Recurring Spend quick totals use explicit row checkboxes and the latest observed amount (the same basis as the next-month estimate). Keep selected outgoings, income, and net visible together, and clear the temporary selection whenever the analysis is refreshed.
- Recurring Spend uses ten-row local pagination for large pattern lists instead of an invisible nested table scroller. Modern table search inputs own their complete appearance and remain excluded from shared form decoration so Safari does not render nested input chrome.
- Recurring Spend table surfaces are inset from their panels; keep the toolbar and table edges aligned rather than rendering the rows full-bleed against the container.
- Modern table toolbars align their search control and row count with the table content. Row counts are quiet status text rather than coloured pills.
- Pivot Analysis loads automatically, excludes internal transfers, presents income, outgoings and net movement before its expandable segment/category/tag matrix, and opens transaction evidence from every amount cell.
- Sidebar information architecture is task-led and uses six stable groups: Overview, Transactions, Insights, Planning, Organise, and System. Navigation labels describe user goals while existing page URLs remain unchanged for bookmarks.
- Apache serves frontend HTML/PHP with `no-store` and revalidates local CSS/JavaScript/JSON on every request; keep `frontend/.htaccess` and the static-page cache-policy regression checks intact when adding assets or pages.
- Active sidebar destinations must use the modern page header or an explicitly specialist page system. Security, account-detail, AI-review, and root administration refinements share `frontend/utility_refresh.css` rather than adding isolated legacy styling.
- Groups use the same focused workspace pattern as Categories and Segments: searchable left catalogue, selected-item detail panel, explicit edit form, active/inactive status, safe delete confirmation, and usage context from the linked transaction count.
- Daily Burn normalises each calendar month's observed outgoing expenditure across that month's actual number of days, reports the result by segment, and keeps actual transaction-day spending separate. Transfers, income, and IGNORE-tagged transactions are excluded.
- `wiki/StyleGuide.md` is the maintained visual-system reference for new and redesigned pages; keep it aligned with shared page-header, surface, typography, table, chart, state, accessibility, and responsive conventions.
- Tag-taxonomy rebuilds use immutable, hashed classification snapshots before staging or cutover. Snapshots cover tag, category, and segment assignments; confirmed transfers and IGNORE-tagged transactions are protected; restores require a preview and never rewrite transaction identity or financial fields.
- Taxonomy discovery is review-only: deterministic transaction patterns, AI canonical-tag proposals, and review decisions stay in Phase 2 staging tables. No discovery or review action may update live tags, aliases, categories, segments, or transaction classifications.
- Taxonomy discovery may be finalised at 95% or greater transaction coverage once every active proposal is approved. The explicit early-finish action marks unresolved patterns as deferred/excluded so they remain unchanged during cutover.
- Taxonomy cutover is a separate explicit action. It applies only approved, eligible snapshot classifications in one database transaction, uses direction-aware aliases, verifies untouched classifications and financial fingerprints before commit, and records the state needed for a guarded rollback.
- Post-cutover legacy cleanup is a second explicit, audited action. It deprecates every active noncanonical `legacy` tag and disables its active aliases, even when deferred or post-snapshot transactions still reference that tag. It must never rewrite those historical classifications, `IGNORE`, system tags, reviewed canonical tags, or financial fields. Later classification edits may block the broader cutover rollback but must not block this independent cleanup; the broader rollback restores cleanup state when its original audit still matches.
- `frontend/tagging.html` is the permanent tagging authority. Keep the unmatched inbox, canonical catalogue, deterministic rules, constrained AI actions and read-only rebuild history together; legacy tagging URLs may redirect into its tabs for bookmarks.
- Routine tag removal means retirement, not deletion: retain historical transaction assignments and disable future rules. Canonical merges move transactions and rules atomically, preserve a merged audit record, and use the destination reporting category unless it is unassigned.
- AI tagging may select only active canonical tags. Unfamiliar suggestions must be returned for explicit review and must never create a live tag automatically. Preserve the complete canonical-name allowlist when trimming alias examples for prompt size.
- Tag rule execution records aggregated usage evidence. New or edited cross-tag rules with overlapping whole-word scope require an explicit confirmation, while direction-specific, exact and longer matches retain deterministic precedence.
- Excel is a dedicated financial-workbook export rather than a raw format option. Keep Summary, Pivot Analysis and Transactions sheets together; retain transfer/ignored ledger rows while excluding them from income and spending calculations.
- The authenticated application shell uses dynamic viewport height and one touch-scrolling main panel. Preserve its `app-shell*` hooks, safe-area bottom spacing, and tablet-friendly overscroll containment; do not return to a `100vh`-only nested scroller.
- A tagging fresh start clears only snapshotted eligible transaction classifications, removes non-IGNORE rules, removes tag-to-category links and legacy keywords, and retains the canonical vocabulary. Record full rule/link/keyword audit state and create a hashed classification snapshot first; confirmed transfers and IGNORE transactions must remain unchanged.
- The second login step is a one-time-code form, not a password form: retain `autocomplete="one-time-code"`, numeric six-digit validation and no autofocus on its code field so Apple Password AutoFill offers verification codes without reopening the password chooser. Keep the first-step `username` and `current-password` annotations unchanged.
- Passkeys are discoverable ES256 WebAuthn credentials and are an alternative complete login, not an extra TOTP step. Require user verification, one-time server challenges, exact origin/RP validation, matching user handles, signature-counter checks and HTTPS outside localhost. Store public credential material only, keep password/TOTP as recovery, manage multiple named credentials in `users.php`, and include them in schema health and backup/restore.
- Installation appearance defaults include glass/paper surfaces, compact/comfortable/roomy desktop density, soft/balanced/square corners, calm/balanced/vivid backdrop strength, small/medium/large page headers, hairline/small/medium/large primary top-accent bars, and standard/reduced motion. Primary top accents reveal rapidly left-to-right unless motion is reduced. Apply them centrally through `frontend/js/menu.js` and `frontend/css/interface-preferences.css`; the sidebar Professional theme switch remains a per-device surface override, and density/header scaling must retain mobile touch targets.
- Paper/Professional view is a distinct site-wide document system owned by `frontend/css/theme-professional.css`: warm-white continuous canvas, horizontal section rules, flattened report heroes, square ledger tables, compact desktop controls, restrained colour and negligible elevation. Keep Glass unchanged, preserve semantic colours and primary accent bars, and retain standard mobile touch heights.
- Dashboard heroes are compact financial briefs, not splash screens. Keep their information and visual identity, remove decorative minimum height, use horizontal space for headline context and secondary signals, and maintain the shared responsive rules in `frontend/css/hero-density.css`.
