<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Datasource\ConnectionInterface;
use DomainException;

final class JudgeRegistrationBilling
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public static function requiresFee(\ArrayAccess|array $account): bool
    {
        return $account['user_type'] === 'Judge' && (int)$account['school_id'] === 0;
    }

    public static function amount(mixed $value): string
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', (string)$value)) {
            throw new DomainException('Enter a non-negative Judges Registration fee with no more than two decimal places.');
        }
        return number_format((float)$value, 2, '.', '');
    }

    public function context(int $judgeId, string $conventionSlug, int $seasonId): array
    {
        $judge = $this->db->execute(
            "SELECT id,user_type,school_id FROM users WHERE id=? AND status=1 AND activation_status=1", [$judgeId]
        )->fetch('assoc');
        if (!$judge || !self::requiresFee($judge)) {
            throw new DomainException('Only a standalone verified judge can select a judge registration fee.');
        }
        $context = $this->db->execute(
            'SELECT cs.*,c.name AS convention_name,c.slug AS convention_slug FROM conventionseasons cs
             JOIN conventions c ON c.id=cs.convention_id AND c.status=1
             JOIN seasons s ON s.id=cs.season_id AND s.season_year=cs.season_year
             WHERE c.slug=? AND cs.season_id=?', [$conventionSlug, $seasonId]
        )->fetch('assoc');
        if (!$context) {
            throw new DomainException('The convention season could not be found.');
        }
        $this->requireStorage();
        return $context;
    }

    private function requireStorage(): void
    {
        foreach (['conventionregistrations', 'transactions', 'transactionjudges'] as $table) {
            $meta = $this->db->execute('SHOW TABLE STATUS WHERE Name=?', [$table])->fetch('assoc');
            if (!$meta || strcasecmp((string)$meta['Engine'], 'InnoDB') !== 0) {
                throw new DomainException('Apply migration 015 before using judge registration billing.');
            }
        }
    }

    public function registration(int $judgeId, int $seasonContextId): ?array
    {
        return $this->db->execute(
            'SELECT * FROM conventionregistrations WHERE user_id=? AND conventionseason_id=? ORDER BY id LIMIT 1',
            [$judgeId, $seasonContextId]
        )->fetch('assoc') ?: null;
    }

    public function selectPrice(int $judgeId, string $slug, int $seasonId, mixed $expectedFee): array
    {
        return $this->db->transactional(function () use ($judgeId, $slug, $seasonId, $expectedFee): array {
            $context = $this->context($judgeId, $slug, $seasonId);
            // Serializes the first registration insert as well as duplicate price submissions.
            $context = $this->db->execute('SELECT * FROM conventionseasons WHERE id=? FOR UPDATE', [$context['id']])->fetch('assoc');
            if ($context['judge_registration_fees'] === null) {
                throw new DomainException('The Judges Registration fee has not been configured. Please contact the events team.');
            }
            $fee = self::amount($context['judge_registration_fees']);
            if (self::amount($expectedFee) !== $fee) {
                throw new DomainException('The Judges Registration fee has changed. Review the current price before saving.');
            }
            $registration = $this->registration($judgeId, (int)$context['id']);
            if ($registration) {
                $registration = $this->db->execute('SELECT * FROM conventionregistrations WHERE id=? FOR UPDATE', [$registration['id']])->fetch('assoc');
            }
            if ($registration && $this->transaction((int)$registration['id'])) {
                return $registration;
            }
            if (!$registration) {
                $this->db->execute(
                    'INSERT INTO conventionregistrations
                     (slug,conventionseason_id,convention_id,user_id,season_id,season_year,status,created,judge_registration_fee)
                     VALUES (?,?,?,?,?,?,2,NOW(),?)',
                    ['judge-registration-' . bin2hex(random_bytes(16)), $context['id'], $context['convention_id'], $judgeId,
                        $context['season_id'], $context['season_year'], $fee]
                );
            } else {
                $this->db->execute('UPDATE conventionregistrations SET judge_registration_fee=?,modified=NOW() WHERE id=?',
                    [$fee, $registration['id']]);
            }
            return $this->registration($judgeId, (int)$context['id'])
                ?? throw new \RuntimeException('Judge price selection could not be saved.');
        });
    }

    public function transaction(int $registrationId): ?array
    {
        return $this->db->execute(
            'SELECT t.* FROM transactions t JOIN transactionjudges j ON j.transaction_id=t.id
             WHERE j.conventionregistration_id=? AND t.status IN (1,2,3) ORDER BY t.id DESC LIMIT 1',
            [$registrationId]
        )->fetch('assoc') ?: null;
    }

    public function submit(int $judgeId, string $slug, int $seasonId, array $eventIds): array
    {
        return $this->db->transactional(function () use ($judgeId, $slug, $seasonId, $eventIds): array {
            $context = $this->context($judgeId, $slug, $seasonId);
            $registration = $this->registration($judgeId, (int)$context['id']);
            if (!$registration) {
                throw new DomainException('Select the Judges Registration price before choosing events.');
            }
            $registration = $this->db->execute('SELECT * FROM conventionregistrations WHERE id=? FOR UPDATE', [$registration['id']])->fetch('assoc');
            if ($registration['judge_registration_fee'] === null) {
                throw new DomainException('Select the Judges Registration price before choosing events.');
            }
            $ids = [];
            foreach ($eventIds as $id) {
                if ((!is_int($id) && !is_string($id)) || !ctype_digit((string)$id) || (int)$id <= 0) {
                    throw new DomainException('Select valid judge events.');
                }
                $ids[] = (int)$id;
            }
            $ids = array_values(array_unique($ids));
            if (!$ids) {
                throw new DomainException('Select at least one event you wish to judge.');
            }
            $allowed = array_map('intval', array_column($this->db->execute(
                'SELECT event_id FROM conventionseasonevents WHERE conventionseasons_id=?', [$context['id']]
            )->fetchAll('assoc'), 'event_id'));
            if (array_diff($ids, $allowed)) {
                throw new DomainException('One or more selected events are not available for this convention.');
            }
            $this->db->execute('UPDATE conventionregistrations SET judges_event_ids=?,modified=NOW() WHERE id=?',
                [implode(',', $ids), $registration['id']]);
            $existing = $this->transaction((int)$registration['id']);
            if ($existing) {
                return $existing;
            }
            $fee = self::amount($registration['judge_registration_fee']);
            $transactionSlug = 'judge-invoice-' . bin2hex(random_bytes(16));
            $this->db->execute(
                "INSERT INTO transactions
                 (slug,conventionregistration_id,conventionseason_id,convention_id,user_id,season_id,season_year,
                  price_structure,payable_amount,tax_percent,tax_amount,total_amount,total_discount_applied,final_amount_paid,status,created)
                 VALUES (?,?,?,?,?,?,?,'judge_registration',?,0,0,?,0,?,3,NOW())",
                [$transactionSlug, $registration['id'], $context['id'], $context['convention_id'], $judgeId,
                    $context['season_id'], $context['season_year'], $fee, $fee, $fee]
            );
            $transactionId = (int)$this->db->execute('SELECT LAST_INSERT_ID()')->fetchColumn(0);
            $this->db->execute(
                'INSERT INTO transactionjudges (transaction_id,conventionregistration_id,judge_id,paid_amount,status,created)
                 VALUES (?,?,?,?,3,NOW())', [$transactionId, $registration['id'], $judgeId, $fee]
            );
            return $this->transaction((int)$registration['id'])
                ?? throw new \RuntimeException('The judge invoice request could not be saved.');
        });
    }
}
