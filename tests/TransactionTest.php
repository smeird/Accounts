<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../php_backend/models/Transaction.php';
require_once __DIR__ . '/../php_backend/models/Log.php';
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
        $this->db->exec('CREATE TABLE transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER, date TEXT, amount REAL, description TEXT, memo TEXT, category_id INTEGER, segment_id INTEGER, tag_id INTEGER, group_id INTEGER, transfer_id INTEGER, ofx_id TEXT, ofx_type TEXT, bank_ofx_id TEXT);');
    }

    public function testDuplicateFitidHandling(): void
    {
        $first = Transaction::create(1, '2024-08-01', 10.0, 'First', null, null, 0, null, null, 'DEBIT', 'DUP123');
        $second = Transaction::create(1, '2024-08-02', 20.0, 'Second', null, null, 0, null, null, 'DEBIT', 'DUP123');
        $this->assertNotSame($first, $second);
        $secondFitid = $this->db->query('SELECT bank_ofx_id FROM transactions WHERE id = ' . $second)->fetchColumn();
        $this->assertSame('DUP123-1', $secondFitid);
        $logCount = $this->db->query("SELECT COUNT(*) FROM logs WHERE level = 'WARNING'")->fetchColumn();
        $this->assertSame(1, (int)$logCount);
    }

    public function testDuplicateTransactionRejection(): void
    {
        $first = Transaction::create(1, '2024-08-03', 30.0, 'Dup', null, null, 0);
        $second = Transaction::create(1, '2024-08-03', 30.0, 'Dup', null, null, 0);
        $this->assertSame($first, $second);
        $count = $this->db->query("SELECT COUNT(*) FROM transactions WHERE description = 'Dup'")->fetchColumn();
        $this->assertSame(1, (int)$count);
    }
}
