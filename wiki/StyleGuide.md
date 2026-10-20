# Accounts visual style guide

The Accounts interface should feel calm, capable and financially trustworthy: soft glass surfaces, precise hierarchy, restrained gradients and dense information that remains easy to scan. Specialist dashboards may have their own visual accent, but they must still feel like part of the same product.

## 1. Information architecture

Keep the six sidebar groups stable:

1. Overview
2. Transactions
3. Insights
4. Planning
5. Organise
6. System

Navigation labels describe the user’s goal, not the implementation. Keep existing URLs stable when renaming or consolidating pages.

## 2. Page anatomy

Every active destination starts with the shared header rendered by `frontend/js/page_header.js`:

```js
window.renderPageHeader(document.querySelector('main.ops-main'), {
    title: 'Daily Burn',
    breadcrumb: 'Insights',
    subtitle: 'Understand the daily cost of your lifestyle.',
    actions: optionalActionNode
});
```

Authenticated workspaces fill the main-panel width beside the sidebar. `frontend/sidebar.css` owns this through `.app-shell-main`; keep page mains, headers and outer content wrappers uncapped, with the existing responsive padding. Maximum widths remain appropriate for readable text, controls, tooltips and dialogs.

Order page content from summary to evidence:

1. Page header
2. Small set of global controls
3. Primary outcome/hero metric
4. Supporting metrics
5. Movement over time
6. Breakdown/drivers
7. Exact values and transaction drill-downs

The default view must answer the page’s main question before the user interacts.

## 3. Surfaces and spacing

- Installation appearance settings provide the default accent palette, typography, surface style, desktop density, corner shape, backdrop strength, page-header size (small through extra large), primary top-accent thickness and motion level. Implement shared components so they continue to respond to the variables and classes applied by `frontend/js/menu.js` and `frontend/css/interface-preferences.css`.
- Use one decorative primary accent per workspace. `frontend/js/menu.js` selects a financial hero when present, otherwise the page header, using `page-primary-accent`. Glass gives that surface a palette-derived gradient; Professional puts a solid palette cue on the document header and uses neutral rules for sections. The sidebar shares the same thickness setting. Secondary cards, metrics, heading dashes and filter panels do not add decorative bars. Preserve semantic status indicators, classification colours and progress bars. Keep hero selection central rather than defining new page-specific accent pseudo-elements.
- Primary top accents reveal rapidly from left to right when a page loads. Honour both the saved reduced-motion setting and the browser's reduced-motion preference; small section markers should remain static.
- Dashboard heroes are compact financial briefs rather than splash screens. Preserve the headline, explanation, secondary signal and supporting metrics, but use the available width to keep them together and avoid decorative minimum heights. Shared density rules belong in `frontend/css/hero-density.css`.
- Glass is the expressive default. Paper is a deliberately distinct compact document workspace: one warm-white canvas, horizontal rules instead of card outlines, square ledger tables, restrained colour, flatter heroes, compact desktop controls and almost no elevation. Do not treat Paper as merely Glass with blur disabled. The sidebar switch remains a per-device override of the installation default.
- Compact and Roomy density change desktop spacing only. Preserve standard mobile touch targets and card-row spacing.
- Use `cards` for the standard glass surface.
- Use `cards cards-solid` for dense filters or controls needing stronger contrast.
- Use `cards cards-tight` for compact forms, tables and comparable chart panels.
- Specialist pages may use a semantic page-specific card class, but should retain the same border opacity, rounded geometry and soft shadow family.
- Keep the desktop top gutter at 12px in both themes, owned by `.app-shell-main`; never pad the body above its viewport-height shell.
- Use one principal scroll container (`main.ops-main`); avoid nested full-page scrolling and document-level horizontal overflow.
- Prefer consistent 12–18px internal gaps and 16–22px card padding. Reduce, do not remove, spacing on mobile.

## 4. Colour and gradients

Brand colours come from the curated catalogue in `Setting::colorPalettes()` and are exposed through shared primary/secondary CSS variables. Do not hardcode a second global palette or assume a saved palette name maps directly to a Tailwind colour.

- Use gradients for hero metrics, progress/runway and a small number of high-signal accents.
- Keep ordinary data surfaces neutral so semantic colours remain meaningful.
- Income/positive movement generally uses teal/emerald.
- Expenditure/pressure uses violet, orange or rose according to the page context.
- Errors use rose/red; warnings amber; information sky/blue; success emerald/teal.
- Never rely on colour alone: pair it with a label, icon, position or value.

Classification colours are stable:

| Classification | Visual family |
| --- | --- |
| Segment | amber |
| Category | emerald |
| Tag | indigo |
| Group | violet |

Use a compact visible key where classifications appear. Pills contain the assigned value only; do not repeat “Segment”, “Category”, “Tag” or “Group” inside every pill. Missing classifications receive an equally visible gap state.

## 5. Typography

`frontend/js/fonts.js` is the font authority. Honour the configured heading, body, table and chart families and accent weight. Choices are grouped through `Setting::fontGroups()`; load only the selected web fonts, with supported weights, so expanding the catalogue does not slow the Settings page. Choosing **Default** must restore the page’s designed typography rather than forcing a browser fallback.

The shared readable scale in `frontend/typography.css` targets:

- Operational body/control text: approximately 14px
- Table rows: approximately 13px
- Labels, captions and table headings: approximately 12px
- Secondary table metadata: approximately 11px, regular weight and muted colour
- Primary summary values: approximately 29px or larger when they are the hero outcome

Use uppercase, tracked captions sparingly for section labels. Within a table row, keep the primary value prominent and render only genuinely supporting information—such as a year, memo, account type or status qualifier—with the shared secondary treatment. Do not use the secondary treatment for amounts, names or classification pills, and do not shrink supporting text below 11px.

## 6. Controls and interaction

- Keep global filters few and consequential.
- Every form control has a visible label; use `data-help` for extra explanation. Professional fields and actions use the shared `--paper-control-radius`, `--paper-control-border` and `--paper-control-height` tokens in `frontend/css/theme-professional.css`, including dialogs. Search/tag/suffix controls have one outer edge and a borderless inner input, with focus shown on the whole field. Keep desktop density preferences, native checkbox/radio/range/colour geometry, 44px phone targets and 16px phone input text.
- Icon-only controls require a specific `aria-label`.
- Use `frontend/js/overlay.js` and `window.showMessage()` for notifications; do not create page-specific toast systems. Notices expand into a reserved shell row above the workspace, preserving the main scroll position and never covering its controls. Glass uses frosted translucent panels; Professional uses flat paper notices with semantic ink accents and horizontal rules. Keep 44px dismiss targets, live announcements, phone navigation clearance and reduced-motion support.
- Provide immediate visible feedback for save, run, update, loading and failure actions.
- Destructive or assignment-clearing actions are isolated and explicitly confirmed.

Workspace links use colour, chevrons and visible keyboard focus without text underlining. Composite Choices search fields own their inner spacing; shared standalone-input decoration must not pad their text-sized input.

## 7. Tables

Choose the table mechanism based on the task:

- Use a native semantic table for compact read-only or statement-style views.
- Use self-hosted Tabulator through `frontend/js/tabulator-tailwind.js` for large, searchable, sortable, paginated or virtualised datasets.
- Keep frozen columns opt-in and decorate rows only through `rowFormatter`.
- Use remote search/pagination for collections without a practical upper bound.
- On mobile, conventional tables become labelled cards; matrix/pivot views keep deliberate horizontal navigation.
- Prioritise identity and primary value columns. Hide secondary detail before compressing every column into unreadable text.
- Use `.table-secondary-text` for new supporting lines inside table cells; existing statement and account metadata hooks inherit the same shared size, weight and colour.

In Professional view, desktop tables use the compact density defined by `frontend/css/theme-professional.css`: reduced row, header and control height with tighter vertical padding. Do not apply that density below the mobile breakpoint, where card rows and touch targets retain their standard spacing.

Professional/Paper view uses a continuous document surface. Content sections are transparent against the sheet and separated primarily by horizontal rules; primary heroes flatten into report sections, tables use square ruled ledger edges, and desktop controls become denser. Inputs, buttons, warnings and the sidebar retain boundaries where they support interaction or orientation. Mobile keeps normal touch heights even though its surfaces remain flat.

Paper's typographic hierarchy is editorial: a strong document title above an ink rule, medium-weight section headings, regular-weight large financial figures with tabular numerals, quiet 12px captions, and smaller regular supporting text. Monthly Statement's multiline summaries remain 14px. Use the configured font families, accent weight and header-size options; do not impose a special Paper font. Flatten nested metric surfaces and remove secondary decorative accent slivers, but retain primary top accents, semantic classification colours, visible focus outlines and mobile touch spacing. Keep all Paper changes scoped to `body.theme-professional` in `frontend/css/theme-professional.css`; Glass must remain unchanged.

## 8. Charts and metrics

- Use the simplest chart that answers the question.
- Lead with a clear metric definition and denominator/window.
- Use `frontend/js/color_map.js` for chart colours and configured chart typography.
- Include `data-chart-desc` text and allow the shared fullscreen control.
- Use bars for ranking/comparison, lines or areas for movement, and stacked areas only when the composition matters.
- Empty charts show a designed empty state, not a blank rectangle.
- Cards, charts and exact-value tables must reconcile or visibly explain why their definitions differ.

For Daily Burn specifically, never merge actual transaction-day spending with the calendar-normalised rate. One explains spikes; the other explains underlying daily cost.

Keep summary icon boxes consistently sized and centred. Side-by-side Trends and Daily Burn metrics stretch to the summary row height with larger, vertically balanced values; stacked layouts remain sized to their content.

## 9. Responsive behaviour

Test at a normal desktop width and around 390px mobile width.

- Collapse multi-column summaries to one column before values become cramped.
- Keep hero metrics legible without dominating the full mobile viewport.
- Stack control groups and preserve comfortable touch targets.
- Ensure chart legends, labels and drill-down controls remain reachable.
- Verify `document.body.scrollWidth === window.innerWidth` unless horizontal navigation is explicitly contained within a matrix/table region.
- The sidebar becomes the shared mobile drawer; do not implement a page-specific mobile menu.
- User-selected density must not reduce mobile touch targets below the standard responsive design.

## 10. States, accessibility and help

Every data view needs:

- Loading state that says what is being prepared
- Populated state
- Useful empty state with a next action where possible
- Inline error state that preserves controls for retry
- Freshness/range context when the date scope is not obvious

Use semantic headings in order, landmarks, labelled controls, keyboard-focusable buttons/links and decorative icons marked `aria-hidden="true"`. Add a page-specific entry to `frontend/js/page_help.js`.

## 11. Implementation checklist

Before merging a new or redesigned page:

- [ ] Navigation placement and label fit the task-led information architecture.
- [ ] Shared page header and contextual help are present.
- [ ] Font and brand settings work.
- [ ] Financial definitions reuse backend/domain logic.
- [ ] Loading, empty, populated and error states are designed.
- [ ] Tables/charts use the shared adapters and colour system.
- [ ] Controls and icon actions are labelled and keyboard usable.
- [ ] Desktop and mobile browser checks pass without page overflow.
- [ ] PHP/JavaScript tests, syntax checks and `git diff --check` pass.
- [ ] Schema changes update `SchemaCatalog.php`; data changes use an explicit migration.

## Group Analysis

Group Analysis in Insights uses shared cards and a compact summary followed by a Highcharts spending chart and a paginated modern tag table. Dates are optional and inclusive; blank dates retain advance deposits and later costs. Money spent and money received stay separate, with net cost defined as spending less receipts. Amount links retain group, tag, dates and inclusion scopes. The all-entries link includes transfers and IGNORE rows for ledger review.

### Pension planning workspace

Pensions uses the shared full-width shell, page header, cards, selected palette and typography. Provider inputs appear in responsive cards, with paired numeric inputs for sliders. Before-tax income and today/future-pound labels stay explicit. Projection tables retain horizontal navigation inside their panel on mobile. A chart failure leaves the numerical results and yearly table available. Save state is visible, and transient notifications use the shared overlay.

### Mortgage planning workspace

Mortgage Planner uses the shared full-width shell, page header, surfaces and readable typography. Pair sliders with labelled numeric inputs; retain direct entry for exact term, rate and overpayment values. Keep normal and overpayment scenarios side by side, term-end shortfalls explicit, and wide amortisation tables horizontally navigable within their panel on mobile. Chart failure must preserve the comparison and schedule. Use the shared notification overlay and visible save state.


### iPhone and installed web-app shell

`frontend/js/web_app.js` and `frontend/css/web-app.css` own install help, connection state, session recovery, safe areas and phone navigation. Full documents declare the manifest, touch icon, theme colour and `viewport-fit=cover` before loading this script. Keep `.app-shell-main` as the sole page scroller. The fixed phone tabs provide Overview, Transactions, Planning and More; More opens the existing six-group menu without focusing the search field or opening the keyboard. The drawer contains keyboard focus while open. Desktop navigation stays unchanged.

Reserve space for the top controls, bottom tabs, live connection banner and home indicator. Inputs use at least 16px text and 44px touch height on phones. Transaction classification opens in a full-screen native dialog; closing it retains unfinished changes. Use the shared notification overlay for transient messages; persistent connectivity/session state belongs in the web-app layer. Preserve boolean `hidden` states even when a component uses flex/grid layout.

Editable forms may opt into in-memory navigation protection with `data-preserve-edits`; report a successful save through `AppWeb.markSaved(form)`, optionally supplying the captured submitted state if controls can change during a request. Never persist financial drafts on the device. Only read-only pages should opt into `data-resume-refresh`. Optional chart assets load after primary data, with numerical evidence remaining usable on failure. Rebuild the committed local Tailwind stylesheet after changing utility classes.

### Cross-page refinements

Specialist workspaces use the same readable hierarchy as dashboards: 14px for explanatory copy and operational controls, 12px for captions, and 11px only for secondary table metadata. Eyebrows use restrained tracking and the selected accent weight. Preserve large financial figures and the distinct Paper editorial hierarchy.

Glass has frosted headers, readable primary cards and flatter inset evidence. Static surfaces keep their elevation on hover; interactive cards may elevate. Paper catalogue workspaces and their rails share the continuous canvas, with one section rule and a neutral rail divider. Nested panels do not repeat the outer rule. Informational introductions remain quiet; genuine warnings retain semantic treatment.

Primary actions and focus treatments use the saved palette, including the independent pension and mortgage planners. Scope planner link treatments to their main workspace so navigation retains its shared appearance. On phones, page titles align below the separate navigation bar and compact heroes retain all their metrics.

The Settings sample previews Glass and Paper as distinct systems. It reflects unsaved palette, density, corner, title-size and typography selections without changing the live installation. Paper uses a warm canvas, solid accent, editorial title, unboxed figures and ruled ledger rows. Public offline and sign-in confirmation screens remain account-free, use a simple document layout, and preserve 44px minimum actions; changing the offline screen requires a new public service-worker cache version.

Sign-in, including the embedded session-recovery form, honours the same per-device Professional override as the workspace. Default choices in the appearance preview clear the selected sample weight/family; Default body type uses the root application font so it does not inherit a previously saved body font.

Daily Burn summary cards keep their natural height. Desktop metric captions sit beside the icon, while amounts and explanatory text use the full card width. The hero and three metrics share a row only from 1200px; tablet workspaces put the three metrics below the hero, and phones retain stacked cards.

Maintenance and taxonomy heroes, primary actions, links and decorative washes use the selected primary/secondary palette. Keep safety states, approvals, errors and rollback actions semantically coloured. In Professional, Daily Burn and taxonomy filters use flat ruled boundaries, maintenance heroes share the paper canvas, and nested explanatory content and cleanup metrics remain unboxed.

The main Tagging workspace follows the same Paper treatment: a flat hero, unboxed financial-health metrics, neutral tab boundaries and palette-based selection. Warning actions, including merge confirmations in dialogs, remain amber; danger and rollback actions retain red.

### Task-first usability (October 2026)

Find Transactions starts with compact search controls and recent activity (latest 100), followed by the result ledger, financial summary and optional spending chart. Date/account/direction/inclusion and classification-gap filters live in one disclosure; active evidence remains visible and one Reset removes every filter. All history is explicit. Chart failure must leave results and totals usable.

A classification review is an explicit batch of at most 250 eligible matching rows with progress and Save and next. A successful save advances only when the submitted controls are still current. Errors and newer edits stay in place. New-tag creation is secondary, and transfer/print actions belong to the transaction record outside the editor. Use one contextual Back on phones. Preserve only navigation controls/scroll in browser history state, never ledger rows or drafts.

After importing, offer exact imported transactions and classification-gap review, using opaque session evidence with a two-hour lifetime and 250-row pages. Expired evidence is an actionable error, never an unfiltered ledger. Failed files and duplicate rows are absent from this evidence.

The Planning landing opens the existing four planning workspaces. Saved pension and mortgage plans display their results before a collapsed Edit assumptions disclosure, with explicit saved/unsaved-preview wording. New plans open setup. Keep before-tax, illustrative and contractual-shortfall limitations beside the results.

Account names wrap and take precedence over identifiers; reveal the full identifier deliberately. Last transaction is activity, while Bank balance dated is the actual imported balance date. Glass supporting figures and embedded tables are flat within their containing surface. Paper keeps its continuous document canvas. Shared metadata uses a readable slate colour; internal transaction-evidence anchors use a quiet chevron and palette-based focus rather than an external-link glyph.

## Dedicated phone product

`phone.html` is a separate compact product, documented in [Phone Interface](PhoneInterface.md). It uses its own neutral light/dark palette, scrolling title, three labelled bottom tabs and native transaction lists. Do not load desktop theme/header/card/table systems into it. Preserve 44px controls, 16px inputs, safe areas, larger-text support and the shared secure session lifecycle. Classification remains a narrow, explicit single-entry edit.
