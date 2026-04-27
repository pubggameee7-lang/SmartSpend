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

// ── Dynamic savings projection ────────────────────────────
$projections = [];

if ($months_to_save <= 3) {
  for ($i = 1; $i <= 3; $i++) {
    $projections['month_' . $i] = round($savings + ($surplus * $i), 2);
  }
} elseif ($months_to_save <= 12) {
  for ($i = 1; $i <= $months_to_save; $i++) {
    $projections['month_' . $i] = round($savings + ($surplus * $i), 2);
  }
} else {
  $years            = floor($months_to_save / 12);
  $remaining_months = $months_to_save % 12;
  $projections['summary'] = "Approximately {$years} year" . ($years > 1 ? 's' : '') .
    ($remaining_months > 0 ? " and {$remaining_months} month" . ($remaining_months > 1 ? 's' : '') : '');
}

// ── Risk scoring ──────────────────────────────────────────
$expense_ratio = ($income > 0) ? ($expenses / $income) : 1;

if ($item_type === 'recurring') {
  if ($surplus_after >= $surplus * 0.4 && $expense_ratio < 0.7)  $risk_level = 'green';
  elseif ($surplus_after >= 0 && $expense_ratio < 0.85)          $risk_level = 'yellow';
  else                                                             $risk_level = 'red';
} else {
  if ($savings >= $item_price && $surplus > 0)                    $risk_level = 'green';
  elseif ($surplus > 0 && $months_to_save <= 12)                  $risk_level = 'yellow';
  else                                                             $risk_level = 'red';
}

// ── Health score (0-100) ──────────────────────────────────
$health_score = 100;
if ($expense_ratio > 0.9)         $health_score -= 40;
elseif ($expense_ratio > 0.7)     $health_score -= 20;
elseif ($expense_ratio > 0.5)     $health_score -= 10;
if ($savings < $income)           $health_score -= 20;
if ($surplus <= 0)                $health_score -= 30;
if ($risk_level === 'red')        $health_score -= 20;
elseif ($risk_level === 'yellow') $health_score -= 10;
$health_score = max(0, min(100, $health_score));

// ── Actionable suggestion ─────────────────────────────────
if ($risk_level === 'red' && $surplus > 0) {
  $needed        = $item_price - $savings;
  $cut_per_month = round($needed / 6, 2);
  $suggestion    = "To afford this in 6 months you would need to save an extra £{$cut_per_month} per month.";
} elseif ($risk_level === 'yellow') {
  $monthly    = round($item_price / max($months_to_save, 1), 2);
  $suggestion = "You are on track - consider setting aside £{$monthly} per month specifically for this.";
} else {
  $ef         = round($expenses * 3, 2);
  $suggestion = "You are in a strong position. Consider keeping at least 3 months of expenses (£{$ef}) as an emergency fund.";
}

// ── Save to database ──────────────────────────────────────
$db = getDB();

$stmt = $db->prepare(
  'INSERT INTO budgets (session_id, income, expenses, savings) VALUES (?, ?, ?, ?)'
);
$stmt->execute([$session_id, $income, $expenses, $savings]);

$stmt = $db->prepare(
  'INSERT INTO assessments
   (session_id, item_name, item_price, item_type, risk_level, surplus, surplus_after, months_to_save)
   VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([
  $session_id, $item_name, $item_price, $item_type,
  $risk_level, $surplus, $surplus_after, $months_to_save
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
  'surplus'        => round($surplus, 2),
  'surplus_after'  => round($surplus_after, 2),
  'months_to_save' => $months_to_save,
  'risk_level'     => $risk_level,
  'health_score'   => $health_score,
  'item_name'      => $item_name,
  'item_price'     => $item_price,
  'item_type'      => $item_type,
  'suggestion'     => $suggestion,
  'projections'    => $projections,
]);