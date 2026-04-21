<?php
require_once '../config/db.php';

header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$message = trim($_POST['message'] ?? '');
$session_id = intval($_POST['session_id'] ?? 0);

if (empty($message)) {
    echo json_encode(['success' => false, 'error' => 'Message cannot be empty.']);
    exit;
}

if ($session_id === 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid session.']);
    exit;
}

$db = getDB();

// ── Save user message to database ─────────────────────────
$stmt = $db->prepare(
    'INSERT INTO messages (session_id, role, content) VALUES (?, ?, ?)'
);
$stmt->execute([$session_id, 'user', $message]);

// ── Parse message for financial data ─────────────────────
// Look for income, expenses, savings, item name and price
$income     = floatval($_POST['income'] ?? 0);
$expenses   = floatval($_POST['expenses'] ?? 0);
$savings    = floatval($_POST['savings'] ?? 0);
$item_name  = trim($_POST['item_name'] ?? '');
$item_price = floatval($_POST['item_price'] ?? 0);
$item_type  = $_POST['item_type'] ?? 'one-time';

$calculation_result = null;
$ai_explanation     = null;

// ── Run calculation if we have enough data ────────────────
if ($income > 0 && $expenses >= 0 && $item_price > 0) {
    $calc_data = [
        'income'     => $income,
        'expenses'   => $expenses,
        'savings'    => $savings,
        'item_name'  => $item_name,
        'item_price' => $item_price,
        'item_type'  => $item_type,
        'session_id' => $session_id,
    ];

    // Call calculate.php logic directly
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

    $proj_1 = round($savings + $surplus, 2);
    $proj_2 = round($savings + ($surplus * 2), 2);
    $proj_3 = round($savings + ($surplus * 3), 2);

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

    $health_score = 100;
    if ($expense_ratio > 0.9)         $health_score -= 40;
    elseif ($expense_ratio > 0.7)     $health_score -= 20;
    elseif ($expense_ratio > 0.5)     $health_score -= 10;
    if ($savings < $income)           $health_score -= 20;
    if ($surplus <= 0)                $health_score -= 30;
    if ($risk_level === 'red')        $health_score -= 20;
    elseif ($risk_level === 'yellow') $health_score -= 10;
    $health_score = max(0, min(100, $health_score));

    // Save to database
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
    $stmt->execute([$user_id, $health_score, 'stable']);

    $calculation_result = [
        'surplus'        => round($surplus, 2),
        'surplus_after'  => round($surplus_after, 2),
        'months_to_save' => $months_to_save,
        'risk_level'     => $risk_level,
        'health_score'   => $health_score,
        'projections'    => [
            'month_1' => $proj_1,
            'month_2' => $proj_2,
            'month_3' => $proj_3,
        ]
    ];

    // ── Call AI for explanation ───────────────────────────
    $ai_context = "User monthly income: £{$income}. Monthly expenses: £{$expenses}. "
                . "Savings: £{$savings}. They want to buy: {$item_name} costing £{$item_price} ({$item_type}). "
                . "Monthly surplus: £{$surplus}. Risk level: {$risk_level}. "
                . "Months to save: {$months_to_save}.";

    require_once 'ai.php';
    $ai_explanation = getAIExplanation($ai_context, $risk_level);
}

// ── Build bot response message ────────────────────────────
if ($calculation_result) {
    $risk      = $calculation_result['risk_level'];
    $emoji     = $risk === 'green' ? 'Good news' : ($risk === 'yellow' ? 'Heads up' : 'Warning');
    $bot_reply = "{$emoji} — here is your affordability assessment for {$item_name}.";
    if ($ai_explanation) {
        $bot_reply .= ' ' . $ai_explanation;
    }
} else {
    $bot_reply = "Please provide your monthly income, expenses, savings, and the item you want to check.";
}

// ── Save bot reply to database ────────────────────────────
$stmt = $db->prepare(
    'INSERT INTO messages (session_id, role, content) VALUES (?, ?, ?)'
);
$stmt->execute([$session_id, 'bot', $bot_reply]);

// ── Return full response ──────────────────────────────────
echo json_encode([
    'success'     => true,
    'bot_reply'   => $bot_reply,
    'calculation' => $calculation_result,
]);