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
             . 'CASE WHEN a.`closed` = 1 THEN 0 ELSE COALESCE(a.`ledger_balance`, 0) END AS `balance`, '
             . 'a.`closed`, a.`closed_at`, '
             . 'MAX(t.`date`) AS `last_transaction`, '
             . "CASE WHEN a.`sort_code` IS NULL OR a.`sort_code` = '' THEN 1 ELSE 0 END AS `is_credit_card` "
             . 'FROM `accounts` a '
             . 'LEFT JOIN `transactions` t ON t.`account_id` = a.`id` '
             . 'AND (t.`tag_id` IS NULL OR t.`tag_id` != :ignore) '
             . 'GROUP BY a.`id`, a.`name`, a.`sort_code`, a.`account_number`, a.`ledger_balance`, a.`closed`, a.`closed_at` '
             . 'ORDER BY a.`closed` ASC, a.`name`';
        $stmt = $db->prepare($sql);
        $stmt->execute(['ignore' => $ignore]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update the stored ledger balance for an account.
     */
    public static function updateLedgerBalance(
        int $accountId,
        float $balance,
        string $date,
        ?int $statementTransactionCount = null
    ): string {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT ledger_balance, ledger_balance_date, closed FROM accounts WHERE id = :id');
        $stmt->execute(['id' => $accountId]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$current) {
            throw new RuntimeException('Account not found while updating its ledger balance');
        }
        if ((int)$current['closed'] === 1) {
            return 'closed';
        }

        $incomingIsZero = abs($balance) < 0.005;
        if ($statementTransactionCount === 0 && $incomingIsZero) {
            return 'protected';
        }

        $currentDate = $current['ledger_balance_date'] ?: null;
        if ($currentDate === null || $date >= $currentDate) {
            $update = $db->prepare('UPDATE accounts SET ledger_balance = :bal, ledger_balance_date = :dt WHERE id = :id');
            $update->execute(['bal' => $balance, 'dt' => $date, 'id' => $accountId]);
            return 'updated';
        }

        // A bank may emit a dated zero placeholder for an inactive account.
        // Recover from an existing placeholder only when the older non-zero
        // snapshot has no recorded movements between its date and the newer
        // zero date; in that case the two snapshots cannot both be genuine.
        if (abs((float)$current['ledger_balance']) < 0.005 && !$incomingIsZero) {
            $movement = $db->prepare(
                'SELECT COUNT(*) FROM transactions '
                . 'WHERE account_id = :account AND date > :incoming_date AND date <= :current_date'
            );
            $movement->execute([
                'account' => $accountId,
                'incoming_date' => $date,
                'current_date' => $currentDate,
            ]);
            if ((int)$movement->fetchColumn() === 0) {
                $update = $db->prepare('UPDATE accounts SET ledger_balance = :bal WHERE id = :id');
                $update->execute(['bal' => $balance, 'id' => $accountId]);
                return 'recovered';
            }
        }

        return 'stale';
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

    /**
     * Close or reopen an account. Closing records a zero balance from the
     * supplied date; reopening never invents or restores a previous balance.
     */
    public static function setClosed(int $accountId, bool $closed, ?string $date = null): array {
        $db = Database::getConnection();
        $date = $date ?: date('Y-m-d');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Invalid account closure date');
        }

        $db->beginTransaction();
        try {
            $check = $db->prepare('SELECT id FROM accounts WHERE id = :id');
            $check->execute(['id' => $accountId]);
            if (!$check->fetchColumn()) {
                throw new InvalidArgumentException('Account not found');
            }

            if ($closed) {
                $update = $db->prepare(
                    'UPDATE accounts SET closed = 1, closed_at = :closed_at, '
                    . 'ledger_balance = 0, ledger_balance_date = :balance_date WHERE id = :id'
                );
                $update->execute(['closed_at' => $date, 'balance_date' => $date, 'id' => $accountId]);
            } else {
                $update = $db->prepare('UPDATE accounts SET closed = 0, closed_at = NULL WHERE id = :id');
                $update->execute(['id' => $accountId]);
            }
            $db->commit();
            return ['account_id' => $accountId, 'closed' => $closed, 'closed_at' => $closed ? $date : null, 'balance' => 0.0];
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
?>
