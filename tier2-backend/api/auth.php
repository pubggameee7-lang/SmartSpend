<?php
require_once '../config/db.php';

header('Content-Type: application/json');

// Prevent session fixation
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// ── CSRF Token generation ─────────────────────────────────
function generateCSRF(): string {
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf_token'];
}

// ── CSRF Token validation ─────────────────────────────────
function validateCSRF(string $token): bool {
  return isset($_SESSION['csrf_token'])
    && hash_equals($_SESSION['csrf_token'], $token);
}

// ── Rate limiting — max 5 login attempts per 15 minutes ──
function checkRateLimit(string $email): bool {
  $key = 'login_attempts_' . md5($email);
  if (!isset($_SESSION[$key])) {
    $_SESSION[$key] = ['count' => 0, 'time' => time()];
  }
  if (time() - $_SESSION[$key]['time'] > 900) {
    $_SESSION[$key] = ['count' => 0, 'time' => time()];
  }
  return $_SESSION[$key]['count'] < 5;
}

function incrementAttempts(string $email): void {
  $key = 'login_attempts_' . md5($email);
  $_SESSION[$key]['count']++;
}

function resetAttempts(string $email): void {
  $key = 'login_attempts_' . md5($email);
  unset($_SESSION[$key]);
}

// ── Sanitise output ───────────────────────────────────────
function clean(string $value): string {
  return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES, 'UTF-8');
}

// ── Standard JSON response ────────────────────────────────
function respond(bool $success, array $data = [], int $code = 200): void {
  http_response_code($code);
  echo json_encode(array_merge(['success' => $success], $data));
  exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Get CSRF token ────────────────────────────────────────
if ($action === 'csrf') {
  respond(true, ['token' => generateCSRF()]);
}

// ── Check session ─────────────────────────────────────────
if ($action === 'check') {
  if (isset($_SESSION['user_id'])) {
    respond(true, [
      'logged_in' => true,
      'email'     => clean($_SESSION['email'])
    ]);
  }
  respond(true, ['logged_in' => false]);
}

// ── Register ──────────────────────────────────────────────
if ($action === 'register') {
  $token    = $_POST['csrf_token'] ?? '';
  $email    = trim($_POST['email'] ?? '');
  $password = $_POST['password'] ?? '';

  if (!validateCSRF($token)) {
    respond(false, ['error' => 'Invalid request.'], 403);
  }

  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, ['error' => 'Invalid email address.'], 400);
  }

  if (strlen($password) < 8) {
    respond(false, ['error' => 'Password must be at least 8 characters.'], 400);
  }

  $db   = getDB();
  $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
  $stmt->execute([$email]);

  if ($stmt->fetch()) {
    respond(false, ['error' => 'An account with that email already exists.'], 409);
  }

  $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
  $stmt = $db->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)');
  $stmt->execute([$email, $hash]);

  // Regenerate session ID after registration — prevents session fixation
  session_regenerate_id(true);
  $_SESSION['user_id'] = $db->lastInsertId();
  $_SESSION['email']   = $email;

  respond(true, ['message' => 'Account created successfully.']);
}

// ── Login ─────────────────────────────────────────────────
if ($action === 'login') {
  $token    = $_POST['csrf_token'] ?? '';
  $email    = trim($_POST['email'] ?? '');
  $password = $_POST['password'] ?? '';

  if (!validateCSRF($token)) {
    respond(false, ['error' => 'Invalid request.'], 403);
  }

  if (empty($email) || empty($password)) {
    respond(false, ['error' => 'Please enter your email and password.'], 400);
  }

  if (!checkRateLimit($email)) {
    respond(false, ['error' => 'Too many login attempts. Please wait 15 minutes.'], 429);
  }

  $db   = getDB();
  $stmt = $db->prepare('SELECT id, password_hash FROM users WHERE email = ?');
  $stmt->execute([$email]);
  $user = $stmt->fetch();

  if (!$user || !password_verify($password, $user['password_hash'])) {
    incrementAttempts($email);
    // Vague error message — never reveal which part is wrong
    respond(false, ['error' => 'Incorrect email or password.'], 401);
  }

  resetAttempts($email);

  // Regenerate session ID after login — prevents session fixation
  session_regenerate_id(true);
  $_SESSION['user_id'] = $user['id'];
  $_SESSION['email']   = $email;

  respond(true, ['message' => 'Logged in successfully.']);
}

// ── Logout ────────────────────────────────────────────────
if ($action === 'logout') {
  session_destroy();
  respond(true, ['message' => 'Logged out.']);
}

respond(false, ['error' => 'Invalid action.'], 400);