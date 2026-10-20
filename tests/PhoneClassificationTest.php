<?php
require_once __DIR__ . '/../php_backend/services/PhoneClassification.php';
$phoneTag = Tag::create('Phone classification test');
$phoneCategory = Category::create('Phone category');
$db->prepare('UPDATE categories SET segment_id = 1 WHERE id = ?')->execute([$phoneCategory]);
CategoryTag::add($phoneCategory, $phoneTag);
$db->exec("INSERT INTO transactions (account_id,date,amount,description,memo) VALUES (1,'2026-10-01',-9.45,'PHONE TEST MERCHANT','Preserved memo')");
$phoneId = (int)$db->lastInsertId();
$db->exec("INSERT INTO transactions (account_id,date,amount,description) VALUES (1,'2026-10-01',-9.45,'PHONE TEST MERCHANT')");
$phoneOtherId = (int)$db->lastInsertId();
$phoneBefore = PhoneClassification::get($phoneId);
$phoneRulesBefore = (int)$db->query('SELECT COUNT(*) FROM tag_aliases')->fetchColumn();
$phoneSaved = PhoneClassification::save($phoneId, $phoneTag, $phoneBefore['revision'], $phoneCategory, 1);
assertEqual($phoneTag, (int)$phoneSaved['tag_id'], 'Phone classification saves the chosen existing tag');
assertEqual($phoneCategory, (int)$phoneSaved['category_id'], 'Phone classification derives the canonical category');
assertEqual(1, (int)$phoneSaved['segment_id'], 'Phone classification derives the category segment');
foreach (['account_id','date','amount','description','memo','group_id','transfer_id'] as $key) {
    assertEqual($phoneBefore[$key], $phoneSaved[$key], 'Phone classification preserves ' . $key);
}
assertEqual(null, Transaction::get($phoneOtherId)['tag_id'], 'Phone save does not classify other matching merchants');
assertEqual($phoneRulesBefore, (int)$db->query('SELECT COUNT(*) FROM tag_aliases')->fetchColumn(), 'Phone save does not create a merchant rule');
try { PhoneClassification::save($phoneId, $phoneTag, $phoneBefore['revision'], $phoneCategory, 1); $phoneRejected=false; }
catch (DomainException $e) { $phoneRejected=true; }
assertEqual(true, $phoneRejected, 'Phone rejects stale transaction evidence');
try { PhoneClassification::save($phoneId, $phoneTag, $phoneSaved['revision'], null, null); $phoneRejected=false; }
catch (DomainException $e) { $phoneRejected=true; }
assertEqual(true, $phoneRejected, 'Phone rejects a stale or forged classification preview');
assertEqual($phoneSaved['revision'], PhoneClassification::get($phoneId)['revision'], 'Rejected phone save rolls back without mutation');
foreach (['transfer_id = 999', 'tag_id = ' . Tag::getIgnoreId()] as $protection) {
    $db->exec('UPDATE transactions SET transfer_id = NULL, tag_id = NULL WHERE id = ' . $phoneOtherId);
    $db->exec('UPDATE transactions SET ' . $protection . ' WHERE id = ' . $phoneOtherId);
    $protected = PhoneClassification::get($phoneOtherId);
    assertEqual(false, $protected['can_classify'], 'Phone marks protected transactions as read only');
    try { PhoneClassification::save($phoneOtherId, $phoneTag, $protected['revision'], $phoneCategory, 1); $phoneRejected=false; }
    catch (InvalidArgumentException $e) { $phoneRejected=true; }
    assertEqual(true, $phoneRejected, 'Phone refuses protected classification writes');
}
$ordinaryOptions = Tag::searchOptions('ignore',20,true,true);
assertEqual([], $ordinaryOptions, 'Phone picker excludes protected IGNORE tags');
$db->prepare("UPDATE tags SET status = 'deprecated' WHERE id = ?")->execute([$phoneTag]);
try { PhoneClassification::save($phoneId, $phoneTag, $phoneSaved['revision'], $phoneCategory, 1); $phoneRejected=false; }
catch (InvalidArgumentException $e) { $phoneRejected=true; }
assertEqual(true, $phoneRejected, 'Phone rejects a tag retired after selection');
