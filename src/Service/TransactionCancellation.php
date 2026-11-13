<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Datasource\ConnectionInterface;
use DomainException;
use RuntimeException;

final class TransactionCancellation
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function cancel(string $slug, int $adminId, string $reason): void
    {
        $this->remove($slug, $adminId, $reason, false);
    }

    public function deleteConfirmed(string $slug, int $adminId, string $reason): void
    {
        if ($adminId <= 0) {
            throw new DomainException('Administrator access is required.');
        }
        $this->remove($slug, $adminId, $reason, true);
    }

    private function remove(string $slug, int $adminId, string $reason, bool $confirmed): void
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new DomainException('Enter a removal reason of up to 500 characters.');
        }
        foreach (['transactions', 'transactionstudents', 'transactionteachers'] as $table) {
            $meta = $this->db->execute('SHOW TABLE STATUS WHERE Name = ?', [$table])->fetch('assoc');
            if (!$meta || strcasecmp((string)$meta['Engine'], 'InnoDB') !== 0) {
                throw new RuntimeException('Transaction cancellation requires InnoDB storage for ' . $table);
            }
        }
        $this->db->transactional(function () use ($slug, $adminId, $reason, $confirmed): void {
            $row = $this->db->execute('SELECT * FROM transactions WHERE slug = ? FOR UPDATE', [$slug])->fetch('assoc');
            if ($confirmed) {
                if (!$row || (int)$row['status'] !== 1) {
                    throw new DomainException('Only an existing confirmed transaction can be deleted using this action.');
                }
            } elseif (!$row || !in_array((int)$row['status'], [0, 2, 3, 4], true)
                || trim((string)$row['transaction_id_received']) !== '') {
                throw new DomainException('Only unpaid requests can be removed. Paid transactions and requests that no longer exist cannot be changed.');
            }
            $lineTables = ['transactionstudents', 'transactionteachers'];
            if ($row['price_structure'] === 'judge_registration') {
                $lineTables[] = 'transactionjudges';
            }
            foreach ($lineTables as $table) {
                if (!$confirmed && $this->db->execute("SELECT id FROM $table WHERE transaction_id = ? AND status = 1 FOR UPDATE", [$row['id']])->fetch('assoc')) {
                    throw new DomainException('This transaction includes confirmed payments and cannot be cancelled.');
                }
            }
            foreach ($lineTables as $table) {
                $this->db->execute("DELETE FROM $table WHERE transaction_id=?", [$row['id']]);
            }
            $this->db->execute('DELETE FROM transactions WHERE id=?', [$row['id']]);
            \Cake\Log\Log::info(($confirmed ? 'Confirmed' : 'Unpaid') . ' transaction #' . (int)$row['id'] . ' permanently removed by admin #' . $adminId . ': ' . $reason);
        });
    }

    public function confirm(int $id, string $reference, string $notes): bool
    {
        return $this->db->transactional(function () use ($id, $reference, $notes): bool {
            $row = $this->db->execute('SELECT status,price_structure FROM transactions WHERE id=? FOR UPDATE', [$id])->fetch('assoc');
            if (!$row || !in_array((int)$row['status'], [0, 2, 3], true)) {
                return false;
            }
            $this->db->execute('UPDATE transactions SET status=1,transaction_id_received=?,transaction_data=?,modified=NOW() WHERE id=?',
                [$reference, $notes, $id]);
            foreach (['transactionstudents', 'transactionteachers'] as $table) {
                $this->db->execute("UPDATE $table SET status=1,modified=NOW() WHERE transaction_id=?", [$id]);
            }
            if ($row['price_structure'] === 'judge_registration') {
                $this->db->execute('UPDATE transactionjudges SET status=1,modified=NOW() WHERE transaction_id=?', [$id]);
            }
            return true;
        });
    }
}
