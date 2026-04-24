<?php
$env_path     = __DIR__ . '/../../.env';
$env_contents = file_get_contents($env_path);
$env          = [];

foreach (explode("\n", $env_contents) as $line) {
  $line = trim($line);
  if (!empty($line) && strpos($line, '=') !== false) {
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim($value);
  }
}

define('DB_HOST',    $env['DB_HOST']    ?? 'localhost');
define('DB_NAME',    $env['DB_NAME']    ?? '');
define('DB_USER',    $env['DB_USER']    ?? '');
define('DB_PASS',    $env['DB_PASS']    ?? '');
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO {
  static $pdo = null;

  if ($pdo === null) {
    $dsn = 'mysql:host=' . DB_HOST
         . ';dbname=' . DB_NAME
         . ';charset=' . DB_CHARSET;

    $options = [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
      $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
      http_response_code(500);
      header('Content-Type: application/json');
      echo json_encode(['error' => 'Database connection failed.']);
      exit;
    }
  }

  return $pdo;
}