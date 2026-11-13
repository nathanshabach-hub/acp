<?php
declare(strict_types=1);

namespace App\Service;

use App\Utility\LegacyEmail;
use Cake\Datasource\ConnectionInterface;
use Cake\Http\Exception\NotFoundException;
use Cake\Mailer\Transport\DebugTransport;
use DomainException;
use RuntimeException;

final class TransactionInvoices
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function available(): bool
    {
        $table = $this->db->execute("SHOW TABLE STATUS WHERE Name='transaction_invoices'")->fetch('assoc');
        if ($table && strcasecmp((string)$table['Engine'], 'InnoDB') !== 0) {
            throw new RuntimeException('Invoices require InnoDB storage.');
        }
        return (bool)$table;
    }

    public function transaction(string $slug): array
    {
        $row = $this->db->execute(
            "SELECT t.*, CASE WHEN u.user_type='School' THEN u.first_name
                    ELSE TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) END AS school_name, u.email_address AS school_email,
                    u.customer_code, c.name AS convention_name
             FROM transactions t JOIN users u ON u.id=t.user_id
             JOIN conventions c ON c.id=t.convention_id WHERE t.slug=?", [$slug]
        )->fetch('assoc');
        if (!$row) {
            throw new NotFoundException('Transaction not found.');
        }
        if (!in_array((int)$row['status'], [1, 2, 3], true)) {
            throw new DomainException('Invoices are available only for pending, invoiced or confirmed transactions.');
        }
        return $row;
    }

    public function find(int $transactionId): ?array
    {
        if (!$this->available()) {
            return null;
        }
        $row = $this->db->execute('SELECT * FROM transaction_invoices WHERE transaction_id=?', [$transactionId])->fetch('assoc');
        if (!$row) {
            return null;
        }
        $row['number'] = 'ACP-' . substr($row['issued'], 0, 4) . '-' . str_pad((string)$row['id'], 6, '0', STR_PAD_LEFT);
        $row['data'] = json_decode($row['snapshot'], true, 512, JSON_THROW_ON_ERROR);
        return $row;
    }

    public function forSchoolNotification(int $schoolId, array $message): ?array
    {
        if ((int)$message['school_id'] !== $schoolId || $message['kind'] !== 'email'
            || !preg_match('/^Convention invoice ACP-(\d{4})-(\d{6,})$/D', $message['subject'], $match)
            || !$this->available()) {
            return null;
        }
        $row = $this->db->execute(
            'SELECT t.id, t.status FROM transaction_invoices i
             JOIN transactions t ON t.id=i.transaction_id
             WHERE i.id=? AND t.user_id=? AND i.emailed_at IS NOT NULL AND t.status IN (1,2,3)',
            [(int)$match[2], $schoolId]
        )->fetch('assoc');
        if (!$row) {
            return null;
        }
        $invoice = $this->find((int)$row['id']);
        if (!$invoice || $invoice['number'] !== 'ACP-' . $match[1] . '-' . $match[2]) {
            return null;
        }
        $invoice['paid'] = (int)$row['status'] === 1;
        return $invoice;
    }

    public function generate(string $slug, int $adminId, string $bankDetails): array
    {
        $bankDetails = trim($bankDetails);
        if ($adminId <= 0) {
            throw new DomainException('Administrator access is required.');
        }
        if ($bankDetails === '' || mb_strlen($bankDetails) > 700) {
            throw new DomainException('Enter bank details of up to 700 characters.');
        }
        if (!$this->available()) {
            throw new RuntimeException('Apply invoice migration 013 first.');
        }
        return $this->db->transactional(function () use ($slug, $adminId, $bankDetails): array {
            $this->db->execute('SELECT id FROM transactions WHERE slug=? FOR UPDATE', [$slug])->fetch('assoc');
            $transaction = $this->transaction($slug);
            $existing = $this->find((int)$transaction['id']);
            if ($existing) {
                return $existing;
            }
            $data = $this->charges($transaction);
            $data += [
                'school' => (string)$transaction['school_name'],
                'email' => (string)$transaction['school_email'],
                'customer_code' => (string)$transaction['customer_code'],
                'convention' => (string)$transaction['convention_name'] . ' (' . $transaction['season_year'] . ')',
                'bank' => $bankDetails,
            ];
            if (mb_strlen($data['school']) > 150 || mb_strlen($data['email']) > 254 || mb_strlen($data['customer_code']) > 100) {
                throw new DomainException('Recipient billing details are too long for the official invoice. Correct the account profile first.');
            }
            $issued = new \DateTimeImmutable('today');
            $this->db->execute(
                'INSERT INTO transaction_invoices (transaction_id,snapshot,issued,due,created_by) VALUES (?,?,?,?,?)',
                [$transaction['id'], json_encode($data, JSON_THROW_ON_ERROR), $issued->format('Y-m-d'),
                    $issued->modify('+14 days')->format('Y-m-d'), $adminId]
            );
            $invoice = $this->find((int)$transaction['id']);
            if (!$invoice) {
                throw new RuntimeException('Invoice could not be saved.');
            }
            // Validate that all fields fit before committing a permanent invoice number.
            $this->pdf($invoice, (int)$transaction['status'] === 1);
            return $invoice;
        });
    }

    private function cents(mixed $value): int
    {
        if (!is_numeric($value) || (float)$value < 0 || !is_finite((float)$value)) {
            throw new DomainException('Invalid transaction charge. Review the saved billing amounts first.');
        }
        return (int)round((float)$value * 100);
    }

    public function charges(array $transaction): array
    {
        $students = $this->db->execute('SELECT * FROM transactionstudents WHERE transaction_id=? ORDER BY id', [$transaction['id']])->fetchAll('assoc');
        $supervisors = $this->db->execute('SELECT * FROM transactionteachers WHERE transaction_id=? ORDER BY id', [$transaction['id']])->fetchAll('assoc');
        $judges = $transaction['price_structure'] === 'judge_registration'
            ? $this->db->execute('SELECT * FROM transactionjudges WHERE transaction_id=? ORDER BY id', [$transaction['id']])->fetchAll('assoc') : [];
        if (!$students && !$supervisors && !$judges) {
            throw new DomainException('This transaction has no billing line items. An invoice cannot be generated.');
        }
        if ($judges && ($students || $supervisors || count($judges) !== 1)) {
            throw new DomainException('A judge invoice must contain exactly one judge registration charge and no school charges.');
        }
        $studentGross = $supervisorGross = $discount = $discountQty = 0;
        $studentRates = $supervisorRates = [];
        foreach ($students as $student) {
            $gross = $this->cents($student['paid_amount']);
            $net = $this->cents($student['final_paid_amount']);
            $savedDiscount = $this->cents($student['amount_discount_applied']);
            if ($net > $gross || $gross - $net !== $savedDiscount) {
                throw new DomainException('Student billing amounts do not reconcile. Correct the transaction before issuing an invoice.');
            }
            $studentGross += $gross;
            $discount += $savedDiscount;
            $discountQty += $savedDiscount > 0 ? 1 : 0;
            $studentRates[] = $gross;
        }
        foreach ($supervisors as $supervisor) {
            $amount = $this->cents($supervisor['paid_amount']);
            $supervisorGross += $amount;
            $supervisorRates[] = $amount;
        }
        $judgeGross = 0;
        $judgeRates = [];
        foreach ($judges as $judge) {
            $amount = $this->cents($judge['paid_amount']);
            $judgeGross += $amount;
            $judgeRates[] = $amount;
        }
        $total = $studentGross + $supervisorGross + $judgeGross - $discount;
        if ($total !== $this->cents($transaction['final_amount_paid'])) {
            throw new DomainException('Billing line items do not match the saved transaction amount. Review the transaction before generating an invoice.');
        }
        return [
            'student_qty' => count($students), 'student_rates' => array_values(array_unique($studentRates)), 'student_gross' => $studentGross,
            'supervisor_qty' => count($supervisors), 'supervisor_rates' => array_values(array_unique($supervisorRates)), 'supervisor_gross' => $supervisorGross,
            'discount_qty' => $discountQty, 'discount' => $discount, 'total' => $total,
            'judge_qty' => count($judges), 'judge_rates' => array_values(array_unique($judgeRates)), 'judge_gross' => $judgeGross,
        ];
    }

    public function pdf(array $invoice, bool $paid): string
    {
        $background = WWW_ROOT . 'img/invoice-template-background.png';
        if (!is_file($background)) {
            throw new RuntimeException('The official invoice background is missing.');
        }
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->SetTitle('Student Convention Invoice ' . $invoice['number']);
        $pdf->SetAuthor('Accelerate Educational Ministries');
        $pdf->AddPage();
        $pdf->Image($background, 0, 0, 210, 297, 'PNG');
        $pdf->SetTextColor(25, 30, 45);
        $write = static function (float $x, float $y, float $width, float $height, string $text, float $size = 10, string $align = 'L') use ($pdf): void {
            $pdf->SetFont('dejavusans', '', $size);
            $pdf->setCellPaddings(1, 0, 1, 0);
            if ($pdf->getStringHeight($width, $text) > $height) {
                throw new DomainException('Invoice details do not fit the official template. Shorten the recipient or bank details.');
            }
            $pdf->MultiCell($width, $height, $text, 0, $align, false, 1, $x, $y, true, 0, false, true, $height, 'T');
        };
        $data = $invoice['data'];
        $judgeInvoice = !empty($data['judge_qty']);
        if ($judgeInvoice) {
            $pdf->SetFillColor(255, 255, 255);
            $pdf->Rect(28, 124, 54, 16, 'F');
            $write(29, 129, 53, 8, 'Judges Registration', 9);
        }
        $money = static fn(int $cents): string => number_format($cents / 100, 2, '.', ',');
        $write(28, 74, 81, 24, $data['school'] . "\n" . $data['email'] . "\nCustomer code: " . $data['customer_code'], 9);
        $write(143, 82, 40, 7, $invoice['number'], 9, 'R');
        $write(155, 91, 29, 7, date('d M Y', strtotime($invoice['issued'])), 9, 'R');
        $write(28, 99, 155, 6, $data['convention'], 8);
        foreach ([
            [129, $judgeInvoice ? $data['judge_qty'] : $data['student_qty'],
                $judgeInvoice ? $data['judge_rates'] : $data['student_rates'],
                $judgeInvoice ? $data['judge_gross'] : $data['student_gross']],
            [144, $data['supervisor_qty'], $data['supervisor_rates'], $data['supervisor_gross']],
            [158, $data['discount_qty'], [], -$data['discount']],
        ] as [$y, $qty, $rates, $subtotal]) {
            $write(85, $y, 12, 8, (string)$qty, 10, 'C');
            $rate = count($rates) === 1 ? $money($rates[0]) : ($rates ? 'Varies' : '-');
            $write(117, $y, 28, 8, $rate, 10, 'R');
            $write(151, $y, 32, 8, $money($subtotal), 10, 'R');
        }
        $write(28, 173, 155, 8, 'Amounts as recorded. No additional tax added.', 8);
        $write(132, 187, 51, 9, $money($data['total']) . ' AUD', 12, 'R');
        $write(28, 211, 76, 35, $data['bank'], 9);
        $write(143, 210, 40, 7, date('d M Y', strtotime($invoice['due'])), 10, 'R');
        $write(119, 222, 64, 8, $paid ? 'PAID - Amount due: 0.00 AUD' : 'Amount due: ' . $money($data['total']) . ' AUD', 9, 'R');
        $write(28, 248, 155, 7, 'Payment reference: ' . $invoice['number'], 8);
        return $pdf->Output('', 'S');
    }

    public function send(string $slug, int $adminId, ?LegacyEmail $email = null): bool
    {
        if ($adminId <= 0) {
            throw new DomainException('Administrator access is required.');
        }
        return $this->db->transactional(function () use ($slug, $email): bool {
            $this->db->execute('SELECT id FROM transactions WHERE slug=? FOR UPDATE', [$slug])->fetch('assoc');
            $transaction = $this->transaction($slug);
            $invoice = $this->find((int)$transaction['id']);
            if (!$invoice) {
                throw new DomainException('Generate the invoice before sending it.');
            }
            $address = $invoice['data']['email'];
            if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
                throw new DomainException('The invoice has no valid recipient email address.');
            }
            if ($address !== (string)$transaction['school_email']) {
                throw new DomainException('The recipient email has changed since this invoice was issued. Contact accounts before sending the saved invoice.');
            }
            $email ??= new LegacyEmail();
            $email->setEmailFormat('html')->setTo($address)
                ->setFrom([HEADERS_FROM_EMAIL => HEADERS_FROM_NAME])
                ->setSubject('Convention invoice ' . $invoice['number'])
                ->addAttachments([$invoice['number'] . '.pdf' => [
                    'data' => $this->pdf($invoice, (int)$transaction['status'] === 1), 'mimetype' => 'application/pdf',
                ]]);
            $email->viewBuilder()->setTemplate('default')->setLayout('admintemplate');
            $email->setViewVars(['content_for_layout' => '<tr><td><p>Dear ' . h($invoice['data']['school']) . ',</p><p>Please find your convention invoice '
                . h($invoice['number']) . ' attached.</p><p>'
                . ((int)$transaction['status'] === 1 ? 'This transaction is confirmed as paid.' : 'Payment is due on ' . h($invoice['due']) . '.')
                . '</p><p>Events Team</p></td></tr>'])->send();
            if ($email->getTransport() instanceof DebugTransport) {
                return false;
            }
            $this->db->execute('UPDATE transaction_invoices SET emailed_at=NOW() WHERE id=?', [$invoice['id']]);
            return true;
        });
    }
}
