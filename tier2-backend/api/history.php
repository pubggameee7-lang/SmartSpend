<?php
require_once '../config/db.php';

header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$method  = $_SERVER['REQUEST_METHOD'];
$db      = getDB();

// ── GET — retrieve all sessions and messages for this user ─
if ($method === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'sessions') {
        $stmt = $db->prepare(
            'SELECT id, title, created_at FROM sessions
             WHERE user_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$user_id]);
        $sessions = $stmt->fetchAll();

        echo json_encode(['success' => true, 'sessions' => $sessions]);
        exit;
    }

    if ($action === 'messages') {
        $session_id = intval($_GET['session_id'] ?? 0);

        if ($session_id === 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid session.']);
            exit;
        }

        // Make sure this session belongs to this user
        $stmt = $db->prepare(
            'SELECT id FROM sessions WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$session_id, $user_id]);

        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Session not found.']);
            exit;
        }

        $stmt = $db->prepare(
            'SELECT role, content, created_at FROM messages
             WHERE session_id = ? ORDER BY created_at ASC'
        );
        $stmt->execute([$session_id]);
        $messages = $stmt->fetchAll();

        echo json_encode(['success' => true, 'messages' => $messages]);
        exit;
    }
}

// ── POST — create a new session ───────────────────────────
if ($method === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'new_session') {
        $title = trim($_POST['title'] ?? 'Budget Session');

        $stmt = $db->prepare(
            'INSERT INTO sessions (user_id, title) VALUES (?, ?)'
        );
        $stmt->execute([$user_id, $title]);

        $session_id = $db->lastInsertId();

        echo json_encode([
            'success'    => true,
            'session_id' => $session_id,
            'title'      => $title
        ]);
        exit;
    }
}

echo json_encode(['success' => false, 'error' => 'Invalid request.']);