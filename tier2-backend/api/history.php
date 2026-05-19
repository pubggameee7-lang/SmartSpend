<?php
ini_set('session.cookie_path', '/');
ini_set('session.cookie_samesite', 'Lax');

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

function clean(string $value): string {
  return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES, 'UTF-8');
}

if ($method === 'GET') {

  if ($action === 'sessions') {
    $stmt = $db->prepare('SELECT id, title, created_at, pinned, archived, project_id FROM sessions WHERE user_id = ? AND archived = 0 ORDER BY pinned DESC, created_at DESC');
    $stmt->execute([$user_id]);
    $sessions = $stmt->fetchAll();
    $clean_sessions = array_map(function($s) {
      return ['id' => $s['id'], 'title' => clean($s['title']), 'created_at' => $s['created_at'], 'pinned' => (bool)$s['pinned'], 'project_id' => $s['project_id']];
    }, $sessions);
    echo json_encode(['success' => true, 'sessions' => $clean_sessions]);
    exit;
  }

  if ($action === 'messages') {
    $session_id = intval($_GET['session_id'] ?? 0);
    if ($session_id === 0) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'Invalid session.']); exit; }
    $stmt = $db->prepare('SELECT id FROM sessions WHERE id = ? AND user_id = ?');
    $stmt->execute([$session_id, $user_id]);
    if (!$stmt->fetch()) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'Session not found.']); exit; }
    $stmt = $db->prepare('SELECT role, content, calculation, created_at FROM messages WHERE session_id = ? ORDER BY created_at ASC');
    $stmt->execute([$session_id]);
    $messages = $stmt->fetchAll();
    $clean_messages = array_map(function($m) {
      return [
        'role'        => $m['role'],
        'content'     => clean($m['content']),
        'calculation' => $m['calculation'] ? json_decode($m['calculation'], true) : null,
        'created_at'  => $m['created_at'],
      ];
    }, $messages);
    echo json_encode(['success' => true, 'messages' => $clean_messages]);
    exit;
  }

  if ($action === 'health_score') {
    $stmt = $db->prepare('SELECT score, trend, created_at FROM health_scores WHERE user_id = ? ORDER BY created_at DESC LIMIT 1');
    $stmt->execute([$user_id]);
    $latest = $stmt->fetch();

    $stmt = $db->prepare('SELECT score, created_at FROM health_scores WHERE user_id = ? ORDER BY created_at ASC LIMIT 10');
    $stmt->execute([$user_id]);
    $history = $stmt->fetchAll();

    $stmt = $db->prepare('SELECT income, expenses FROM budgets WHERE session_id IN (SELECT id FROM sessions WHERE user_id = ?) ORDER BY id DESC LIMIT 1');
    $stmt->execute([$user_id]);
    $budget = $stmt->fetch();
    $expense_ratio = null;
    if ($budget && $budget['income'] > 0) {
      $expense_ratio = round(($budget['expenses'] / $budget['income']) * 100, 1);
    }

    if ($latest) {
      echo json_encode([
        'success'       => true,
        'score'         => $latest['score'],
        'trend'         => $latest['trend'],
        'history'       => $history,
        'expense_ratio' => $expense_ratio,
      ]);
    } else {
      echo json_encode(['success' => false]);
    }
    exit;
  }

  if ($action === 'last_assessment') {
    $session_id = intval($_GET['session_id'] ?? 0);
    if ($session_id === 0) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'Invalid session.']); exit; }
    $stmt = $db->prepare('SELECT id FROM sessions WHERE id = ? AND user_id = ?');
    $stmt->execute([$session_id, $user_id]);
    if (!$stmt->fetch()) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'Session not found.']); exit; }
    $stmt = $db->prepare('SELECT item_name, item_price, item_type, risk_level, surplus, surplus_after, months_to_save FROM assessments WHERE session_id = ? ORDER BY created_at DESC LIMIT 1');
    $stmt->execute([$session_id]);
    $assessment = $stmt->fetch();
    if ($assessment) {
      echo json_encode(['success' => true, 'assessment' => [
        'item_name'      => clean($assessment['item_name']),
        'item_price'     => $assessment['item_price'],
        'item_type'      => $assessment['item_type'],
        'risk_level'     => $assessment['risk_level'],
        'surplus'        => $assessment['surplus'],
        'surplus_after'  => $assessment['surplus_after'],
        'months_to_save' => $assessment['months_to_save'],
      ]]);
    } else {
      echo json_encode(['success' => false]);
    }
    exit;
  }

  if ($action === 'all_assessments') {
    $stmt = $db->prepare('
      SELECT a.item_name, a.item_price, a.item_type, a.risk_level,
             a.surplus, a.surplus_after, a.months_to_save, a.created_at
      FROM assessments a
      JOIN sessions s ON a.session_id = s.id
      WHERE s.user_id = ?
      ORDER BY a.created_at DESC
      LIMIT 50
    ');
    $stmt->execute([$user_id]);
    $assessments = $stmt->fetchAll();
    $clean_assessments = array_map(function($a) {
      return [
        'item_name'      => clean($a['item_name']),
        'item_price'     => $a['item_price'],
        'item_type'      => $a['item_type'],
        'risk_level'     => $a['risk_level'],
        'surplus'        => $a['surplus'],
        'surplus_after'  => $a['surplus_after'],
        'months_to_save' => $a['months_to_save'],
        'created_at'     => $a['created_at'],
      ];
    }, $assessments);
    echo json_encode(['success' => true, 'assessments' => $clean_assessments]);
    exit;
  }

  if ($action === 'search') {
    $q = trim($_GET['q'] ?? '');
    if (!$q) { echo json_encode(['success' => true, 'results' => []]); exit; }
    $stmt = $db->prepare('
      SELECT DISTINCT s.id, s.title, m.content
      FROM sessions s
      JOIN messages m ON m.session_id = s.id
      WHERE s.user_id = ? AND s.archived = 0
      AND m.content LIKE ?
      ORDER BY m.created_at DESC
      LIMIT 20
    ');
    $stmt->execute([$user_id, '%' . $q . '%']);
    $rows = $stmt->fetchAll();
    $results = array_map(function($r) use ($q) {
      // Extract snippet around keyword
      $pos     = stripos($r['content'], $q);
      $start   = max(0, $pos - 40);
      $snippet = '...' . substr($r['content'], $start, 100) . '...';
      return [
        'session_id'   => $r['id'],
        'session_title'=> clean($r['title']),
        'snippet'      => clean($snippet),
      ];
    }, $rows);
    echo json_encode(['success' => true, 'results' => $results]);
    exit;
  }

  if ($action === 'archived_sessions') {
    $stmt = $db->prepare('SELECT id, title, created_at FROM sessions WHERE user_id = ? AND archived = 1 ORDER BY created_at DESC');
    $stmt->execute([$user_id]);
    $sessions = $stmt->fetchAll();
    $clean_sessions = array_map(function($s) {
      return ['id' => $s['id'], 'title' => clean($s['title']), 'created_at' => $s['created_at']];
    }, $sessions);
    echo json_encode(['success' => true, 'sessions' => $clean_sessions]);
    exit;
  }

  if ($action === 'projects') {
    $stmt = $db->prepare('SELECT id, name FROM projects WHERE user_id = ? ORDER BY created_at ASC');
    $stmt->execute([$user_id]);
    $projects = $stmt->fetchAll();
    $result = [];
    foreach ($projects as $p) {
      $stmt2 = $db->prepare('SELECT id, title, pinned FROM sessions WHERE user_id = ? AND project_id = ? AND archived = 0 ORDER BY pinned DESC, created_at DESC');
      $stmt2->execute([$user_id, $p['id']]);
      $sessions = $stmt2->fetchAll();
      $result[] = [
        'id'       => $p['id'],
        'name'     => clean($p['name']),
        'sessions' => array_map(function($s) {
          return ['id' => $s['id'], 'title' => clean($s['title']), 'pinned' => (bool)$s['pinned']];
        }, $sessions),
      ];
    }
    echo json_encode(['success' => true, 'projects' => $result]);
    exit;
  }

  if ($action === 'search') {
    $q = trim($_GET['q'] ?? '');
    if (!$q) { echo json_encode(['success' => true, 'results' => []]); exit; }
    $stmt = $db->prepare('
      SELECT DISTINCT s.id, s.title, m.content
      FROM sessions s
      JOIN messages m ON m.session_id = s.id
      WHERE s.user_id = ? AND s.archived = 0
      AND m.content LIKE ?
      ORDER BY m.created_at DESC
      LIMIT 20
    ');
    $stmt->execute([$user_id, '%' . $q . '%']);
    $rows = $stmt->fetchAll();
    $results = array_map(function($r) use ($q) {
      // Extract snippet around keyword
      $pos     = stripos($r['content'], $q);
      $start   = max(0, $pos - 40);
      $snippet = '...' . substr($r['content'], $start, 100) . '...';
      return [
        'session_id'   => $r['id'],
        'session_title'=> clean($r['title']),
        'snippet'      => clean($snippet),
      ];
    }, $rows);
    echo json_encode(['success' => true, 'results' => $results]);
    exit;
  }

  if ($action === 'archived_sessions') {
    $stmt = $db->prepare('SELECT id, title, created_at FROM sessions WHERE user_id = ? AND archived = 1 ORDER BY created_at DESC');
    $stmt->execute([$user_id]);
    $sessions = $stmt->fetchAll();
    $clean_sessions = array_map(function($s) {
      return ['id' => $s['id'], 'title' => clean($s['title']), 'created_at' => $s['created_at']];
    }, $sessions);
    echo json_encode(['success' => true, 'sessions' => $clean_sessions]);
    exit;
  }

  if ($action === 'savings_goals') {
    $stmt = $db->prepare('SELECT g.*, u.saved_income, u.saved_expenses FROM savings_goals g JOIN users u ON u.id = g.user_id WHERE g.user_id = ? ORDER BY g.created_at DESC');
    $stmt->execute([$user_id]);
    $goals = $stmt->fetchAll();
    $result = [];
    foreach ($goals as $g) {
      $income   = $g['custom_income']   ?? $g['saved_income'];
      $expenses = $g['custom_expenses'] ?? $g['saved_expenses'];
      $surplus  = floatval($income) - floatval($expenses);
      $remaining = max(0, floatval($g['target_amount']) - floatval($g['current_savings']));
      $now      = new DateTime();
      $deadline = new DateTime($g['deadline']);
      $diff     = $now->diff($deadline);
      $months_left = max(0, ($diff->y * 12) + $diff->m + ($diff->invert ? 0 : 0));
      if ($deadline < $now) $months_left = 0;
      $monthly_needed = $months_left > 0 ? ceil($remaining / $months_left) : $remaining;
      $pct = $g['target_amount'] > 0 ? round((floatval($g['current_savings']) / floatval($g['target_amount'])) * 100) : 0;
      $result[] = [
        'id'             => $g['id'],
        'item_name'      => clean($g['item_name']),
        'target_amount'  => floatval($g['target_amount']),
        'current_savings'=> floatval($g['current_savings']),
        'deadline'       => $g['deadline'],
        'custom_income'  => $g['custom_income'],
        'custom_expenses'=> $g['custom_expenses'],
        'income'         => floatval($income),
        'expenses'       => floatval($expenses),
        'surplus'        => round($surplus, 2),
        'remaining'      => round($remaining, 2),
        'months_left'    => $months_left,
        'monthly_needed' => round($monthly_needed, 2),
        'pct'            => $pct,
      ];
    }
    echo json_encode(['success' => true, 'goals' => $result]);
    exit;
  }

  if ($action === 'last_budget') {
    $stmt = $db->prepare('
      SELECT b.income, b.expenses, b.savings
      FROM budgets b
      JOIN sessions s ON b.session_id = s.id
      WHERE s.user_id = ?
      ORDER BY b.id DESC
      LIMIT 1
    ');
    $stmt->execute([$user_id]);
    $budget = $stmt->fetch();
    if ($budget) {
      echo json_encode(['success' => true, 'budget' => [
        'income'   => $budget['income'],
        'expenses' => $budget['expenses'],
        'savings'  => $budget['savings'],
      ]]);
    } else {
      echo json_encode(['success' => false]);
    }
    exit;
  }
}

if ($method === 'POST') {

  if ($action === 'new_session') {
    $title = clean($_POST['title'] ?? 'Budget Session');
    if (empty($title)) $title = 'Budget Session';
    $stmt = $db->prepare('INSERT INTO sessions (user_id, title) VALUES (?, ?)');
    $stmt->execute([$user_id, $title]);
    $session_id = $db->lastInsertId();
    echo json_encode(['success' => true, 'session_id' => $session_id, 'title' => $title]);
    exit;
  }

  if ($action === 'rename_session') {
    $session_id = intval($_POST['session_id'] ?? 0);
    $title      = clean($_POST['title'] ?? '');
    if (!$title || !$session_id) { echo json_encode(['success' => false, 'error' => 'Invalid data.']); exit; }
    $stmt = $db->prepare('UPDATE sessions SET title = ? WHERE id = ? AND user_id = ?');
    $stmt->execute([$title, $session_id, $user_id]);
    echo json_encode(['success' => true]);
    exit;
  }

  if ($action === 'delete_session') {
    $session_id = intval($_POST['session_id'] ?? 0);
    if (!$session_id) { echo json_encode(['success' => false, 'error' => 'Invalid session.']); exit; }
    $stmt = $db->prepare('DELETE FROM sessions WHERE id = ? AND user_id = ?');
    $stmt->execute([$session_id, $user_id]);
    echo json_encode(['success' => true]);
    exit;
  }

  if ($action === 'create_savings_goal') {
    $item_name  = clean($_POST['item_name'] ?? '');
    $target     = floatval($_POST['target_amount'] ?? 0);
    $deadline   = clean($_POST['deadline'] ?? '');
    $savings    = floatval($_POST['current_savings'] ?? 0);
    if (!$item_name || !$target || !$deadline) { echo json_encode(['success'=>false,'error'=>'Missing fields']); exit; }
    $stmt = $db->prepare('INSERT INTO savings_goals (user_id, item_name, target_amount, deadline, current_savings) VALUES (?,?,?,?,?)');
    $stmt->execute([$user_id, $item_name, $target, $deadline, $savings]);
    echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
    exit;
  }

  if ($action === 'update_savings_goal') {
    $goal_id  = intval($_POST['goal_id'] ?? 0);
    $savings  = floatval($_POST['current_savings'] ?? 0);
    $income   = isset($_POST['custom_income'])   && $_POST['custom_income']   !== '' ? floatval($_POST['custom_income'])   : null;
    $expenses = isset($_POST['custom_expenses'])  && $_POST['custom_expenses'] !== '' ? floatval($_POST['custom_expenses']) : null;
    if (!$goal_id) { echo json_encode(['success'=>false]); exit; }
    $stmt = $db->prepare('UPDATE savings_goals SET current_savings=?, custom_income=?, custom_expenses=? WHERE id=? AND user_id=?');
    $stmt->execute([$savings, $income, $expenses, $goal_id, $user_id]);
    echo json_encode(['success' => true]);
    exit;
  }

  if ($action === 'delete_savings_goal') {
    $goal_id = intval($_POST['goal_id'] ?? 0);
    if (!$goal_id) { echo json_encode(['success'=>false]); exit; }
    $stmt = $db->prepare('DELETE FROM savings_goals WHERE id=? AND user_id=?');
    $stmt->execute([$goal_id, $user_id]);
    echo json_encode(['success' => true]);
    exit;
  }

  if ($action === 'create_project') {
    $name = clean($_POST['name'] ?? '');
    if (!$name) { echo json_encode(['success' => false, 'error' => 'Name required.']); exit; }
    $stmt = $db->prepare('INSERT INTO projects (user_id, name) VALUES (?, ?)');
    $stmt->execute([$user_id, $name]);
    echo json_encode(['success' => true, 'project_id' => $db->lastInsertId(), 'name' => $name]);
    exit;
  }

  if ($action === 'rename_project') {
    $project_id = intval($_POST['project_id'] ?? 0);
    $name       = clean($_POST['name'] ?? '');
    if (!$project_id || !$name) { echo json_encode(['success' => false]); exit; }
    $stmt = $db->prepare('UPDATE projects SET name = ? WHERE id = ? AND user_id = ?');
    $stmt->execute([$name, $project_id, $user_id]);
    echo json_encode(['success' => true]);
    exit;
  }

  if ($action === 'delete_project') {
    $project_id = intval($_POST['project_id'] ?? 0);
    if (!$project_id) { echo json_encode(['success' => false]); exit; }
    $stmt = $db->prepare('UPDATE sessions SET project_id = NULL WHERE project_id = ? AND user_id = ?');
    $stmt->execute([$project_id, $user_id]);
    $stmt = $db->prepare('DELETE FROM projects WHERE id = ? AND user_id = ?');
    $stmt->execute([$project_id, $user_id]);
    echo json_encode(['success' => true]);
    exit;
  }

  if ($action === 'assign_project') {
    $session_id = intval($_POST['session_id'] ?? 0);
    $project_id = ($_POST['project_id'] === 'null' || $_POST['project_id'] === '') ? null : intval($_POST['project_id']);
    if (!$session_id) { echo json_encode(['success' => false]); exit; }
    $stmt = $db->prepare('UPDATE sessions SET project_id = ? WHERE id = ? AND user_id = ?');
    $stmt->execute([$project_id, $session_id, $user_id]);
    echo json_encode(['success' => true]);
    exit;
  }

  if ($action === 'unarchive_session') {
    $session_id = intval($_POST['session_id'] ?? 0);
    if (!$session_id) { echo json_encode(['success' => false]); exit; }
    $stmt = $db->prepare('UPDATE sessions SET archived = 0 WHERE id = ? AND user_id = ?');
    $stmt->execute([$session_id, $user_id]);
    echo json_encode(['success' => true]);
    exit;
  }

  if ($action === 'pin_session') {
    $session_id = intval($_POST['session_id'] ?? 0);
    $pinned     = intval($_POST['pinned'] ?? 0);
    if (!$session_id) { echo json_encode(['success' => false]); exit; }
    $stmt = $db->prepare('UPDATE sessions SET pinned = ? WHERE id = ? AND user_id = ?');
    $stmt->execute([$pinned, $session_id, $user_id]);
    echo json_encode(['success' => true]);
    exit;
  }

  if ($action === 'archive_session') {
    $session_id = intval($_POST['session_id'] ?? 0);
    if (!$session_id) { echo json_encode(['success' => false]); exit; }
    $stmt = $db->prepare('UPDATE sessions SET archived = 1 WHERE id = ? AND user_id = ?');
    $stmt->execute([$session_id, $user_id]);
    echo json_encode(['success' => true]);
    exit;
  }

  if ($action === 'duplicate_session') {
    $session_id = intval($_POST['session_id'] ?? 0);
    if (!$session_id) { echo json_encode(['success' => false, 'error' => 'Invalid session.']); exit; }
    // Verify ownership
    $stmt = $db->prepare('SELECT title FROM sessions WHERE id = ? AND user_id = ?');
    $stmt->execute([$session_id, $user_id]);
    $original = $stmt->fetch();
    if (!$original) { echo json_encode(['success' => false, 'error' => 'Session not found.']); exit; }
    // Create new session
    $new_title = $original['title'] . ' (copy)';
    $stmt = $db->prepare('INSERT INTO sessions (user_id, title) VALUES (?, ?)');
    $stmt->execute([$user_id, $new_title]);
    $new_session_id = $db->lastInsertId();
    // Copy messages
    $stmt = $db->prepare('SELECT role, content, calculation FROM messages WHERE session_id = ? ORDER BY created_at ASC');
    $stmt->execute([$session_id]);
    $messages = $stmt->fetchAll();
    $insert = $db->prepare('INSERT INTO messages (session_id, role, content, calculation) VALUES (?, ?, ?, ?)');
    foreach ($messages as $msg) {
      $insert->execute([$new_session_id, $msg['role'], $msg['content'], $msg['calculation']]);
    }
    echo json_encode(['success' => true, 'session_id' => $new_session_id, 'title' => $new_title]);
    exit;
  }
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Invalid request.']);