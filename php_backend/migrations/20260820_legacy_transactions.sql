-- Legacy transaction migration: accounts.Transations -> newaccounts.transactions
--
-- MySQL 8.0+ only. Run the complete script in MySQL Workbench first with the
-- final ROLLBACK unchanged. Review every result set, then replace only the final
-- ROLLBACK with COMMIT and run the complete script again.
--
-- This migration deliberately imports no legacy categories, tags, segments,
-- groups, ignore status, or transfer links. The current application should
-- identify transfers after import from the raw amount/date/account/OFX evidence.

SET @source_schema = 'accounts';
SET @target_schema = 'newaccounts';

-- Abort naturally if either expected table is unavailable to the current user.
SELECT COUNT(*) AS legacy_rows_before
FROM accounts.Transations;

SELECT COUNT(*) AS target_rows_before,
       MIN(`date`) AS target_first_date,
       MAX(`date`) AS target_last_date,
       ROUND(SUM(`amount`), 2) AS target_net_before
FROM newaccounts.transactions;

-- Preflight: all legacy account identifiers must resolve through an exact target
-- account number, one of the two confirmed historical aliases, or one of the
-- three closed accounts created below.
WITH account_resolution AS (
    SELECT o.id_Trans,
           LEFT(SHA2(TRIM(o.`account number`), 256), 8) AS fingerprint,
           CASE
               WHEN a.id IS NOT NULL THEN 'exact'
               WHEN LEFT(SHA2(TRIM(o.`account number`), 256), 8) IN
                    ('70d07f00', '6e229944') THEN 'confirmed alias'
               WHEN LEFT(SHA2(TRIM(o.`account number`), 256), 8) IN
                    ('a6b1b6e2', 'db93d95e', '78d51fa3') THEN 'closed account'
               ELSE 'unresolved'
           END AS resolution
    FROM accounts.Transations o
    LEFT JOIN newaccounts.accounts a
      ON TRIM(a.account_number) = TRIM(o.`account number`)
)
SELECT resolution, COUNT(*) AS legacy_rows
FROM account_resolution
GROUP BY resolution
ORDER BY resolution;

START TRANSACTION;

-- These three source identifiers are historical accounts that do not exist in
-- the current account catalogue. Their real account numbers are copied without
-- being printed by this script. Closed accounts have a zero live balance and do
-- not contribute to current portfolio balances.
INSERT INTO newaccounts.accounts
    (`name`, `sort_code`, `account_number`, `ledger_balance`,
     `ledger_balance_date`, `closed`, `closed_at`)
SELECT CASE LEFT(SHA2(TRIM(o.`account number`), 256), 8)
           WHEN 'a6b1b6e2' THEN 'Legacy account 5043 (to 2016)'
           WHEN 'db93d95e' THEN 'Legacy account 5043 (2016-2018)'
           WHEN '78d51fa3' THEN 'Legacy account 8968'
       END AS account_name,
       NULL,
       TRIM(o.`account number`),
       0.00,
       MAX(DATE(o.trans_date)),
       1,
       MAX(DATE(o.trans_date))
FROM accounts.Transations o
WHERE LEFT(SHA2(TRIM(o.`account number`), 256), 8) IN
      ('a6b1b6e2', 'db93d95e', '78d51fa3')
  AND NOT EXISTS (
      SELECT 1
      FROM newaccounts.accounts a
      WHERE TRIM(a.account_number) = TRIM(o.`account number`)
  )
GROUP BY TRIM(o.`account number`),
         LEFT(SHA2(TRIM(o.`account number`), 256), 8);

SELECT ROW_COUNT() AS closed_accounts_inserted;

-- Import one canonical row per account/FITID. Existing FITIDs and exact ledger
-- signatures are skipped, making the migration safe to rerun and protecting
-- against records already brought across by an earlier OFX import.
INSERT INTO newaccounts.transactions
    (`account_id`, `date`, `amount`, `description`, `memo`,
     `category_id`, `segment_id`, `tag_id`, `group_id`, `transfer_id`,
     `ofx_id`, `ofx_type`, `bank_ofx_id`)
WITH mapped_source AS (
    SELECT o.*,
           COALESCE(
               exact_account.id,
               CASE LEFT(SHA2(TRIM(o.`account number`), 256), 8)
                   WHEN '70d07f00' THEN job_fund.id
                   WHEN '6e229944' THEN credit_card.id
               END
           ) AS mapped_account_id
    FROM accounts.Transations o
    LEFT JOIN newaccounts.accounts exact_account
      ON TRIM(exact_account.account_number) = TRIM(o.`account number`)
    LEFT JOIN newaccounts.accounts job_fund
      ON job_fund.name = 'Find new Job Fund'
    LEFT JOIN newaccounts.accounts credit_card
      ON credit_card.name = 'Credit Card'
), ranked_source AS (
    SELECT m.*,
           ROW_NUMBER() OVER (
               PARTITION BY m.mapped_account_id, m.fitid
               ORDER BY m.id_Trans
           ) AS fitid_rank
    FROM mapped_source m
    WHERE m.mapped_account_id IS NOT NULL
), eligible_source AS (
    SELECT r.*,
           TRIM(COALESCE(NULLIF(r.Payee_name, ''),
                         NULLIF(r.Memo, ''),
                         'Unlabelled transaction')) AS target_description
    FROM ranked_source r
    WHERE r.fitid_rank = 1
      AND NOT EXISTS (
          SELECT 1
          FROM newaccounts.transactions t
          WHERE t.account_id = r.mapped_account_id
            AND t.bank_ofx_id = TRIM(r.fitid)
      )
)
SELECT e.mapped_account_id,
       DATE(e.trans_date),
       e.ammount,
       e.target_description,
       NULLIF(e.Memo, ''),
       NULL,
       NULL,
       NULL,
       NULL,
       NULL,
       CONCAT('legacy:accounts:Transations:', e.id_Trans),
       NULLIF(TRIM(e.type), ''),
       TRIM(e.fitid)
FROM eligible_source e
WHERE NOT EXISTS (
    SELECT 1
    FROM newaccounts.transactions t
    WHERE t.account_id = e.mapped_account_id
      AND t.date = DATE(e.trans_date)
      AND t.amount = e.ammount
      AND UPPER(TRIM(t.description)) = UPPER(e.target_description)
      AND UPPER(TRIM(COALESCE(t.memo, ''))) =
          UPPER(TRIM(COALESCE(e.Memo, '')))
);

SET @transactions_inserted = ROW_COUNT();
SELECT @transactions_inserted AS transactions_inserted;

-- Reconciliation inside the transaction. The expected value from the assessment
-- on 2026-08-20 was 22,690 inserted transactions. Stop and investigate if the
-- current result differs materially; the databases may have changed since then.
SELECT COUNT(*) AS target_rows_after,
       MIN(`date`) AS target_first_date_after,
       MAX(`date`) AS target_last_date_after,
       ROUND(SUM(`amount`), 2) AS target_net_after
FROM newaccounts.transactions;

SELECT a.name AS account,
       COUNT(*) AS imported_rows,
       MIN(t.date) AS first_date,
       MAX(t.date) AS last_date,
       ROUND(SUM(t.amount), 2) AS imported_net
FROM newaccounts.transactions t
JOIN newaccounts.accounts a ON a.id = t.account_id
WHERE t.ofx_id LIKE 'legacy:accounts:Transations:%'
GROUP BY a.id, a.name
ORDER BY first_date, a.name;

SELECT COUNT(*) AS unresolved_source_rows
FROM accounts.Transations o
WHERE NOT EXISTS (
    SELECT 1
    FROM newaccounts.accounts a
    WHERE TRIM(a.account_number) = TRIM(o.`account number`)
)
AND LEFT(SHA2(TRIM(o.`account number`), 256), 8) NOT IN
    ('70d07f00', '6e229944');

SELECT COUNT(*) AS imported_rows_with_classification_error
FROM newaccounts.transactions
WHERE ofx_id LIKE 'legacy:accounts:Transations:%'
  AND (category_id IS NOT NULL OR segment_id IS NOT NULL OR tag_id IS NOT NULL
       OR group_id IS NOT NULL OR transfer_id IS NOT NULL);

-- Safety default. Change only this final ROLLBACK to COMMIT after reviewing the
-- dry-run results above. Never run both statements.
ROLLBACK;
-- COMMIT;
