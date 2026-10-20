# October 2026 usability and appearance work

This completes sections A (usability) and B (look and feel) of the 20 October review. A separate phone frontend remains the next phase; this work refines the existing responsive application.

| Review item | Delivered behavior |
| --- | --- |
| A1 Activity first | Find Transactions loads the latest 100 rows automatically; controls and ledger precede summaries and charts. All history is explicit. |
| A2 Transaction editing | Wrapping account/classification identity, one editor Save action, secondary transfer/print menu, deliberate new-tag disclosure. |
| A3 Honest freshness | Last transaction wording and the bank-reported balance date are separate. |
| A4 Search filters | Dates, account, direction, transfers, ignored entries and missing tags/categories; incoming chart evidence remains scoped; Reset clears it. |
| A5 Contextual return | One phone Back action, validated originating URL, navigation-only list state and unsaved-edit protection. |
| A6 Planning | A landing page for budgets, projects, pensions and mortgages; existing URLs remain available. |
| A7 Import next step | Exact committed new transactions and gaps, via expiring session-owned evidence; duplicates and failed files are excluded. |
| A8 Review queue | Up to 250 eligible rows, progress and explicit Save and next; errors and newer edits prevent advancement. |
| A9 Returning planners | Saved results before collapsed assumptions, clear preview/save state, first-time setup open. Forecast calculations, warnings and revision checks retained. |
| B1 Metadata contrast | Shared secondary table text darkened to readable slate. |
| B2 Glass insets | Flat account summary and embedded ledger rows; planner figures lose repeated decorative borders. |
| B3 Account identity | Names wrap, full identifiers reveal deliberately; balances precede supporting counts. |
| B4 Internal evidence | Quiet internal chevron, existing descriptive accessible labels and palette-based keyboard focus. |
| B5 Plain language | Search/statement/task wording and user-facing import assurances. |
| B6 Results-first composition | Compact search, ledger before charts, saved planner results before forms, simpler editor actions and existing responsive spacing refinements. |

Validation: 709 PHP assertions; JavaScript checks including the 50-page application-shell audit; dedicated evidence ownership/expiry/pagination and return-navigation tests; rebuilt local assets. Browser verification used synthetic accounts/transactions and real planner calculation code, checking both themes, filters, save failure, edits during an in-flight save, successful queue advancement/return, planner save/preview states and the existing phone classification dialog. No physical iPhone hardware test was performed.

Evidence is limited to five recent session scopes, up to 100,000 committed IDs each, with 250-row pages and a maximum two-hour life. Oversized imports still complete; the UI explicitly withholds unavailable scoped links. No financial pages, drafts or writes are cached/queued on the device. No schema migration is required.
