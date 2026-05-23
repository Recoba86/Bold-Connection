<?php
// Copy this file to config.php and fill in your values.
// This variable added for high load panels which their response time is long and bot can't communicate with online panel!
// null for default settings
$request_exec_timeout = null;

$dbhost      = '{database_url}';
$dbname      = '{database_name}';
$usernamedb  = '{username_db}';
$passworddb  = '{password_db}';

$APIKEY      = '{API_KEY}';
$adminnumber = '{admin_number}';
$domainhosts = '{domain_name}';
$usernamebot = '{username_bot}';

// Security: set in production. Empty = legacy fallbacks where noted in code.
$allow_self_signed_certs = false;
$telegram_webhook_secret = '';
$payment_webhook_key     = '';

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

$dsn = "mysql:host={$dbhost};dbname={$dbname};charset=utf8mb4";

// Initialise as null so callers can detect a failed connection instead of
// fatal-erroring on a non-object access.
$pdo = null;

try {
    $pdo = new PDO($dsn, $usernamedb, $passworddb, $options);
} catch (\PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
}

/**
 * Telegram and most external webhooks must always receive an HTTP 200,
 * otherwise the provider retries the same payload indefinitely and the
 * worker stays in an error-storm. When the DB connection is missing we
 * cannot service the request - acknowledge it and exit cleanly.
 */
if ($pdo === null) {
    // Only ack for HTTP requests; let CLI scripts surface the failure.
    if (PHP_SAPI !== 'cli') {
        http_response_code(200);
        echo json_encode(['ok' => false, 'description' => 'Service temporarily unavailable']);
        exit;
    }
}
