<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

final class TransactionDetailsTest extends TestCase
{
    use IntegrationTestTrait;

    public function testDetailsRequireTheTransactionOwner(): void
    {
        $connection = ConnectionManager::get('default');
        $owner = $connection->execute(
            "SELECT t.slug, t.user_id FROM transactions t
             INNER JOIN users u ON u.id = t.user_id
             WHERE u.user_type = 'School' AND u.status = 1 AND u.activation_status = 1
             AND t.slug IS NOT NULL AND t.slug <> '' LIMIT 1"
        )->fetch('assoc');
        if (!$owner) {
            $this->markTestSkipped('An active school with a transaction is required.');
        }
        $otherSchool = $connection->execute(
            "SELECT id FROM users WHERE user_type = 'School' AND status = 1
             AND activation_status = 1 AND id <> ? LIMIT 1",
            [$owner['user_id']]
        )->fetchColumn();
        if (!$otherSchool) {
            $this->markTestSkipped('A second active school is required.');
        }

        $url = '/transactions/viewdetails/' . rawurlencode($owner['slug']);
        $this->session(['user_id' => (int)$owner['user_id'], 'user_type' => 'School']);
        $this->get($url);
        $this->assertResponseOk();
        $this->assertResponseContains('Transaction Details');

        $this->session(['user_id' => (int)$otherSchool, 'user_type' => 'School']);
        $this->get($url);
        $this->assertRedirectContains('/transactions/mytransactions');

        $this->session(['user_id' => (int)$owner['user_id'], 'user_type' => 'Teacher_Parent']);
        $this->get($url);
        $this->assertRedirectContains('/users/dashboard');

        $this->session(['user_id' => null, 'user_type' => null]);
        $this->get($url);
        $this->assertRedirectContains('/users/login');
    }
}
