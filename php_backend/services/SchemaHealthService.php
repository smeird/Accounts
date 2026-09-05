<?php
// Audits and repairs schema metadata without inserting, updating, or deleting application records.
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../SchemaCatalog.php';

class SchemaHealthService {
    private $db;
    private $snapshotProvider;
    private $schemaExecutor;

    public function __construct($db = null, $snapshotProvider = null, $schemaExecutor = null) {
        $this->db = $db;
        $this->snapshotProvider = $snapshotProvider;
        $this->schemaExecutor = $schemaExecutor;
        if ($this->db === null && $this->snapshotProvider === null) {
            $this->db = Database::getConnection();
        }
    }

    /** @return array<string,mixed> */
    public function audit(): array {
        $snapshot = $this->collectSnapshot();
        return self::analyseSnapshot($snapshot);
    }

    /**
     * Execute only server-catalogued repairs selected by their stable issue IDs.
     * Client-provided SQL is never accepted.
     *
     * @param array<int,string> $selectedIssueIds
     * @return array<string,mixed>
     */
    public function repair(array $selectedIssueIds): array {
        $before = $this->audit();
        $selected = array_fill_keys(array_map('strval', $selectedIssueIds), true);
        $results = [];

        foreach ($before['issues'] as $issue) {
            if (empty($selected[$issue['id']]) || empty($issue['repairable'])) {
                continue;
            }
            try {
                $this->executeSchemaIssue($issue);
                $results[] = [
                    'id' => $issue['id'],
                    'status' => 'success',
                    'message' => $issue['operation'] . ' completed.',
                ];
            } catch (Throwable $e) {
                $results[] = [
                    'id' => $issue['id'],
                    'status' => 'error',
                    'message' => $issue['operation'] . ' failed: ' . $e->getMessage(),
                ];
            }
        }

        $after = $this->audit();
        $failed = count(array_filter($results, function($result) {
            return $result['status'] === 'error';
        }));
        $succeeded = count($results) - $failed;

        return [
            'status' => $failed > 0 ? ($succeeded > 0 ? 'partial' : 'error') : 'success',
            'message' => !$results
                ? 'No selected schema repairs were available to run.'
                : ($failed > 0
                    ? sprintf('%d schema repair%s completed and %d failed.', $succeeded, $succeeded === 1 ? '' : 's', $failed)
                    : sprintf('%d schema repair%s completed.', $succeeded, $succeeded === 1 ? '' : 's')),
            'results' => $results,
            'summary' => [
                'attempted' => count($results),
                'succeeded' => $succeeded,
                'failed' => $failed,
            ],
            'audit' => $after,
        ];
    }

    /**
     * Compare a metadata snapshot with the canonical catalogue.
     *
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public static function analyseSnapshot(array $snapshot): array {
        $catalog = SchemaCatalog::tables();
        $issues = [];
        $checks = 0;
        $actualTables = isset($snapshot['tables']) && is_array($snapshot['tables']) ? $snapshot['tables'] : [];

        foreach ($catalog as $tableName => $expectedTable) {
            $checks++;
            if (!isset($actualTables[$tableName])) {
                $issues[] = self::issue(
                    'table:' . $tableName,
                    'table',
                    $tableName,
                    $tableName,
                    'critical',
                    'Required table is missing.',
                    'Create table ' . $tableName,
                    true,
                    SchemaCatalog::createTableSql($tableName, $expectedTable)
                );
                continue;
            }

            $actualTable = $actualTables[$tableName];
            $actualColumns = isset($actualTable['columns']) ? $actualTable['columns'] : [];
            $missingColumns = [];
            foreach ($expectedTable['columns'] as $columnName => $expectedColumn) {
                $checks++;
                if (!isset($actualColumns[$columnName])) {
                    $missingColumns[$columnName] = $expectedColumn;
                    $repairable = !empty($expectedColumn['safe_add']);
                    $issues[] = self::issue(
                        'column:' . $tableName . '.' . $columnName,
                        'column',
                        $tableName,
                        $columnName,
                        'critical',
                        'Required column is missing.',
                        'Add column ' . $tableName . '.' . $columnName,
                        $repairable,
                        $repairable ? SchemaCatalog::addColumnSql($tableName, $columnName, $expectedColumn) : null,
                        $repairable ? null : 'This required column has no safe default; adding it could require changing existing records.'
                    );
                    continue;
                }
                $differences = self::columnDifferences($expectedColumn, $actualColumns[$columnName]);
                if ($differences) {
                    $issues[] = self::issue(
                        'column_definition:' . $tableName . '.' . $columnName,
                        'column_definition',
                        $tableName,
                        $columnName,
                        'warning',
                        'Column definition differs: ' . implode('; ', $differences) . '.',
                        'Review column definition for ' . $tableName . '.' . $columnName,
                        false,
                        null,
                        'Automatic type changes are disabled because they can transform or truncate stored values.'
                    );
                }
            }

            $actualIndexes = isset($actualTable['indexes']) ? $actualTable['indexes'] : [];
            $checks++;
            if (!empty($expectedTable['primary'])) {
                if (!isset($actualIndexes['PRIMARY'])) {
                    $canAddPrimary = self::dependenciesRepairable($expectedTable['primary'], $missingColumns);
                    $issues[] = self::issue(
                        'index:' . $tableName . '.PRIMARY',
                        'index',
                        $tableName,
                        'PRIMARY',
                        'critical',
                        'Primary key is missing.',
                        'Add primary key to ' . $tableName,
                        $canAddPrimary,
                        $canAddPrimary ? SchemaCatalog::addPrimarySql($tableName, $expectedTable['primary']) : null,
                        $canAddPrimary ? null : 'The primary-key columns must be repaired first.'
                    );
                } elseif (!self::sameColumns($expectedTable['primary'], $actualIndexes['PRIMARY']['columns'] ?? [])) {
                    $issues[] = self::issue(
                        'index_definition:' . $tableName . '.PRIMARY',
                        'index_definition',
                        $tableName,
                        'PRIMARY',
                        'critical',
                        'Primary-key columns differ from the expected schema.',
                        'Review primary key for ' . $tableName,
                        false,
                        null,
                        'Replacing a primary key can affect relationships and requires manual review.'
                    );
                }
            }

            foreach ($expectedTable['indexes'] as $indexName => $expectedIndex) {
                $checks++;
                if (!isset($actualIndexes[$indexName])) {
                    if (self::hasEquivalentIndex($actualIndexes, $expectedIndex)) {
                        continue;
                    }
                    $canAddIndex = self::dependenciesRepairable($expectedIndex['columns'], $missingColumns);
                    $issues[] = self::issue(
                        'index:' . $tableName . '.' . $indexName,
                        'index',
                        $tableName,
                        $indexName,
                        'warning',
                        ($expectedIndex['unique'] ? 'Required unique index' : 'Required lookup index') . ' is missing.',
                        'Add index ' . $indexName,
                        $canAddIndex,
                        $canAddIndex ? SchemaCatalog::addIndexSql($tableName, $indexName, $expectedIndex) : null,
                        $canAddIndex ? null : 'The indexed columns must be repaired first.'
                    );
                    continue;
                }
                $actualIndex = $actualIndexes[$indexName];
                if ((bool)$expectedIndex['unique'] !== (bool)($actualIndex['unique'] ?? false)
                    || !self::sameColumns($expectedIndex['columns'], $actualIndex['columns'] ?? [])) {
                    $issues[] = self::issue(
                        'index_definition:' . $tableName . '.' . $indexName,
                        'index_definition',
                        $tableName,
                        $indexName,
                        'warning',
                        'Index definition differs from the expected columns or uniqueness.',
                        'Review index ' . $indexName,
                        false,
                        null,
                        'Replacing an existing index is intentionally left for manual review.'
                    );
                }
            }

            $actualForeignKeys = isset($actualTable['foreign_keys']) ? $actualTable['foreign_keys'] : [];
            foreach ($expectedTable['foreign_keys'] as $constraintName => $expectedForeignKey) {
                $checks++;
                if (self::hasForeignKey($actualForeignKeys, $expectedForeignKey)) {
                    continue;
                }
                $canAddForeignKey = self::dependenciesRepairable($expectedForeignKey['columns'], $missingColumns);
                $issues[] = self::issue(
                    'foreign_key:' . $tableName . '.' . $constraintName,
                    'foreign_key',
                    $tableName,
                    $constraintName,
                    'warning',
                    'Required relationship constraint is missing.',
                    'Add relationship ' . $constraintName,
                    $canAddForeignKey,
                    $canAddForeignKey ? SchemaCatalog::addForeignKeySql($tableName, $constraintName, $expectedForeignKey) : null,
                    $canAddForeignKey ? null : 'The relationship columns must be repaired first.'
                );
            }
        }

        foreach (SchemaCatalog::obsoleteIndexes() as $tableName => $indexes) {
            foreach ($indexes as $indexName => $reason) {
                $checks++;
                if (!isset($actualTables[$tableName]['indexes'][$indexName])) {
                    continue;
                }
                $issues[] = self::issue(
                    'obsolete_index:' . $tableName . '.' . $indexName,
                    'obsolete_index',
                    $tableName,
                    $indexName,
                    'warning',
                    $reason,
                    'Remove obsolete index ' . $indexName,
                    true,
                    'DROP INDEX IF EXISTS "' . str_replace('"', '""', $indexName) . '"'
                );
            }
        }

        $repairable = count(array_filter($issues, function($issue) {
            return !empty($issue['repairable']);
        }));
        $manual = count($issues) - $repairable;

        return [
            'status' => $issues ? 'issues' : 'healthy',
            'healthy' => !$issues,
            'checked_at' => gmdate('c'),
            'database' => [
                'name' => (string)($snapshot['database'] ?? ''),
                'driver' => (string)($snapshot['driver'] ?? ''),
                'server_version' => (string)($snapshot['server_version'] ?? ''),
            ],
            'summary' => [
                'managed_tables' => count($catalog),
                'checks' => $checks,
                'passed' => max(0, $checks - count($issues)),
                'issues' => count($issues),
                'repairable' => $repairable,
                'manual' => $manual,
            ],
            'issues' => $issues,
            'scope' => 'Schema objects only. No INSERT, UPDATE, DELETE, TRUNCATE, or record backfill statements are generated.',
        ];
    }

    /** @return array<string,mixed> */
    public static function expectedSnapshot(): array {
        $tables = [];
        foreach (SchemaCatalog::tables() as $tableName => $table) {
            $columns = [];
            foreach ($table['columns'] as $columnName => $column) {
                $columns[$columnName] = [
                    'data_type' => $column['type'],
                    'column_type' => null,
                    'nullable' => $column['nullable'] === null ? true : $column['nullable'],
                    'length' => $column['length'],
                    'precision' => $column['precision'],
                    'scale' => $column['scale'],
                    'extra' => $column['extra'] ?: '',
                ];
            }
            $indexes = [];
            if ($table['primary']) {
                $indexes['PRIMARY'] = ['unique' => true, 'columns' => $table['primary']];
            }
            foreach ($table['indexes'] as $indexName => $index) {
                $indexes[$indexName] = $index;
            }
            $foreignKeys = [];
            foreach ($table['foreign_keys'] as $name => $foreignKey) {
                $foreignKey['name'] = $name;
                $foreignKeys[] = $foreignKey;
            }
            $tables[$tableName] = [
                'engine' => null,
                'columns' => $columns,
                'indexes' => $indexes,
                'foreign_keys' => $foreignKeys,
            ];
        }
        return [
            'driver' => 'pgsql',
            'database' => 'test',
            'server_version' => 'test',
            'tables' => $tables,
        ];
    }

    /** @return array<string,mixed> */
    private function collectSnapshot(): array {
        if (is_callable($this->snapshotProvider)) {
            return call_user_func($this->snapshotProvider);
        }
        $driver = (string)$this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver !== 'pgsql') {
            throw new RuntimeException('Database Health requires PostgreSQL.');
        }

        $databaseName = (string)$this->db->query('SELECT CURRENT_DATABASE()')->fetchColumn();
        $snapshot = [
            'driver' => $driver,
            'database' => $databaseName,
            'server_version' => (string)$this->db->getAttribute(PDO::ATTR_SERVER_VERSION),
            'tables' => [],
        ];

        $tableRows = $this->db->query(
            "SELECT table_name FROM information_schema.tables "
            . "WHERE table_schema = CURRENT_SCHEMA() AND table_type = 'BASE TABLE'"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tableRows as $row) {
            $snapshot['tables'][$row['table_name']] = [
                'engine' => null,
                'columns' => [],
                'indexes' => [],
                'foreign_keys' => [],
            ];
        }

        $columnRows = $this->db->query(
            'SELECT table_name, column_name, data_type, udt_name, is_nullable, '
            . 'character_maximum_length, numeric_precision, numeric_scale, is_identity '
            . 'FROM information_schema.columns WHERE table_schema = CURRENT_SCHEMA() '
            . 'ORDER BY table_name, ordinal_position'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columnRows as $row) {
            if (!isset($snapshot['tables'][$row['table_name']])) {
                continue;
            }
            $snapshot['tables'][$row['table_name']]['columns'][$row['column_name']] = [
                'data_type' => strtolower((string)$row['data_type']),
                'column_type' => strtolower((string)$row['udt_name']),
                'nullable' => strtoupper((string)$row['is_nullable']) === 'YES',
                'length' => $row['character_maximum_length'] === null ? null : (int)$row['character_maximum_length'],
                'precision' => $row['numeric_precision'] === null ? null : (int)$row['numeric_precision'],
                'scale' => $row['numeric_scale'] === null ? null : (int)$row['numeric_scale'],
                'extra' => strtoupper((string)$row['is_identity']) === 'YES' ? 'identity' : '',
            ];
        }

        $indexRows = $this->db->query(
            "SELECT tbl.relname AS table_name, idx.relname AS index_name, ind.indisunique AS is_unique, "
            . "ind.indisprimary AS is_primary, STRING_AGG(att.attname, ',' ORDER BY ord.ordinality) AS columns_csv "
            . 'FROM pg_index ind JOIN pg_class idx ON idx.oid = ind.indexrelid '
            . 'JOIN pg_class tbl ON tbl.oid = ind.indrelid JOIN pg_namespace ns ON ns.oid = tbl.relnamespace '
            . 'JOIN LATERAL UNNEST(ind.indkey) WITH ORDINALITY ord(attnum, ordinality) ON TRUE '
            . 'JOIN pg_attribute att ON att.attrelid = tbl.oid AND att.attnum = ord.attnum '
            . "WHERE ns.nspname = CURRENT_SCHEMA() GROUP BY tbl.relname, idx.relname, ind.indisunique, ind.indisprimary"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($indexRows as $row) {
            if (!isset($snapshot['tables'][$row['table_name']])) {
                continue;
            }
            $name = self::databaseBoolean($row['is_primary']) ? 'PRIMARY' : $row['index_name'];
            $snapshot['tables'][$row['table_name']]['indexes'][$name] = [
                'unique' => self::databaseBoolean($row['is_unique']),
                'columns' => $row['columns_csv'] === '' ? [] : explode(',', $row['columns_csv']),
            ];
        }

        $foreignKeyRows = $this->db->query(
            "SELECT src.relname AS table_name, con.conname AS constraint_name, dst.relname AS referenced_table_name, "
            . "STRING_AGG(sa.attname, ',' ORDER BY ord.ordinality) AS columns_csv, "
            . "STRING_AGG(da.attname, ',' ORDER BY ord.ordinality) AS referenced_columns_csv, "
            . "CASE con.confdeltype WHEN 'c' THEN 'CASCADE' WHEN 'n' THEN 'SET NULL' WHEN 'd' THEN 'SET DEFAULT' "
            . "WHEN 'r' THEN 'RESTRICT' ELSE 'NO ACTION' END AS delete_rule "
            . 'FROM pg_constraint con JOIN pg_class src ON src.oid = con.conrelid JOIN pg_class dst ON dst.oid = con.confrelid '
            . 'JOIN pg_namespace ns ON ns.oid = src.relnamespace '
            . 'JOIN LATERAL UNNEST(con.conkey, con.confkey) WITH ORDINALITY ord(srcnum, dstnum, ordinality) ON TRUE '
            . 'JOIN pg_attribute sa ON sa.attrelid = src.oid AND sa.attnum = ord.srcnum '
            . 'JOIN pg_attribute da ON da.attrelid = dst.oid AND da.attnum = ord.dstnum '
            . "WHERE con.contype = 'f' AND ns.nspname = CURRENT_SCHEMA() "
            . 'GROUP BY src.relname, con.conname, dst.relname, con.confdeltype'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($foreignKeyRows as $row) {
            if (isset($snapshot['tables'][$row['table_name']])) {
                $snapshot['tables'][$row['table_name']]['foreign_keys'][] = [
                    'name' => $row['constraint_name'],
                    'columns' => explode(',', $row['columns_csv']),
                    'referenced_table' => $row['referenced_table_name'],
                    'referenced_columns' => explode(',', $row['referenced_columns_csv']),
                    'delete_rule' => strtoupper((string)$row['delete_rule']),
                ];
            }
        }
        return $snapshot;
    }

    private function executeSchemaIssue(array $issue) {
        if (is_callable($this->schemaExecutor)) {
            call_user_func($this->schemaExecutor, $issue);
            return;
        }
        $sql = isset($issue['sql']) ? (string)$issue['sql'] : '';
        if ($sql === '') {
            throw new RuntimeException('No catalogue repair is available for this issue.');
        }
        $this->db->exec($sql);
    }

    /** @return array<int,string> */
    private static function columnDifferences(array $expected, array $actual): array {
        $differences = [];
        if (self::normaliseType($actual['data_type'] ?? '') !== self::normaliseType($expected['type'])) {
            $differences[] = 'expected type ' . $expected['type'] . ', found ' . ($actual['data_type'] ?? 'unknown');
        }
        if ($expected['length'] !== null && (int)($actual['length'] ?? 0) !== (int)$expected['length']) {
            $differences[] = 'expected length ' . $expected['length'] . ', found ' . ($actual['length'] ?? 'unknown');
        }
        if ($expected['precision'] !== null && (int)($actual['precision'] ?? 0) !== (int)$expected['precision']) {
            $differences[] = 'expected precision ' . $expected['precision'] . ', found ' . ($actual['precision'] ?? 'unknown');
        }
        if ($expected['scale'] !== null && (int)($actual['scale'] ?? 0) !== (int)$expected['scale']) {
            $differences[] = 'expected scale ' . $expected['scale'] . ', found ' . ($actual['scale'] ?? 'unknown');
        }
        if ($expected['nullable'] !== null && (bool)($actual['nullable'] ?? false) !== (bool)$expected['nullable']) {
            $differences[] = $expected['nullable'] ? 'expected nullable values' : 'expected NOT NULL';
        }
        if ($expected['extra'] !== null
            && stripos((string)($actual['extra'] ?? ''), (string)$expected['extra']) === false) {
            $differences[] = 'expected ' . $expected['extra'];
        }
        if ($expected['column_type'] !== null
            && strtolower((string)($actual['column_type'] ?? '')) !== strtolower((string)$expected['column_type'])) {
            $differences[] = 'expected column type ' . $expected['column_type'];
        }
        return $differences;
    }

    private static function normaliseType($type): string {
        $type = strtolower(trim((string)$type));
        $aliases = [
            'int' => 'integer', 'tinyint' => 'smallint', 'decimal' => 'numeric',
            'varchar' => 'character varying', 'char' => 'character', 'longtext' => 'text',
            'enum' => 'character varying', 'timestamp' => 'timestamp without time zone',
        ];
        return $aliases[$type] ?? $type;
    }

    private static function databaseBoolean($value): bool {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }

    private static function dependenciesRepairable(array $columns, array $missingColumns): bool {
        foreach ($columns as $column) {
            if (isset($missingColumns[$column]) && empty($missingColumns[$column]['safe_add'])) {
                return false;
            }
        }
        return true;
    }

    private static function sameColumns(array $expected, array $actual): bool {
        return array_values($expected) === array_values($actual);
    }

    private static function hasEquivalentIndex(array $actualIndexes, array $expected): bool {
        foreach ($actualIndexes as $actualIndex) {
            if ((bool)$expected['unique'] === (bool)($actualIndex['unique'] ?? false)
                && self::sameColumns($expected['columns'], $actualIndex['columns'] ?? [])) {
                return true;
            }
        }
        return false;
    }

    private static function normaliseReferentialAction($action): string {
        $normalised = strtoupper(trim((string)$action));
        return $normalised === 'NO ACTION' ? 'RESTRICT' : $normalised;
    }

    private static function hasForeignKey(array $actualForeignKeys, array $expected): bool {
        foreach ($actualForeignKeys as $foreignKey) {
            if (self::sameColumns($expected['columns'], $foreignKey['columns'] ?? [])
                && (string)$expected['referenced_table'] === (string)($foreignKey['referenced_table'] ?? '')
                && self::sameColumns($expected['referenced_columns'], $foreignKey['referenced_columns'] ?? [])
                && self::normaliseReferentialAction($expected['delete_rule'])
                    === self::normaliseReferentialAction($foreignKey['delete_rule'] ?? 'RESTRICT')) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string,mixed> */
    private static function issue(
        string $id,
        string $kind,
        string $table,
        string $object,
        string $severity,
        string $message,
        string $operation,
        bool $repairable,
        $sql = null,
        $manualReason = null
    ): array {
        return [
            'id' => $id,
            'kind' => $kind,
            'table' => $table,
            'object' => $object,
            'severity' => $severity,
            'message' => $message,
            'operation' => $operation,
            'repairable' => $repairable,
            'sql' => $repairable ? $sql : null,
            'manual_reason' => $repairable ? null : $manualReason,
        ];
    }
}
