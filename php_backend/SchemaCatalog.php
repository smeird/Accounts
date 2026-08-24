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
                'origin' => self::column("ENUM('system','manual','ai','legacy') NOT NULL DEFAULT 'legacy'", 'enum', false, true),
                'status' => self::column("ENUM('proposed','active','deprecated','merged') NOT NULL DEFAULT 'active'", 'enum', false, true),
                'merged_into_tag_id' => self::column('INT DEFAULT NULL', 'int', true, true),
                'created_at' => self::column('TIMESTAMP DEFAULT CURRENT_TIMESTAMP', 'timestamp', null, true),
                'updated_at' => self::column('TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'timestamp', null, true, null, 'on update CURRENT_TIMESTAMP'),
            ], ['id'], [
                'unique_tag_name_normalized' => self::index(['name_normalized'], true),
                'idx_tags_status' => self::index(['status']),
                'idx_tags_merged_into' => self::index(['merged_into_tag_id']),
            ]),
            'tag_aliases' => self::table([
                'id' => self::column('INT AUTO_INCREMENT', 'int', false, false, null, 'auto_increment'),
                'tag_id' => self::column('INT NOT NULL', 'int', false, false),
                'alias' => self::column('VARCHAR(150) NOT NULL', 'varchar', false, false, 150),
                'alias_normalized' => self::column('VARCHAR(150) NOT NULL', 'varchar', false, true, 150, null, null, null, null, "VARCHAR(150) NOT NULL DEFAULT ''"),
                'match_type' => self::column("ENUM('exact','contains') NOT NULL DEFAULT 'contains'", 'enum', false, true),
                'direction' => self::column("ENUM('any','outgoing','incoming') NOT NULL DEFAULT 'any'", 'enum', false, true),
                'active' => self::column('TINYINT(1) DEFAULT 1', 'tinyint', true, true),
                'origin' => self::column("ENUM('system','manual','ai','legacy') NOT NULL DEFAULT 'legacy'", 'enum', false, true),
                'confidence' => self::column('DECIMAL(5,4) DEFAULT NULL', 'decimal', true, true, null, null, 5, 4),
                'support_count' => self::column('INT NOT NULL DEFAULT 0', 'int', false, true),
                'last_matched_at' => self::column('TIMESTAMP NULL DEFAULT NULL', 'timestamp', true, true),
                'created_at' => self::column('TIMESTAMP DEFAULT CURRENT_TIMESTAMP', 'timestamp', null, true),
                'updated_at' => self::column('TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'timestamp', null, true, null, 'on update CURRENT_TIMESTAMP'),
            ], ['id'], [
                'unique_alias_direction' => self::index(['alias_normalized', 'direction'], true),
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
                'idx_transactions_account_date_id' => self::index(['account_id', 'date', 'id']),
                'idx_transactions_date' => self::index(['date']),
            ], [
                'fk_transactions_account' => self::foreignKey(['account_id'], 'accounts', ['id']),
                'fk_transactions_category' => self::foreignKey(['category_id'], 'categories', ['id']),
                'fk_transactions_segment' => self::foreignKey(['segment_id'], 'segments', ['id']),
                'fk_transactions_tag' => self::foreignKey(['tag_id'], 'tags', ['id']),
                'fk_transactions_group' => self::foreignKey(['group_id'], 'transaction_groups', ['id']),
            ]),
            'tag_migration_runs' => self::table([
                'id' => self::column('BIGINT AUTO_INCREMENT', 'bigint', false, false, null, 'auto_increment'),
                'name' => self::column('VARCHAR(150) NOT NULL', 'varchar', false, false, 150),
                'status' => self::column("ENUM('snapshot','staging','ready','applied','rolled_back','cancelled') NOT NULL DEFAULT 'snapshot'", 'enum', false, true),
                'contract_version' => self::column("VARCHAR(20) NOT NULL DEFAULT 'v1'", 'varchar', false, true, 20),
                'created_by' => self::column('VARCHAR(100) DEFAULT NULL', 'varchar', true, true, 100),
                'transaction_count' => self::column('INT NOT NULL DEFAULT 0', 'int', false, true),
                'eligible_count' => self::column('INT NOT NULL DEFAULT 0', 'int', false, true),
                'protected_transfer_count' => self::column('INT NOT NULL DEFAULT 0', 'int', false, true),
                'protected_ignore_count' => self::column('INT NOT NULL DEFAULT 0', 'int', false, true),
                'snapshot_hash' => self::column('CHAR(64) NOT NULL', 'char', false, true, 64, null, null, null, null, "CHAR(64) NOT NULL DEFAULT ''"),
                'created_at' => self::column('TIMESTAMP DEFAULT CURRENT_TIMESTAMP', 'timestamp', null, true),
                'discovery_started_at' => self::column('TIMESTAMP NULL DEFAULT NULL', 'timestamp', true, true),
                'ready_at' => self::column('TIMESTAMP NULL DEFAULT NULL', 'timestamp', true, true),
                'applied_at' => self::column('TIMESTAMP NULL DEFAULT NULL', 'timestamp', true, true),
                'rolled_back_at' => self::column('TIMESTAMP NULL DEFAULT NULL', 'timestamp', true, true),
                'cutover_summary' => self::column('LONGTEXT DEFAULT NULL', 'longtext', true, true),
            ], ['id'], [
                'idx_tag_migration_runs_created' => self::index(['created_at']),
                'idx_tag_migration_runs_status' => self::index(['status']),
            ]),
            'transaction_classification_snapshots' => self::table([
                'run_id' => self::column('BIGINT NOT NULL', 'bigint', false, false),
                'transaction_id' => self::column('INT NOT NULL', 'int', false, false),
                'tag_id' => self::column('INT DEFAULT NULL', 'int', true, true),
                'category_id' => self::column('INT DEFAULT NULL', 'int', true, true),
                'segment_id' => self::column('INT DEFAULT NULL', 'int', true, true),
                'eligible' => self::column('TINYINT(1) NOT NULL DEFAULT 1', 'tinyint', false, true),
                'protection_reason' => self::column("ENUM('transfer','ignored') DEFAULT NULL", 'enum', true, true),
                'created_at' => self::column('TIMESTAMP DEFAULT CURRENT_TIMESTAMP', 'timestamp', null, true),
            ], ['run_id', 'transaction_id'], [
                'idx_classification_snapshot_transaction' => self::index(['transaction_id']),
                'idx_classification_snapshot_eligibility' => self::index(['run_id', 'eligible']),
            ], [
                'fk_classification_snapshot_run' => self::foreignKey(['run_id'], 'tag_migration_runs', ['id'], 'CASCADE'),
            ]),
            'tag_taxonomy_proposals' => self::table([
                'id' => self::column('BIGINT AUTO_INCREMENT', 'bigint', false, false, null, 'auto_increment'),
                'run_id' => self::column('BIGINT NOT NULL', 'bigint', false, false),
                'canonical_name' => self::column('VARCHAR(100) NOT NULL', 'varchar', false, false, 100),
                'canonical_name_normalized' => self::column('VARCHAR(100) NOT NULL', 'varchar', false, false, 100),
                'description' => self::column('TEXT DEFAULT NULL', 'text', true, true),
                'category_id' => self::column('INT DEFAULT NULL', 'int', true, true),
                'confidence' => self::column('DECIMAL(5,4) DEFAULT NULL', 'decimal', true, true, null, null, 5, 4),
                'rationale' => self::column('VARCHAR(500) DEFAULT NULL', 'varchar', true, true, 500),
                'status' => self::column("ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'", 'enum', false, true),
                'origin' => self::column("ENUM('ai','manual') NOT NULL DEFAULT 'ai'", 'enum', false, true),
                'pattern_count' => self::column('INT NOT NULL DEFAULT 0', 'int', false, true),
                'transaction_count' => self::column('INT NOT NULL DEFAULT 0', 'int', false, true),
                'absolute_amount' => self::column('DECIMAL(14,2) NOT NULL DEFAULT 0', 'decimal', false, true, null, null, 14, 2),
                'reviewed_by' => self::column('VARCHAR(100) DEFAULT NULL', 'varchar', true, true, 100),
                'reviewed_at' => self::column('TIMESTAMP NULL DEFAULT NULL', 'timestamp', true, true),
                'created_at' => self::column('TIMESTAMP DEFAULT CURRENT_TIMESTAMP', 'timestamp', null, true),
                'updated_at' => self::column('TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'timestamp', null, true, null, 'on update CURRENT_TIMESTAMP'),
            ], ['id'], [
                'unique_taxonomy_proposal_name' => self::index(['run_id', 'canonical_name_normalized'], true),
                'idx_taxonomy_proposal_status' => self::index(['run_id', 'status']),
            ], [
                'fk_taxonomy_proposal_run' => self::foreignKey(['run_id'], 'tag_migration_runs', ['id'], 'CASCADE'),
                'fk_taxonomy_proposal_category' => self::foreignKey(['category_id'], 'categories', ['id'], 'SET NULL'),
            ]),
            'tag_taxonomy_patterns' => self::table([
                'id' => self::column('BIGINT AUTO_INCREMENT', 'bigint', false, false, null, 'auto_increment'),
                'run_id' => self::column('BIGINT NOT NULL', 'bigint', false, false),
                'proposal_id' => self::column('BIGINT DEFAULT NULL', 'bigint', true, true),
                'signature' => self::column('CHAR(64) NOT NULL', 'char', false, false, 64),
                'alias' => self::column('VARCHAR(150) NOT NULL', 'varchar', false, false, 150),
                'alias_normalized' => self::column('VARCHAR(150) NOT NULL', 'varchar', false, false, 150),
                'direction' => self::column("ENUM('outgoing','incoming') NOT NULL", 'enum', false, false),
                'sample_description' => self::column('VARCHAR(255) DEFAULT NULL', 'varchar', true, true, 255),
                'sample_memo' => self::column('VARCHAR(255) DEFAULT NULL', 'varchar', true, true, 255),
                'current_tags' => self::column('TEXT DEFAULT NULL', 'text', true, true),
                'transaction_count' => self::column('INT NOT NULL DEFAULT 0', 'int', false, true),
                'absolute_amount' => self::column('DECIMAL(14,2) NOT NULL DEFAULT 0', 'decimal', false, true, null, null, 14, 2),
                'first_seen' => self::column('DATE DEFAULT NULL', 'date', true, true),
                'last_seen' => self::column('DATE DEFAULT NULL', 'date', true, true),
                'confidence' => self::column('DECIMAL(5,4) DEFAULT NULL', 'decimal', true, true, null, null, 5, 4),
                'rationale' => self::column('VARCHAR(500) DEFAULT NULL', 'varchar', true, true, 500),
                'status' => self::column("ENUM('pending','proposed','excluded') NOT NULL DEFAULT 'pending'", 'enum', false, true),
                'created_at' => self::column('TIMESTAMP DEFAULT CURRENT_TIMESTAMP', 'timestamp', null, true),
                'updated_at' => self::column('TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'timestamp', null, true, null, 'on update CURRENT_TIMESTAMP'),
            ], ['id'], [
                'unique_taxonomy_pattern_signature' => self::index(['run_id', 'signature'], true),
                'idx_taxonomy_pattern_status' => self::index(['run_id', 'status']),
                'idx_taxonomy_pattern_proposal' => self::index(['proposal_id']),
            ], [
                'fk_taxonomy_pattern_run' => self::foreignKey(['run_id'], 'tag_migration_runs', ['id'], 'CASCADE'),
                'fk_taxonomy_pattern_proposal' => self::foreignKey(['proposal_id'], 'tag_taxonomy_proposals', ['id'], 'SET NULL'),
            ]),
            'transaction_tag_proposals' => self::table([
                'run_id' => self::column('BIGINT NOT NULL', 'bigint', false, false),
                'transaction_id' => self::column('INT NOT NULL', 'int', false, false),
                'pattern_id' => self::column('BIGINT NOT NULL', 'bigint', false, false),
                'proposal_id' => self::column('BIGINT DEFAULT NULL', 'bigint', true, true),
                'current_tag_id' => self::column('INT DEFAULT NULL', 'int', true, true),
                'confidence' => self::column('DECIMAL(5,4) DEFAULT NULL', 'decimal', true, true, null, null, 5, 4),
                'created_at' => self::column('TIMESTAMP DEFAULT CURRENT_TIMESTAMP', 'timestamp', null, true),
            ], ['run_id', 'transaction_id'], [
                'idx_transaction_tag_proposal_pattern' => self::index(['pattern_id']),
                'idx_transaction_tag_proposal_destination' => self::index(['run_id', 'proposal_id']),
            ], [
                'fk_transaction_tag_proposal_run' => self::foreignKey(['run_id'], 'tag_migration_runs', ['id'], 'CASCADE'),
                'fk_transaction_tag_proposal_pattern' => self::foreignKey(['pattern_id'], 'tag_taxonomy_patterns', ['id'], 'CASCADE'),
                'fk_transaction_tag_proposal_destination' => self::foreignKey(['proposal_id'], 'tag_taxonomy_proposals', ['id'], 'SET NULL'),
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
            'tag_aliases' => [
                'unique_alias_normalized' => 'Direction-aware aliases replace the legacy text-only uniqueness rule.',
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
