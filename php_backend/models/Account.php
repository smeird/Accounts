<?php
// Model for account records stored in the database.
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/Tag.php';

class Account {
    /**
     * Create a new account with the provided name.
     */
    public static function create(string $name, ?string $sortCode = null, ?string $accountNumber = null): int {
        $db = Database::getConnection();
        $stmt = $db->prepare('INSERT INTO accounts (name, sort_code, account_number) VALUES (:name, :sort_code, :account_number)');
        $stmt->execute(['name' => $name, 'sort_code' => $sortCode, 'account_number' => $accountNumber]);
        return (int)$db->lastInsertId();
    }

    /**
     * Retrieve basic details for all accounts including transaction count and bank-reported balance.
     */
    public static function getSummaries(): array {
        $db = Database::getConnection();
        $ignore = Tag::getIgnoreId();
        $sql = 'SELECT a.`id`, a.`name`, a.`sort_code`, a.`account_number`, COUNT(t.`id`) AS `transactions`, '
             . 'COALESCE(a.`ledger_balance`, 0) AS `balance`, '
             . 'MAX(t.`date`) AS `last_transaction`, '
             . 'CASE WHEN a.`sort_code` IS NULL OR a.`sort_code` = "" THEN 1 ELSE 0 END AS `is_credit_card` '
             . 'FROM `accounts` a '
             . 'LEFT JOIN `transactions` t ON t.`account_id` = a.`id` '
             . 'AND (t.`tag_id` IS NULL OR t.`tag_id` != :ignore) '
             . 'GROUP BY a.`id`, a.`name`, a.`sort_code`, a.`account_number`, a.`ledger_balance` '
             . 'ORDER BY a.`name`';
        $stmt = $db->prepare($sql);
        $stmt->execute(['ignore' => $ignore]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update the stored ledger balance for an account.
     */
    public static function updateLedgerBalance(int $accountId, float $balance, string $date): void {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'UPDATE accounts SET ledger_balance = :bal, ledger_balance_date = :dt '
            . 'WHERE id = :id AND (ledger_balance_date IS NULL OR ledger_balance_date <= :incoming_date)'
        );
        $stmt->execute(['bal' => $balance, 'dt' => $date, 'id' => $accountId, 'incoming_date' => $date]);
    }

    /**
     * Build chronological balances around a bank-reported snapshot.
     *
     * @param array<int,array{date:string,amount:mixed,id?:mixed}> $transactions
     * @return array<int,array{date:string,balance:float}>
     */
    public static function buildBalanceHistory(float $ledgerBalance, ?string $ledgerDate, array $transactions): array {
        usort($transactions, static function (array $left, array $right): int {
            $dateOrder = strcmp((string)$left['date'], (string)$right['date']);
            return $dateOrder !== 0 ? $dateOrder : ((int)($left['id'] ?? 0) <=> (int)($right['id'] ?? 0));
        });

        if ($ledgerDate === null || $ledgerDate === '') {
            $balance = $ledgerBalance;
            $history = [];
            foreach ($transactions as $transaction) {
                $balance += (float)$transaction['amount'];
                $history[] = ['date' => (string)$transaction['date'], 'balance' => $balance];
            }
            return $history;
        }

        $includedTotal = 0.0;
        foreach ($transactions as $transaction) {
            if ((string)$transaction['date'] <= $ledgerDate) {
                $includedTotal += (float)$transaction['amount'];
            }
        }

        $balance = $ledgerBalance - $includedTotal;
        $history = [];
        foreach ($transactions as $transaction) {
            $date = (string)$transaction['date'];
            if ($date > $ledgerDate) {
                continue;
            }
            $balance += (float)$transaction['amount'];
            $history[] = ['date' => $date, 'balance' => $balance];
        }

        if (!$history || $history[count($history) - 1]['date'] < $ledgerDate) {
            $history[] = ['date' => $ledgerDate, 'balance' => $ledgerBalance];
        }

        $balance = $ledgerBalance;
        foreach ($transactions as $transaction) {
            $date = (string)$transaction['date'];
            if ($date <= $ledgerDate) {
                continue;
            }
            $balance += (float)$transaction['amount'];
            $history[] = ['date' => $date, 'balance' => $balance];
        }
        return $history;
    }

    /**
     * Rename an existing account.
     */
    public static function rename(int $accountId, string $name): void {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE accounts SET name = :name WHERE id = :id');
        $stmt->execute(['name' => $name, 'id' => $accountId]);
    }
}
?>
