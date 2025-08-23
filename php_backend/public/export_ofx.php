<?php
// Exports all transactions as a single OFX file
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../models/Tag.php';

header('Content-Type: application/x-ofx');
$host = $_SERVER['HTTP_HOST'] ?? 'backup';
$host = preg_replace('/[^A-Za-z0-9_-]/', '_', $host);
$filename = $host . '-' . date('Y-m-d') . '.ofx';
header('Content-Disposition: attachment; filename="' . $filename . '"');

try {
    $db = Database::getConnection();
    $ignore = Tag::getIgnoreId();
    $start = $_GET['start'] ?? '1970-01-01';
    $end   = $_GET['end'] ?? date('Y-m-d');
    $stmt = $db->prepare('SELECT id, date, amount, description, memo, ofx_id FROM transactions WHERE (tag_id IS NULL OR tag_id != :ignore) AND date BETWEEN :start AND :end ORDER BY date');
    $stmt->execute(['ignore' => $ignore, 'start' => $start, 'end' => $end]);
    $txns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Retrieve basic account details for the OFX header. If there are
    // multiple accounts we simply use the first one which mirrors the
    // behaviour of the importer that expects a single account per file.
    $accStmt = $db->query('SELECT name, sort_code, account_number FROM accounts ORDER BY id LIMIT 1');
    $account = $accStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $accName   = htmlspecialchars($account['name'] ?? 'Account');
    $sortCode  = htmlspecialchars($account['sort_code'] ?? '');
    $accNumber = htmlspecialchars($account['account_number'] ?? '00000000');

    echo "OFXHEADER:100\n";
    echo "DATA:OFXSGML\n";
    echo "VERSION:102\n";
    echo "SECURITY:NONE\n";
    echo "ENCODING:USASCII\n";
    echo "CHARSET:1252\n";
    echo "COMPRESSION:NONE\n";
    echo "OLDFILEUID:NONE\n";
    echo "NEWFILEUID:NONE\n\n";

    echo "<OFX>\n";
    echo "  <BANKMSGSRSV1>\n";
    echo "    <STMTTRNRS>\n";
    echo "      <TRNUID>1</TRNUID>\n";
    echo "      <STATUS><CODE>0</CODE><SEVERITY>INFO</SEVERITY></STATUS>\n";
    echo "      <STMTRS>\n";
    echo "        <CURDEF>GBP</CURDEF>\n";
    echo "        <BANKACCTFROM>\n";
    if ($sortCode !== '') {
        echo "          <BANKID>{$sortCode}</BANKID>\n";
    }
    echo "          <ACCTID>{$accNumber}</ACCTID>\n";
    echo "          <ACCTTYPE>CHECKING</ACCTTYPE>\n";
    echo "          <ACCTNAME>{$accName}</ACCTNAME>\n";
    echo "        </BANKACCTFROM>\n";
    echo "        <BANKTRANLIST>\n";

    foreach ($txns as $tx) {
        $date = date('Ymd', strtotime($tx['date']));
        $amount = $tx['amount'];
        $id = $tx['ofx_id'] ?: $tx['id'];
        $name = htmlspecialchars($tx['description'] ?? '');
        $memo = htmlspecialchars($tx['memo'] ?? '');
        echo "          <STMTTRN>\n";
        echo "            <TRNTYPE>OTHER</TRNTYPE>\n";
        echo "            <DTPOSTED>{$date}</DTPOSTED>\n";
        echo "            <TRNAMT>{$amount}</TRNAMT>\n";
        echo "            <FITID>{$id}</FITID>\n";
        echo "            <NAME>{$name}</NAME>\n";
        echo "            <MEMO>{$memo}</MEMO>\n";
        echo "          </STMTTRN>\n";
    }

    echo "        </BANKTRANLIST>\n";
    echo "      </STMTRS>\n";
    echo "    </STMTTRNRS>\n";
    echo "  </BANKMSGSRSV1>\n";
    echo "</OFX>\n";
} catch (Exception $e) {
    http_response_code(500);
    echo $e->getMessage();
}
