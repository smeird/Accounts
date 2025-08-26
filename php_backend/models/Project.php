<?php
// Model for home improvement projects with cost and benefit tracking.
require_once __DIR__ . '/../Database.php';

class Project {
    /**
     * Insert a new project and return its id.
     */
    public static function create(array $data): int {
        $db = Database::getConnection();
        $stmt = $db->prepare('INSERT INTO projects (name, description, rationale, cost_low, cost_medium, cost_high, funding_source, recurring_cost, estimated_time, expected_lifespan, benefit_financial, benefit_quality, benefit_risk, benefit_sustainability, weight_financial, weight_quality, weight_risk, weight_sustainability, dependencies, risks) VALUES (:name, :description, :rationale, :cost_low, :cost_medium, :cost_high, :funding_source, :recurring_cost, :estimated_time, :expected_lifespan, :benefit_financial, :benefit_quality, :benefit_risk, :benefit_sustainability, :weight_financial, :weight_quality, :weight_risk, :weight_sustainability, :dependencies, :risks)');
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
            'risks' => $data['risks'] ?? null
        ]);
        return (int)$db->lastInsertId();
    }

    /**
     * Retrieve all projects with computed weighted score.
     */
    public static function all(): array {
        $db = Database::getConnection();
        $sql = 'SELECT *, (
            benefit_financial*weight_financial +
            benefit_quality*weight_quality +
            benefit_risk*weight_risk +
            benefit_sustainability*weight_sustainability
        ) AS score FROM projects ORDER BY score DESC, id ASC';
        $stmt = $db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update an existing project.
     */
    public static function update(int $id, array $data): bool {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE projects SET name=:name, description=:description, rationale=:rationale, cost_low=:cost_low, cost_medium=:cost_medium, cost_high=:cost_high, funding_source=:funding_source, recurring_cost=:recurring_cost, estimated_time=:estimated_time, expected_lifespan=:expected_lifespan, benefit_financial=:benefit_financial, benefit_quality=:benefit_quality, benefit_risk=:benefit_risk, benefit_sustainability=:benefit_sustainability, weight_financial=:weight_financial, weight_quality=:weight_quality, weight_risk=:weight_risk, weight_sustainability=:weight_sustainability, dependencies=:dependencies, risks=:risks WHERE id=:id');
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
            'id' => $id
        ]);
    }

    /**
     * Delete a project.
     */
    public static function delete(int $id): bool {
        $db = Database::getConnection();
        $stmt = $db->prepare('DELETE FROM projects WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }
}

?>

