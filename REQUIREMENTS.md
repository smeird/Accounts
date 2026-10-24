# Product requirements

This document describes the current functional baseline. New work should preserve the financial rules and shared UI behaviour unless a change explicitly replaces them.

## Cross-cutting requirements

- Require an authenticated session for application pages and JSON APIs.
- Use GBP formatting throughout the interface.
- Exclude confirmed transfers and `IGNORE`-tagged transactions from analytical totals.
- Preserve transaction history when accounts are closed; closed accounts have a zero live balance.
- Return structured errors and show useful loading, empty, success and failure states.
- Prevent stale page shells and local assets after deployment.
- Support keyboard use, descriptive labels and responsive layouts without horizontal document overflow.
- Give every active navigation page a modern page header and contextual help.

## Authentication and administration

- Authenticate with username/password and optional TOTP, or with a registered discoverable passkey that requires device-level user verification.
- Allow each signed-in user to register, name, review and remove multiple passkeys while retaining password login as recovery.
- Bind passkey ceremonies to one-time server challenges, the exact public origin and relying-party ID; store only public credentials and audit their use.
- Manage users and passwords without exposing stored credentials.
- Configure session timeout, branding, colour scheme, typography and AI settings.
- Keep OpenAI tokens server-side and expose only configured/not-configured state.
- Provide structured logs and configurable retention.

## Accounts and balances

- List active accounts, current balances, statement dates and recent movement.
- Edit account names and mark accounts closed or reopened.
- Accept a newer reliable OFX ledger balance; reject older snapshots and unreliable zero placeholders.
- Reconstruct balance history around dated ledger snapshots.

## Import and transaction integrity

- Import one or many OFX/QFX files with per-file progress and completion summaries.
- Commit each file atomically and roll back on downstream failure.
- Preserve complete transaction descriptions and memos within schema limits.
- Treat per-account bank FITIDs as authoritative and use a stable fallback identity when absent.
- Preserve legitimate same-day transactions with matching amounts/descriptions.
- Resolve uniquely matching masked accounts without creating duplicates.

## Transactions and reporting

- Search by text, memo, amount, classification and date range.
- Edit transaction detail and classifications.
- Save and reuse report definitions.
- Generate tables, charts, PDF/print output and OFX/CSV/XLSX exports.
- Support natural-language filters with deterministic fallback when AI is unavailable.
- Detect, link, unlink and assist with equal/opposite inter-account transfers.

## Classification

- Maintain reusable tags, merchant aliases, categories, segments and groups.
- Learn aliases from confirmed tagging and reuse canonical tags on similar transactions.
- Never create a new tag merely for every transaction.
- Assign tags to categories and categories to segments from searchable one-screen workspaces.
- Propagate mapping changes to matching existing transactions.
- Allow AI to map only unassigned tags to allowlisted existing categories at sufficient confidence.
- Preserve established mappings during automated classification.

## Analytical dashboards

- **Financial Overview:** balances, current cash flow, spending trend, budgets, accounts, recent activity and attention items.
- **Monthly Activity:** statement rows and summary metrics, rendering rows before secondary analytics.
- **Trends & Comparisons:** month, YTD, year, trailing 12 months, all-time and custom periods; like-for-like comparisons; category/segment/group/tag breakdowns and drill-downs.
- **Daily Burn:** expenditure divided by each month’s calendar days, history by segment, actual daily spending, rolling average and evidence links.
- **Year in Review:** annual income, expenditure, cash flow, positive months, quarterly movement and leading drivers.
- **Financial Picture:** reconciled portfolio position, cash-flow history and category/segment/tag/account context.
- **Regular Income & Bills:** recurring patterns, trailing totals, next-month estimates and evidence links.
- **Analysis Matrix:** expandable segment/category/tag analysis with income, expenditure and net movement.
- **Group Analysis:** optional inclusive dates, SQL-aggregated tag outgoings, receipts and net cost, visible untagged rows and exact transaction evidence; analytical totals exclude transfers and IGNORE.
- **12-Month Forecast:** expected, conservative and optimistic paths with visible assumptions and coverage.

## Daily Burn definition

- Count negative transaction amounts as positive expenditure values.
- Exclude income, confirmed transfers and `IGNORE`-tagged rows.
- Resolve segment from the transaction first, then its category; retain an `Unsegmented` bucket.
- Calculate `observed monthly expenditure ÷ calendar days in that month` for every month and segment.
- Keep actual transaction-day expenditure separate from the normalised burn rate.
- Define the historical average as the mean of selected monthly daily rates.
- Support 3, 6, 12 and 24-month windows plus available history.

## Planning and organisation

- Create monthly category budgets and visualise expenditure, runway and pressure.
- Suggest budgets with AI using observed history and a savings goal.
- Create, compare, score, archive and restore projects.
- Associate project spending with transaction groups and exclude transfers.
- Provide a Planning overview linking budgets, projects, pensions and mortgages.
- Save installation-wide pension and mortgage documents separately from bank totals, with optimistic revision checks and distinct backup parts.
- Pension illustrations support provider fees, fixed nominal contributions, inflation, optional user-supplied State Pension, selectable end age and level or Go-Go/Slow-Go/No-Go spending; never promise a retirement date or assume entitlement.
- Mortgage illustrations compare contractual payments and monthly/lump-sum overpayments, optional rate resets and explicit term-end shortfalls; fees and lender limits remain outside the model.

## Phone, sessions and imports

- Offer a dedicated neutral light/dark phone interface with Overview, This month and Search; preserve an explicit Full website escape for imports, planning and administration.
- Keep statement pickers unfiltered for iPhone Files; validate OFX/QFX extensions case-insensitively after selection and on the server.
- Check session validity on resume; refresh only when the opaque import revision changes and there are no unfinished edits. Preserve original-user reauthentication without replaying writes.
- Cache only the account-free offline screen, never financial pages, APIs, credentials or drafts.
- Keep import evidence session-owned, opaque, bounded and expiring; do not silently broaden expired evidence.

## Operations, health and recovery

- Run tagging, category and segment refreshes in the recommended order or individually.
- Keep assignment clearing separate and explicitly confirmed without deleting rules.
- Detect potential duplicates and support reviewed cleanup.
- Back up and restore selected business-data sections, passkey public credentials and saved pension/mortgage plans using format version 8. Backups are compressed, not encrypted.
- Application Updates may install only a clean, fetched and rechecked fast-forward on the current branch; private-repository helpers expose no general shell/Git authority.
- Audit schema tables, columns, indexes, keys and relationships against `SchemaCatalog.php`.
- Permit only catalogue-generated schema repairs; never generate record-changing repair SQL.

## UI and visual system

- Use task-led sidebar groups: Overview, Transactions, Insights, Planning, Organise and System.
- Render shared page headers with title, breadcrumb, subtitle and optional actions.
- Use shared glass cards by default and solid cards for dense controls.
- Honour configurable heading, body, table and chart fonts and accent weight.
- Use consistent semantic colours, gradients and classification pills.
- Render primary ledgers and exact evidence before optional charts; dashboard briefs may lead with summary figures. Chart failure must not block transaction results.
- Use native responsive tables for straightforward read-only views and the shared Tabulator adapter for large interactive grids.
- Validate every new surface on desktop and mobile. See [wiki/StyleGuide.md](wiki/StyleGuide.md).
