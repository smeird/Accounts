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
            if (($snapshot['driver'] ?? 'mysql') === 'mysql') {
                $checks++;
                $engine = strtoupper((string)($actualTable['engine'] ?? ''));
                if ($engine !== '' && $engine !== 'INNODB') {
                    $issues[] = self::issue(
                        'engine:' . $tableName,
                        'engine',
                        $tableName,
                        $tableName,
                        'warning',
                        'Table engine is ' . $engine . '; InnoDB is expected for reliable constraints.',
                        'Review table engine for ' . $tableName,
                        false,
                        null,
                        'Changing a storage engine rebuilds the table and is intentionally left for manual review.'
                    );
                }
            }

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
                    'ALTER TABLE `' . $tableName . '` DROP INDEX `' . $indexName . '`'
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
                    'column_type' => $column['column_type'] ?: $column['type'],
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
                'engine' => 'InnoDB',
                'columns' => $columns,
                'indexes' => $indexes,
                'foreign_keys' => $foreignKeys,
            ];
        }
        return [
            'driver' => 'mysql',
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
        if ($driver !== 'mysql') {
            throw new RuntimeException('Database Health currently supports MySQL databases only.');
        }

        $databaseName = (string)$this->db->query('SELECT DATABASE()')->fetchColumn();
        $snapshot = [
            'driver' => $driver,
            'database' => $databaseName,
            'server_version' => (string)$this->db->getAttribute(PDO::ATTR_SERVER_VERSION),
            'tables' => [],
        ];

        $tableRows = $this->db->query(
            "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tableRows as $row) {
            $snapshot['tables'][$row['TABLE_NAME']] = [
                'engine' => $row['ENGINE'],
                'columns' => [],
                'indexes' => [],
                'foreign_keys' => [],
            ];
        }

        $columnRows = $this->db->query(
            'SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, '
            . 'CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE, EXTRA '
            . 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() '
            . 'ORDER BY TABLE_NAME, ORDINAL_POSITION'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columnRows as $row) {
            if (!isset($snapshot['tables'][$row['TABLE_NAME']])) {
                continue;
            }
            $snapshot['tables'][$row['TABLE_NAME']]['columns'][$row['COLUMN_NAME']] = [
                'data_type' => strtolower((string)$row['DATA_TYPE']),
                'column_type' => strtolower((string)$row['COLUMN_TYPE']),
                'nullable' => strtoupper((string)$row['IS_NULLABLE']) === 'YES',
                'length' => $row['CHARACTER_MAXIMUM_LENGTH'] === null ? null : (int)$row['CHARACTER_MAXIMUM_LENGTH'],
                'precision' => $row['NUMERIC_PRECISION'] === null ? null : (int)$row['NUMERIC_PRECISION'],
                'scale' => $row['NUMERIC_SCALE'] === null ? null : (int)$row['NUMERIC_SCALE'],
                'extra' => (string)$row['EXTRA'],
            ];
        }

        $indexRows = $this->db->query(
            'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, COLUMN_NAME, SEQ_IN_INDEX '
            . 'FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() '
            . 'ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($indexRows as $row) {
            if (!isset($snapshot['tables'][$row['TABLE_NAME']])) {
                continue;
            }
            if (!isset($snapshot['tables'][$row['TABLE_NAME']]['indexes'][$row['INDEX_NAME']])) {
                $snapshot['tables'][$row['TABLE_NAME']]['indexes'][$row['INDEX_NAME']] = [
                    'unique' => (int)$row['NON_UNIQUE'] === 0,
                    'columns' => [],
                ];
            }
            $snapshot['tables'][$row['TABLE_NAME']]['indexes'][$row['INDEX_NAME']]['columns'][] = $row['COLUMN_NAME'];
        }

        $foreignKeyRows = $this->db->query(
            'SELECT kcu.TABLE_NAME, kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME, '
            . 'kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME, kcu.ORDINAL_POSITION, '
            . 'rc.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE kcu '
            . 'JOIN information_schema.REFERENTIAL_CONSTRAINTS rc '
            . 'ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA '
            . 'AND rc.TABLE_NAME = kcu.TABLE_NAME AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME '
            . 'WHERE kcu.CONSTRAINT_SCHEMA = DATABASE() AND kcu.REFERENCED_TABLE_NAME IS NOT NULL '
            . 'ORDER BY kcu.TABLE_NAME, kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION'
        )->fetchAll(PDO::FETCH_ASSOC);
        $groupedForeignKeys = [];
        foreach ($foreignKeyRows as $row) {
            $key = $row['TABLE_NAME'] . ':' . $row['CONSTRAINT_NAME'];
            if (!isset($groupedForeignKeys[$key])) {
                $groupedForeignKeys[$key] = [
                    'table' => $row['TABLE_NAME'],
                    'name' => $row['CONSTRAINT_NAME'],
                    'columns' => [],
                    'referenced_table' => $row['REFERENCED_TABLE_NAME'],
                    'referenced_columns' => [],
                    'delete_rule' => strtoupper((string)$row['DELETE_RULE']),
                ];
            }
            $groupedForeignKeys[$key]['columns'][] = $row['COLUMN_NAME'];
            $groupedForeignKeys[$key]['referenced_columns'][] = $row['REFERENCED_COLUMN_NAME'];
        }
        foreach ($groupedForeignKeys as $foreignKey) {
            $tableName = $foreignKey['table'];
            unset($foreignKey['table']);
            if (isset($snapshot['tables'][$tableName])) {
                $snapshot['tables'][$tableName]['foreign_keys'][] = $foreignKey;
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
        if (strtolower((string)($actual['data_type'] ?? '')) !== strtolower((string)$expected['type'])) {
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

    private static function hasForeignKey(array $actualForeignKeys, array $expected): bool {
        foreach ($actualForeignKeys as $foreignKey) {
            if (self::sameColumns($expected['columns'], $foreignKey['columns'] ?? [])
                && (string)$expected['referenced_table'] === (string)($foreignKey['referenced_table'] ?? '')
                && self::sameColumns($expected['referenced_columns'], $foreignKey['referenced_columns'] ?? [])
                && strtoupper((string)$expected['delete_rule']) === strtoupper((string)($foreignKey['delete_rule'] ?? 'RESTRICT'))) {
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
