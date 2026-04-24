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

$user_id    = $_SESSION['user_id'];
$message    = trim($_POST['message'] ?? '');
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

// ── Save user message ─────────────────────────────────────
$stmt = $db->prepare(
  'INSERT INTO messages (session_id, role, content) VALUES (?, ?, ?)'
);
$stmt->execute([$session_id, 'user', $message]);

// ── Load conversation state from database ─────────────────
$stmt = $db->prepare(
  'SELECT state FROM conversation_state WHERE session_id = ?'
);
$stmt->execute([$session_id]);
$row = $stmt->fetch();

$state = $row ? json_decode($row['state'], true) : [
  'step'                  => 'greeting',
  'income'                => null,
  'expenses'              => null,
  'savings'               => null,
  'checks'                => [],
  'pending_item'          => null,
  'emergency_fund_warned' => false,
];

// ── Helper: extract number (handles k/K shorthand) ────────
function extractNumber(string $msg): ?float {
  $msg = str_replace([',', '£', '$', '€', '%'], '', $msg);
  if (preg_match('/(\d+(\.\d{1,2})?)\s*k/i', $msg, $km)) {
    return floatval($km[1]) * 1000;
  }
  preg_match('/\d+(\.\d{1,2})?/', $msg, $matches);
  return isset($matches[0]) ? floatval($matches[0]) : null;
}

// ── Helper: detect recurring ──────────────────────────────
function isRecurring(string $msg): bool {
  return (bool) preg_match('/per month|monthly|\/mo|subscription|recurring/i', $msg);
}

// ── Helper: clean item name ───────────────────────────────
function extractItemName(string $msg): string {
  $name = preg_replace('/£?[\d,]+(\.\d{1,2})?\s*k?/i', '', $msg);
  $name = preg_replace('/\b(a|an|the|for|at|to|buy|buying|get|want|need|afford|per|month|monthly|costs?|priced?|is|was|id like|i want|can i|could i|how about|purchase)\b/i', '', $name);
  $name = trim(preg_replace('/\s+/', ' ', $name));
  return $name ?: 'item';
}

// ── Helper: calculate affordability ──────────────────────
function calculate(float $income, float $expenses, float $savings, float $item_price, string $item_type): array {
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

  // ── Dynamic savings projection ────────────────────────
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

  $expense_ratio = ($income > 0) ? ($expenses / $income) : 1;

  if ($item_type === 'recurring') {
    if ($surplus_after >= $surplus * 0.4 && $expense_ratio < 0.7)  $risk = 'green';
    elseif ($surplus_after >= 0 && $expense_ratio < 0.85)          $risk = 'yellow';
    else                                                             $risk = 'red';
  } else {
    if ($savings >= $item_price && $surplus > 0)                    $risk = 'green';
    elseif ($surplus > 0 && $months_to_save <= 12)                  $risk = 'yellow';
    else                                                             $risk = 'red';
  }

  $health = 100;
  if ($expense_ratio > 0.9)       $health -= 40;
  elseif ($expense_ratio > 0.7)   $health -= 20;
  elseif ($expense_ratio > 0.5)   $health -= 10;
  if ($savings < $income)         $health -= 20;
  if ($surplus <= 0)              $health -= 30;
  if ($risk === 'red')            $health -= 20;
  elseif ($risk === 'yellow')     $health -= 10;
  $health = max(0, min(100, $health));

  if ($risk === 'red' && $surplus > 0) {
    $needed        = $item_price - $savings;
    $cut_per_month = round($needed / 6, 2);
    $suggestion    = "To afford this in 6 months you would need to save an extra £{$cut_per_month} per month.";
  } elseif ($risk === 'yellow') {
    $monthly    = round($item_price / max($months_to_save, 1), 2);
    $suggestion = "You are on track — consider setting aside £{$monthly} per month specifically for this.";
  } else {
    $ef         = round($expenses * 3, 2);
    $suggestion = "You are in a strong position. Consider keeping at least 3 months of expenses (£{$ef}) as an emergency fund.";
  }

  return [
    'surplus'        => round($surplus, 2),
    'surplus_after'  => round($surplus_after, 2),
    'months_to_save' => $months_to_save,
    'risk_level'     => $risk,
    'health_score'   => $health,
    'expense_ratio'  => round($expense_ratio * 100, 1),
    'suggestion'     => $suggestion,
    'projections'    => $projections,
  ];
}

// ── Helper: emergency fund warning ───────────────────────
function emergencyFundWarning(float $savings, float $expenses): ?string {
  $recommended = $expenses * 3;
  if ($savings < $recommended) {
    return "I also noticed your savings of £" . number_format($savings, 2) . " are below the recommended 3-month emergency fund of £" . number_format($recommended, 2) . ". It is worth building this up before making large purchases.";
  }
  return null;
}

// ── Helper: save result to DB ─────────────────────────────
function saveResult(PDO $db, int $session_id, int $user_id, array $state, string $item_name, float $item_price, string $item_type, array $calc): void {
  $stmt = $db->prepare(
    'INSERT INTO budgets (session_id, income, expenses, savings) VALUES (?, ?, ?, ?)'
  );
  $stmt->execute([$session_id, $state['income'], $state['expenses'], $state['savings']]);

  $stmt = $db->prepare(
    'INSERT INTO assessments
     (session_id, item_name, item_price, item_type, risk_level, surplus, surplus_after, months_to_save)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
  );
  $stmt->execute([
    $session_id, $item_name, $item_price, $item_type,
    $calc['risk_level'], $calc['surplus'], $calc['surplus_after'], $calc['months_to_save']
  ]);

  $stmt = $db->prepare(
    'INSERT INTO health_scores (user_id, score, trend) VALUES (?, ?, ?)'
  );
  $stmt->execute([$user_id, $calc['health_score'], 'stable']);
}

// ── Helper: run full calculation and build response ───────
function runCalculation(PDO $db, int $session_id, int $user_id, array &$state, string $message, float $price): array {
  $item_name = isset($state['pending_item']) && $state['pending_item']
    ? $state['pending_item']
    : extractItemName($message);

  $item_type = isRecurring($message) ? 'recurring' : 'one-time';
  $calc      = calculate($state['income'], $state['expenses'], $state['savings'], $price, $item_type);

  saveResult($db, $session_id, $user_id, $state, $item_name, $price, $item_type, $calc);

  $state['checks'][]     = [
    'item_name'  => $item_name,
    'item_price' => $price,
    'item_type'  => $item_type,
    'risk_level' => $calc['risk_level'],
    'calc'       => $calc,
  ];
  $state['pending_item'] = null;
  $state['step']         = 'followup';

  require_once 'ai.php';
  $ai_context = "Monthly income: £{$state['income']}. Expenses: £{$state['expenses']}. "
              . "Savings: £{$state['savings']}. Item: {$item_name} at £{$price} ({$item_type}). "
              . "Surplus: £{$calc['surplus']}. Risk: {$calc['risk_level']}. "
              . "Months to save: {$calc['months_to_save']}. Suggestion: {$calc['suggestion']}.";
  $ai_text = getAIExplanation($ai_context, $calc['risk_level']);

  $risk_label = $calc['risk_level'] === 'green' ? 'Good news'
              : ($calc['risk_level'] === 'yellow' ? 'Heads up' : 'Warning');

  $bot_reply = "{$risk_label} — here is your result for {$item_name}.\n\n{$ai_text}";

  $calculation = array_merge($calc, [
    'item_name'  => $item_name,
    'item_price' => $price,
    'item_type'  => $item_type,
  ]);

  return [$bot_reply, $calculation];
}

// ── Conversation flow ─────────────────────────────────────
$bot_reply     = '';
$calculation   = null;
$quick_replies = [];
$lower         = strtolower(trim($message));

// ── Global: Reset ─────────────────────────────────────────
if ($lower === 'reset' || $lower === 'start over' || $lower === 'restart') {
  $state = [
    'step'                  => 'income',
    'income'                => null,
    'expenses'              => null,
    'savings'               => null,
    'checks'                => [],
    'pending_item'          => null,
    'emergency_fund_warned' => false,
  ];
  $bot_reply     = "No problem — let's start fresh. What is your monthly income after tax?";
  $quick_replies = ['£1500', '£2000', '£2500', '£3000', 'Other'];
  $stmt = $db->prepare('INSERT INTO conversation_state (session_id, state) VALUES (?, ?) ON DUPLICATE KEY UPDATE state = VALUES(state), updated_at = NOW()');
  $stmt->execute([$session_id, json_encode($state)]);
  $stmt = $db->prepare('INSERT INTO messages (session_id, role, content, calculation) VALUES (?, ?, ?, ?)');
  $stmt->execute([$session_id, 'bot', $bot_reply, null]);
  echo json_encode(['success' => true, 'bot_reply' => $bot_reply, 'calculation' => null, 'quick_replies' => $quick_replies, 'step' => $state['step']]);
  exit;
}

// ── Global: Other button ──────────────────────────────────
if ($lower === 'other') {
  $bot_reply = match($state['step']) {
    'income'      => 'Please type your monthly income as a number.',
    'expenses'    => 'Please type your total monthly expenses as a number.',
    'savings'     => 'Please type your current savings as a number. Type 0 if you have none.',
    'item'        => 'What would you like to buy and how much does it cost?',
    'followup'    => 'What would you like to do next? You can check another item, run a stress test, or reset.',
    'stress_test' => 'What percentage drop would you like to simulate? For example: 20',
    default       => 'Please type your response.',
  };
  $quick_replies = [];
  $stmt = $db->prepare('INSERT INTO conversation_state (session_id, state) VALUES (?, ?) ON DUPLICATE KEY UPDATE state = VALUES(state), updated_at = NOW()');
  $stmt->execute([$session_id, json_encode($state)]);
  $stmt = $db->prepare('INSERT INTO messages (session_id, role, content, calculation) VALUES (?, ?, ?, ?)');
  $stmt->execute([$session_id, 'bot', $bot_reply, null]);
  echo json_encode(['success' => true, 'bot_reply' => $bot_reply, 'calculation' => null, 'quick_replies' => [], 'step' => $state['step']]);
  exit;
}

// ── Global: Escape stress test ────────────────────────────
if ($state['step'] === 'stress_test' && !preg_match('/\d|total|loss|drop/i', $message)) {
  $state['step'] = 'item';
  $bot_reply     = "No problem — let's get back to checking affordability. What would you like to buy and how much does it cost?";
  $quick_replies = ['A laptop £800', 'A phone £600', 'A car £10k', 'Other'];
  $stmt = $db->prepare('INSERT INTO conversation_state (session_id, state) VALUES (?, ?) ON DUPLICATE KEY UPDATE state = VALUES(state), updated_at = NOW()');
  $stmt->execute([$session_id, json_encode($state)]);
  $stmt = $db->prepare('INSERT INTO messages (session_id, role, content, calculation) VALUES (?, ?, ?, ?)');
  $stmt->execute([$session_id, 'bot', $bot_reply, null]);
  echo json_encode(['success' => true, 'bot_reply' => $bot_reply, 'calculation' => null, 'quick_replies' => $quick_replies, 'step' => $state['step']]);
  exit;
}

switch ($state['step']) {

  case 'greeting':
    if (preg_match('/hi|hello|hey|start|help|afford|budget|check|buy|can i/i', $message)) {
      $state['step'] = 'income';
      $bot_reply     = "Hello! I am SmartSpend, your personal budget assistant. I can help you work out if you can afford something, check your financial health, and give you personalised suggestions.\n\nTo get started — what is your monthly income after tax?";
      $quick_replies = ['£1500', '£2000', '£2500', '£3000', 'Other'];
    } else {
      $bot_reply     = "Hi there! I am SmartSpend. I can help you figure out if you can afford something, check your budget health, or run a stress test on your finances. What would you like to do?";
      $quick_replies = ['Check if I can afford something', 'Check my budget health', 'Run a stress test', 'Other'];
    }
    break;

  case 'income':
    $num = extractNumber($message);
    if ($num && $num > 0) {
      $state['income'] = $num;
      $state['step']   = 'expenses';
      $bot_reply       = "Got it — monthly income of £" . number_format($num, 2) . ". Now, what are your total monthly expenses? Include rent, food, bills, transport and subscriptions.";
      $quick_replies   = ['£500', '£800', '£1200', '£1500', 'Other'];
    } else {
      $bot_reply     = "What is your monthly income after tax? Just type the number — for example 2500 or 2.5k.";
      $quick_replies = ['£1500', '£2000', '£2500', '£3000', 'Other'];
    }
    break;

  case 'expenses':
    $num = extractNumber($message);
    if ($num !== null && $num >= 0) {
      $state['expenses'] = $num;
      $state['step']     = 'savings';
      if ($num >= $state['income']) {
        $bot_reply = "Your expenses of £" . number_format($num, 2) . " are equal to or higher than your income — you have no monthly surplus. How much do you currently have saved?";
      } else {
        $surplus   = $state['income'] - $num;
        $bot_reply = "Monthly expenses of £" . number_format($num, 2) . " — that leaves you a surplus of £" . number_format($surplus, 2) . " per month. How much do you currently have saved?";
      }
      $quick_replies = ['£0', '£500', '£1000', '£2000', 'Other'];
    } else {
      $bot_reply     = "Please enter your total monthly expenses as a number — for example 1200 or 1.2k.";
      $quick_replies = ['£500', '£800', '£1200', '£1500', 'Other'];
    }
    break;

  case 'savings':
    $num = extractNumber($message);
    if ($num !== null && $num >= 0) {
      $state['savings'] = $num;
      $state['step']    = 'item';
      $ef_warning       = emergencyFundWarning($num, $state['expenses']);
      $ef_note          = $ef_warning ? "\n\n" . $ef_warning : '';
      $state['emergency_fund_warned'] = (bool) $ef_warning;
      $bot_reply     = "Savings of £" . number_format($num, 2) . " noted.{$ef_note}\n\nWhat would you like to buy or check? Tell me the item and the price.";
      $quick_replies = ['A laptop £800', 'A phone £600', 'A car £10k', 'Netflix £18 per month', 'Other'];
    } else {
      $bot_reply     = "Please enter your savings as a number. Type 0 if you have none.";
      $quick_replies = ['£0', '£500', '£1000', 'Other'];
    }
    break;

  case 'item':
    if (preg_match('/stress|lost.*job|what if.*lost|redundan/i', $message)) {
      $state['step'] = 'stress_test';
      $bot_reply     = "Let's run a stress test. What percentage drop in income would you like to simulate?";
      $quick_replies = ['20% drop', '50% drop', 'Total loss', 'Other'];
      break;
    }

    if (count($state['checks']) > 0 && preg_match('/compare|vs|versus|better|cheaper|which/i', $message)) {
      $last      = end($state['checks']);
      $bot_reply = "Your last check was {$last['item_name']} at £{$last['item_price']} with a {$last['risk_level']} risk. What would you like to compare it with?";
      $quick_replies = ['A laptop £800', 'A phone £600', 'Other'];
      break;
    }

    $price = extractNumber($message);

    if (!$price && isset($state['pending_item']) && $state['pending_item']) {
      $bot_reply     = "How much does the {$state['pending_item']} cost? Just type the price — for example 5000 or 5k.";
      $quick_replies = ['£1000', '£5000', '£10000', '£20000', 'Other'];
      break;
    }

    if ($price && $price > 0) {
      [$bot_reply, $calculation] = runCalculation($db, $session_id, $user_id, $state, $message, $price);
      $quick_replies = ['Check another item', 'Compare with something else', 'Run a stress test', 'Reset budget'];
    } else {
      $possible_item = preg_replace('/\b(a|an|the|buy|want|need|afford|get|id like|i want|can i|could i|how about)\b/i', '', $message);
      $possible_item = trim(preg_replace('/\s+/', ' ', $possible_item));
      if (strlen($possible_item) > 2) {
        $state['pending_item'] = $possible_item;
        $bot_reply             = "How much does the {$possible_item} cost?";
        $quick_replies         = ['£500', '£1000', '£5000', '£10000', 'Other'];
      } else {
        $bot_reply     = "What would you like to buy and how much does it cost? For example: a laptop for £800, or a car for £10k.";
        $quick_replies = ['A laptop £800', 'A phone £600', 'A car £10k', 'Netflix £18 per month', 'Other'];
      }
    }
    break;

  case 'followup':
    $price = extractNumber($message);
    if ($price && $price > 0 && isset($state['pending_item']) && $state['pending_item']) {
      [$bot_reply, $calculation] = runCalculation($db, $session_id, $user_id, $state, $message, $price);
      $quick_replies = ['Check another item', 'Compare with something else', 'Run a stress test', 'Reset budget'];
      break;
    }

    if (preg_match('/another|check|buy|afford|else|more/i', $message)) {
      $state['step'] = 'item';
      $bot_reply     = "Sure — your income, expenses and savings are still saved. What else would you like to check?";
      $quick_replies = ['A laptop £800', 'A phone £600', 'A car £10k', 'Other'];

    } elseif (preg_match('/compare|vs|versus|which|better/i', $message)) {
      if (count($state['checks']) > 0) {
        $last          = end($state['checks']);
        $bot_reply     = "Your last check was {$last['item_name']} at £{$last['item_price']} with a {$last['risk_level']} risk. What would you like to compare it with?";
        $state['step'] = 'item';
      } else {
        $bot_reply     = "Tell me the first item and price and I will help you compare.";
        $state['step'] = 'item';
      }
      $quick_replies = ['A laptop £800', 'A phone £600', 'Other'];

    } elseif (preg_match('/stress|lost.*job|what if|redundan|unemployed/i', $message)) {
      $state['step'] = 'stress_test';
      $bot_reply     = "Let's run a stress test. What percentage drop in income are you worried about?";
      $quick_replies = ['20% drop', '50% drop', 'Total loss', 'Other'];

    } elseif (preg_match('/cut|reduce|save more|netflix|subscription/i', $message)) {
      $cut_amount = extractNumber($message);
      if ($cut_amount && $state['income'] && $state['expenses']) {
        $new_expenses = max(0, $state['expenses'] - $cut_amount);
        $new_surplus  = $state['income'] - $new_expenses;
        $bot_reply    = "If you cut £" . number_format($cut_amount, 2) . " from your expenses, your new monthly surplus would be £" . number_format($new_surplus, 2) . ". Would you like to re-run an affordability check with these updated figures?";
        $quick_replies = ['Yes recalculate', 'No thanks', 'Other'];
      } else {
        $bot_reply     = "How much are you thinking of cutting from your expenses?";
        $quick_replies = ['£50', '£100', '£200', 'Other'];
      }

    } elseif (preg_match('/yes.*recalculate|recalculate/i', $message)) {
      $state['step'] = 'item';
      $bot_reply     = "Great — what would you like to check with the updated figures?";
      $quick_replies = ['A laptop £800', 'A phone £600', 'Other'];

    } else {
      $bot_reply     = "Would you like to check another item, compare two items, run a stress test, or start over?";
      $quick_replies = ['Check another item', 'Run a stress test', 'Reset budget', 'Other'];
    }
    break;

  case 'stress_test':
    $pct        = extractNumber($message);
    $total_loss = (bool) preg_match('/total|100|all|no income|zero/i', $message);

    if ($total_loss) {
      $new_income = 0;
    } elseif ($pct && $pct > 0) {
      $new_income = $state['income'] * (1 - ($pct / 100));
    } else {
      $bot_reply     = "What percentage drop would you like to simulate? For example: 20%, 50%, or total loss.";
      $quick_replies = ['20% drop', '50% drop', 'Total loss', 'Other'];
      break;
    }

    $new_surplus   = $new_income - $state['expenses'];
    $months_runway = ($state['savings'] > 0 && $state['expenses'] > 0)
      ? round($state['savings'] / $state['expenses'], 1)
      : 0;

    $drop_label = $total_loss ? 'a total loss of income' : "a {$pct}% drop in income";

    if ($new_surplus >= 0) {
      $bot_reply = "Stress test result — with {$drop_label}, your income would be £" . number_format($new_income, 2) . " and your monthly surplus would still be £" . number_format($new_surplus, 2) . ". Your finances are resilient.";
    } else {
      $shortfall = abs($new_surplus);
      $bot_reply = "Stress test result — with {$drop_label}, your income would be £" . number_format($new_income, 2) . " and you would have a monthly shortfall of £" . number_format($shortfall, 2) . ". Your savings of £" . number_format($state['savings'], 2) . " would last approximately {$months_runway} months.";
    }

    $state['step'] = 'followup';
    $quick_replies = ['Check another item', 'Reset budget', 'Run another stress test', 'Other'];
    break;

  default:
    $state['step'] = 'greeting';
    $bot_reply     = "Hi! I am SmartSpend. How can I help you today?";
    $quick_replies = ['Check if I can afford something', 'Check my budget health', 'Run a stress test', 'Other'];
    break;
}

// ── Save state to database ────────────────────────────────
$stmt = $db->prepare(
  'INSERT INTO conversation_state (session_id, state)
   VALUES (?, ?)
   ON DUPLICATE KEY UPDATE state = VALUES(state), updated_at = NOW()'
);
$stmt->execute([$session_id, json_encode($state)]);

// ── Save bot reply with calculation JSON ──────────────────
$calc_json = $calculation ? json_encode($calculation) : null;
$stmt = $db->prepare(
  'INSERT INTO messages (session_id, role, content, calculation) VALUES (?, ?, ?, ?)'
);
$stmt->execute([$session_id, 'bot', $bot_reply, $calc_json]);

// ── Return response ───────────────────────────────────────
echo json_encode([
  'success'       => true,
  'bot_reply'     => $bot_reply,
  'calculation'   => $calculation,
  'quick_replies' => $quick_replies,
  'step'          => $state['step'],
]);