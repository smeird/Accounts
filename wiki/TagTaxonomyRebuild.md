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

## Acceptance thresholds

- At least 98% of eligible transactions classified or explicitly reviewed.
- 100% of confirmed transfers protected.
- 100% reconciliation of financial totals before and after cutover.
- No unreviewed canonical tags created by automated processing.
- Zero taxonomy growth when an unchanged dataset is processed again.
- Alias false-positive rate below 2% in the approved evaluation sample.
