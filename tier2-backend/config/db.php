<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'smartspend_db');
define('DB_USER', 'your_db_username');
define('DB_PASS', 'your_db_password');
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