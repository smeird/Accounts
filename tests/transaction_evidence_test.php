<?php
require_once __DIR__ . '/../php_backend/services/TransactionEvidence.php';
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$_SESSION = ['user_id'=>7];
$token=TransactionEvidence::create(range(1,501));
check(strlen($token)===48,'Opaque bounded token');
check(TransactionEvidence::page($token)['ids']===range(1,250),'First exact evidence page');
check(TransactionEvidence::page($token,3)['ids']===[501],'Final partial page');
check(TransactionEvidence::page($token)['total']===501,'Full import count');
try { TransactionEvidence::page($token,4); throw new RuntimeException('Invalid page accepted'); } catch (InvalidArgumentException $e) {}
$_SESSION['user_id']=8;
try { TransactionEvidence::page($token); throw new RuntimeException('Other user accepted'); } catch (InvalidArgumentException $e) {}
$_SESSION['user_id']=7;
$_SESSION['transaction_evidence'][$token]['expires']=time()-1;
try { TransactionEvidence::page($token); throw new RuntimeException('Expired evidence accepted'); } catch (InvalidArgumentException $e) {}
check(TransactionEvidence::create([])===null,'Empty import has no review');
for($n=0;$n<8;$n++) TransactionEvidence::create([$n+1]);
check(count($_SESSION['transaction_evidence'])===5,'Bounded session history');
$token=TransactionEvidence::create([3,3,0,-1,4]);
check(TransactionEvidence::page($token)['ids']===[3,4],'Positive unique evidence');
echo "PASS: Exact paged import evidence, ownership, expiry and storage bounds\n";
$token=TransactionEvidence::create([9], 'import');
try { TransactionEvidence::page($token, 1, 'review'); throw new RuntimeException('Wrong purpose accepted'); } catch (InvalidArgumentException $e) {}
echo "PASS: Import evidence cannot impersonate a classification-review queue\n";
