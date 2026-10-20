# Pensions & Retirement

Open **Planning → Pensions & Retirement**. This workspace models investment pension pots independently of your bank accounts and transaction ledger.

## Getting started

1. Set your age and the date on which that age is correct. The initial age of 52 is only a starting estimate; decimals are supported.
2. Add each provider, its latest pot value and valuation date, total monthly contributions, and annual percentage fee. Contributions mean the amount actually reaching the pot, including employer contributions and tax relief.
3. Move the retirement-age and forecast-end-age sliders. The default end age is 95.
4. Inspect monthly and daily before-tax income in today’s money and pounds at retirement. Optionally enter a monthly target to find the earliest whole-year retirement age meeting that target under the central assumptions.
5. Add your own [State Pension forecast](https://www.gov.uk/check-state-pension) and [confirmed State Pension age](https://www.gov.uk/state-pension-age), if known. No entitlement is assumed.
6. Save the plan to persist changes and update Financial Overview. Include **Pension pots & retirement plan** in a selective backup; full backups include it automatically.

Changes are previews until saved. Another browser cannot silently overwrite a newer saved revision. Plan and provider details are shared by signed-in users of the installation, like the account ledger.

## Three-phase spending

In **Edit assumptions**, choose **Go-Go / Slow-Go / No-Go** to split retirement into three equal age spans ending at the selected forecast end age. Go-Go is the reference (100%); editable Slow-Go and No-Go levels default to 80% and 60% of its purchasing power. These are starting assumptions, not recommended spending levels. No-Go cannot exceed Slow-Go. Later care costs may increase rather than fall.

The model solves for the Go-Go monthly income that can fund the entire profile, allowing more spending earlier in exchange for lower later targets. The phase table shows exact age boundaries and central monthly targets; yearly figures also include monthly phase transitions. Withdrawals use the phase at each month’s start, so a fractional-month boundary takes effect at the next withdrawal. State Pension remains a floor on available income; surplus over a phase target is not reinvested. The optional retirement-age target tests Go-Go income. Saved profiles feed Financial Overview and travel with pension backups; older plans retain level income.

## Calculation

The deterministic model compounds monthly. Before retirement, each pot earns its own fee-adjusted nominal return and receives fixed nominal contributions at month end. At retirement, the projected total is converted into today’s purchasing power. Retirement uses the value-weighted monthly return of the projected pots, assuming a rebalanced portfolio, net of fees and inflation.

A bounded numerical search finds a level monthly real income fundable from the retirement date through to the chosen end age. Withdrawals occur at month start, followed by monthly growth. State Pension is deducted from the withdrawal needed only after its specified start age. The balance must stay nonnegative in every month, so later State Pension cannot fund an earlier shortfall. When State Pension exceeds this income, the excess is available to spend and is not reinvested. The yearly table shows this increase.

Lower and higher scenarios shift both growth assumptions by minus/plus two percentage points. They are sensitivity illustrations, not probability bounds. Daily income is monthly income multiplied by 12 and divided by 365.2425. Future-pound headline amounts refer to retirement; each yearly row uses inflation to that row’s age. Monthly intervals round up when dates or fractional ages fall between months.

Valuations are treated as today’s starting balances. Past growth or contributions are not invented; values older than 90 days are flagged. Income is before tax and assumes no initial lump sum or inheritance reserve. Tax, allowances, other savings, guarantees, market volatility and changing government policy are not modelled. These are planning illustrations, not guaranteed income or annuity quotations.

Private pension access and State Pension ages are distinct. The default private access age is 57, reflecting the normal minimum age from April 2028. Change it only to match confirmed scheme eligibility. See [MoneyHelper’s access guidance](https://www.moneyhelper.org.uk/en/pensions-and-retirement/taking-your-pension/when-can-i-take-money-from-my-pension.html).

## Installation and verification

Existing installations: use **System → Database Health** to create the missing `pension_plans` table from `SchemaCatalog`. Fresh installs create it with the other tables. Do not run the destructive fresh-install schema reset against an existing database.

Backup format version 7 includes the pension section; restore continues to accept previous versions. The versioned, bounded JSON document contains up to 100 pots and is stored in its own table with an optimistic revision. Restores validate its contents and advance the revision to invalidate old browser saves. An expired retirement age can be restored and then adjusted in the planner. Older backups without pensions leave the existing pension workspace unchanged.

Run `php tests/run_tests.php` for calculations, validation, persistence, dashboard integration and backup/restore. With a local PHP server and Playwright available, run `node tests/pensions_browser.cjs` for browser verification using isolated API fixtures and the real PHP calculator. Neither test requires entering personal pension data.
