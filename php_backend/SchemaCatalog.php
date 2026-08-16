<?php
// Canonical database structure shared by fresh installs and schema health checks.
class SchemaCatalog {
    public static function tables(): array {
        return [
            'users' => self::table([
                'id' => self::column('INT AUTO_INCREMENT', 'int', false, false, null, 'auto_increment'),
                'username' => self::column('VARCHAR(100) NOT NULL', 'varchar', false, false, 100),
                'password' => self::column('VARCHAR(255) NOT NULL', 'varchar', false, false, 255),
            ], ['id'], [
                'username' => self::index(['username'], true),
            ]),
            'totp_secrets' => self::table([
                'username' => self::column('VARCHAR(100) NOT NULL', 'varchar', false, false, 100),
                'secret' => self::column('VARCHAR(64) NOT NULL', 'varchar', false, false, 64),
                'created_at' => self::column('TIMESTAMP DEFAULT CURRENT_TIMESTAMP', 'timestamp', null, true),
            ], ['username']),
            'settings' => self::table([
                'name' => self::column('VARCHAR(100) NOT NULL', 'varchar', false, false, 100),
                'value' => self::column('TEXT NOT NULL', 'text', false, false),
            ], ['name']),
            'saved_reports' => self::table([
                'id' => self::column('INT AUTO_INCREMENT', 'int', false, false, null, 'auto_increment'),
                'name' => self::column('VARCHAR(255) NOT NULL', 'varchar', false, false, 255),
                'description' => self::column('TEXT DEFAULT NULL', 'text', true, true),
                'filters' => self::column('TEXT NOT NULL', 'text', false, false),
                'created_at' => self::column('TIMESTAMP DEFAULT CURRENT_TIMESTAMP', 'timestamp', null, true),
            ], ['id']),
            'accounts' => self::table([
                'id' => self::column('INT AUTO_INCREMENT', 'int', false, false, null, 'auto_increment'),
                'name' => self::column('VARCHAR(100) NOT NULL', 'varchar', false, false, 100),
                'sort_code' => self::column('VARCHAR(20) DEFAULT NULL', 'varchar', true, true, 20),
                'account_number' => self::column('VARCHAR(50) DEFAULT NULL', 'varchar', true, true, 50),
                'ledger_balance' => self::column('DECIMAL(10,2) DEFAULT 0', 'decimal', true, true, null, null, 10, 2),
                'ledger_balance_date' => self::column('DATE DEFAULT NULL', 'date', true, true),
                'closed' => self::column('TINYINT NOT NULL DEFAULT 0', 'tinyint', false, true),
                'closed_at' => self::column('DATE DEFAULT NULL', 'date', true, true),
            ], ['id']),
            'segments' => self::table([
                'id' => self::column('INT AUTO_INCREMENT', 'int', false, false, null, 'auto_increment'),
                'name' => self::column('VARCHAR(100) NOT NULL', 'varchar', false, false, 100),
                'description' => self::column('TEXT DEFAULT NULL', 'text', true, true),
                'created_at' => self::column('TIMESTAMP DEFAULT CURRENT_TIMESTAMP', 'timestamp', null, true),
                'updated_at' => self::column('TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'timestamp', null, true, null, 'on update CURRENT_TIMESTAMP'),
            ], ['id']),
            'categories' => self::table([
                'id' => self::column('INT AUTO_INCREMENT', 'int', false, false, null, 'auto_increment'),
                'name' => self::column('VARCHAR(100) NOT NULL', 'varchar', false, false, 100),
                'description' => self::column('TEXT DEFAULT NULL', 'text', true, true),
                'segment_id' => self::column('INT DEFAULT NULL', 'int', true, true),
                'created_at' => self::column('TIMESTAMP DEFAULT CURRENT_TIMESTAMP', 'timestamp', null, true),
                'updated_at' => self::column('TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'timestamp', null, true, null, 'on update CURRENT_TIMESTAMP'),
            ], ['id'], [], [
                'fk_categories_segment' => self::foreignKey(['segment_id'], 'segments', ['id']),
            ]),
            'budgets' => self::table([
                'id' => self::column('INT AUTO_INCREMENT', 'int', false, false, null, 'auto_increment'),
                'category_id' => self::column('INT NOT NULL', 'int', false, false),
                'month' => self::column('TINYINT NOT NULL', 'tinyint', false, false),
                'year' => self::column('INT NOT NULL', 'int', false, false),
                'amount' => self::column('DECIMAL(10,2) NOT NULL', 'decimal', false, false, null, null, 10, 2),
            ], ['id'], [
                'unique_budget' => self::index(['category_id', 'month', 'year'], true),
            ], [
                'fk_budgets_category' => self::foreignKey(['category_id'], 'categories', ['id']),
            ]),
            'projects' => self::table([
                'id' => self::column('INT AUTO_INCREMENT', 'int', false, false, null, 'auto_increment'),
                'name' => self::column('VARCHAR(255) NOT NULL', 'varchar', false, false, 255),
                'description' => self::column('TEXT DEFAULT NULL', 'text', true, true),
                'rationale' => self::column('TEXT DEFAULT NULL', 'text', true, true),
                'cost_low' => self::column('DECIMAL(10,2) DEFAULT NULL', 'decimal', true, true, null, null, 10, 2),
                'cost_medium' => self::column('DECIMAL(10,2) DEFAULT NULL', 'decimal', true, true, null, null, 10, 2),
                'cost_high' => self::column('DECIMAL(10,2) DEFAULT NULL', 'decimal', true, true, null, null, 10, 2),
                'funding_source' => self::column('VARCHAR(100) DEFAULT NULL', 'varchar', true, true, 100),
                'recurring_cost' => self::column('DECIMAL(10,2) DEFAULT NULL', 'decimal', true, true, null, null, 10, 2),
                'estimated_time' => self::column('INT DEFAULT NULL', 'int', true, true),
                'expected_lifespan' => self::column('INT DEFAULT NULL', 'int', true, true),
                'benefit_financial' => self::column('TINYINT DEFAULT 0', 'tinyint', true, true),
                'benefit_quality' => self::column('TINYINT DEFAULT 0', 'tinyint', true, true),
                'benefit_risk' => self::column('TINYINT DEFAULT 0', 'tinyint', true, true),
                'benefit_sustainability' => self::column('TINYINT DEFAULT 0', 'tinyint', true, true),
                'weight_financial' => self::column('TINYINT DEFAULT 1', 'tinyint', true, true),
                'weight_quality' => self::column('TINYINT DEFAULT 1', 'tinyint', true, true),
                'weight_risk' => self::column('TINYINT DEFAULT 1', 'tinyint', true, true),
                'weight_sustainability' => self::column('TINYINT DEFAULT 1', 'tinyint', true, true),
                'dependencies' => self::column('TEXT DEFAULT NULL', 'text', true, true),
                'risks' => self::column('TEXT DEFAULT NULL', 'text', true, true),
                'archived' => self::column('TINYINT DEFAULT 0', 'tinyint', true, true),
                'group_id' => self::column('INT DEFAULT NULL', 'int', true, true),
                'created_at' => self::column('TIMESTAMP DEFAULT CURRENT_TIMESTAMP', 'timestamp', null, true),
            ], ['id']),
            'segment_categories' => self::table([
                'segment_id' => self::column('INT NOT NULL', 'int', false, false),
                'category_id' => self::column('INT NOT NULL', 'int', false, false),
            ], ['segment_id', 'category_id'], [], [
                'fk_segment_categories_segment' => self::foreignKey(['segment_id'], 'segments', ['id']),
                'fk_segment_categories_category' => self::foreignKey(['category_id'], 'categories', ['id']),
            ]),
            'tags' => self::table([
                'id' => self::column('INT AUTO_INCREMENT', 'int', false, false, null, 'auto_increment'),
                'name' => self::column('VARCHAR(100) NOT NULL', 'varchar', false, false, 100),
                'name_normalized' => self::column('VARCHAR(100) DEFAULT NULL', 'varchar', true, true, 100),
                'keyword' => self::column('VARCHAR(100) DEFAULT NULL', 'varchar', true, true, 100),
                'description' => self::column('TEXT DEFAULT NULL', 'text', true, true),
            ], ['id'], [
                'unique_tag_name_normalized' => self::index(['name_normalized'], true),
            ]),
            'tag_aliases' => self::table([
                'id' => self::column('INT AUTO_INCREMENT', 'int', false, false, null, 'auto_increment'),
                'tag_id' => self::column('INT NOT NULL', 'int', false, false),
                'alias' => self::column('VARCHAR(150) NOT NULL', 'varchar', false, false, 150),
                'alias_normalized' => self::column('VARCHAR(150) NOT NULL', 'varchar', false, true, 150, null, null, null, null, "VARCHAR(150) NOT NULL DEFAULT ''"),
                'match_type' => self::column("ENUM('exact','contains') NOT NULL DEFAULT 'contains'", 'enum', false, true),
                'active' => self::column('TINYINT(1) DEFAULT 1', 'tinyint', true, true),
                'created_at' => self::column('TIMESTAMP DEFAULT CURRENT_TIMESTAMP', 'timestamp', null, true),
                'updated_at' => self::column('TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'timestamp', null, true, null, 'on update CURRENT_TIMESTAMP'),
            ], ['id'], [
                'unique_alias_normalized' => self::index(['alias_normalized'], true),
                'idx_tag_aliases_tag_id' => self::index(['tag_id']),
            ], [
                'fk_tag_aliases_tag' => self::foreignKey(['tag_id'], 'tags', ['id'], 'CASCADE'),
            ]),
            'category_tags' => self::table([
                'category_id' => self::column('INT NOT NULL', 'int', false, false),
                'tag_id' => self::column('INT NOT NULL', 'int', false, false),
            ], ['category_id', 'tag_id'], [], [
                'fk_category_tags_category' => self::foreignKey(['category_id'], 'categories', ['id']),
                'fk_category_tags_tag' => self::foreignKey(['tag_id'], 'tags', ['id']),
            ]),
            'transaction_groups' => self::table([
                'id' => self::column('INT AUTO_INCREMENT', 'int', false, false, null, 'auto_increment'),
                'name' => self::column('VARCHAR(100) NOT NULL', 'varchar', false, false, 100),
                'description' => self::column('TEXT DEFAULT NULL', 'text', true, true),
                'active' => self::column('TINYINT DEFAULT 1', 'tinyint', true, true),
            ], ['id']),
            'transactions' => self::table([
                'id' => self::column('INT AUTO_INCREMENT', 'int', false, false, null, 'auto_increment'),
                'account_id' => self::column('INT NOT NULL', 'int', false, false),
                'date' => self::column('DATE NOT NULL', 'date', false, false),
                'amount' => self::column('DECIMAL(10,2) NOT NULL', 'decimal', false, false, null, null, 10, 2),
                'description' => self::column('VARCHAR(255) NOT NULL', 'varchar', false, false, 255),
                'memo' => self::column('VARCHAR(255) DEFAULT NULL', 'varchar', true, true, 255),
                'category_id' => self::column('INT DEFAULT NULL', 'int', true, true),
                'segment_id' => self::column('INT DEFAULT NULL', 'int', true, true),
                'tag_id' => self::column('INT DEFAULT NULL', 'int', true, true),
                'group_id' => self::column('INT DEFAULT NULL', 'int', true, true),
                'transfer_id' => self::column('INT DEFAULT NULL', 'int', true, true),
                'ofx_id' => self::column('VARCHAR(255) DEFAULT NULL', 'varchar', true, true, 255),
                'ofx_type' => self::column('VARCHAR(50) DEFAULT NULL', 'varchar', true, true, 50),
                'bank_ofx_id' => self::column('VARCHAR(255) DEFAULT NULL', 'varchar', true, true, 255),
            ], ['id'], [
                'ofx_id' => self::index(['ofx_id'], true),
                'unique_bank_fitid' => self::index(['account_id', 'bank_ofx_id'], true),
                'idx_transaction_fallback' => self::index(['account_id', 'date', 'amount']),
                'idx_transactions_date' => self::index(['date']),
            ], [
                'fk_transactions_account' => self::foreignKey(['account_id'], 'accounts', ['id']),
                'fk_transactions_category' => self::foreignKey(['category_id'], 'categories', ['id']),
                'fk_transactions_segment' => self::foreignKey(['segment_id'], 'segments', ['id']),
                'fk_transactions_tag' => self::foreignKey(['tag_id'], 'tags', ['id']),
                'fk_transactions_group' => self::foreignKey(['group_id'], 'transaction_groups', ['id']),
            ]),
            'logs' => self::table([
                'id' => self::column('INT AUTO_INCREMENT', 'int', false, false, null, 'auto_increment'),
                'level' => self::column('VARCHAR(10) NOT NULL', 'varchar', false, false, 10),
                'message' => self::column('TEXT NOT NULL', 'text', false, false),
                'created_at' => self::column('TIMESTAMP DEFAULT CURRENT_TIMESTAMP', 'timestamp', null, true),
            ], ['id'], [
                'idx_logs_created_at' => self::index(['created_at']),
            ]),
        ];
    }

    public static function obsoleteIndexes(): array {
        return [
            'transactions' => [
                'unique_txn' => 'Legacy core-field uniqueness can reject genuine matching purchases.',
            ],
        ];
    }

    public static function createStatements(): array {
        $statements = [];
        foreach (self::tables() as $tableName => $table) {
            $statements[$tableName] = self::createTableSql($tableName, $table);
        }
        return $statements;
    }

    public static function createTableSql(string $tableName, array $table): string {
        $parts = [];
        foreach ($table['columns'] as $columnName => $column) {
            $parts[] = '    `' . $columnName . '` ' . $column['definition'];
        }
        if (!empty($table['primary'])) {
            $parts[] = '    PRIMARY KEY (' . self::columnList($table['primary']) . ')';
        }
        foreach ($table['indexes'] as $indexName => $index) {
            $parts[] = '    ' . ($index['unique'] ? 'UNIQUE ' : '') . 'KEY `' . $indexName . '` (' . self::columnList($index['columns']) . ')';
        }
        foreach ($table['foreign_keys'] as $constraintName => $foreignKey) {
            $sql = '    CONSTRAINT `' . $constraintName . '` FOREIGN KEY (' . self::columnList($foreignKey['columns']) . ')'
                . ' REFERENCES `' . $foreignKey['referenced_table'] . '` (' . self::columnList($foreignKey['referenced_columns']) . ')';
            if ($foreignKey['delete_rule'] !== 'RESTRICT') {
                $sql .= ' ON DELETE ' . $foreignKey['delete_rule'];
            }
            $parts[] = $sql;
        }
        return "CREATE TABLE IF NOT EXISTS `{$tableName}` (\n" . implode(",\n", $parts) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    public static function addColumnSql(string $tableName, string $columnName, array $column): string {
        return 'ALTER TABLE `' . $tableName . '` ADD COLUMN `' . $columnName . '` ' . ($column['add_definition'] ?: $column['definition']);
    }

    public static function addPrimarySql(string $tableName, array $columns): string {
        return 'ALTER TABLE `' . $tableName . '` ADD PRIMARY KEY (' . self::columnList($columns) . ')';
    }

    public static function addIndexSql(string $tableName, string $indexName, array $index): string {
        return 'ALTER TABLE `' . $tableName . '` ADD ' . ($index['unique'] ? 'UNIQUE ' : '') . 'INDEX `' . $indexName . '` (' . self::columnList($index['columns']) . ')';
    }

    public static function addForeignKeySql(string $tableName, string $constraintName, array $foreignKey): string {
        $sql = 'ALTER TABLE `' . $tableName . '` ADD CONSTRAINT `' . $constraintName . '` FOREIGN KEY ('
            . self::columnList($foreignKey['columns']) . ') REFERENCES `' . $foreignKey['referenced_table'] . '` ('
            . self::columnList($foreignKey['referenced_columns']) . ')';
        if ($foreignKey['delete_rule'] !== 'RESTRICT') {
            $sql .= ' ON DELETE ' . $foreignKey['delete_rule'];
        }
        return $sql;
    }

    private static function table(array $columns, array $primary, array $indexes = [], array $foreignKeys = []): array {
        return [
            'columns' => $columns,
            'primary' => $primary,
            'indexes' => $indexes,
            'foreign_keys' => $foreignKeys,
        ];
    }

    private static function column(
        string $definition,
        string $type,
        $nullable,
        bool $safeAdd,
        $length = null,
        $extra = null,
        $precision = null,
        $scale = null,
        $columnType = null,
        $addDefinition = null
    ): array {
        return [
            'definition' => $definition,
            'add_definition' => $addDefinition,
            'type' => $type,
            'nullable' => $nullable,
            'safe_add' => $safeAdd,
            'length' => $length,
            'extra' => $extra,
            'precision' => $precision,
            'scale' => $scale,
            'column_type' => $columnType,
        ];
    }

    private static function index(array $columns, bool $unique = false): array {
        return ['columns' => $columns, 'unique' => $unique];
    }

    private static function foreignKey(array $columns, string $referencedTable, array $referencedColumns, string $deleteRule = 'RESTRICT'): array {
        return [
            'columns' => $columns,
            'referenced_table' => $referencedTable,
            'referenced_columns' => $referencedColumns,
            'delete_rule' => strtoupper($deleteRule),
        ];
    }

    private static function columnList(array $columns): string {
        return implode(', ', array_map(function($column) {
            return '`' . $column . '`';
        }, $columns));
    }
}
