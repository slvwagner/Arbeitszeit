<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/functions.php';

$config = require __DIR__ . '/../config/database.php';

try {
    $pdo = new PDO($config['dsn'], $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $exception) {
    http_response_code(500);
    echo '<h1>Database connection failed</h1>';
    echo '<p>' . h($exception->getMessage()) . '</p>';
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$currentEmployee = get_default_employee($pdo);
