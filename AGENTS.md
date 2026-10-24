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
- Target PHP version: 8.5 and above.
- PostgreSQL is the only supported production database. Configure it with `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, and optional `DB_SSLMODE`; `DB_DSN=sqlite::memory:` remains test-only.
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
- Login is passkey-first through conditional WebAuthn mediation and `autocomplete="username webauthn"`: allow the browser to offer a matching discoverable credential and authenticate immediately after selection, but never attempt to enumerate credentials. Retain the explicit passkey button and password/TOTP fallbacks.
- Transaction Details uses the bounded tag picker with classification metadata. Selecting an existing tag must immediately choose its canonical category and show the category-derived segment; saving continues to apply that classification through the existing tag/category/segment propagation rules.
- Installation appearance defaults include glass/paper surfaces, compact/comfortable/roomy desktop density, soft/balanced/square corners, calm/balanced/vivid backdrop strength, small/medium/large/extra-large page headers, hairline/small/medium/large primary top-accent bars, and standard/reduced motion. Primary top accents reveal rapidly left-to-right unless motion is reduced. Apply them centrally through `frontend/js/menu.js` and `frontend/css/interface-preferences.css`; the sidebar Professional theme switch remains a per-device surface override, and density/header scaling must retain mobile touch targets.
- Paper/Professional view is a distinct site-wide document system owned by `frontend/css/theme-professional.css`: warm-white continuous canvas, horizontal section rules, flattened report heroes, square ledger tables, compact desktop controls, restrained colour and negligible elevation. Keep Glass unchanged, preserve semantic colours and primary accent bars, and retain standard mobile touch heights.
- Dashboard heroes are compact financial briefs, not splash screens. Keep their information and visual identity, remove decorative minimum height, use horizontal space for headline context and secondary signals, and maintain the shared responsive rules in `frontend/css/hero-density.css`.
- Transaction-derived dashboard values use `frontend/js/transaction_drilldown.js` to open `search.html` with exact inclusive dates, direction, transfer/IGNORE scopes and classification/account/group or bounded transaction-ID evidence. Only values that can be reproduced from a current identifiable transaction set receive this link treatment; balances, forecasts, plans, remaining values and historical audit counts do not.
- Application Updates is the authenticated deployment control. It may operate only on the application checkout and its `origin` remote, must fetch and recheck immediately before installation, and may perform only a clean working-tree fast-forward of the currently checked-out branch. Dirty, detached, ahead, diverged, missing-remote, concurrent, and non-fast-forward states require manual server review. Private-repository servers use the root-owned `accounts-application-git` allowlist installed by `scripts/install-application-update-helper.sh`; never expose deployment SSH keys or general Git/shell sudo access to the Apache user, and re-run the installer when the protected helper source changes.
- Raspberry Pi production installs use the interactive root-run `deploy.sh` on 64-bit Raspberry Pi OS Bookworm or Trixie. Keep generated database/passkey settings outside the checkout in the root-owned Apache environment configuration, keep runtime uploads ignored, expose the app only after successful HTTPS provisioning, and preserve `www-data` ownership needed by the guarded in-app updater.
- Keep the README Raspberry Pi installation guide short and task-led: prepare DNS/ports, run the single installer command, answer its prompts, then sign in, check Database Health, and create an off-device backup. Put lower-level diagnostics and recovery details in `wiki/Setup.md`.
- The curated appearance catalogue includes cool, neutral, natural, warm and multitone accent palettes. Add colours only through `Setting::colorPalettes()` with stable keys, descriptive labels and sufficiently deep primary/secondary values that remain legible for white button text in Glass and Paper views.
- PostgreSQL is the production data authority and PHP 8.5 is the minimum runtime. Keep schema creation and Database Health driven by `SchemaCatalog`, preserve PostgreSQL identity sequences during logical restores, and migrate legacy MySQL installations only through the versioned application backup format; never attempt an in-place data-directory conversion.
- Paper typography follows an editorial hierarchy: strong document titles, medium section headings, large regular-weight financial figures, quiet captions and lighter supporting text. Honour selected families, accent weights and header sizes; flatten nested metric surfaces and secondary decorative slivers without changing Glass, semantic classification colours, primary accents or mobile touch targets.

- Transaction workspace pages use all available main-panel width rather than a fixed maximum width, retaining their responsive padding and sidebar offset.
- Find Transactions sorts the API's ISO `YYYY-MM-DD` dates as strings so chronological sorting does not depend on Luxon or locale-specific date parsing.

- Group Analysis is a read-only Insights view with inclusive optional dates, SQL-aggregated tag spending, separate receipts and net cost, visible untagged entries, and transfer/IGNORE exclusions. Transaction evidence combines a standalone group filter with tag filters; the all-entries link includes excluded ledger rows.

- Find Transactions preserves group/tag/date evidence filters and keeps results usable when chart assets fail. Free-text group and classification name searches are case-insensitive on PostgreSQL.

- Monthly Activity pie slices filter the local ledger to the contributing outgoing, non-transfer transactions for that category, clear the table search, and scroll to the ledger. All activity clears the category filter.

- Find Transactions shows a signed Amount total in a wrapping footer below the table (visible on mobile and independent of fixed column widths) for all matching rows across pages. Table filtering recalculates it; transfer and ignored rows contribute when present in the result set.

- AI Data Fix supports reviewed merchant-rule corrections without a named source tag: resolve or explicitly create the target tag, replace same-phrase/same-direction aliases, preserve broader rules with overlap warnings, and apply to the previewed eligible ledger set atomically. Reject stale transaction/rule evidence; protect transfers, IGNORE and system classifications, and keep non-tag fields unchanged.

- AI category assignment visits unassigned active non-system tags once per run using stable ID batches, with up to three recent non-transfer transaction examples. Automatically apply only allowlisted category suggestions at 85% confidence or greater; leave insufficient-evidence tags unassigned, preserve existing links, and propagate new links only to the affected eligible transactions. The Tagging automation reports actual assignments and unknowns, supports stopping between batches, and shows the latest prompt/response when AI debug is enabled.

- All authenticated page workspaces fill the available main-panel width. The shared `.app-shell-main` owns width and sidebar offset; page mains, headers and content wrappers must not add fixed maximum widths. Keep responsive gutters and readable text, input, tooltip and dialog limits.

- Pensions & Retirement is a standalone, installation-wide planning document in `pension_plans`, separate from bank/ledger totals. Save with optimistic revision checks; forecasts are deterministic monthly drawdown illustrations before tax, with provider fees, fixed nominal contributions, inflation, optional user-supplied State Pension and a selectable end age. Financial Overview uses only the saved plan, and Pensions is a distinct backup/restore part. Never assume full State Pension entitlement or describe an illustrative target age as a guaranteed retirement date.

- Mortgage Planner models one repayment mortgage independently of the ledger in `mortgage_plans`, with optimistic save revisions and a distinct backup part. Compare normal payments with fixed monthly and immediate lump-sum overpayments; optional rate changes either re-amortise the current balance over the remaining contractual term or retain the payment. Display term-end shortfalls explicitly, never equate maturity with payoff, and keep lender fees, early-repayment charges and overpayment limits visibly outside the illustration.


- The iPhone/installable web-app layer is owned by `frontend/js/web_app.js` and `frontend/css/web-app.css`, with a relative-path manifest and installation-root `sw.js`. Phone tabs retain the six-group menu under More; transaction classification uses a full-screen native dialog. Preserve one main scroller, safe areas, 44px touch targets and 16px phone inputs.
- Web-app caching is public-only: the service worker may cache only the account-free offline screen, never financial pages, APIs, downloads, credentials or drafts. Existing no-store/revalidation rules stay intact; writes/imports are never queued or replayed. Increment the public-cache version when the offline screen changes and require an explicit safe update before reloading.
- Session expiry keeps the current document in memory behind a sign-in dialog; reauthenticate in a same-origin sign-in frame inside the app and require the original user before continuing. Login return paths are validated by `LoginDestination` and consumed once across password, TOTP and passkey flows. Editable forms use `data-preserve-edits` and report successful saves through `AppWeb.markSaved`; only read-only pages opt into automatic resume refresh.
- Tailwind CSS, Font Awesome, app icons and file-export helpers are built locally with pinned npm development dependencies and committed under `frontend/vendor`/`frontend/icons`; production does not need Node.js. Run `npm ci && npm run build:assets` after utility/dependency changes, preserve runtime palette variants and vendored licences, and keep chart loading secondary to primary data.

- Shared notifications reserve an animated row above the main workspace instead of floating over content. Glass uses translucent frosted panels; Professional uses flat paper notices with horizontal rules. Keep `showMessage()` / `dismissNotification()` compatible, semantic tones, reduced-motion support and phone navigation clearance.

- Decorative accents use one primary surface per workspace: `menu.js` marks the financial hero or fallback header with `page-primary-accent`. Glass uses the shared palette gradient; Professional uses a solid palette cue on its document header. Keep secondary cards, metrics, headings and filters free of decorative strips; preserve semantic status indicators and progress bars. Shared geometry, thickness and motion belong in `interface-preferences.css`, with Paper treatment in `theme-professional.css`.

- Professional form controls use the shared Paper radius, border and height tokens in `theme-professional.css`, scoped to workspaces and dialogs. Composite search/tag/suffix controls own one outer border and keep their inner inputs borderless. Desktop density may scale control height; phones retain at least 44px targets and 16px input text. Preserve native checkbox/radio/range/colour geometry, semantic button colours and visible keyboard focus.

- Specialist workspace captions use the shared 12px floor; explanations and operational controls use 14px. Reserve 11px for secondary table metadata. Static Glass surfaces keep stable elevation, embedded charts/tables inherit their parent surface, and only explicitly interactive cards elevate on hover. Paper catalogue rails share a continuous document canvas and nested panels do not repeat outer section rules.
- Settings appearance previews reflect the actual Glass/Paper surface hierarchy, selected palette, corners, density, title scale and typography without changing saved preferences. Phone page headers align beneath the separate navigation bar; keep financial heroes compact while retaining every metric and touch target.

- Standalone and embedded sign-in honour the device’s Professional surface override, falling back to the installation setting when none is saved or browser storage is unavailable. Appearance preview Default typography must clear the sample override; Default body type resolves from the application’s root font rather than inheriting the saved body choice.

- Daily Burn headline metrics size to their content rather than stretching to the hero. On desktop, captions share a row with their icon and financial values use the full card width; the four-column hero/metric layout starts at 1200px so narrower workspaces retain readable figures.

- Maintenance and taxonomy hero icons, primary actions and decorative washes follow the installation palette. Preserve distinct success/error/approval/rollback colours; icons inside these heroes and actions inherit their container foreground so the shared brand-icon hook cannot hide them against coloured backgrounds. Professional filter panels and maintenance heroes use flat ruled surfaces, including Daily Burn; nested explanatory areas and cleanup metrics remain unboxed.

- Find Transactions opens the latest 100 rows before charts; explicit filters and All history search the full ledger. Preserve incoming evidence filters, show active scope, and keep the ledger before summaries/charts. Classification-gap review uses explicit save-and-next batches of at most 250 eligible rows; failed saves and newer edits must not advance navigation.
- Imported-transaction evidence uses opaque, purpose-bound, session-owned tokens in `TransactionEvidence`, expires after two hours, and pages exact committed new IDs in groups of 250. Never include duplicate/rolled-back rows, expose raw unbounded ID lists in URLs, persist financial evidence in browser storage, or silently broaden expired evidence to all transactions.
- Transaction return context is validated by `frontend/js/transaction_navigation.js`; browser history state may hold only list controls and scroll position. Keep one contextual phone Back action and preserve unsaved-edit guards. Transfer and print actions remain outside the classification editor; new tag creation is an explicit disclosure.
- Planning opens `frontend/planning.html` with existing budgets, projects, pension and mortgage workspaces. Saved pension/mortgage plans show results first with a collapsed assumptions editor and explicit saved/unsaved-preview state; first-time plans open setup. Keep forecasts, optimistic revisions and warnings intact.
- Account freshness distinguishes last transaction date from the bank balance date. Use `ledger_balance_date` for the latter, never infer import freshness from transaction activity. Account names wrap, full identifiers use a deliberate reveal, and supporting portfolio counts stay secondary to balances.

- The dedicated phone product is `frontend/phone.html`, owned by `frontend/js/phone.js`, `frontend/js/phone_entry.js` and `frontend/css/phone.css`. It has three stable bottom destinations (Overview, This month, Search), an independent neutral light/dark visual system and no desktop menu, theme, table or permanent back bar. Early routing covers narrow windows and touch phones in landscape; Full website is an explicit per-session escape. This replaces the six-group More menu only in the dedicated phone product; desktop remains complete.
- Phone data uses shared financial calculations, true current-calendar-month totals and bounded 50-row server pagination with totals over every match. Saved pension/mortgage figures remain separate from account balances with their own dates. Navigation state and unsaved classifications stay in memory; only appearance and full-site preferences may use device storage.
- Phone This month includes a compact Highcharts spending strip with four named categories and expandable Other categories. Aggregate the complete eligible month on the server, independent of ledger pagination or activity filters; exclude transfers, income and IGNORE, retain uncategorised spending, and link categories to exact dated transaction evidence. Keep chart loading optional and secondary to the ledger and accessible category labels.
- Phone classification is one explicit, atomic transaction edit through `PhoneClassification`: choose an existing active non-system tag, preview its linked category/segment, validate optimistic transaction and mapping evidence, and update only that entry's classification. Protect transfers, IGNORE and system tags; never create tags, edit taxonomy, learn rules, replay a write or alter financial fields. Preserve the draft through session expiry and require explicit Save after recovery.

- Nginx deployments must mirror the frontend cache policy explicitly because they do not read `.htaccess`. `scripts/nginx-frontend-cache.conf` is the root-hosted counterpart: include it after dot-file denial, retain the PHP handler, verify configuration before reload and verify live response headers after deployment.

- Preserve component-owned CSS classes when changing loading states on live table containers. Toggle only the page's loading class; Find Transactions reuses its Tabulator instance across searches, so replacing the root `className` removes the structural table styles and causes overlapping headers/rows.

- Web-app resume checks session validity quietly and refreshes data only when `statement_import_revision` changes. Publish this opaque revision in the same transaction as changed statement data (including balance-only imports); failed, duplicate-only and stale unchanged imports do not advance it. Keep the comparison in memory, preserve unfinished edits, and keep software-update notices independent.

- Workspace top gutters belong to `.app-shell-main` (12px on desktop in both themes); do not add body padding above the viewport shell. Dashboard metric icons use consistent, centred icon boxes, and side-by-side Trends/Daily Burn metrics fill their summary row.
- Workspace links use colour, chevrons and visible keyboard focus without text underlining. Choices search inputs own their inner geometry and are excluded from shared standalone-input decoration.
- Find Transactions tracks its main scroller offset with a passive scroll listener for return navigation and Safari wheel-scroll synchronisation; retain the bottom-to-up browser regression check.
- Smart tagging separates classification from rule approval: only approved active manual/AI-origin canonical tags are selectable, clear non-ambiguous decisions need at least 90% estimated confidence, and exact frozen batches contain at most 250 transactions. Stable session-owned cursors skip unresolved patterns without including later imports. AI never creates live rules, tag definitions or category links; uncertain classifications and multiword merchant-rule candidates stay in a bounded two-hour server-session review queue. Rule activation requires an explicit scope preview, a matching preview token, stale-evidence checks and overlap confirmation. Catalogue creation asks for a distinct reporting purpose and checks similar names; merge suggestions remain review-only.

- Category tag assignment shows all-history ledger transaction counts, outgoing, incoming and total absolute value (including transfers). Default to most transactions first and offer count, value and alphabetical sorting without changing assignment behaviour.

- Pensions supports optional Go-Go/Slow-Go/No-Go spending across equal age thirds from retirement to the selected end age. Solve sustainable Go-Go income with editable nonincreasing later percentages (defaults 80/60), preserve level-income legacy plans, apply phases at monthly withdrawal start, and treat State Pension above a phase target as available surplus rather than reinvestment. Targets and headline income refer to Go-Go when enabled.

- Statement upload pickers omit `accept` so iOS Files can select OFX/QFX files with unrecognised document types. Keep case-insensitive extension validation after selection and server-side validation.

- Maintain documentation in README.md, REQUIREMENTS.md and wiki/; publish wiki pages from that source with scripts/sync_wiki.py, which rewrites links for GitHub Wiki. Keep historical reviews explicitly dated/superseded and installation verification limits distinct from successful hardware acceptance.
