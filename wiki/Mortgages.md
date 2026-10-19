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

Actual lenders may calculate interest daily and use different payment dates. Fees, redemption charges, early-repayment charges, annual overpayment allowances, payment holidays, offset accounts, interest-only borrowing and further future rate changes are excluded. See [MoneyHelper’s overpayment guidance](https://www.moneyhelper.org.uk/en/homes/buying-a-home/should-you-pay-off-your-mortgage-early) and check your own terms before making payments.

## Installation, backups and tests

Fresh installations create `mortgage_plans` through `SchemaCatalog`. On an existing installation, use **System → Database Health** to create that table safely. Never run the destructive fresh-install schema reset against a populated database.

The bounded planning document is stored separately from the ledger with a revision. Backup format version 8 includes mortgage data; restore accepts older versions and leaves a mortgage workspace alone when the backup contains no mortgage section. Restores validate the plan and advance the revision so old browser saves cannot overwrite restored data. A missing table does not break Financial Overview or pre-migration backups.

Run `php tests/run_tests.php` for amortisation, date handling, rate changes, validation, save conflicts, dashboard totals and backup/restore. With a local PHP server and Playwright available, run `node tests/mortgages_browser.cjs` for browser verification using isolated API state and the real PHP calculator.
