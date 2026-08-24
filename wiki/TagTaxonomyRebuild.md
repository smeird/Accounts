# Tag Taxonomy Rebuild

The rebuild treats bank wording, canonical tags, categories and segments as separate layers:

- An **alias** is stable wording received from a bank and points to one canonical tag.
- A **tag** is a controlled, reusable transaction type.
- A **category** is the broader reporting classification implied by a tag.
- A **segment** is the highest-level financial grouping implied by a category.

## Phase 1: preserve the current state

After deploying the Phase 1 code, open **System → Database Health** and apply the catalogue-controlled repairs. Then open **System → Tag Rebuild Safety** and create a named baseline snapshot.

The snapshot records every current transaction ID with its `tag_id`, `category_id` and `segment_id`. It also records whether the transaction is eligible for future retagging. Confirmed transfers and transactions carrying the `IGNORE` tag are protected. The snapshot receives a SHA-256 integrity hash and creation manifest; creating it does not update live classifications.

## Recovery guarantees

A restore is blocked when the integrity hash differs, a snapshotted transaction has been deleted, or an original tag/category/segment no longer exists. The preview reports how many assignments would change, including protected rows, and how many transactions were imported after the snapshot.

Only the three classification fields are restored. Amounts, dates, descriptions, memos, accounts, transfer links, groups and bank identifiers are outside the restore operation. Transactions imported after the snapshot are not touched.

Full application backups include migration runs and classification snapshots. Keep the baseline snapshot and a downloaded full backup until the rebuilt taxonomy has completed its observation period.

## Phase 2: discover and review the new taxonomy

After Phase 2 is deployed, apply the new catalogue repairs in **System → Database Health**, then open **System → Taxonomy Studio** and select the protected Phase 1 baseline.

Preparation reads only eligible snapshotted transactions. It removes changing numeric bank references, keeps incoming and outgoing patterns separate, and groups repeated wording into stable candidate aliases. Transfers and `IGNORE` transactions never enter the discovery queue.

AI analysis runs in bounded batches and returns proposed canonical tags, definitions, optional existing category IDs, confidence and a short rationale. All pattern IDs and category IDs are server-allowlisted. `IGNORE`, unknown patterns, unknown categories, duplicates and previously rejected names are refused. The model can add records only to the staging tables.

Every canonical proposal must be reviewed. A reviewer can edit the name, definition and existing category, approve it, or reject it. Rejecting a proposal returns its aliases to the pending AI queue and prevents that rejected name being suggested again. Adding a new alias to an already approved canonical tag returns that proposal to review.

The taxonomy can be marked ready when every eligible pattern resolves to an approved canonical proposal. Once transaction coverage reaches 95%, the reviewer may instead choose **Finish and defer remainder**. This requires every active canonical proposal to be approved and records the unresolved patterns as deferred, leaving those transactions unchanged. Ready means the staging vocabulary is frozen for the later cutover phase; it still does not create live tags, aliases or transaction assignments.

## Phase 3: reconcile and apply

After Phase 3 is deployed, run **System → Database Health** once more. This adds direction to live alias rules and the cutover audit field. Existing rules default to **Either direction**; reviewed patterns create distinct incoming and outgoing rules where the same wording has different meanings, such as a purchase and its refund.

Open **System → Taxonomy Cutover** to review the exact plan. Apply stays disabled unless the immutable snapshot hash is valid, approved coverage is at least 95%, every analysed pattern points to one approved tag, and no direction-specific alias points to competing destinations. The preview identifies new and reused tags, category and segment mappings, direction-aware aliases, deferred transactions, newly protected transactions and imports made after the snapshot.

The confirmed cutover runs inside one database transaction. It creates or reuses the reviewed canonical tags, installs their direction-aware aliases, applies reviewed category and segment relationships, and retags only covered, eligible snapshot transactions. Transfers, `IGNORE` rows, deferred patterns and post-snapshot imports remain unchanged. Old tags are deprecated only when the cutover leaves them unused.

Before commit, the service verifies every expected classification, hashes the untouched classification set, and compares the complete ledger transaction count, signed amount total and absolute amount total with the pre-cutover fingerprint. Any difference cancels the entire operation. The audit record retains the previous tag, alias and category state plus the immutable snapshot reference.

A rollback is available only while the audited cutover state still matches live state. It restores snapshot classifications and the previous taxonomy relationships atomically, leaves post-snapshot transactions untouched, and reconciles the financial fingerprint again. If later manual changes would make rollback unsafe, it is blocked instead of overwriting them.

Full backups use format version 5 and preserve the baseline, candidate patterns, canonical proposals, reviews, direction-aware aliases, transaction-level staging coverage and cutover audit.

## Acceptance thresholds

- At least 95% of eligible transactions assigned to reviewed canonical proposals; any remainder must be explicitly deferred.
- 100% of confirmed transfers protected.
- 100% reconciliation of financial totals before and after cutover.
- No unreviewed canonical tags created by automated processing.
- Zero taxonomy growth when an unchanged dataset is processed again.
- Alias false-positive rate below 2% in the approved evaluation sample.
