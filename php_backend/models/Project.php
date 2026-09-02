<?php
// Model for home improvement projects with cost and benefit tracking.
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/TransactionGroup.php';

class Project {
    /** Fixed decision weights. Per-project weighting made unlike projects
     * incomparable, so these are deliberately shared by the whole portfolio. */
    private const PRIORITY_WEIGHTS = [
        'benefit_risk' => 35,           // consequence of delay
        'weight_risk' => 25,            // urgency (legacy column, new meaning)
        'benefit_sustainability' => 20, // asset preservation
        'benefit_financial' => 10,      // financial impact
        'benefit_quality' => 10,        // daily-life impact
    ];

    private static function rating(array $data, string $key, int $default = 0): int {
        $value = isset($data[$key]) && is_numeric($data[$key]) ? (int)$data[$key] : $default;
        return max(0, min(5, $value));
    }

    /**
     * HTML number inputs submit an empty string when an optional field is left
     * blank. PostgreSQL rejects that value for DECIMAL and INT columns, so
     * store a genuine SQL NULL instead.
     */
    private static function nullableNumber(array $data, string $key, bool $integer = false) {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }
        $value = is_string($data[$key]) ? trim($data[$key]) : $data[$key];
        if ($value === '' || !is_numeric($value)) {
            return null;
        }
        return $integer ? (int)$value : $value;
    }

    /** Return a transparent 0-100 importance score. */
    public static function calculatePriorityScore(array $project): int {
        $weighted = 0;
        foreach (self::PRIORITY_WEIGHTS as $field => $weight) {
            $weighted += self::rating($project, $field) * $weight;
        }
        return (int)round($weighted / 5);
    }

    /**
     * Group the score into an action-oriented tier. A severe consequence that
     * is already urgent is critical regardless of softer benefits.
     */
    public static function priorityTier(array $project): array {
        $consequence = self::rating($project, 'benefit_risk');
        $urgency = self::rating($project, 'weight_risk');
        $preservation = self::rating($project, 'benefit_sustainability');
        $score = self::calculatePriorityScore($project);
        if ($consequence >= 5 && $urgency >= 4) return ['key' => 'critical', 'label' => 'Critical — act now', 'rank' => 1];
        if ($score >= 70 || ($consequence >= 4 && $urgency >= 4)) return ['key' => 'important', 'label' => 'Important — plan next', 'rank' => 2];
        if ($score >= 50 || $consequence >= 4 || $preservation >= 4) return ['key' => 'preventive', 'label' => 'Preventive — schedule soon', 'rank' => 3];
        if ($score >= 30) return ['key' => 'improvement', 'label' => 'Improvement — worthwhile', 'rank' => 4];
        return ['key' => 'nice', 'label' => 'Nice to have', 'rank' => 5];
    }

    private static function addPriorityFields(array $rows): array {
        foreach ($rows as &$row) {
            $tier = self::priorityTier($row);
            $row['score'] = self::calculatePriorityScore($row);
            $row['priority_key'] = $tier['key'];
            $row['priority_label'] = $tier['label'];
            $row['priority_rank'] = $tier['rank'];
        }
        unset($row);
        usort($rows, static function (array $a, array $b): int {
            $rank = ((int)$a['priority_rank']) <=> ((int)$b['priority_rank']);
            if ($rank !== 0) return $rank;
            $score = ((int)$b['score']) <=> ((int)$a['score']);
            return $score !== 0 ? $score : ((int)$a['id']) <=> ((int)$b['id']);
        });
        return $rows;
    }
    /**
     * Insert a new project and return its id.
     */
    public static function create(array $data): int {
        $db = Database::getConnection();
        $groupId = TransactionGroup::create($data['name'] ?? 'Project', $data['description'] ?? null, !($data['archived'] ?? 0));
        $stmt = $db->prepare('INSERT INTO projects (name, description, rationale, cost_low, cost_medium, cost_high, funding_source, recurring_cost, estimated_time, expected_lifespan, benefit_financial, benefit_quality, benefit_risk, benefit_sustainability, weight_financial, weight_quality, weight_risk, weight_sustainability, dependencies, risks, archived, group_id) VALUES (:name, :description, :rationale, :cost_low, :cost_medium, :cost_high, :funding_source, :recurring_cost, :estimated_time, :expected_lifespan, :benefit_financial, :benefit_quality, :benefit_risk, :benefit_sustainability, :weight_financial, :weight_quality, :weight_risk, :weight_sustainability, :dependencies, :risks, :archived, :group_id)');
        $stmt->execute([
            'name' => $data['name'] ?? '',
            'description' => $data['description'] ?? null,
            'rationale' => $data['rationale'] ?? null,
            'cost_low' => self::nullableNumber($data, 'cost_low'),
            'cost_medium' => self::nullableNumber($data, 'cost_medium'),
            'cost_high' => self::nullableNumber($data, 'cost_high'),
            'funding_source' => $data['funding_source'] ?? null,
            'recurring_cost' => self::nullableNumber($data, 'recurring_cost'),
            'estimated_time' => self::nullableNumber($data, 'estimated_time', true),
            'expected_lifespan' => self::nullableNumber($data, 'expected_lifespan', true),
            'benefit_financial' => self::rating($data, 'benefit_financial'),
            'benefit_quality' => self::rating($data, 'benefit_quality'),
            'benefit_risk' => self::rating($data, 'benefit_risk'),
            'benefit_sustainability' => self::rating($data, 'benefit_sustainability'),
            'weight_financial' => 1,
            'weight_quality' => 1,
            'weight_risk' => self::rating($data, 'weight_risk'),
            'weight_sustainability' => 1,
            'dependencies' => $data['dependencies'] ?? null,
            'risks' => $data['risks'] ?? null,
            'archived' => $data['archived'] ?? 0,
            'group_id' => $groupId
        ]);
        return (int)$db->lastInsertId();
    }

    /**
     * Retrieve all projects with a consistent portfolio priority score.
     */
    public static function all(bool $archived = false): array {
        $db = Database::getConnection();
        $params = ['archived' => $archived ? 1 : 0];
        try {
            $sql = 'SELECT p.*,
            COALESCE(SUM(CASE WHEN t.amount < 0 THEN -t.amount ELSE 0 END),0) AS spent
            FROM projects p
            LEFT JOIN transactions t ON t.group_id = p.group_id AND t.transfer_id IS NULL
            WHERE p.archived = :archived
            GROUP BY p.id
            ORDER BY p.id ASC';
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return self::addPriorityFields($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) {
            // If the transactions table does not exist yet, fallback to a query
            // without the join so projects can still be listed.
            $sql = 'SELECT p.*, 0 AS spent
            FROM projects p
            WHERE p.archived = :archived
            ORDER BY p.id ASC';
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return self::addPriorityFields($stmt->fetchAll(PDO::FETCH_ASSOC));
        }
    }

    /**
     * Update an existing project.
     */
    public static function update(int $id, array $data): bool {
        $db = Database::getConnection();
        // rename associated transaction group and align active flag
        $projStmt = $db->prepare('SELECT group_id, archived FROM projects WHERE id = :id');
        $projStmt->execute(['id' => $id]);
        $proj = $projStmt->fetch(PDO::FETCH_ASSOC);
        if (!$proj) {
            return false;
        }
        $groupId = (int)($proj['group_id'] ?? 0);
        $currentArchived = (int)($proj['archived'] ?? 0);
        $archivedFlag = $data['archived'] ?? $currentArchived;
        if($groupId){
            TransactionGroup::update($groupId, $data['name'] ?? 'Project', $data['description'] ?? null, !$archivedFlag);
        }
        $stmt = $db->prepare('UPDATE projects SET name=:name, description=:description, rationale=:rationale, cost_low=:cost_low, cost_medium=:cost_medium, cost_high=:cost_high, funding_source=:funding_source, recurring_cost=:recurring_cost, estimated_time=:estimated_time, expected_lifespan=:expected_lifespan, benefit_financial=:benefit_financial, benefit_quality=:benefit_quality, benefit_risk=:benefit_risk, benefit_sustainability=:benefit_sustainability, weight_financial=:weight_financial, weight_quality=:weight_quality, weight_risk=:weight_risk, weight_sustainability=:weight_sustainability, dependencies=:dependencies, risks=:risks, archived=:archived WHERE id=:id');
        return $stmt->execute([
            'name' => $data['name'] ?? '',
            'description' => $data['description'] ?? null,
            'rationale' => $data['rationale'] ?? null,
            'cost_low' => self::nullableNumber($data, 'cost_low'),
            'cost_medium' => self::nullableNumber($data, 'cost_medium'),
            'cost_high' => self::nullableNumber($data, 'cost_high'),
            'funding_source' => $data['funding_source'] ?? null,
            'recurring_cost' => self::nullableNumber($data, 'recurring_cost'),
            'estimated_time' => self::nullableNumber($data, 'estimated_time', true),
            'expected_lifespan' => self::nullableNumber($data, 'expected_lifespan', true),
            'benefit_financial' => self::rating($data, 'benefit_financial'),
            'benefit_quality' => self::rating($data, 'benefit_quality'),
            'benefit_risk' => self::rating($data, 'benefit_risk'),
            'benefit_sustainability' => self::rating($data, 'benefit_sustainability'),
            'weight_financial' => 1,
            'weight_quality' => 1,
            'weight_risk' => self::rating($data, 'weight_risk'),
            'weight_sustainability' => 1,
            'dependencies' => $data['dependencies'] ?? null,
            'risks' => $data['risks'] ?? null,
            'archived' => $archivedFlag,
            'id' => $id
        ]);
    }

    /**
     * Mark a project as archived or active.
     */
    public static function setArchived(int $id, bool $archived): bool {
        $db = Database::getConnection();
        $gidStmt = $db->prepare('SELECT group_id FROM projects WHERE id = :id');
        $gidStmt->execute(['id' => $id]);
        $groupIdValue = $gidStmt->fetchColumn();
        if ($groupIdValue === false) {
            return false;
        }

        $stmt = $db->prepare('UPDATE projects SET archived = :archived WHERE id = :id');
        $ok = $stmt->execute(['archived' => $archived ? 1 : 0, 'id' => $id]);
        if($ok){
            $groupId = (int)$groupIdValue;
            if($groupId){
                TransactionGroup::setActive($groupId, !$archived);
            }
        }
        return $ok;
    }

    /**
     * Delete a project.
     */
    public static function delete(int $id): bool {
        $db = Database::getConnection();
        // find and delete associated group
        $gidStmt = $db->prepare('SELECT group_id FROM projects WHERE id = :id');
        $gidStmt->execute(['id' => $id]);
        $groupIdValue = $gidStmt->fetchColumn();
        if ($groupIdValue === false) {
            return false;
        }
        $groupId = (int)$groupIdValue;
        $stmt = $db->prepare('DELETE FROM projects WHERE id = :id');
        $ok = $stmt->execute(['id' => $id]);
        if($ok && $groupId){
            TransactionGroup::delete($groupId);
        }
        return $ok;
    }
}

?>
