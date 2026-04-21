<?php
require_once '../config/db.php';

header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in.']);
    exit;
}

$income     = floatval($_POST['income'] ?? 0);
$expenses   = floatval($_POST['expenses'] ?? 0);
$savings    = floatval($_POST['savings'] ?? 0);
$item_name  = trim($_POST['item_name'] ?? '');
$item_price = floatval($_POST['item_price'] ?? 0);
$item_type  = $_POST['item_type'] ?? 'one-time';
$session_id = intval($_POST['session_id'] ?? 0);

if ($income <= 0) {
    echo json_encode(['success' => false, 'error' => 'Please provide a valid monthly income.']);
    exit;
}

if ($expenses < 0) {
    echo json_encode(['success' => false, 'error' => 'Expenses cannot be negative.']);
    exit;
}

if ($item_price <= 0) {
    echo json_encode(['success' => false, 'error' => 'Please provide a valid item price.']);
    exit;
}

if (!in_array($item_type, ['one-time', 'recurring'])) {
    $item_type = 'one-time';
}

// ── Core calculations ─────────────────────────────────────
$surplus = $income - $expenses;

if ($item_type === 'recurring') {
    $surplus_after  = $surplus - $item_price;
    $months_to_save = 0;
} else {
    $surplus_after  = $surplus;
    $months_to_save = ($item_price > $savings && $surplus > 0)
        ? (int) ceil(($item_price - $savings) / $surplus)
        : 0;
}

// ── 3-month savings projection ────────────────────────────
$proj_1 = $savings + $surplus;
$proj_2 = $savings + ($surplus * 2);
$proj_3 = $savings + ($surplus * 3);

// ── Risk scoring ──────────────────────────────────────────
$expense_ratio = ($income > 0) ? ($expenses / $income) : 1;

if ($item_type === 'recurring') {
    if ($surplus_after >= $surplus * 0.4 && $expense_ratio < 0.7) {
        $risk_level = 'green';
    } elseif ($surplus_after >= 0 && $expense_ratio < 0.85) {
        $risk_level = 'yellow';
    } else {
        $risk_level = 'red';
    }
} else {
    if ($savings >= $item_price && $surplus > 0) {
        $risk_level = 'green';
    } elseif ($months_to_save <= 3 && $surplus > 0) {
        $risk_level = 'yellow';
    } else {
        $risk_level = 'red';
    }
}

// ── Health score (0-100) ──────────────────────────────────
$health_score = 100;

if ($expense_ratio > 0.9)       $health_score -= 40;
elseif ($expense_ratio > 0.7)   $health_score -= 20;
elseif ($expense_ratio > 0.5)   $health_score -= 10;

if ($savings < $income)         $health_score -= 20;
if ($surplus <= 0)              $health_score -= 30;
if ($risk_level === 'red')      $health_score -= 20;
elseif ($risk_level === 'yellow') $health_score -= 10;

$health_score = max(0, min(100, $health_score));

// ── Save budget and assessment to database ────────────────
$db = getDB();

$stmt = $db->prepare(
    'INSERT INTO budgets (session_id, income, expenses, savings)
     VALUES (?, ?, ?, ?)'
);
$stmt->execute([$session_id, $income, $expenses, $savings]);

$stmt = $db->prepare(
    'INSERT INTO assessments
     (session_id, item_name, item_price, item_type, risk_level, surplus, surplus_after, months_to_save)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([
    $session_id,
    $item_name,
    $item_price,
    $item_type,
    $risk_level,
    $surplus,
    $surplus_after,
    $months_to_save
]);

$stmt = $db->prepare(
    'INSERT INTO health_scores (user_id, score, trend) VALUES (?, ?, ?)'
);
$stmt->execute([$_SESSION['user_id'], $health_score, 'stable']);

// ── Return result ─────────────────────────────────────────
echo json_encode([
    'success'        => true,
    'income'         => $income,
    'expenses'       => $expenses,
    'savings'        => $savings,
    'surplus'        => $surplus,
    'surplus_after'  => $surplus_after,
    'months_to_save' => $months_to_save,
    'risk_level'     => $risk_level,
    'health_score'   => $health_score,
    'item_name'      => $item_name,
    'item_price'     => $item_price,
    'item_type'      => $item_type,
    'projections'    => [
        'month_1' => round($proj_1, 2),
        'month_2' => round($proj_2, 2),
        'month_3' => round($proj_3, 2),
    ]
]);