<?php
require_once __DIR__ . '/../php_backend/LoginDestination.php';
function expectDestination(mixed $input, ?string $expected): void {
    if (LoginDestination::validate($input) !== $expected) throw new RuntimeException('Unexpected return destination for ' . var_export($input, true));
}
expectDestination('frontend/transaction.html?id=17#classification', 'frontend/transaction.html?id=17#classification');
expectDestination('frontend/search.html?group_id=7&start=2026-01-01&end=2026-01-31', 'frontend/search.html?group_id=7&start=2026-01-01&end=2026-01-31');
expectDestination('settings.php', 'settings.php');
foreach (['https://evil.example', '//evil.example', '/frontend/index.html', 'frontend/../index.php', 'frontend/%2e%2e/index.php', 'frontend/index.html%0d%0aLocation:evil', "frontend/index.html\r\nX-Test: bad", 'frontend\\index.html', 'php_backend/public/export.php', 'frontend/does-not-exist.html', [], null] as $bad) expectDestination($bad, null);
$_SESSION = ['login_return' => 'frontend/transaction.html?id=17'];
if (LoginDestination::consume() !== 'frontend/transaction.html?id=17' || isset($_SESSION['login_return'])) throw new RuntimeException('Return destination must be consumed once.');
if (LoginDestination::consume() !== 'frontend/instant.html') throw new RuntimeException('Unexpected default launch.');
echo "PASS: Safe return destinations, evidence queries, malicious redirects and one-time consumption\n";
