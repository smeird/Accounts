# Mortgage Planner

Open **Planning → Mortgage Planner** to model one repayment mortgage separately from bank balances and transactions.

## Get started

1. Enter the lender/name, outstanding balance, balance date, annual mortgage interest rate and remaining contractual term in months. The term caption shows years and months.
2. Enter the normal monthly payment from your statement, excluding overpayments. Leave blank or zero to calculate a repayment from the balance, rate and term.
3. Explore an extra monthly payment with the slider or numeric input, and optionally a one-off overpayment on the balance date.
4. Under **What if the interest rate changes?**, enter the payment month when the new rate starts and its assumed rate. Month 25 gives 24 payments at the old rate. Zero disables the change. Choose whether to recalculate the normal payment over the remaining contractual term or hold it fixed.
5. Compare the payoff date, time saved, total interest, interest saved, first-month interest/capital and any amount still owed at the contractual term end. Open the repayment schedule for annual checkpoints or compare the two balance curves.
6. Save to persist the plan and update Financial Overview. Include **Mortgage & repayment plan** in a selective backup; full backups include it automatically.

Changes are previews until saved. The installation shares one mortgage plan among signed-in users. Saving uses an optimistic revision check to prevent an older browser from overwriting a newer plan.

## Calculation and limits

The planner uses standard repayment amortisation. Monthly interest is the opening balance multiplied by the annual interest rate divided by 12, rounded to pennies. Interest is charged before payment. The zero-rate calculation divides the balance by the term without an interest formula. Payments are capped at the balance plus interest, and calculated repayment schedules use an adjusted final payment for rounding.

The first payment is one calendar month after the balance date. Month-end dates clamp to the last valid day while preserving the original anchor day for later months. The planner does not invent transactions or past payments: update an old balance/date to obtain a current illustration. Dates over 60 days old are flagged.

The baseline excludes both overpayments; the comparison includes both. The lump sum reduces principal before the first interest charge. Extra monthly payments remain fixed and aim to reduce the payoff time. At a rate reset, recalculated payments use each scenario’s then-current balance and remaining contractual months, with extras on top. Holding payments fixed can extend the illustrated payoff beyond the contractual end or leave growing debt.

A contractual maturity date is not proof the balance is paid. Any shortfall remains visible. Dates beyond that term are illustrations and do not represent a lender-agreed extension. No payoff is claimed if the balance does not clear within 1,200 months, and very large growing balances stop early. Lifetime interest and savings are unavailable if either scenario never clears; missing term-end figures are not presented as zero.

Actual lenders may calculate interest daily and use different payment dates. The original overpayment illustration excludes fees; renewal comparisons below account for entered product fees and cashback only. Other fees, redemption charges, early-repayment charges, annual overpayment allowances, payment holidays, offset accounts, interest-only borrowing and further future rate changes are excluded. See [MoneyHelper’s overpayment guidance](https://www.moneyhelper.org.uk/en/homes/buying-a-home/should-you-pay-off-your-mortgage-early) and check your own terms before making payments.

## Installation, backups and tests

Fresh installations create `mortgage_plans` through `SchemaCatalog`. On an existing installation, use **System → Database Health** to create that table safely. Never run the destructive fresh-install schema reset against a populated database.

The bounded planning document is stored separately from the ledger with a revision. Backup format version 8 includes mortgage data; restore accepts older versions and leaves a mortgage workspace alone when the backup contains no mortgage section. Restores validate the plan and advance the revision so old browser saves cannot overwrite restored data. A missing table does not break Financial Overview or pre-migration backups.

Run `php tests/run_tests.php` for amortisation, date handling, rate changes, validation, save conflicts, dashboard totals and backup/restore. With a local PHP server and Playwright available, run `node tests/mortgages_browser.cjs` for browser verification using isolated API state and the real PHP calculator.

## Fixed-deal deadline and renewal planning

Under **Edit assumptions → Your current fixed deal**, enter the exact start/end dates from your offer, an estimated property value and how many months before expiry you want to begin reviewing deals (default six). The result shows days until expiry, progress through the recorded fixed period, your personal review date, projected debt and loan-to-value. The reminder is not a lender application window or a notification service outside the page.

The renewal projection keeps your entered current rate until the deal ends. It counts anchored monthly payment dates on or before that date at the old rate; the following payment uses the comparison rate. It models normal payments and the balance reduction from your immediate/monthly overpayments separately. A lender’s actual daily-interest and payment calendar can differ. The review date clamps month-end days rather than rolling into a later month. An expired deal is flagged: update the balance and actual current rate instead of treating an old fixed rate as today’s variable rate.

Renewal comparisons are separate from the original **What if the interest rate changes?** experiment, which still supports one optional reset. That experiment does not change the renewal projection. If the contractual mortgage term finishes before renewal, there is no remaining term to re-amortise.

## Manual comparisons

A horizontal monthly-payment chart shows your current regular payment beside each renewal deal, with a dashed current-payment reference line and explicit increases or savings. When overpayments change the projected balance, paired bars show both renewal scenarios; they exclude continuing monthly extras. Upfront fees/cashback stay in the cost table; financed fees are included in monthly payments. The payment/change summaries and full cost table remain available if charts cannot load.

Save up to twelve named comparisons, each with the annual interest rate, new fixed period in months, product fee, cashback and whether to finance the fee. The illustration projects the debt to renewal and recalculates the new monthly payment over the remaining contractual term. It also shows the payment on the lower balance resulting from planned pre-renewal overpayments. Continuing overpayments after renewal are not included in those quoted normal monthly payments.

Compare like fixed periods. The table shows monthly payment/change, fee treatment, cash paid during the illustrated fix, financing cost and closing debt. Cash paid includes repayments and upfront fees less cashback. Financing cost is repayments plus upfront fees plus closing debt minus the original debt and cashback, so capital repayments are not mistaken for an expense and financed fees are counted once. The period is capped at the remaining mortgage term. Only product fees and cashback are included; valuation, legal, broker, redemption and early-repayment charges remain outside the model. No comparison is an offer or an eligibility decision.

Older saved plans and backups gain empty renewal dates and comparisons without a schema migration. Dates and comparisons travel in the existing mortgage backup part and retain the existing optimistic save protection. Nothing changes bank transactions, account totals or your saved plan until you explicitly save.

## Free public lender collection

**Published lender rates** checks three official sources:

- [NatWest public residential rates](https://www.natwest.com/mortgages/mortgage-rates.html): fixed-rate product cards, with published LTV, fees and visible restrictions. This general range does not reliably identify remortgage eligibility or every incentive; check the lender before using a product.
- [HSBC remortgage rates](https://www.hsbc.co.uk/mortgages/move-your-mortgage/rates/): fixed-rate tables, keeping interest separate from APRC/reversion rates and preserving fee/incentive variants. Premier conditions remain visible.
- [Barclays remortgage comparison](https://www.barclays.co.uk/mortgages/remortgage/rates/): the public product JSON linked by that page; only fixed residential products marked available for remortgage are collected. Published conditions and fixed end dates still need checking.

No commercial feed or subscription is used. This is a selected public benchmark list, not whole-market sourcing. It cannot see personalised retention offers or establish affordability. Today’s nominal two/five-year products may have a specific fixed end date and may not exist at your future renewal. Copying one creates an editable assumption; confirm the period, eligibility, fees and incentives before saving. Collection never selects a deal or overwrites your assumptions automatically.

Each source shows **last successful update**, last attempt, any source error and whether data is stale (no success in 48 hours). Failed HTTP requests, oversized responses and unrecognised markup leave the previous good products and success timestamp intact. Barclays’ source-file publication time is shown separately when available; a successful download does not prove the lender changed its products that day.

The page checks cached status immediately and automatically requests sources whose last attempt is at least 12 hours old. **Check lender rates** requests a refresh, subject to a 12-hour per-source cooldown (including failed attempts) and a production PostgreSQL lock against concurrent updates. No background scheduler is installed: checks happen while the page is open. The server uses only fixed allowlisted HTTPS destinations, does not follow redirects, bounds response size/time, and sends no balance, property value or customer details to lenders. A blocked or redesigned site remains a visible failed source; no anti-bot challenge is bypassed.

Cached public products/status are disposable `settings` entries named `mortgage_rates_natwest`, `mortgage_rates_hsbc` and `mortgage_rates_barclays`. They travel only with a settings backup; your manual/copy-edited assumptions travel with Mortgages. Do not use these endpoints for external arbitrary URLs.

## Collector and renewal verification

```bash
php scripts/check-mortgage-rates.php
php scripts/check-mortgage-rates.php --live
php tests/run_tests.php
```

The harness is CLI-only and offline by default, using synthetic markup contracts. `--live` fetches the three official public sources and reports counts and a sample without writing any settings or touching the ledger. Live source layouts were checked during implementation on 24 October 2026; counts change as products change. `MortgageRenewalTest.php` covers expiry/payment boundaries, fee financing, legacy-plan compatibility, source parsing, retained success after failure, refresh cooldown and URL restrictions. With a local server and Playwright, run `node tests/mortgage_renewal_browser.cjs` for deadline, comparison, conflict, source-failure and mobile/Paper checks.
