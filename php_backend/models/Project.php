<?php
// Model for home improvement projects with cost and benefit tracking.
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/TransactionGroup.php';

class Project {
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
            'cost_low' => $data['cost_low'] ?? null,
            'cost_medium' => $data['cost_medium'] ?? null,
            'cost_high' => $data['cost_high'] ?? null,
            'funding_source' => $data['funding_source'] ?? null,
            'recurring_cost' => $data['recurring_cost'] ?? null,
            'estimated_time' => $data['estimated_time'] ?? null,
            'expected_lifespan' => $data['expected_lifespan'] ?? null,
            'benefit_financial' => $data['benefit_financial'] ?? 0,
            'benefit_quality' => $data['benefit_quality'] ?? 0,
            'benefit_risk' => $data['benefit_risk'] ?? 0,
            'benefit_sustainability' => $data['benefit_sustainability'] ?? 0,
            'weight_financial' => $data['weight_financial'] ?? 1,
            'weight_quality' => $data['weight_quality'] ?? 1,
            'weight_risk' => $data['weight_risk'] ?? 1,
            'weight_sustainability' => $data['weight_sustainability'] ?? 1,
            'dependencies' => $data['dependencies'] ?? null,
            'risks' => $data['risks'] ?? null,
            'archived' => $data['archived'] ?? 0,
            'group_id' => $groupId
        ]);
        return (int)$db->lastInsertId();
    }

    /**
     * Retrieve all projects with computed weighted score.
     */
    public static function all(bool $archived = false): array {
        $db = Database::getConnection();
        $params = ['archived' => $archived ? 1 : 0];
        try {
            $sql = 'SELECT p.*, (
                benefit_financial*weight_financial +
                benefit_quality*weight_quality +
                benefit_risk*weight_risk +
                benefit_sustainability*weight_sustainability
            ) AS score,
            COALESCE(SUM(t.amount),0) AS spent
            FROM projects p
            LEFT JOIN transactions t ON t.group_id = p.group_id
            WHERE p.archived = :archived
            GROUP BY p.id
            ORDER BY score DESC, p.id ASC';
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // If the transactions table does not exist yet, fallback to a query
            // without the join so projects can still be listed.
            $sql = 'SELECT p.*, (
                benefit_financial*weight_financial +
                benefit_quality*weight_quality +
                benefit_risk*weight_risk +
                benefit_sustainability*weight_sustainability
            ) AS score, 0 AS spent
            FROM projects p
            WHERE p.archived = :archived
            ORDER BY score DESC, p.id ASC';
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
            'cost_low' => $data['cost_low'] ?? null,
            'cost_medium' => $data['cost_medium'] ?? null,
            'cost_high' => $data['cost_high'] ?? null,
            'funding_source' => $data['funding_source'] ?? null,
            'recurring_cost' => $data['recurring_cost'] ?? null,
            'estimated_time' => $data['estimated_time'] ?? null,
            'expected_lifespan' => $data['expected_lifespan'] ?? null,
            'benefit_financial' => $data['benefit_financial'] ?? 0,
            'benefit_quality' => $data['benefit_quality'] ?? 0,
            'benefit_risk' => $data['benefit_risk'] ?? 0,
            'benefit_sustainability' => $data['benefit_sustainability'] ?? 0,
            'weight_financial' => $data['weight_financial'] ?? 1,
            'weight_quality' => $data['weight_quality'] ?? 1,
            'weight_risk' => $data['weight_risk'] ?? 1,
            'weight_sustainability' => $data['weight_sustainability'] ?? 1,
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
        $stmt = $db->prepare('UPDATE projects SET archived = :archived WHERE id = :id');
        $ok = $stmt->execute(['archived' => $archived ? 1 : 0, 'id' => $id]);
        if($ok){
            $gidStmt = $db->prepare('SELECT group_id FROM projects WHERE id = :id');
            $gidStmt->execute(['id' => $id]);
            $groupId = (int)$gidStmt->fetchColumn();
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
        $groupId = (int)$gidStmt->fetchColumn();
        $stmt = $db->prepare('DELETE FROM projects WHERE id = :id');
        $ok = $stmt->execute(['id' => $id]);
        if($ok && $groupId){
            TransactionGroup::delete($groupId);
        }
        return $ok;
    }
}

?>

