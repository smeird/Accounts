# Tagging logic review and repair plan

Reviewed 6 September 2026. **Eight confirmed issue groups need repair.** Existing tests pass, but do not cover these failure cases. This review changes no application behaviour or live classifications.

## Scope and validation

Traced deterministic matching, import/account tagging, alias learning, the tagging workspace, transaction-edit endpoints, AI tagging and correction, category/segment propagation, and legacy tag APIs. Checked the rebuild/fresh-start protection paths and their existing regression coverage; this is not an exhaustive concurrency audit of the migration subsystem.

- `php tests/run_tests.php`: passed on PHP 8.5.8 using the suite's isolated in-memory SQLite database.
- Five frontend test files passed: `tagging`, `transaction_tag_picker`, `tag_taxonomy_discovery`, `tag_taxonomy_cutover`, and `tag_migration`.
- Additional isolated SQLite probes reproduced the outcomes below using the actual model/service methods. The AI grouping query was executed directly; no paid AI requests were made.
- No production database was inspected or modified. PostgreSQL-specific execution and concurrent requests still need integration testing. These findings establish defects in the code, not the number of affected live transactions.

Existing strengths include direction/exact/length precedence in deterministic aliases, retaining the complete AI canonical-name allowlist, normal retirement retaining historical assignments, and snapshot/cutover/fresh-start protection tests.

## Confirmed findings

### 1. P1 — AI batches lose transaction direction

**Code:** `php_backend/public/ai_tags.php:37`, `:345`, `:348`.

The query groups by description and memo and averages the amount. Both update branches use those same text fields without a direction predicate. An incoming and outgoing transaction with identical wording therefore receive one decision. The average also drives the direction of the learned rule.

**Reproduction:** two untagged rows with the same wording and amounts −£100 and +£100 became one batch entry with `amount=0` and `cnt=2`. The endpoint's update predicate includes both rows. This can mix repayments, refunds, spending and income, and create an `any`-direction rule from offsetting amounts.

**Fix:** include outgoing/incoming/zero direction in grouping, prompt context, learning and writes. Freeze the candidate IDs sent for review and update only still-eligible members of that batch. Do not infer a group's direction from an average.

**Acceptance:** opposite signs with identical description/memo receive independent decisions; zero amounts have a defined separate scope; a newly imported matching row is not silently included in an old AI decision.

### 2. P1 — Alias conflicts bypass the AI active-tag allowlist

**Code:** `php_backend/models/Tag.php:383`; `php_backend/public/ai_tags.php:307`.

Alias learning looks up existing rules without checking the rule's active flag or its tag's status. AI tagging then replaces the accepted canonical tag with `existing_tag_id` on a conflict. Retirement disables rules but retains them, so a disabled rule belonging to a retired tag can send new transactions back into that retired tag.

**Reproduction:** retire a tag with an `oldmerchant` outgoing rule, then learn that merchant for an active tag. Learning returns `conflict` and the retired tag ID; the endpoint explicitly adopts this ID.

**Fix:** return conflicts for review rather than overriding the accepted tag with an inactive mapping. Check target eligibility again immediately before writing, including after the AI response. Define reuse of retired rule wording as an explicit reviewed rule edit.

**Acceptance:** active, disabled, deprecated and merged conflict cases; retirement during the AI request; no transaction or new rule may acquire a non-selectable destination.

### 3. P1 — Automatic learning re-enables and broadens manually configured rules

**Code:** `php_backend/models/Tag.php:394`.

When an alias already belongs to the selected tag, learning unconditionally sets `active=1` and `match_type=contains`. This branch also skips overlap warnings. Simply saving a transaction or accepting an AI decision can undo a deliberate disabled/exact setting.

**Reproduction:** learning `ACME` against an existing disabled exact outgoing `acme` rule changed it to active contains. It can then match `ACME OTHER SERVICE`.

**Fix:** preserve existing activation and match type during learning. Reactivation or broadening must go through the rule editor's explicit change and overlap-confirmation path. Evidence recording should not change rule semantics.

**Acceptance:** enabled/disabled × exact/contains rules remain unchanged after manual and AI learning, including cases with a competing tag's overlapping rule.

### 4. P1 — Narrow AI corrections move broader future rules

**Code:** `php_backend/services/AiTagCorrectionService.php:197`, especially `:207`.

`moveMatchingAliases()` moves a rule when either its wording contains the correction term or the term contains the rule wording. A narrow historical correction can therefore redirect a much broader rule. The preview shows transaction samples but does not enumerate this future-rule impact.

**Reproduction:** correct only `AMAZON PRIME` from source to target while leaving `AMAZON SHOPPING` on the source. The broad `amazon` rule moved to the target, and the matcher subsequently selected the target for ordinary shopping.

**Fix:** preview exact rule changes separately. Do not move a rule whose scope exceeds the corrected transaction pattern. Preserve the broad rule and, when appropriate, propose a narrower direction-aware rule with overlap confirmation.

**Acceptance:** subscription-only correction leaves ordinary shopping's historical and future classification intact; exact/contains and direction combinations are covered; changes between preview and apply invalidate the preview.

### 5. P1 — Correction apply does not revalidate current eligibility

**Code:** `php_backend/services/AiTagCorrectionService.php:117`, `:127`, `:133`, `:150`, `:171`.

The target is checked when building the preview but not at apply. Selection and update do not exclude confirmed transfers or IGNORE transactions. Empty-source merging bypasses the protected-system-tag checks in `Tag::merge()`.

**Reproduction:** prepare a correction targeting an active tag, retire that target, then apply. The service updated a confirmed-transfer row to the retired destination successfully. Code inspection also shows no IGNORE exclusion or system-source guard.

**Fix:** lock and revalidate destination/source state at apply; retain the previewed transaction set and require rows to remain eligible. Exclude protected classifications from bulk correction and reject protected source retirement/merge. Show skipped or stale rows explicitly, and bind rule mutations to the validated preview.

**Acceptance:** target retired/merged after preview; transaction becomes a transfer or IGNORE after preview; protected source tag; changed rule scope; concurrent manual classification. Every rejected operation leaves classifications and rules unchanged.

### 6. P1 — Legacy tag APIs bypass newer safeguards

**Code:** `php_backend/public/tags.php:15`, `:19`, PUT branch; `php_backend/models/Tag.php:273`, `:647`; `php_backend/public/update_transaction_tag.php:38`; `php_backend/public/update_transaction.php:64`.

The authenticated `remap_aliases` action remains callable and re-evaluates already tagged transactions without IGNORE protection or the snapshot/guarded-cutover workflow. The legacy PUT handler calls unrestricted `Tag::update()`, bypassing the workspace's protection against renaming system tags. Transaction-edit paths accept supplied tag IDs without checking active status and use an all-status lookup for names.

**Reproduction:** an IGNORE-tagged `ACME` transaction was changed to an ordinary tag by `remapAllTransactionsToCanonicalTags(true)`. The other bypasses are directly visible in endpoint/model validation.

**Fix:** remove obsolete remap mutation access or route it through a previewed, snapshot-backed operation. Route catalogue mutations through the guarded service and validate transaction destinations server-side. Keep deliberate single-transaction IGNORE assignment available without permitting bulk un-ignoring through remap.

**Acceptance:** exercise HTTP endpoints, not only service methods: protected rename fails; retired/merged destination fails; legacy remap cannot rewrite protected history; supported bookmark redirects continue working.

### 7. P2 — Segment propagation leaves stale values and rewrites transfers

**Code:** `php_backend/models/CategoryTag.php:25`; `php_backend/models/Segment.php:177`.

Category assignment updates transaction categories, but segment propagation only writes a non-null category-derived segment. It never clears a segment after the category or its segment is removed, and it has no transfer exclusion.

**Reproduction:** clearing a tag's category and running propagation left `{category_id:null, segment_id:1}`. A confirmed transfer with stored segment 99 was rewritten to its category's segment 1. This leaves stored classifications inconsistent and can change protected audit state; reporting impact depends on whether a view uses the stored segment or joins through category.

**Fix:** reconcile segment IDs in both directions, including null, and preserve protected transfers. Put affected tag/category/segment changes into one transaction rather than relying on a later global pass. Define and test IGNORE preservation consistently.

**Acceptance:** category A→B, category→null, category with no segment, segment removal, transfers, IGNORE, and repeat execution. A second unchanged pass should make no changes.

### 8. P2 — Failed catalogue saves can partially persist

**Code:** `php_backend/services/TaggingWorkspaceService.php:46`, `:64`.

Create/reactivate or rename occurs before category validation inside `CategoryTag::assign()`. Its transaction cannot roll back the earlier tag mutation. Segment propagation is a separate later operation as well.

**Reproduction:** update an active tag's name while supplying a nonexistent category. The service threw `Category not found`, but the new name remained saved.

**Fix:** validate referenced objects and wrap the entire catalogue operation in one service-owned database transaction. Refactor nested transaction ownership in category assignment so the complete operation can roll back safely.

**Acceptance:** invalid category, duplicate name, and injected propagation failure leave tag metadata, status, mappings and transaction classifications unchanged. Include reactivation and create cases.

## Implementation sequence

1. **Add failing behavioural regressions for findings 1–8.** Extract the AI decision-application logic from the HTTP script into a service with an injectable model response. Preserve endpoint behaviour while enabling tests without network requests. Add endpoint tests for legacy bypasses.
2. **Contain incorrect writes.** Fix direction scoping, retired-target conflicts, rule-setting preservation and obsolete endpoint bypasses (1–3, 6). Revalidate current tag/transaction state at write time. Use conditional updates so concurrent manual edits are not overwritten.
3. **Make correction previews authoritative.** Fix 4–5 together: preview transaction and rule effects, reject broader unreviewed rule movement, validate stale state under locks, protect system classifications and retain an audit of applied effects.
4. **Make classification saves atomic and consistent.** Fix 7–8 with shared transaction ownership and null-aware propagation. Review all callers, including imports, manual edits, workspace actions and AI category assignment.
5. **Validate on PostgreSQL.** Run the full PHP and frontend suites, then PostgreSQL integration tests covering null comparisons, transaction rollback, locks and concurrent tagging/editing/retirement. Add a repeated-run check and verify financial fields are unchanged.
6. **Assess existing data after code repair.** Produce a read-only discrepancy report for inactive-tag assignments, conflicting/overbroad rules and category/segment inconsistencies. Direction errors require transaction-level evidence; do not automatically infer them solely from shared wording. Snapshot any proposed repairs and present their exact effects before applying them.

## Follow-up design checks

These need an explicit contract rather than being silently folded into repairs:

- AI correction currently promises tag-only transaction changes, and the tests enforce that promise. Decide whether its preview should explicitly include the destination category/segment, or whether reporting classifications intentionally remain unchanged. Do not silently change that contract.
- Automatic alias derivation takes the first qualifying merchant token. Assess whether multiword merchants need reviewed phrase candidates to prevent broad collisions even where no competing rule exists yet.
- AI category links created through `ai_tags.php` use name resolution rather than the dedicated category tagger's confidence policy. Also check propagation when a new mapping is created but the final deterministic replay updates zero rows.
- The deterministic bulk writer selects eligible rows first and updates by ID alone. Test concurrent manual edits, transfer confirmation and tag retirement in PostgreSQL; use guarded writes and appropriate locking in the repaired shared path.

Completion means the reproduced cases are covered by passing behavioural tests, supported endpoints enforce the same eligibility rules, PostgreSQL concurrency checks pass, and any historical-data repair remains a separate reviewed operation.
