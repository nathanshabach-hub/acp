<?php
declare(strict_types=1);

// Copy outside the application and public web roots; replace placeholders privately.
// This example intentionally cannot boot until storage and secrets are provisioned.
return [
    'environment' => 'demo',
    'publicUrl' => 'https://convention.accelerateministries.com.au/acp_demo',
    'releaseId' => 'REPLACE_WITH_APPROVED_RELEASE',
    'storageRoot' => '/REPLACE_WITH_PRIVATE_STORAGE/demo',
    'securitySalt' => 'REPLACE_WITH_UNIQUE_ENVIRONMENT_SECRET',
    'database' => [
        'host' => 'REPLACE_WITH_DATABASE_HOST',
        'database' => 'REPLACE_WITH_DEMO_DATABASE',
        'username' => 'REPLACE_WITH_DEMO_USER',
        'password' => 'REPLACE_WITH_DEMO_PASSWORD',
        'encoding' => 'utf8',
    ],
    'sender' => 'REPLACE_WITH_DEMO_SENDER',
    'emailMode' => 'smtp_test',
    'testRecipient' => 'REPLACE_WITH_APPROVED_TEST_INBOX',
    'smtp' => [
        'host' => 'REPLACE_WITH_TEST_SMTP_HOST',
        'port' => 587,
        'username' => 'REPLACE_WITH_TEST_SMTP_USERNAME',
        'password' => 'REPLACE_WITH_TEST_SMTP_PASSWORD',
    ],
    'payments' => 'sandbox',
    'paypalSandboxEmail' => 'REPLACE_WITH_PAYPAL_SANDBOX_MERCHANT_EMAIL',
    'integrations' => [],
    'commands' => [],
    // Optional direct delivery for self-service password resets only.
    // Configure in the protected server file after confirming recipients.
    'passwordResetMail' => [
        'enabled' => false,
        'host' => 'REPLACE_WITH_SMTP_HOST',
        'port' => 587,
        'username' => 'REPLACE_WITH_SMTP_USERNAME',
        'password' => 'REPLACE_WITH_SMTP_PASSWORD',
        'sender' => 'REPLACE_WITH_SENDER_ADDRESS',
        'notify' => 'REPLACE_WITH_EVENTS_TEAM_ADDRESS',
    ],
];
