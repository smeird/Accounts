<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../php_backend/NaturalLanguageReportParser.php';
require_once __DIR__ . '/../php_backend/Database.php';

class NaturalLanguageReportParserTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('DB_DSN=sqlite::memory:');
        $ref = new ReflectionClass(Database::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null);
        $db = Database::getConnection();
        $db->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT);');
        $db->exec('CREATE TABLE tags (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, keyword TEXT, description TEXT);');
        $db->exec('CREATE TABLE segments (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, description TEXT);');
        $db->exec('CREATE TABLE transaction_groups (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, description TEXT, active INTEGER);');
        $db->exec('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT);');

        $db->exec('INSERT INTO categories (name) VALUES ("cars");');
    }

    public function testParseCategoryAndDateRange(): void
    {
        $filters = NaturalLanguageReportParser::parse('costs for cars in the last 12 months');
        $this->assertSame(1, $filters['category']);
        $this->assertSame(date('Y-m-d', strtotime('-12 months')), $filters['start']);
        $this->assertSame(date('Y-m-d'), $filters['end']);
    }

    public function testParseMultipleTags(): void
    {
        $db = Database::getConnection();
        $db->exec("INSERT INTO tags (name, keyword, description) VALUES ('car', '', ''), ('auto', '', '')");
        $filters = NaturalLanguageReportParser::parse('car auto');
        $this->assertIsArray($filters['tag']);
        sort($filters['tag']);
        $this->assertSame([1,2], $filters['tag']);
    }
}
?>
