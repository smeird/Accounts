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

- Installation appearance settings provide the default surface style, desktop density, corner shape, backdrop strength and motion level. Implement shared components so they continue to respond to the classes applied by `frontend/js/menu.js` and `frontend/css/interface-preferences.css`.
- Glass is the expressive default; Paper reuses Professional view’s flat, document-like treatment. The sidebar switch remains a per-device override of the installation default.
- Compact and Roomy density change desktop spacing only. Preserve standard mobile touch targets and card-row spacing.
- Use `cards` for the standard glass surface.
- Use `cards cards-solid` for dense filters or controls needing stronger contrast.
- Use `cards cards-tight` for compact forms, tables and comparable chart panels.
- Specialist pages may use a semantic page-specific card class, but should retain the same border opacity, rounded geometry and soft shadow family.
- Use one principal scroll container (`main.ops-main`); avoid nested full-page scrolling and document-level horizontal overflow.
- Prefer consistent 12–18px internal gaps and 16–22px card padding. Reduce, do not remove, spacing on mobile.

## 4. Colour and gradients

Brand colours come from shared settings and CSS variables. Do not hardcode a second global palette.

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

`frontend/js/fonts.js` is the font authority. Honour the configured heading, body, table and chart families and accent weight. Choosing **Default** must restore the page’s designed typography rather than forcing a browser fallback.

The shared readable scale in `frontend/typography.css` targets:

- Operational body/control text: approximately 14px
- Table rows: approximately 13px
- Labels, captions and table headings: approximately 12px
- Secondary table metadata: approximately 11px, regular weight and muted colour
- Primary summary values: approximately 29px or larger when they are the hero outcome

Use uppercase, tracked captions sparingly for section labels. Within a table row, keep the primary value prominent and render only genuinely supporting information—such as a year, memo, account type or status qualifier—with the shared secondary treatment. Do not use the secondary treatment for amounts, names or classification pills, and do not shrink supporting text below 11px.

## 6. Controls and interaction

- Keep global filters few and consequential.
- Every form control has a visible label; use `data-help` for extra explanation.
- Icon-only controls require a specific `aria-label`.
- Use `frontend/js/overlay.js` and `window.showMessage()` for notifications; do not create page-specific toast systems.
- Provide immediate visible feedback for save, run, update, loading and failure actions.
- Destructive or assignment-clearing actions are isolated and explicitly confirmed.

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

Professional view uses a plain-paper surface model. Most content cards and panels keep their white background but omit their outline and elevation; use spacing, typography and internal dividers to communicate structure. Inputs, buttons, warnings, deliberate hero treatments and the sidebar may retain boundaries where they support interaction or orientation.

## 8. Charts and metrics

- Use the simplest chart that answers the question.
- Lead with a clear metric definition and denominator/window.
- Use `frontend/js/color_map.js` for chart colours and configured chart typography.
- Include `data-chart-desc` text and allow the shared fullscreen control.
- Use bars for ranking/comparison, lines or areas for movement, and stacked areas only when the composition matters.
- Empty charts show a designed empty state, not a blank rectangle.
- Cards, charts and exact-value tables must reconcile or visibly explain why their definitions differ.

For Daily Burn specifically, never merge actual transaction-day spending with the calendar-normalised rate. One explains spikes; the other explains underlying daily cost.

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
