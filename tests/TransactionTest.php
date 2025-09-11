<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../php_backend/models/Transaction.php';
require_once __DIR__ . '/../php_backend/models/Log.php';
require_once __DIR__ . '/../php_backend/models/Tag.php';
require_once __DIR__ . '/../php_backend/Database.php';

class TransactionTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        putenv('DB_DSN=sqlite::memory:');
        $ref = new ReflectionClass(Database::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null);
        $this->db = Database::getConnection();
        $this->db->exec('CREATE TABLE accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT);');
        $this->db->exec('INSERT INTO accounts (name) VALUES ("Checking");');
        $this->db->exec('CREATE TABLE logs (id INTEGER PRIMARY KEY AUTOINCREMENT, level TEXT, message TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);');
        $this->db->exec('CREATE TABLE tags (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, keyword TEXT, description TEXT);');
        $this->db->exec('CREATE TABLE transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER, date TEXT, amount REAL, description TEXT, memo TEXT, category_id INTEGER, segment_id INTEGER, tag_id INTEGER, group_id INTEGER, transfer_id INTEGER, ofx_id TEXT, ofx_type TEXT, bank_ofx_id TEXT);');
    }

    public function testDuplicateFitidHandling(): void
    {
        $first = Transaction::create(1, '2024-08-01', 10.0, 'First', null, null, 0, null, null, 'DEBIT', 'DUP123');
        $second = Transaction::create(1, '2024-08-02', 20.0, 'Second', null, null, 0, null, null, 'DEBIT', 'DUP123');
        $this->assertSame(0, $second);
        $count = $this->db->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
        $this->assertSame(1, (int)$count);
        $logCount = $this->db->query("SELECT COUNT(*) FROM logs WHERE level = 'WARNING'")->fetchColumn();
        $this->assertSame(1, (int)$logCount);
    }

    public function testDuplicateTransactionRejection(): void
    {
        $first = Transaction::create(1, '2024-08-03', 30.0, 'Dup', null, null, 0);
        $second = Transaction::create(1, '2024-08-03', 30.0, 'Dup', null, null, 0);
        $this->assertSame(0, $second);
        $count = $this->db->query("SELECT COUNT(*) FROM transactions WHERE description = 'Dup'")->fetchColumn();
        $this->assertSame(1, (int)$count);
    }

    public function testRecurringIncomeAndSpendDetection(): void
    {
        $now = time();
        $u1 = date('Y-m-15', strtotime('-3 months', $now));
        $u2 = date('Y-m-15', strtotime('-2 months', $now));
        $u3 = date('Y-m-15', strtotime('-1 month', $now));
        $e1 = date('Y-m-25', strtotime('-3 months', $now));
        $e2 = date('Y-m-25', strtotime('-2 months', $now));
        $e3 = date('Y-m-25', strtotime('-1 month', $now));
        $old1 = date('Y-m-d', strtotime('-7 months', $now));
        $old2 = date('Y-m-d', strtotime('-6 months', $now));
        $oneOff = date('Y-m-d', strtotime('-20 days', $now));

        $this->db->exec("INSERT INTO transactions (account_id, date, amount, description) VALUES
            (1, '$u1', -100, 'Utility Co'),
            (1, '$u2', -110, 'Utility Co'),
            (1, '$u3', -90, 'Utility Co'),
            (1, '$e1', 2000, 'Employer'),
            (1, '$e2', 2100, 'Employer'),
            (1, '$e3', 2200, 'Employer'),
            (1, '$old1', -30, 'OldService'),
            (1, '$old2', -35, 'OldService'),
            (1, '$oneOff', -50, 'One-off')
        ");
        $spend = Transaction::getRecurringSpend(false);
        $income = Transaction::getRecurringSpend(true);
        $this->assertSame(1, count($spend));
        $this->assertSame(1, count($income));
        $this->assertSame(15, $spend[0]['day']);
        $this->assertSame(25, $income[0]['day']);
        $this->assertSame(3, $spend[0]['occurrences']);
        $this->assertSame(3, $income[0]['occurrences']);
        $this->assertEquals(300.0, $spend[0]['total']);
        $this->assertEquals(6300.0, $income[0]['total']);
        $this->assertEquals(100.0, $spend[0]['average']);
        $this->assertEquals(2100.0, $income[0]['average']);
        $this->assertEquals(90.0, $spend[0]['last_amount']);
        $this->assertEquals(2200.0, $income[0]['last_amount']);
    }
}
