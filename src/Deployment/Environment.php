<?php
declare(strict_types=1);

namespace App\Deployment;

use RuntimeException;

final class Environment
{
    private static array $settings = ['environment' => 'local'];

    public static function initialize(string $root): void
    {
        $name = getenv('ACP_ENV');
        if ($name === false || $name === '') {
            $host = strtolower((string)parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
            if (PHP_SAPI !== 'cli' && !in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
                throw new RuntimeException('Explicit application environment configuration is required.');
            }
            $name = 'local';
        }
        if ($name === 'local') {
            self::$settings = ['environment' => 'local'];
            if (PHP_SAPI === 'cli' && !getenv('HTTP_PATH')) {
                putenv('HTTP_PATH=http://127.0.0.1:8000');
            }

            return;
        }
        $path = getenv('ACP_CONFIG_FILE');
        $canonical = is_string($path) ? realpath($path) : false;
        $documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
        if ($canonical === false || !is_file($canonical) || is_link($path)
            || str_starts_with($canonical, $root . DIRECTORY_SEPARATOR)
            || ($documentRoot !== false && str_starts_with($canonical, $documentRoot . DIRECTORY_SEPARATOR))
            || (fileperms($canonical) & 0027) !== 0) {
            throw new RuntimeException('Protected application configuration is required.');
        }
        try {
            $settings = require $canonical;
        } catch (\ParseError) {
            throw new RuntimeException('Protected application configuration could not be parsed.');
        }
        if (!is_array($settings)) {
            throw new RuntimeException('Application environment configuration is invalid.');
        }
        self::$settings = self::validate($name, $settings, $root);
    }

    public static function validate(string $name, array $settings, string $root): array
    {
        if (!in_array($name, ['demo', 'live'], true) || ($settings['environment'] ?? null) !== $name) {
            throw new RuntimeException('Application environment identity is invalid.');
        }
        $expected = 'https://convention.accelerateministries.com.au/' . ($name === 'demo' ? 'acp_demo' : 'acp');
        if (($settings['publicUrl'] ?? null) !== $expected) {
            throw new RuntimeException('Application public URL does not match its environment.');
        }
        foreach (['storageRoot', 'securitySalt'] as $key) {
            if (!is_string($settings[$key] ?? null) || trim($settings[$key]) === '') {
                throw new RuntimeException('Required environment configuration is missing.');
            }
        }
        if (strlen($settings['securitySalt']) < 32 || str_contains($settings['securitySalt'], 'REPLACE')) {
            throw new RuntimeException('An environment-specific security salt is required.');
        }
        if (!is_string($settings['releaseId'] ?? null)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,79}\z/', $settings['releaseId']) !== 1) {
            throw new RuntimeException('An explicit release identity is required.');
        }
        $storage = realpath($settings['storageRoot']);
        $documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
        if ($storage === false || !is_dir($storage) || $storage === $root
            || str_starts_with($storage, $root . DIRECTORY_SEPARATOR)
            || ($documentRoot !== false && ($storage === $documentRoot
                || str_starts_with($storage, $documentRoot . DIRECTORY_SEPARATOR)))
            || basename($storage) !== $name) {
            throw new RuntimeException('Environment storage must be provisioned outside the release.');
        }
        foreach (['tmp', 'logs', 'sessions', 'uploads', 'cache'] as $directory) {
            $child = realpath($storage . DIRECTORY_SEPARATOR . $directory);
            if ($child === false || !is_dir($child) || !str_starts_with($child, $storage . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Environment storage directories are unavailable.');
            }
        }
        $settings['storageRoot'] = $storage;
        $database = $settings['database'] ?? [];
        foreach (['host', 'database', 'username', 'password'] as $key) {
            if (!is_string($database[$key] ?? null) || trim($database[$key]) === '') {
                throw new RuntimeException('Explicit environment database configuration is required.');
            }
        }
        if (array_diff(array_keys($database), ['host', 'database', 'username', 'password', 'port', 'encoding']) !== []) {
            throw new RuntimeException('Unsupported environment database overrides.');
        }
        if ($name === 'demo') {
            if (!str_contains(strtolower($database['database']), 'demo')
                || !str_contains(strtolower($database['username']), 'demo')
                || !in_array($settings['emailMode'] ?? null, ['capture', 'smtp_test'], true)
                || !in_array($settings['payments'] ?? null, ['disabled', 'sandbox'], true)
                || ($settings['integrations'] ?? null) !== []
                || !is_array($settings['commands'] ?? null)
                || array_diff($settings['commands'], ['result_export_cleanup']) !== []) {
                throw new RuntimeException('Demo safety configuration is incomplete or unsafe.');
            }
            if (($settings['emailMode'] ?? null) === 'smtp_test') {
                if (!is_array($settings['smtp'] ?? null)
                    || array_diff(array_keys($settings['smtp']), ['host', 'port', 'username', 'password']) !== []) {
                    throw new RuntimeException('Demo test-mail configuration contains unsupported settings.');
                }
                foreach (['host', 'username', 'password'] as $key) {
                    if (!is_string($settings['smtp'][$key] ?? null) || trim($settings['smtp'][$key]) === '') {
                        throw new RuntimeException('Explicit Demo test-mail configuration is required.');
                    }
                }
                if (!is_int($settings['smtp']['port'] ?? null)
                    || $settings['smtp']['port'] < 1 || $settings['smtp']['port'] > 65535
                    || !is_string($settings['testRecipient'] ?? null)
                    || filter_var($settings['testRecipient'], FILTER_VALIDATE_EMAIL) === false
                    || !is_string($settings['sender'] ?? null)
                    || filter_var($settings['sender'], FILTER_VALIDATE_EMAIL) === false) {
                    throw new RuntimeException('A valid Demo mail recipient, sender, and SMTP port are required.');
                }
            }
            if (($settings['payments'] ?? null) === 'sandbox'
                && (!is_string($settings['paypalSandboxEmail'] ?? null)
                    || filter_var($settings['paypalSandboxEmail'], FILTER_VALIDATE_EMAIL) === false)) {
                throw new RuntimeException('A PayPal Sandbox merchant email is required.');
            }
        } elseif (($settings['emailMode'] ?? null) !== 'smtp'
            || !in_array($settings['payments'] ?? null, ['disabled', 'live'], true)
            || !is_array($settings['integrations'] ?? null)
            || !is_array($settings['commands'] ?? null)) {
            throw new RuntimeException('Explicit Live integration configuration is required.');
        }
        if ($name === 'live') {
            foreach (['host', 'username', 'password'] as $key) {
                if (!is_string($settings['smtp'][$key] ?? null) || $settings['smtp'][$key] === '') {
                    throw new RuntimeException('Explicit Live mail configuration is required.');
                }
            }
            if (!isset($settings['sender']) || filter_var($settings['sender'], FILTER_VALIDATE_EMAIL) === false
                || str_ends_with($settings['sender'], '.invalid')) {
                throw new RuntimeException('An explicit Live sender address is required.');
            }
        }

        return $settings;
    }

    public static function settings(): array
    {
        return self::$settings;
    }

    public static function isHosted(): bool
    {
        return self::$settings['environment'] !== 'local';
    }

    public static function paths(): array
    {
        if (!self::isHosted()) {
            return [];
        }
        $root = self::$settings['storageRoot'] . DIRECTORY_SEPARATOR;

        return ['tmp' => $root . 'tmp' . DIRECTORY_SEPARATOR, 'logs' => $root . 'logs' . DIRECTORY_SEPARATOR,
            'cache' => $root . 'cache' . DIRECTORY_SEPARATOR];
    }

    public static function apply(array $config, array $settings): array
    {
        $name = $settings['environment'];
        $config['Environment'] = ['name' => $name];
        if ($name === 'local') {
            return $config;
        }
        $storage = $settings['storageRoot'] . DIRECTORY_SEPARATOR;
        $config['debug'] = false;
        $config['App']['base'] = $name === 'demo' ? '/acp_demo' : '/acp';
        $config['App']['fullBaseUrl'] = 'https://convention.accelerateministries.com.au';
        $config['App']['paths']['temporary'] = $storage . 'tmp' . DIRECTORY_SEPARATOR;
        $config['Security']['salt'] = $settings['securitySalt'];
        $config['Datasources'] = ['default' => array_replace($config['Datasources']['default'], $settings['database'], [
            'url' => null, 'init' => [], 'flags' => [], 'log' => false,
        ])];
        foreach ($config['Cache'] as $key => &$cache) {
            $cache['path'] = $storage . 'cache' . DIRECTORY_SEPARATOR;
            $cache['prefix'] = 'acp_' . $name . '_' . $settings['releaseId'] . '_' . $key . '_';
            $cache['url'] = null;
        }
        unset($cache);
        foreach ($config['Log'] as &$log) {
            $log['path'] = $storage . 'logs' . DIRECTORY_SEPARATOR;
            $log['url'] = null;
        }
        unset($log);
        $config['Session'] = [
            'defaults' => 'php', 'cookie' => 'ACP_' . strtoupper($name) . '_SESSION',
            'cookiePath' => $config['App']['base'], 'timeout' => 120,
            'ini' => ['session.save_handler' => 'files', 'session.save_path' => $storage . 'sessions',
                'session.cookie_domain' => '', 'session.use_only_cookies' => true, 'session.cookie_secure' => true,
                'session.cookie_httponly' => true, 'session.cookie_samesite' => 'Lax',
                'session.use_strict_mode' => true, 'session.gc_maxlifetime' => 7200],
        ];
        $config['PublicationCsrf']['cookieName'] = 'acp_' . $name . '_csrf';
        if ($name === 'demo' && $settings['emailMode'] === 'smtp_test') {
            $config['EmailTransport'] = ['default' => array_replace($settings['smtp'], [
                'className' => 'App\\Mailer\\Transport\\DemoSmtpTransport',
                'testRecipient' => $settings['testRecipient'],
                'testSender' => $settings['sender'],
                'url' => null,
                'tls' => true,
            ])];
        } else {
            $config['EmailTransport'] = ['default' => $name === 'demo'
                ? ['className' => 'App\\Mailer\\Transport\\DemoTransport']
                : array_replace($settings['smtp'], ['className' => 'Smtp', 'url' => null, 'tls' => true])];
        }
        $config['Email'] = ['default' => ['transport' => 'default', 'from' => $settings['sender'] ?? 'noreply@example.invalid']];
        $config['Email']['mode'] = $settings['emailMode'];
        $config['Payments'] = [
            'enabled' => ($name === 'live' && $settings['payments'] === 'live')
                || ($name === 'demo' && $settings['payments'] === 'sandbox'),
            'mode' => $settings['payments'],
            'sandboxEmail' => $settings['paypalSandboxEmail'] ?? null,
        ];
        $config['Integrations']['allowed'] = $settings['integrations'];
        $config['Commands']['allowed'] = $settings['commands'];

        return $config;
    }
}
