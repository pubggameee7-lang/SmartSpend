<?php
require_once '../config/db.php';

header('Content-Type: application/json');
session_start();

$action = $_POST['action'] ?? '';

if ($action === 'register') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Invalid email address.']);
        exit;
    }

    if (strlen($password) < 8) {
        echo json_encode(['success' => false, 'error' => 'Password must be at least 8 characters.']);
        exit;
    }

    $db   = getDB();
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);

    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'An account with that email already exists.']);
        exit;
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $db->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)');
    $stmt->execute([$email, $hash]);

    $_SESSION['user_id'] = $db->lastInsertId();
    $_SESSION['email']   = $email;

    echo json_encode(['success' => true, 'message' => 'Account created successfully.']);
    exit;
}

if ($action === 'login') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        echo json_encode(['success' => false, 'error' => 'Please enter your email and password.']);
        exit;
    }

    $db   = getDB();
    $stmt = $db->prepare('SELECT id, password_hash FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        echo json_encode(['success' => false, 'error' => 'Incorrect email or password.']);
        exit;
    }

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['email']   = $email;

    echo json_encode(['success' => true, 'message' => 'Logged in successfully.']);
    exit;
}

if ($action === 'logout') {
    session_destroy();
    echo json_encode(['success' => true, 'message' => 'Logged out.']);
    exit;
}

if ($action === 'check') {
    if (isset($_SESSION['user_id'])) {
        echo json_encode(['success' => true, 'logged_in' => true, 'email' => $_SESSION['email']]);
    } else {
        echo json_encode(['success' => true, 'logged_in' => false]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action.']);