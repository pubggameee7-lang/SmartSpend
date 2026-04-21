<?php
require_once '../config/db.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['success' => false, 'error' => 'Not logged in.']);
  exit;
}

$user_id = $_SESSION['user_id'];
$method  = $_SERVER['REQUEST_METHOD'];
$action  = $_POST['action'] ?? $_GET['action'] ?? '';
$db      = getDB();

// ── Sanitise output ───────────────────────────────────────
function clean(string $value): string {
  return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES, 'UTF-8');
}

// ── GET requests ──────────────────────────────────────────
if ($method === 'GET') {

  // Get all sessions for this user
  if ($action === 'sessions') {
    $stmt = $db->prepare(
      'SELECT id, title, created_at
       FROM sessions
       WHERE user_id = ?
       ORDER BY created_at DESC'
    );
    $stmt->execute([$user_id]);
    $sessions = $stmt->fetchAll();

    $clean_sessions = array_map(function($s) {
      return [
        'id'         => $s['id'],
        'title'      => clean($s['title']),
        'created_at' => $s['created_at'],
      ];
    }, $sessions);

    echo json_encode(['success' => true, 'sessions' => $clean_sessions]);
    exit;
  }

  // Get all messages for a session
  if ($action === 'messages') {
    $session_id = intval($_GET['session_id'] ?? 0);

    if ($session_id === 0) {
      http_response_code(400);
      echo json_encode(['success' => false, 'error' => 'Invalid session.']);
      exit;
    }

    // Verify session belongs to this user
    $stmt = $db->prepare(
      'SELECT id FROM sessions WHERE id = ? AND user_id = ?'
    );
    $stmt->execute([$session_id, $user_id]);

    if (!$stmt->fetch()) {
      http_response_code(403);
      echo json_encode(['success' => false, 'error' => 'Session not found.']);
      exit;
    }

    $stmt = $db->prepare(
      'SELECT role, content, created_at
       FROM messages
       WHERE session_id = ?
       ORDER BY created_at ASC'
    );
    $stmt->execute([$session_id]);
    $messages = $stmt->fetchAll();

    $clean_messages = array_map(function($m) {
      return [
        'role'       => $m['role'],
        'content'    => clean($m['content']),
        'created_at' => $m['created_at'],
      ];
    }, $messages);

    echo json_encode(['success' => true, 'messages' => $clean_messages]);
    exit;
  }

  // Get latest health score for this user
  if ($action === 'health_score') {
    $stmt = $db->prepare(
      'SELECT score, trend
       FROM health_scores
       WHERE user_id = ?
       ORDER BY created_at DESC
       LIMIT 1'
    );
    $stmt->execute([$user_id]);
    $score = $stmt->fetch();

    if ($score) {
      echo json_encode([
        'success' => true,
        'score'   => $score['score'],
        'trend'   => $score['trend'],
      ]);
    } else {
      echo json_encode(['success' => false]);
    }
    exit;
  }

  // Get last assessment for a session
  if ($action === 'last_assessment') {
    $session_id = intval($_GET['session_id'] ?? 0);

    if ($session_id === 0) {
      http_response_code(400);
      echo json_encode(['success' => false, 'error' => 'Invalid session.']);
      exit;
    }

    // Verify session belongs to this user
    $stmt = $db->prepare(
      'SELECT id FROM sessions WHERE id = ? AND user_id = ?'
    );
    $stmt->execute([$session_id, $user_id]);

    if (!$stmt->fetch()) {
      http_response_code(403);
      echo json_encode(['success' => false, 'error' => 'Session not found.']);
      exit;
    }

    $stmt = $db->prepare(
      'SELECT item_name, risk_level, surplus, surplus_after, months_to_save
       FROM assessments
       WHERE session_id = ?
       ORDER BY created_at DESC
       LIMIT 1'
    );
    $stmt->execute([$session_id]);
    $assessment = $stmt->fetch();

    if ($assessment) {
      echo json_encode([
        'success'    => true,
        'assessment' => [
          'item_name'      => clean($assessment['item_name']),
          'risk_level'     => $assessment['risk_level'],
          'surplus'        => $assessment['surplus'],
          'surplus_after'  => $assessment['surplus_after'],
          'months_to_save' => $assessment['months_to_save'],
        ],
      ]);
    } else {
      echo json_encode(['success' => false]);
    }
    exit;
  }
}

// ── POST requests ─────────────────────────────────────────
if ($method === 'POST') {

  // Create a new session
  if ($action === 'new_session') {
    $title = clean($_POST['title'] ?? 'Budget Session');

    if (empty($title)) {
      $title = 'Budget Session';
    }

    $stmt = $db->prepare(
      'INSERT INTO sessions (user_id, title) VALUES (?, ?)'
    );
    $stmt->execute([$user_id, $title]);

    $session_id = $db->lastInsertId();

    echo json_encode([
      'success'    => true,
      'session_id' => $session_id,
      'title'      => $title,
    ]);
    exit;
  }
}

// ── Fallback ──────────────────────────────────────────────
http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Invalid request.']);