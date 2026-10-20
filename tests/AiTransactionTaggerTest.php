<?php
// Offline behavioural tests: model responses are injected; no API or live database.
$standaloneAiTagging = !function_exists('assertEqual');
if ($standaloneAiTagging) {
    putenv('DB_DSN=sqlite::memory:');
    require_once __DIR__ . '/../php_backend/Database.php';
    $db = Database::getConnection();
    $db->exec("CREATE TABLE tags (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, name_normalized TEXT UNIQUE, keyword TEXT, description TEXT, origin TEXT DEFAULT 'legacy', status TEXT DEFAULT 'active', merged_into_tag_id INTEGER, created_at TEXT, updated_at TEXT)");
    $db->exec("CREATE TABLE tag_aliases (id INTEGER PRIMARY KEY AUTOINCREMENT, tag_id INTEGER, alias TEXT, alias_normalized TEXT, match_type TEXT, direction TEXT DEFAULT 'any', active INTEGER DEFAULT 1, origin TEXT, confidence REAL, support_count INTEGER DEFAULT 0, last_matched_at TEXT, created_at TEXT, updated_at TEXT, UNIQUE(alias_normalized, direction))");
    $db->exec('CREATE TABLE transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER, date TEXT, amount REAL, description TEXT, memo TEXT, tag_id INTEGER, category_id INTEGER, segment_id INTEGER, transfer_id INTEGER, group_id INTEGER, ofx_type TEXT)');
    $db->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY, name TEXT, segment_id INTEGER)');
    $db->exec('CREATE TABLE category_tags (tag_id INTEGER, category_id INTEGER)');
    $db->exec('CREATE TABLE segments (id INTEGER PRIMARY KEY, name TEXT)');
    $results = [];
    function assertEqual($expected, $actual, string $message) {
        global $results;
        $results[] = ($expected === $actual ? 'PASS: ' : 'FAIL: ') . $message . ($expected === $actual ? '' : ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}
require_once __DIR__ . '/../php_backend/services/AiTransactionTagger.php';
require_once __DIR__ . '/../php_backend/services/TaggingWorkspaceService.php';

// This file runs last in the combined in-memory suite.
$db->exec('DELETE FROM transactions');
$db->exec('DELETE FROM tag_aliases');
$db->exec('DELETE FROM category_tags');
$db->exec('DELETE FROM tags');
Tag::clearMatchCaches();
$food = Tag::create('Groceries', null, 'Food shopping for home. Includes supermarkets. Excludes restaurants.', 'manual');
$fuel = Tag::create('Vehicle fuel', null, 'Petrol and diesel. Excludes household energy.', 'manual');
$legacy = Tag::create('Old merchant one-off', null, null, 'legacy');
$system = Tag::create('System special', null, null, 'system');
$ignore = Tag::getIgnoreId();
$insertAiTx = $db->prepare('INSERT INTO transactions (account_id, date, amount, description, memo, tag_id, category_id, segment_id, transfer_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
$addAiTx = function (string $description, float $amount = -10, ?int $tag = null, ?int $transfer = null, ?string $memo = null, int $account = 1) use ($db, $insertAiTx): int {
    $insertAiTx->execute([$account, '2026-10-20', $amount, $description, $memo, $tag, null, null, $transfer]);
    return (int)$db->lastInsertId();
};
$decision = fn($group, $tagId, $confidence = 0.96, $ambiguous = false) => ['id' => $group['id'], 'decision' => 'match', 'tag_id' => $tagId, 'confidence' => $confidence, 'ambiguous' => $ambiguous, 'reason' => 'Specific grocery wording matches the approved definition.'];
$rejectedAi = function (callable $action, string $message) {
    try { $action(); $rejected = false; } catch (InvalidArgumentException $e) { $rejected = true; }
    assertEqual(true, $rejected, $message);
};
$stateAi = [];
$ai = new AiTransactionTagger($stateAi, $db);
assertEqual([$food, $fuel], array_map('intval', array_column($ai->catalogue(), 'id')), 'AI allowlist excludes legacy, system and IGNORE tags');
assertEqual([], Tag::searchOptions('Old merchant', 20, false, false, true), 'AI review picker excludes legacy tags server-side');
$outgoing = $addAiTx('GROCERY MARKET REF 123', -10);
$incoming = $addAiTx('GROCERY MARKET REF 123', 10);
$protectedAi = $addAiTx('GROCERY MARKET REF 123', -20, null, 123);
$ignoredAi = $addAiTx('GROCERY MARKET REF 123', -20, $ignore);
$batchAi = $ai->batch();
assertEqual(2, count($batchAi['groups']), 'Same wording in opposite directions gets separate AI patterns');
$catalogueAi = $ai->catalogue();
$promptAi = $ai->prompt($catalogueAi, $batchAi);
assertEqual(true, str_contains($promptAi, 'Excludes restaurants.'), 'Prompt includes tag definitions and exclusions');
assertEqual(true, str_contains($promptAi, 'untrusted data'), 'Prompt treats merchant wording as untrusted input');
$lateAi = $addAiTx('GROCERY MARKET REF 123', -11);
$resultAi = $ai->applyResponse($batchAi, $catalogueAi, ['transactions' => array_map(fn($g) => $decision($g, $food), $batchAi['groups'])]);
assertEqual(2, $resultAi['processed'], 'High-confidence decisions classify the exact examined rows');
assertEqual(null, $db->query("SELECT tag_id FROM transactions WHERE id=$lateAi")->fetchColumn(), 'A matching import after the request is outside AI decision evidence');
assertEqual(null, $db->query("SELECT tag_id FROM transactions WHERE id=$protectedAi")->fetchColumn(), 'AI leaves confirmed transfers unchanged');
assertEqual($ignore, (int)$db->query("SELECT tag_id FROM transactions WHERE id=$ignoredAi")->fetchColumn(), 'AI leaves IGNORE unchanged');
assertEqual(0, (int)$db->query('SELECT COUNT(*) FROM tag_aliases')->fetchColumn(), 'Accepted AI classifications create no active or inactive rules');
assertEqual(0, (int)$db->query('SELECT COUNT(*) FROM category_tags')->fetchColumn(), 'Smart tagging cannot create category links');
assertEqual(2, count(array_filter($ai->reviews(), fn($r) => $r['kind'] === 'rule')), 'Outgoing and incoming rule proposals remain separate approvals');

// Invalid, ambiguous, unknown, missing and duplicate decisions never write.
$db->exec('DELETE FROM transactions');
$stateAi = [];
$ai = new AiTransactionTagger($stateAi, $db);
foreach (['LOW CONFIDENCE', 'AMBIGUOUS MARKET', 'UNKNOWN ID', 'MISSING ANSWER', 'DUPLICATE ANSWER', 'NO AMBIGUITY FLAG', 'NONNUMERIC CONFIDENCE'] as $wording) $addAiTx($wording);
$batchAi = $ai->batch(); $groupsAi = $batchAi['groups'];
$itemsAi = [$decision($groupsAi[0], $food, 0.89), $decision($groupsAi[1], $food, 0.99, true), $decision($groupsAi[2], 999999), $decision($groupsAi[4], $food), $decision($groupsAi[4], $fuel), $decision($groupsAi[5], $food), $decision($groupsAi[6], $food)];
unset($itemsAi[5]['ambiguous']); $itemsAi[6]['confidence'] = '0.99';
$resultAi = $ai->applyResponse($batchAi, $ai->catalogue(), ['transactions' => $itemsAi]);
assertEqual(0, $resultAi['processed'], 'Uncertain, unknown, missing, duplicate and malformed decisions are held');
assertEqual(7, count($ai->reviews()), 'Every examined unresolved pattern receives a review item');
assertEqual(5, (int)$db->query('SELECT COUNT(*) FROM tags')->fetchColumn(), 'Unresolved decisions never grow the tag catalogue');
$reviewAi = $ai->reviews()[0];
$otherStoreAi = []; $otherSessionAi = new AiTransactionTagger($otherStoreAi, $db);
$rejectedAi(fn() => $otherSessionAi->approveClassification($reviewAi['token'], $food), 'Review tokens are isolated to their owning session');
$rejectedAi(fn() => $ai->approveClassification($reviewAi['token'], $legacy), 'Explicit review still rejects an unapproved legacy destination');
assertEqual(1, $ai->approveClassification($reviewAi['token'], $food), 'Human review can classify an uncertain pattern without creating a rule');
$rejectedAi(fn() => $ai->approveClassification($reviewAi['token'], $food), 'Consumed review tokens cannot be replayed');

// Stale catalogue, transaction and deterministic-rule evidence are rejected.
$db->exec('DELETE FROM transactions'); $stateAi = []; $ai = new AiTransactionTagger($stateAi, $db);
$staleAi = $addAiTx('STALE MARKET'); $batchAi = $ai->batch(); $catalogueAi = $ai->catalogue();
$db->exec("UPDATE transactions SET amount=-99 WHERE id=$staleAi");
$resultAi = $ai->applyResponse($batchAi, $catalogueAi, ['transactions' => [$decision($batchAi['groups'][0], $food)]]);
assertEqual(0, $resultAi['processed'], 'Amount edits during an AI request invalidate the frozen evidence');
$rejectedAi(fn() => $ai->approveClassification($ai->reviews()[0]['token'], $food), 'Stale reviewed evidence cannot overwrite an edit');
$batchAi = $ai->batch(); $catalogueAi = $ai->catalogue();
$db->exec("UPDATE tags SET status='deprecated' WHERE id=$food");
assertEqual(0, $ai->applyResponse($batchAi, $catalogueAi, ['transactions' => [$decision($batchAi['groups'][0], $food)]])['processed'], 'Retirement during the AI request prevents classification');
$db->exec("UPDATE tags SET status='active' WHERE id=$food");
$batchAi = $ai->batch(); $catalogueAi = $ai->catalogue();
TagAlias::create($fuel, 'stale market', 'exact', true, 'manual', null, 0, 'outgoing');
assertEqual(0, $ai->applyResponse($batchAi, $catalogueAi, ['transactions' => [$decision($batchAi['groups'][0], $food)]])['processed'], 'A new deterministic rule during the request prevents stale AI application');

// Reviewed phrases retain multiple words and require scope approval.
$db->exec('DELETE FROM transactions'); $db->exec('DELETE FROM tag_aliases'); Tag::clearMatchCaches();
$stateAi = []; $ai = new AiTransactionTagger($stateAi, $db);
$addAiTx('CARD PAYMENT BRITISH GAS REF 1234');
$batchAi = $ai->batch();
$ai->applyResponse($batchAi, $ai->catalogue(), ['transactions' => [$decision($batchAi['groups'][0], $food)]]);
$ruleAi = $ai->reviews()[0];
assertEqual('british gas', $ruleAi['alias'], 'Rule candidates retain a stable multiword merchant phrase');
$rejectedAi(fn() => $ai->approveRule($ruleAi['token'], false), 'Rules cannot be approved without a preview');
$matchingAi = $addAiTx('BRITISH GAS REF 4567', -20, null, null, null, 2);
$otherTaggedAi = $addAiTx('BRITISH GAS SERVICE', -30, $fuel);
TagAlias::create($fuel, 'british gas service', 'contains', true, 'manual', null, 0, 'outgoing');
$previewAi = $ai->previewRule($ruleAi['token'], $ruleAi['alias']);
assertEqual(1, $previewAi['apply_now'], 'Rule preview shows the exact eligible matches across accounts');
assertEqual(1, $previewAi['other_tag_count'], 'Preview exposes differently tagged matches without rewriting them');
$rejectedAi(fn() => $ai->approveRule($ruleAi['token'], false, $previewAi['preview_token']), 'Overlapping scope requires explicit confirmation');
$addedAfterPreviewAi = $addAiTx('BRITISH GAS NEW REF 789');
$rejectedAi(fn() => $ai->approveRule($ruleAi['token'], true, $previewAi['preview_token']), 'New matching evidence invalidates a rule preview');
$oldPreviewTokenAi = $previewAi['preview_token'];
$previewAi = $ai->previewRule($ruleAi['token'], $ruleAi['alias']);
$rejectedAi(fn() => $ai->approveRule($ruleAi['token'], true, $oldPreviewTokenAi), 'An older browser preview cannot approve a replacement scope');
$beforeMoneyAi = $db->query('SELECT id, account_id, date, amount, description, memo, transfer_id FROM transactions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
assertEqual(2, $ai->approveRule($ruleAi['token'], true, $previewAi['preview_token']), 'Reviewed rule classifies only its previewed eligible rows');
assertEqual($fuel, (int)$db->query("SELECT tag_id FROM transactions WHERE id=$otherTaggedAi")->fetchColumn(), 'Rule approval preserves existing differently tagged history');
assertEqual($beforeMoneyAi, $db->query('SELECT id, account_id, date, amount, description, memo, transfer_id FROM transactions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'Rule approval preserves all financial and identity fields');
assertEqual($food, Tag::findMatch('BRITISH GAS OTHER REF', -20), 'Approved rule handles future same-direction transactions');
assertEqual(2, (int)$db->query("SELECT support_count FROM tag_aliases WHERE alias_normalized='british gas' AND direction='outgoing'")->fetchColumn(), 'Rule approval records exactly the rows actually matched');
assertEqual(true, (bool)$db->query("SELECT last_matched_at FROM tag_aliases WHERE alias_normalized='british gas' AND direction='outgoing'")->fetchColumn(), 'Rule approval records its initial usage timestamp');
assertEqual(null, Tag::findMatch('BRITISH GAS OTHER REF', 20), 'Approved outgoing rule does not capture incoming refunds');
$rejectedAi(fn() => $ai->approveRule($ruleAi['token'], true), 'Approved rule tokens cannot be replayed');

// Disabled exact rules are never silently reactivated or broadened.
$db->exec('DELETE FROM transactions'); $db->exec('DELETE FROM tag_aliases'); Tag::clearMatchCaches();
$stateAi = []; $ai = new AiTransactionTagger($stateAi, $db);
$addAiTx('ACME ENERGY REF 1234');
$disabledAi = TagAlias::create($food, 'acme energy', 'exact', false, 'manual', null, 0, 'outgoing');
$batchAi = $ai->batch();
$ai->applyResponse($batchAi, $ai->catalogue(), ['transactions' => [$decision($batchAi['groups'][0], $food)]]);
$ruleAi = $ai->reviews()[0]; $previewAi = $ai->previewRule($ruleAi['token'], 'acme energy');
$rejectedAi(fn() => $ai->approveRule($ruleAi['token'], true, $previewAi['preview_token']), 'Existing disabled rules require the rule editor rather than AI reactivation');
assertEqual(['active' => 0, 'match_type' => 'exact'], $db->query("SELECT active, match_type FROM tag_aliases WHERE id=$disabledAi")->fetch(PDO::FETCH_ASSOC), 'Existing activation and match type stay unchanged');

// Stable bounded batches advance past uncertain patterns and exclude new imports.
$db->exec('DELETE FROM transactions'); $db->exec('DELETE FROM tag_aliases'); Tag::clearMatchCaches();
$stateAi = []; $ai = new AiTransactionTagger($stateAi, $db);
for ($i = 0; $i < 260; $i++) $addAiTx('UNKNOWN MERCHANT');
$batchAi = $ai->batch();
assertEqual(250, count($batchAi['groups'][0]['rows']), 'An AI batch freezes at most 250 transaction rows');
$resultAi = $ai->applyResponse($batchAi, $ai->catalogue(), ['transactions' => []]);
assertEqual(true, $resultAi['has_more'], 'A run reports later evidence even when current rows are unresolved');
$addAiTx('IMPORTED LATER');
$nextAi = $ai->batch($resultAi['run_token']);
assertEqual(10, count($nextAi['groups'][0]['rows']), 'Cursor advances past unknowns while retaining the original upper bound');
assertEqual(false, $ai->applyResponse($nextAi, $ai->catalogue(), ['transactions' => []])['has_more'], 'Unresolved early patterns cannot starve the end of a run');
$stateAi['run']['expires'] = time() - 1;
$rejectedAi(fn() => $ai->batch($nextAi['token']), 'Expired run tokens do not silently broaden scope');
foreach ($stateAi['reviews'] as &$pendingAi) $pendingAi['expires'] = time() - 1;
unset($pendingAi); $ai = new AiTransactionTagger($stateAi, $db);
assertEqual([], $ai->reviews(), 'Expired suggestions disappear without classification or rule changes');

// Saved rules still run first, with guarded writes and usage evidence.
$db->exec('DELETE FROM transactions'); $db->exec('DELETE FROM tag_aliases'); Tag::clearMatchCaches();
$stateAi = []; $ai = new AiTransactionTagger($stateAi, $db);
$localAi = $addAiTx('SAVED MERCHANT'); $localEditedAi = $addAiTx('SAVED MERCHANT');
$aliasAi = TagAlias::create($food, 'saved merchant', 'contains', true, 'manual', null, 0, 'outgoing');
$batchAi = $ai->batch();
$db->exec("UPDATE transactions SET tag_id=$fuel WHERE id=$localEditedAi");
assertEqual(1, $ai->applySavedRules($batchAi), 'Local tagging does not overwrite an edit made after batch capture');
assertEqual([], $batchAi['groups'], 'Locally classified patterns are removed before the AI request');
assertEqual(1, (int)$db->query("SELECT support_count FROM tag_aliases WHERE id=$aliasAi")->fetchColumn(), 'Local rule execution records actual usage');

// Classification propagates only the selected tag's saved reporting structure.
$db->exec('DELETE FROM transactions'); $db->exec('DELETE FROM tag_aliases'); Tag::clearMatchCaches();
$segmentAi = (int)$db->query('SELECT COALESCE(MAX(id), 0) + 1 FROM segments')->fetchColumn();
$categoryAi = (int)$db->query('SELECT COALESCE(MAX(id), 0) + 1 FROM categories')->fetchColumn();
$db->prepare('INSERT INTO segments (id, name) VALUES (?, ?)')->execute([$segmentAi, 'AI test segment']);
$db->prepare('INSERT INTO categories (id, name, segment_id) VALUES (?, ?, ?)')->execute([$categoryAi, 'AI test category', $segmentAi]);
$db->prepare('INSERT INTO category_tags (tag_id, category_id) VALUES (?, ?)')->execute([$food, $categoryAi]);
$stateAi = []; $ai = new AiTransactionTagger($stateAi, $db);
$classifiedAi = $addAiTx('SPECIFIC GROCERY PURCHASE');
$unrelatedAi = $addAiTx('UNRELATED CLASSIFIED', -10, $fuel);
$batchAi = $ai->batch();
$ai->applyResponse($batchAi, $ai->catalogue(), ['transactions' => [$decision($batchAi['groups'][0], $food, 0.90)]]);
assertEqual([$food, $categoryAi, $segmentAi], array_map('intval', $db->query("SELECT tag_id, category_id, segment_id FROM transactions WHERE id=$classifiedAi")->fetch(PDO::FETCH_NUM)), 'An accepted 90% match uses its saved category and segment');
assertEqual(null, $db->query("SELECT category_id FROM transactions WHERE id=$unrelatedAi")->fetchColumn(), 'Smart tagging does not globally recategorise unrelated history');

// Preview and application remain bounded even for a popular merchant.
$db->exec('DELETE FROM transactions'); $stateAi = []; $ai = new AiTransactionTagger($stateAi, $db);
$addAiTx('BOUNDED SERVICE REF 111');
$batchAi = $ai->batch();
$ai->applyResponse($batchAi, $ai->catalogue(), ['transactions' => [$decision($batchAi['groups'][0], $food)]]);
$ruleAi = $ai->reviews()[0];
for ($i = 0; $i < 260; $i++) $addAiTx('BOUNDED SERVICE REF ' . (1000 + $i));
$previewAi = $ai->previewRule($ruleAi['token'], $ruleAi['alias']);
assertEqual(260, $previewAi['untagged'], 'Rule preview discloses the complete untagged match count');
assertEqual(250, $previewAi['apply_now'], 'Rule approval clearly bounds the immediate transaction set');
assertEqual(250, $ai->approveRule($ruleAi['token'], false, $previewAi['preview_token']), 'Rule approval writes no more than 250 frozen eligible rows');
assertEqual(10, (int)$db->query('SELECT COUNT(*) FROM transactions WHERE tag_id IS NULL')->fetchColumn(), 'Remaining merchant matches wait for normal saved-rule execution');

// Invalid model confidence must never poison the JSON review response.
$db->exec('DELETE FROM transactions'); $db->exec('DELETE FROM tag_aliases'); Tag::clearMatchCaches();
$stateAi = []; $ai = new AiTransactionTagger($stateAi, $db); $addAiTx('INVALID CONFIDENCE');
$batchAi = $ai->batch();
$ai->applyResponse($batchAi, $ai->catalogue(), ['transactions' => [$decision($batchAi['groups'][0], $food, INF)]]);
assertEqual(null, $ai->reviews()[0]['confidence'], 'Nonfinite model confidence stays unknown');
assertEqual(true, json_encode($ai->reviews()) !== false, 'Malformed confidence cannot break subsequent review responses');

// Catalogue growth needs a purpose and close-name review; approvals are explicit.
$workspaceAi = new TaggingWorkspaceService($db);
$rejectedAi(fn() => $workspaceAi->createTag('One-off merchant', null, null), 'New catalogue tags require a distinct reporting purpose');
$similarAi = $workspaceAi->createTag('Groceries shopping', 'Food at home', null, 'A proposed separate household grocery budget.');
assertEqual(true, $similarAi['requires_confirmation'] ?? false, 'Near-duplicate names require a separate confirmation');
assertEqual($food, $similarAi['similar_tags'][0]['id'] ?? null, 'Creation suggests the nearest existing reusable tag');
$workspaceAi->approveForAi($legacy);
assertEqual(true, in_array($legacy, array_map('intval', array_column($ai->catalogue(), 'id')), true), 'Explicit catalogue approval makes an ordinary legacy tag AI selectable');
$rejectedAi(fn() => $workspaceAi->approveForAi($ignore), 'IGNORE cannot become an ordinary AI destination');
$rejectedAi(fn() => $workspaceAi->approveForAi($system), 'System tags cannot become ordinary AI destinations');

if ($standaloneAiTagging) {
    echo implode("\n", $results), "\n";
    exit(count(array_filter($results, fn($line) => str_starts_with($line, 'FAIL:'))) ? 1 : 0);
}
