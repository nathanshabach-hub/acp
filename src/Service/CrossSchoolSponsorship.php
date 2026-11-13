<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Datasource\ConnectionInterface;

final class CrossSchoolSponsorship
{
    public function __construct(private ConnectionInterface $connection)
    {
    }

    public function available(): bool
    {
        $table = $this->connection->execute("SHOW TABLE STATUS WHERE Name = 'cross_school_sponsorship_requests'")->fetch('assoc');
        if ($table && strcasecmp((string)$table['Engine'], 'InnoDB') !== 0) {
            throw new \RuntimeException('Cross-school requests require an InnoDB table.');
        }
        return (bool)$table;
    }

    /** @param list<int> $studentRegistrationIds */
    public function hasPending(array $studentRegistrationIds): bool
    {
        $ids = array_values(array_filter(array_map('intval', $studentRegistrationIds), fn($id) => $id > 0));
        if (!$ids || !$this->available()) {
            return false;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return (bool)$this->connection->execute(
            "SELECT id FROM cross_school_sponsorship_requests WHERE status = 'pending_school' AND student_registration_id IN ($placeholders) LIMIT 1",
            $ids
        )->fetch('num');
    }
}
