<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../php_backend/models/Project.php';
require_once __DIR__ . '/../php_backend/Database.php';

class ProjectSavingTest extends TestCase
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
        $this->db->exec('CREATE TABLE transaction_groups (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, description TEXT, active TINYINT DEFAULT 1);');
        $this->db->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, description TEXT, rationale TEXT, cost_low REAL, cost_medium REAL, cost_high REAL, funding_source TEXT, recurring_cost REAL, estimated_time INT, expected_lifespan INT, benefit_financial INT, benefit_quality INT, benefit_risk INT, benefit_sustainability INT, weight_financial INT, weight_quality INT, weight_risk INT, weight_sustainability INT, dependencies TEXT, risks TEXT, archived TINYINT, group_id INT, created_at TEXT DEFAULT CURRENT_TIMESTAMP);');
    }

    public function testProjectPersistsWithoutTransactionsTable(): void
    {
        $id = Project::create(['name' => 'New']);
        $projects = Project::all(false);
        $this->assertCount(1, $projects);
        $this->assertSame('New', $projects[0]['name']);
        $this->assertSame(0, (int)$projects[0]['spent']);
    }

    public function testArchivingProjectDisablesGroup(): void
    {
        $id = Project::create(['name' => 'Test']);
        Project::setArchived($id, true);
        $active = $this->db->query('SELECT active FROM transaction_groups WHERE id = (SELECT group_id FROM projects WHERE id = '.$id.')')->fetchColumn();
        $this->assertSame(0, (int)$active);
    }
}
