# Total Assets & Retirement

Open **Planning → Total Assets & Retirement** to see assets owned today beside a retirement scenario. Start by updating and saving your [pension pots](Pensions.md), [mortgage](Mortgages.md) and bank balances. Check all source dates; values are treated as today's starting balances even when recorded earlier.

The top horizontal bars separate pensions, liquid wealth, home equity and mortgage-backed property value. Mortgage debt is displayed for context and excluded from net wealth. Home equity equals property value less mortgage debt, including negative equity. Recorded open-account balances are included; closed accounts are excluded. Add only assets and debts not already represented by these sources. Other assets must be liquid or realisable at their entered value; other debts immediately offset liquid wealth.

## Explore a scenario

1. Set retirement age, forecast end age and monthly living-cost target in today's pounds, before tax. Mortgage payments are added separately. The target uses Go-Go spending when the saved pension plan enables phases.
2. Enter a current property valuation, replacement home price and all selling, buying, tax, moving and early repayment costs. Compare cash released by downsizing now. Enable downsizing to model a move at retirement. A negative release needs funding; equity alone is not spending money.
3. Try extra monthly pension contributions and cash saving from future earnings. Both remain fixed nominal payments until retirement; salary itself is never counted as wealth. Extra pension contributions are a hypothetical zero-fee pot, including whatever employer payments and relief you enter. Saved pots keep their provider fees.
4. Enter other retirement income and its starting age if relevant. This is an inflation-linked before-tax income stream to the end age, starting no earlier than retirement; exclude the State Pension already supplied in Pensions.
5. Compare lower, central and higher growth illustrations, the first tested whole-year retirement age meeting the target, and the yearly assets table. A shortfall can occur while inaccessible pensions or home wealth remain.
6. Select **Save scenario** to preserve assumptions. Planner defaults resets the preview only; save explicitly to keep it. Concurrent saves and changed source-plan revisions require reloading. No source planner is edited.

## Calculation boundaries

Monthly calculations honour saved pension growth, fees, inflation, access age, spending phases and user-supplied State Pension. Spending occurs before monthly growth; contributions arrive at month end and stop at retirement. Liquid savings fund withdrawals first, followed by accessible pension pots. Income first covers living costs and mortgage payments; any surplus is available but not reinvested. Negative liquid figures represent cumulative unfunded gaps, not available borrowing. Income is shown before tax and is not an annuity quotation.

Mortgage regular payments and any saved rate reset apply from the starting balance. Saved extra payments and lump sums are excluded to avoid assuming they can be paid from the same cash included as assets. Pre-retirement payments are assumed affordable from earnings in addition to contributions and savings. Retirement withdrawals also fund mortgage payments. Any outstanding contractual-maturity debt is repaid in full, drawing on cash and accessible pension assets; insufficient funding is reported rather than assuming refinancing. Early repayment charges must be entered separately in moving costs.

A move at retirement repays outstanding mortgage debt, buys the replacement home and pays costs; only the residual becomes liquid wealth. Both property prices follow the entered nominal home growth. Moving costs increase with inflation. No equity release loan is modelled. No inheritance reserve is retained in spendable assets. All pension pots are assumed accessible at the saved common access age.

Lower/higher cases change pension and liquid-asset growth by two percentage points either side of the entered rates. They do not change home growth. These deterministic cases do not measure likelihood or account for tax, investment sequence risk, contribution limits, benefits or care costs. An illustrative retirement age is never a guarantee. Review [MoneyHelper's downsizing guidance](https://www.moneyhelper.org.uk/en/pensions-and-retirement/taking-your-pension/downsizing-in-retirement) and use personal provider forecasts.

## Saving and backups

One installation-wide scenario is stored with optimistic revisions in the `total_assets_plan` setting; no schema migration is required. Include **Settings**, **Pensions** and **Mortgages** in [Backup & Restore](Setup.md#backups-and-restores) to preserve the full planning context. Drafts stay in memory and never enter browser storage or service-worker caches. Tables and calculations remain available if charts cannot load.

Verification: `php tests/total_assets_test.php`, `php tests/run_tests.php`, and `tests/total_assets_browser.cjs` with Playwright and a local PHP server. Browser tests use isolated source fixtures and real PHP forecasts; they do not modify application financial data.
