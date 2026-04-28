<?php
require_once '../config/db.php';
require_once 'ai.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['success' => false, 'error' => 'Not logged in.']);
  exit;
}

$user_id     = $_SESSION['user_id'];
$raw_message = trim($_POST['message'] ?? '');
$session_id  = intval($_POST['session_id'] ?? 0);

if ($raw_message === '') {
  echo json_encode(['success' => false, 'error' => 'Message cannot be empty.']);
  exit;
}

if ($session_id === 0) {
  echo json_encode(['success' => false, 'error' => 'Invalid session.']);
  exit;
}

$db      = getDB();
$message = correctTypos(trim($raw_message));

$stmt = $db->prepare('INSERT INTO messages (session_id, role, content) VALUES (?, ?, ?)');
$stmt->execute([$session_id, 'user', $raw_message]);

$stmt = $db->prepare('SELECT state FROM conversation_state WHERE session_id = ?');
$stmt->execute([$session_id]);
$row = $stmt->fetch();

$state = $row ? json_decode($row['state'], true) : [
  'step'                  => 'greeting',
  'income'                => null,
  'expenses'              => null,
  'savings'               => null,
  'checks'                => [],
  'pending_item'          => null,
  'pending_price'         => null,
  'pending_type'          => null,
  'pending_sub'           => null,
  'pending_goal'          => null,
  'expected_next'         => null,
  'emergency_fund_warned' => false,
];

foreach (['pending_sub', 'pending_goal', 'expected_next', 'pending_price', 'pending_type'] as $k) {
  if (!isset($state[$k])) $state[$k] = null;
}

$stmt = $db->prepare('SELECT role, content FROM messages WHERE session_id = ? ORDER BY created_at DESC LIMIT 16');
$stmt->execute([$session_id]);
$history_raw = array_reverse($stmt->fetchAll());
$history     = array_values(array_filter($history_raw, fn($m) => !($m['role'] === 'user' && $m['content'] === $raw_message)));

function extractNumber(string $msg): ?float {
  $cleaned = str_replace([',', '£', '$', '€', '%'], '', $msg);
  $num     = null;
  if (preg_match('/(\d+(\.\d{1,2})?)\s*k/i', $cleaned, $km)) {
    $num = floatval($km[1]) * 1000;
  } elseif (preg_match('/\d+(\.\d{1,2})?/', $cleaned, $matches)) {
    $num = floatval($matches[0]);
  }
  if ($num !== null && preg_match('/per week|weekly|a week|each week|\/week/i', $msg)) {
    $num = round($num * 4.33, 2);
  }
  return $num;
}

function isRecurring(string $msg): bool {
  return (bool) preg_match('/per month|monthly|\/mo|subscription|recurring|per week|weekly|session|class/i', $msg);
}

function cleanItemName(string $msg): string {
  $name = preg_replace('/£?[\d,]+(\.\d{1,2})?\s*k?/i', '', $msg);
  $name = preg_replace('/\b(hmm+|um+|uh+|a|an|the|for|at|to|buy|buying|get|getting|myself|yourself|want|need|afford|per|month|monthly|week|weekly|costs?|priced?|is|was|id like|i want|thinking of|im thinking|can i|could i|how about|purchase|no|its|my|idk|if|every|though|think|with|we|last|talked|about|previous|earlier|before|that|same|check|can|afford|new|some|just|going to|gonna|would like|like to|treat|myself to)\b/i', ' ', $name);
  $name = trim(preg_replace('/\s+/', ' ', $name));
  return strlen($name) > 1 ? $name : 'item';
}

function formatMonths(int $months): string {
  if ($months <= 0) return 'already affordable';
  $y  = floor($months / 12);
  $mo = $months % 12;
  if ($y > 0) return $y . ' year' . ($y > 1 ? 's' : '') . ($mo > 0 ? ' and ' . $mo . ' months' : '');
  return $months . ' month' . ($months > 1 ? 's' : '');
}

function hasMultipleItems(string $msg): bool {
  $andCount = substr_count(strtolower($msg), ' and ');
  return $andCount >= 1 && preg_match('/\d/', $msg) && preg_match('/\b(bag|retreat|holiday|car|phone|laptop|watch|shoes|dress|jacket|ring|sofa|tv)\b/i', $msg);
}

function calculate(float $income, float $expenses, float $savings, float $item_price, string $item_type): array {
  $surplus = $income - $expenses;

  if ($item_type === 'recurring') {
    $surplus_after  = $surplus - $item_price;
    $months_to_save = 0;
    $projections    = [];
    for ($i = 1; $i <= 3; $i++) {
      $projections['month_' . $i] = round($savings + ($surplus_after * $i), 2);
    }
  } else {
    $surplus_after  = $surplus;
    $months_to_save = ($item_price > $savings && $surplus > 0)
      ? (int) ceil(($item_price - $savings) / $surplus)
      : 0;
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
      $y   = floor($months_to_save / 12);
      $rem = $months_to_save % 12;
      $projections['summary'] = 'Approximately ' . $y . ' year' . ($y > 1 ? 's' : '') .
        ($rem > 0 ? ' and ' . $rem . ' months' : '');
    }
  }

  $er = ($income > 0) ? ($expenses / $income) : 1;

  if ($item_type === 'recurring') {
    if ($surplus_after >= $surplus * 0.4 && $er < 0.7) $risk = 'green';
    elseif ($surplus_after >= 0 && $er < 0.85)         $risk = 'yellow';
    else                                                 $risk = 'red';
  } else {
    if ($savings >= $item_price && $surplus > 0)         $risk = 'green';
    elseif ($surplus > 0 && $months_to_save <= 12)       $risk = 'yellow';
    else                                                  $risk = 'red';
  }

  $health = 100;
  if ($er > 0.9)      $health -= 40;
  elseif ($er > 0.7)  $health -= 20;
  elseif ($er > 0.5)  $health -= 10;
  if ($savings < $income) $health -= 20;
  if ($surplus <= 0)      $health -= 30;
  if ($risk === 'red')    $health -= 20;
  elseif ($risk === 'yellow') $health -= 10;
  $health = max(0, min(100, $health));

  $ef = round($expenses * 3, 2);
  if ($item_type === 'recurring') {
    if ($risk === 'green')      $sug = 'You can comfortably afford this. Surplus after cost: £' . number_format($surplus_after, 2) . '.';
    elseif ($risk === 'yellow') $sug = 'Affordable but reduces surplus to £' . number_format($surplus_after, 2) . '.';
    else                        $sug = 'This recurring cost would put your finances under pressure.';
  } elseif ($risk === 'red' && $surplus > 0) {
    $sug = 'To afford this in 6 months save an extra £' . round(($item_price - $savings) / 6, 2) . '/month.';
  } elseif ($risk === 'red') {
    $sug = 'Expenses exceed income so saving for this is not possible right now.';
  } elseif ($risk === 'yellow') {
    $sug = 'Consider setting aside £' . round($item_price / max($months_to_save, 1), 2) . '/month to reach your goal faster.';
  } else {
    $sug = 'Make sure you have at least 3 months of expenses (£' . $ef . ') as an emergency fund.';
  }

  return [
    'surplus'        => round($surplus, 2),
    'surplus_after'  => round($surplus_after, 2),
    'months_to_save' => $months_to_save,
    'risk_level'     => $risk,
    'health_score'   => $health,
    'expense_ratio'  => round($er * 100, 1),
    'suggestion'     => $sug,
    'projections'    => $projections,
  ];
}

function emergencyFundWarning(float $savings, float $expenses): ?string {
  $recommended = $expenses * 3;
  if ($savings < $recommended) {
    return 'Your savings of £' . number_format($savings, 2) . ' are below the recommended 3-month emergency fund of £' . number_format($recommended, 2) . '.';
  }
  return null;
}

function saveResult(PDO $db, int $session_id, int $user_id, array $state, string $item_name, float $item_price, string $item_type, array $calc): void {
  $stmt = $db->prepare('INSERT INTO budgets (session_id, income, expenses, savings) VALUES (?, ?, ?, ?)');
  $stmt->execute([$session_id, $state['income'], $state['expenses'], $state['savings']]);
  $stmt = $db->prepare('INSERT INTO assessments (session_id, item_name, item_price, item_type, risk_level, surplus, surplus_after, months_to_save) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
  $stmt->execute([$session_id, $item_name, $item_price, $item_type, $calc['risk_level'], $calc['surplus'], $calc['surplus_after'], $calc['months_to_save']]);
  $stmt = $db->prepare('INSERT INTO health_scores (user_id, score, trend) VALUES (?, ?, ?)');
  $stmt->execute([$user_id, $calc['health_score'], 'stable']);
}

function runCalculation(PDO $db, int $session_id, int $user_id, array &$state, string $item_name, float $price, string $item_type, array $history): array {
  $calc = calculate($state['income'], $state['expenses'], $state['savings'], $price, $item_type);
  saveResult($db, $session_id, $user_id, $state, $item_name, $price, $item_type, $calc);

  $state['checks'][]      = ['item_name' => $item_name, 'item_price' => $price, 'item_type' => $item_type, 'risk_level' => $calc['risk_level'], 'calc' => $calc];
  $state['pending_item']  = null;
  $state['pending_price'] = null;
  $state['pending_type']  = null;
  $state['pending_goal']  = null;
  $state['expected_next'] = null;
  $state['step']          = 'followup';

  $ai_ctx  = 'Income: £' . $state['income'] . '. Expenses: £' . $state['expenses'] . '. Savings: £' . $state['savings'] . '. Item: ' . $item_name . ' at £' . $price . ' (' . $item_type . '). Surplus: £' . $calc['surplus'] . '. Surplus after: £' . $calc['surplus_after'] . '. Risk: ' . $calc['risk_level'] . '. Months to save: ' . $calc['months_to_save'] . '.';
  $ai_text = getAIExplanation($ai_ctx, $calc['risk_level'], $history);
  $label   = $calc['risk_level'] === 'green' ? 'Good news' : ($calc['risk_level'] === 'yellow' ? 'Heads up' : 'Warning');

  return [
    $label . ' - here is your result for ' . $item_name . ".\n\n" . $ai_text,
    array_merge($calc, ['item_name' => $item_name, 'item_price' => $price, 'item_type' => $item_type])
  ];
}

function saveAndRespond(PDO $db, int $session_id, array $state, string $bot_reply, ?array $calculation, array $quick_replies): void {
  $stmt = $db->prepare('INSERT INTO conversation_state (session_id, state) VALUES (?, ?) ON DUPLICATE KEY UPDATE state = VALUES(state), updated_at = NOW()');
  $stmt->execute([$session_id, json_encode($state)]);
  $stmt = $db->prepare('INSERT INTO messages (session_id, role, content, calculation) VALUES (?, ?, ?, ?)');
  $stmt->execute([$session_id, 'bot', $bot_reply, $calculation ? json_encode($calculation) : null]);
  echo json_encode(['success' => true, 'bot_reply' => $bot_reply, 'calculation' => $calculation, 'quick_replies' => $quick_replies, 'step' => $state['step']]);
  exit;
}

$price         = extractNumber($message);
$lower         = strtolower(trim($message));
$bot_reply     = '';
$calculation   = null;
$quick_replies = ['Check another item', 'Run a stress test', 'Reset budget', 'Other'];
$budget_complete = !empty($state['income']) && !empty($state['expenses']) && isset($state['savings']);

// Hard reset
if (preg_match('/^(reset|start over|restart)$/i', $lower)) {
  $state = ['step' => 'income', 'income' => null, 'expenses' => null, 'savings' => null, 'checks' => [], 'pending_item' => null, 'pending_price' => null, 'pending_type' => null, 'pending_sub' => null, 'pending_goal' => null, 'expected_next' => null, 'emergency_fund_warned' => false];
  saveAndRespond($db, $session_id, $state, "No problem - let's start fresh. What is your monthly income after tax?", null, ['£1500', '£2000', '£2500', '£3000', 'Other']);
}

// Subscription flow
if (in_array($state['step'], ['sub_name', 'sub_price', 'sub_frequency', 'sub_duration'])) {
  if ($state['step'] === 'sub_name') {
    $sub_name = trim($message);
    if (strlen($sub_name) < 2 || is_numeric($sub_name)) saveAndRespond($db, $session_id, $state, 'What is it called? For example: Netflix, Pilates, Gym.', null, ['Netflix', 'Spotify', 'Gym', 'Other']);
    $state['pending_sub']['name'] = $sub_name;
    $state['step']                = 'sub_price';
    saveAndRespond($db, $session_id, $state, 'How much does ' . $sub_name . ' cost per session or per month?', null, ['£5', '£10', '£20', '£50', 'Other']);
  }
  if ($state['step'] === 'sub_price') {
    $p = extractNumber($message);
    if (!$p || $p <= 0) saveAndRespond($db, $session_id, $state, 'How much does it cost?', null, ['£5', '£10', '£20', '£50', 'Other']);
    $is_m = preg_match('/per month|monthly/i', $message);
    $is_w = preg_match('/per week|weekly|a week|each week/i', $message);
    $state['pending_sub']['raw_price'] = $p;
    $state['step'] = 'sub_frequency';
    if ($is_m) {
      $state['pending_sub']['monthly_cost'] = $p;
      $state['step'] = 'sub_duration';
      saveAndRespond($db, $session_id, $state, 'Got it - £' . number_format($p, 2) . '/month. Ongoing or fixed period?', null, ['Ongoing', '1 month', '3 months', '6 months', 'Other']);
    } elseif ($is_w) {
      saveAndRespond($db, $session_id, $state, 'Got it - £' . number_format($p, 2) . '/session. Every week or does it vary?', null, ['Every week', 'About 3 times a month', 'About 2 times a month', 'It varies', 'Other']);
    } else {
      saveAndRespond($db, $session_id, $state, 'Is that per session, per week, or per month?', null, ['Per session', 'Per week', 'Per month', 'Other']);
    }
  }
  if ($state['step'] === 'sub_frequency') {
    $raw = $state['pending_sub']['raw_price'] ?? 0;
    $mc  = 0;
    if (preg_match('/per month|monthly/i', $message))                    $mc = $raw;
    elseif (preg_match('/per week|weekly|every week|all 4/i', $message)) $mc = round($raw * 4.33, 2);
    elseif (preg_match('/3 time|3 week|three|about 3/i', $message))      $mc = round($raw * 3, 2);
    elseif (preg_match('/2 time|2 week|twice|two|about 2/i', $message))  $mc = round($raw * 2, 2);
    elseif (preg_match('/once|1 time|1 week|one/i', $message))           $mc = $raw;
    elseif (preg_match('/varies|vary|sometimes/i', $message))             $mc = round($raw * 3, 2);
    elseif ($price && $price > 0)                                         $mc = round($raw * $price, 2);
    else saveAndRespond($db, $session_id, $state, 'How often per month?', null, ['Every week', 'About 3 times', 'About 2 times', 'It varies', 'Other']);
    if ($mc > 0) {
      $state['pending_sub']['monthly_cost'] = $mc;
      $state['step'] = 'sub_duration';
      saveAndRespond($db, $session_id, $state, 'That works out to £' . number_format($mc, 2) . '/month. Ongoing or fixed period?', null, ['Ongoing', '1 month', '3 months', '6 months', 'Other']);
    }
  }
  if ($state['step'] === 'sub_duration') {
    $mc      = $state['pending_sub']['monthly_cost'] ?? 0;
    $sname   = $state['pending_sub']['name'] ?? 'subscription';
    $months  = extractNumber($message);
    $ongoing = preg_match('/ongoing|long.?term|permanent|forever|no end|open/i', $message);
    if ($ongoing) {
      [$bot_reply, $calculation] = runCalculation($db, $session_id, $user_id, $state, $sname, $mc, 'recurring', $history);
      saveAndRespond($db, $session_id, $state, $bot_reply, $calculation, ['Check another item', 'Run a stress test', 'Reset budget']);
    } elseif ($months && $months > 0) {
      $total = round($mc * $months, 2);
      [$bot_reply, $calculation] = runCalculation($db, $session_id, $user_id, $state, $sname . ' (' . $months . ' months)', $total, 'one-time', $history);
      saveAndRespond($db, $session_id, $state, $bot_reply, $calculation, ['Check another item', 'Run a stress test', 'Reset budget']);
    } else {
      saveAndRespond($db, $session_id, $state, 'Ongoing or fixed period?', null, ['Ongoing', '1 month', '3 months', '6 months', 'Other']);
    }
  }
}

// STEP 1: Extract structured facts from every message
$facts = extractFacts($message, $state, $history);
$updated_fields = applyFacts($facts, $state);

// Update step based on what was just collected
if (in_array('income', $updated_fields) && empty($state['expenses'])) {
  $state['step'] = 'expenses';
  $state['expected_next'] = 'expenses';
}
if (in_array('expenses', $updated_fields) && !isset($state['savings'])) {
  $state['step'] = 'savings';
  $state['expected_next'] = 'savings';
}
if (in_array('savings', $updated_fields)) {
  $state['expected_next'] = null;
  if (!empty($state['pending_goal']) && empty($state['pending_item'])) {
    $state['pending_item']  = $state['pending_goal'];
    $state['expected_next'] = 'item_price';
  }
  if (!empty($state['pending_item']) && !empty($state['pending_price'])) {
    $state['step'] = 'item';
  } else {
    $state['step'] = !empty($state['pending_item']) ? 'item' : 'item';
  }
}

// STEP 2: Auto-calculate if we now have everything
if (!empty($state['income']) && !empty($state['expenses']) && isset($state['savings'])
  && !empty($state['pending_item']) && !empty($state['pending_price'])) {
  $item_name = $state['pending_item'];
  $item_price = floatval($state['pending_price']);
  $item_type  = $state['pending_type'] ?? (isRecurring($message) ? 'recurring' : 'one-time');
  [$bot_reply, $calculation] = runCalculation($db, $session_id, $user_id, $state, $item_name, $item_price, $item_type, $history);
  saveAndRespond($db, $session_id, $state, $bot_reply, $calculation, ['Check another item', 'Compare with something else', 'Run a stress test', 'Reset budget']);
}

// Refresh budget_complete after fact extraction
$budget_complete = !empty($state['income']) && !empty($state['expenses']) && isset($state['savings']);

// STEP 3: Classify and route remaining logic
$intent = classifyMessage($message, $state, $history);

// Hard correction
if ($intent === 'correction' || preg_match('/^(no[\s,.!]|no$|sorry|actually|i meant|wait|i already|my income is|my salary is|my expenses are|my savings are)/i', $message)) {
  if ($facts['is_correction'] ?? false) {
    $field = $facts['correcting_field'] ?? null;
    if ($field === 'income' && !empty($facts['income'])) {
      $state['income'] = $facts['income']; $state['step'] = 'expenses'; $state['expected_next'] = 'expenses';
      saveAndRespond($db, $session_id, $state, 'No worries - income corrected to £' . number_format($facts['income'], 2) . '. What are your monthly expenses?', null, ['£500', '£800', '£1200', '£1500', 'Other']);
    }
    if ($field === 'expenses' && !empty($facts['expenses'])) {
      $state['expenses'] = $facts['expenses']; $state['step'] = 'savings'; $state['expected_next'] = 'savings';
      saveAndRespond($db, $session_id, $state, 'No worries - expenses corrected to £' . number_format($facts['expenses'], 2) . '. How much do you have saved?', null, ['£0', '£500', '£1000', 'Other']);
    }
    if ($field === 'savings' && isset($facts['savings'])) {
      $state['savings'] = $facts['savings']; $state['step'] = 'item';
      saveAndRespond($db, $session_id, $state, 'No worries - savings corrected to £' . number_format($facts['savings'], 2) . '. What would you like to check?', null, ['A laptop £800', 'A phone £600', 'A car £10k', 'Other']);
    }
  }
  // Fallback correction
  if ($price !== null && $price >= 0) {
    if (preg_match('/income|salary|wage|earn/i', $message)) {
      $state['income'] = $price; $state['step'] = 'expenses'; $state['expected_next'] = 'expenses';
      saveAndRespond($db, $session_id, $state, 'No worries - income corrected to £' . number_format($price, 2) . '. What are your monthly expenses?', null, ['£500', '£800', '£1200', '£1500', 'Other']);
    } elseif (preg_match('/expenses|bills|rent|spend/i', $message)) {
      $state['expenses'] = $price; $state['step'] = 'savings'; $state['expected_next'] = 'savings';
      saveAndRespond($db, $session_id, $state, 'No worries - expenses corrected to £' . number_format($price, 2) . '. How much do you have saved?', null, ['£0', '£500', '£1000', 'Other']);
    } elseif (preg_match('/savings|saved|have saved/i', $message)) {
      $state['savings'] = $price; $state['step'] = 'item';
      saveAndRespond($db, $session_id, $state, 'No worries - savings corrected to £' . number_format($price, 2) . '. What would you like to check?', null, ['A laptop £800', 'A phone £600', 'Other']);
    } elseif (!empty($state['pending_item'])) {
      $item_type = $state['pending_type'] ?? 'one-time';
      [$bot_reply, $calculation] = runCalculation($db, $session_id, $user_id, $state, $state['pending_item'], $price, $item_type, $history);
      saveAndRespond($db, $session_id, $state, $bot_reply, $calculation, ['Check another item', 'Compare', 'Run a stress test', 'Reset budget']);
    }
  }
  // No number correction - back up one step
  $prev = match($state['step']) {
    'expenses' => 'income', 'savings' => 'expenses', 'item' => 'savings', default => $state['step'],
  };
  $state['step'] = $prev; $state['expected_next'] = $prev;
  $bot_reply = match($prev) {
    'income'   => "No problem - what is your monthly income after tax?",
    'expenses' => "No problem - what are your total monthly expenses?",
    'savings'  => "No problem - how much do you currently have saved?",
    default    => "No problem - what would you like to correct?",
  };
  saveAndRespond($db, $session_id, $state, $bot_reply, null, ['Other']);
}

// Multi-item
if ($intent === 'multi_item' || hasMultipleItems($message)) {
  $items = extractItemsFromMessage($message, $state, $history);
  if (count($items) >= 2) {
    $all_priced = array_reduce($items, fn($c, $i) => $c && !empty($i['price']), true);
    if ($all_priced && $budget_complete) {
      $bot_reply = generateMultiItemResponse($items, $state, $history, $message);
      usort($items, fn($a, $b) => $a['price'] <=> $b['price']);
      $cheapest = $items[0];
      [$calc_reply, $calculation] = runCalculation($db, $session_id, $user_id, $state, $cheapest['name'], $cheapest['price'], $cheapest['type'] ?? 'one-time', $history);
      saveAndRespond($db, $session_id, $state, $bot_reply, $calculation, ['Check another item', 'Compare', 'Run a stress test', 'Reset budget']);
    }
  }
}

// expected_next priority
if ($state['expected_next'] && $price !== null) {
  switch ($state['expected_next']) {
    case 'item_price':
      $item_name = $state['pending_item'] ?? $state['pending_goal'] ?? 'item';
      $item_type = $state['pending_type'] ?? (isRecurring($message) ? 'recurring' : 'one-time');
      $state['expected_next'] = null;
      [$bot_reply, $calculation] = runCalculation($db, $session_id, $user_id, $state, $item_name, $price, $item_type, $history);
      saveAndRespond($db, $session_id, $state, $bot_reply, $calculation, ['Check another item', 'Compare', 'Run a stress test', 'Reset budget']);
    case 'income':
      if ($price > 0) {
        $state['income'] = $price; $state['step'] = 'expenses'; $state['expected_next'] = 'expenses';
        saveAndRespond($db, $session_id, $state, 'Got it - income of £' . number_format($price, 2) . '. What are your total monthly expenses?', null, ['£500', '£800', '£1200', '£1500', 'Other']);
      }
      break;
    case 'expenses':
      if ($price >= 0) {
        $state['expenses'] = $price; $state['step'] = 'savings'; $state['expected_next'] = 'savings';
        $surplus = ($state['income'] ?? 0) - $price;
        $msg_r   = $price >= ($state['income'] ?? 0)
          ? 'Expenses of £' . number_format($price, 2) . ' - no surplus. How much do you have saved?'
          : 'Expenses of £' . number_format($price, 2) . ' - surplus of £' . number_format($surplus, 2) . '/month. How much do you have saved?';
        saveAndRespond($db, $session_id, $state, $msg_r, null, ['£0', '£500', '£1000', '£2000', 'Other']);
      }
      break;
    case 'savings':
      if ($price >= 0) {
        $state['savings'] = $price; $state['expected_next'] = null;
        $ef_note = '';
        $ef      = emergencyFundWarning($price, $state['expenses'] ?? 0);
        if ($ef) $ef_note = "\n\n" . $ef;
        $state['emergency_fund_warned'] = (bool) $ef;
        if (!empty($state['pending_item']) && !empty($state['pending_price'])) {
          $item_type = $state['pending_type'] ?? 'one-time';
          [$bot_reply, $calculation] = runCalculation($db, $session_id, $user_id, $state, $state['pending_item'], floatval($state['pending_price']), $item_type, $history);
          if ($ef_note) $bot_reply = trim($ef_note) . "\n\n" . $bot_reply;
          saveAndRespond($db, $session_id, $state, $bot_reply, $calculation, ['Check another item', 'Compare', 'Run a stress test', 'Reset budget']);
        } elseif (!empty($state['pending_goal'])) {
          $state['step'] = 'item'; $state['pending_item'] = $state['pending_goal']; $state['expected_next'] = 'item_price';
          saveAndRespond($db, $session_id, $state, 'Savings of £' . number_format($price, 2) . ' noted.' . $ef_note . "\n\nNow - how much does the " . $state['pending_goal'] . " cost?", null, ['£500', '£1000', '£5000', '£10000', 'Other']);
        } else {
          $state['step'] = 'item';
          saveAndRespond($db, $session_id, $state, 'Savings of £' . number_format($price, 2) . ' noted.' . $ef_note . "\n\nWhat would you like to buy or check?", null, ['A laptop £800', 'A phone £600', 'A car £10k', 'A subscription', 'Other']);
        }
      }
      break;
    case 'stress_pct':
      $total_loss = (bool) preg_match('/total|100|all|no income|zero/i', $message);
      $pct        = $total_loss ? 100 : $price;
      $new_income = $total_loss ? 0 : (($state['income'] ?? 0) * (1 - ($pct / 100)));
      $new_surplus   = $new_income - ($state['expenses'] ?? 0);
      $months_runway = ($state['savings'] > 0 && ($state['expenses'] ?? 0) > 0) ? round($state['savings'] / $state['expenses'], 1) : 0;
      $drop_label    = $total_loss ? 'a total loss of income' : 'a ' . $pct . '% drop in income';
      if ($new_surplus > 0) {
        $bot_reply = 'Stress test - with ' . $drop_label . ', income drops to £' . number_format($new_income, 2) . ' and surplus reduces to £' . number_format($new_surplus, 2) . '.';
      } elseif ($new_surplus == 0) {
        $bot_reply = 'Stress test - with ' . $drop_label . ', income exactly covers expenses. Savings of £' . number_format($state['savings'], 2) . ' provide a buffer.';
      } else {
        $bot_reply = 'Stress test - with ' . $drop_label . ', income drops to £' . number_format($new_income, 2) . ' giving a shortfall of £' . number_format(abs($new_surplus), 2) . '/month. Savings last ' . $months_runway . ' months.';
      }
      $state['step'] = 'followup'; $state['expected_next'] = null;
      saveAndRespond($db, $session_id, $state, $bot_reply, null, ['Check another item', 'Reset budget', 'Run another stress test', 'Other']);
  }
}

// pending_item with price - always wins
if ($state['pending_item'] && $price !== null && $price > 0 && !preg_match('/\b(what|how|when|why|who|tell|help|can you|should|would|do you)\b/i', $message)) {
  $item_type = $state['pending_type'] ?? (isRecurring($message) ? 'recurring' : 'one-time');
  [$bot_reply, $calculation] = runCalculation($db, $session_id, $user_id, $state, $state['pending_item'], $price, $item_type, $history);
  saveAndRespond($db, $session_id, $state, $bot_reply, $calculation, ['Check another item', 'Compare', 'Run a stress test', 'Reset budget']);
}

// Income step
if ($state['step'] === 'income' && $price && $price > 0 && !preg_match('/\b(what|how|when|why|who|tell|help)\b/i', $message)) {
  $state['income'] = $price; $state['step'] = 'expenses'; $state['expected_next'] = 'expenses';
  saveAndRespond($db, $session_id, $state, 'Got it - income of £' . number_format($price, 2) . '. What are your total monthly expenses?', null, ['£500', '£800', '£1200', '£1500', 'Other']);
}

// Expenses step
if ($state['step'] === 'expenses' && $price !== null && $price >= 0 && !preg_match('/\b(what|how|when|why|who|tell|help)\b/i', $message)) {
  $state['expenses'] = $price; $state['step'] = 'savings'; $state['expected_next'] = 'savings';
  $surplus = ($state['income'] ?? 0) - $price;
  $msg_r   = $price >= ($state['income'] ?? 0)
    ? 'Expenses of £' . number_format($price, 2) . ' - no surplus. How much do you have saved?'
    : 'Expenses of £' . number_format($price, 2) . ' - surplus of £' . number_format($surplus, 2) . '/month. How much do you have saved?';
  saveAndRespond($db, $session_id, $state, $msg_r, null, ['£0', '£500', '£1000', '£2000', 'Other']);
}

// Savings step
if ($state['step'] === 'savings' && $price !== null && $price >= 0 && !preg_match('/\b(what|how|when|why|who|tell|help)\b/i', $message)) {
  $state['savings'] = $price; $state['expected_next'] = null;
  $ef_note = '';
  $ef      = emergencyFundWarning($price, $state['expenses'] ?? 0);
  if ($ef) $ef_note = "\n\n" . $ef;
  $state['emergency_fund_warned'] = (bool) $ef;

  if (!empty($state['pending_item']) && !empty($state['pending_price'])) {
    $item_type = $state['pending_type'] ?? 'one-time';
    [$bot_reply, $calculation] = runCalculation($db, $session_id, $user_id, $state, $state['pending_item'], floatval($state['pending_price']), $item_type, $history);
    if ($ef_note) $bot_reply = trim($ef_note) . "\n\n" . $bot_reply;
    saveAndRespond($db, $session_id, $state, $bot_reply, $calculation, ['Check another item', 'Compare', 'Run a stress test', 'Reset budget']);
  } elseif (!empty($state['pending_goal'])) {
    $state['step'] = 'item'; $state['pending_item'] = $state['pending_goal']; $state['expected_next'] = 'item_price';
    saveAndRespond($db, $session_id, $state, 'Savings of £' . number_format($price, 2) . ' noted.' . $ef_note . "\n\nNow - how much does the " . $state['pending_goal'] . " cost?", null, ['£500', '£1000', '£5000', '£10000', 'Other']);
  } else {
    $state['step'] = 'item';
    saveAndRespond($db, $session_id, $state, 'Savings of £' . number_format($price, 2) . ' noted.' . $ef_note . "\n\nWhat would you like to buy or check?", null, ['A laptop £800', 'A phone £600', 'A car £10k', 'A subscription', 'Other']);
  }
}

// Item step with price
if ($state['step'] === 'item' && $price && $price > 0 && !in_array($intent, ['income_figure', 'expenses_figure', 'savings_figure'])) {
  $item_name = !empty($state['pending_item']) ? $state['pending_item'] : cleanItemName($message);
  $item_type = $state['pending_type'] ?? (isRecurring($message) ? 'recurring' : 'one-time');
  [$bot_reply, $calculation] = runCalculation($db, $session_id, $user_id, $state, $item_name, $price, $item_type, $history);
  saveAndRespond($db, $session_id, $state, $bot_reply, $calculation, ['Check another item', 'Compare', 'Run a stress test', 'Reset budget']);
}

// Item without price
if (in_array($intent, ['item_without_price', 'affordability_request']) && !$price) {
  $item_name = !empty($facts['item_name']) ? $facts['item_name'] : cleanItemName($message);
  if (strlen($item_name) > 1) {
    if (empty($state['income'])) {
      $state['pending_goal'] = $item_name; $state['step'] = 'income'; $state['expected_next'] = 'income';
      saveAndRespond($db, $session_id, $state, "I would love to help you check if you can afford a " . $item_name . ". I need a few numbers first - what is your monthly income after tax?", null, ['£1500', '£2000', '£2500', '£3000', 'Other']);
    } elseif (empty($state['expenses'])) {
      $state['pending_goal'] = $item_name; $state['step'] = 'expenses'; $state['expected_next'] = 'expenses';
      saveAndRespond($db, $session_id, $state, "Checking for a " . $item_name . ". What are your monthly expenses?", null, ['£500', '£800', '£1200', '£1500', 'Other']);
    } elseif (!isset($state['savings'])) {
      $state['pending_goal'] = $item_name; $state['step'] = 'savings'; $state['expected_next'] = 'savings';
      saveAndRespond($db, $session_id, $state, "Checking for a " . $item_name . ". How much do you currently have saved?", null, ['£0', '£500', '£1000', 'Other']);
    } else {
      $state['pending_item'] = $item_name; $state['expected_next'] = 'item_price';
      saveAndRespond($db, $session_id, $state, "How much does the " . $item_name . " cost?", null, ['£500', '£1000', '£5000', '£10000', 'Other']);
    }
  }
}

// Subscription trigger
if ($intent === 'subscription_request' || preg_match('/\bsubscription\b|\bclass(es)?\b|\bpilates\b|\byoga\b|\bgym\b|\bmembership\b/i', $message)) {
  if (!$price && $budget_complete) {
    $state['step'] = 'sub_name'; $state['pending_sub'] = [];
    $possible_name = cleanItemName($message);
    if (strlen($possible_name) > 1 && !preg_match('/subscription|class|membership/i', $possible_name)) {
      $state['pending_sub']['name'] = $possible_name; $state['step'] = 'sub_price';
      saveAndRespond($db, $session_id, $state, 'How much does ' . $possible_name . ' cost per session or per month?', null, ['£5', '£10', '£20', '£50', 'Other']);
    }
    saveAndRespond($db, $session_id, $state, 'What is the subscription or class called?', null, ['Netflix', 'Spotify', 'Gym', 'Pilates', 'Other']);
  }
}

// Comparison
if ($intent === 'comparison' || preg_match('/compare|vs\b|versus|which.*better|both of them|i want both/i', $message)) {
  if (count($state['checks']) >= 2) {
    $bot_reply = generateComparison($state['checks'], $state, $history);
  } elseif (count($state['checks']) === 1) {
    $state['step'] = 'item';
    $bot_reply     = 'Your last check was ' . $state['checks'][0]['item_name'] . ' at £' . $state['checks'][0]['item_price'] . '. What would you like to compare it with?';
    $quick_replies = ['A laptop £800', 'A phone £600', 'Other'];
  } else {
    $bot_reply = 'Tell me the first item and price and I will help you compare.'; $quick_replies = ['Other'];
  }
  saveAndRespond($db, $session_id, $state, $bot_reply, null, $quick_replies);
}

// Custom savings calc
if ($intent === 'custom_savings_calc' || preg_match('/calculate.*based|if I save|can only save|save.*per month|based on.*saving/i', $message)) {
  if ($price && $price > 0) {
    $bot_reply = generateCustomSavingsCalc($price, $state, $history);
    saveAndRespond($db, $session_id, $state, $bot_reply, null, $quick_replies);
  }
}

// Stress test
if ($intent === 'stress_test' || preg_match('/stress.*test|what if.*income|lost.*job|redundan/i', $message)) {
  if ($price && $price > 0 && $price <= 100) {
    $new_income  = ($state['income'] ?? 0) * (1 - ($price / 100));
    $new_surplus = $new_income - ($state['expenses'] ?? 0);
    $months_run  = ($state['savings'] > 0 && ($state['expenses'] ?? 0) > 0) ? round($state['savings'] / $state['expenses'], 1) : 0;
    $bot_reply   = $new_surplus > 0
      ? 'Stress test - with a ' . $price . '% drop, income falls to £' . number_format($new_income, 2) . ' and surplus reduces to £' . number_format($new_surplus, 2) . '.'
      : 'Stress test - with a ' . $price . '% drop, income falls to £' . number_format($new_income, 2) . ' giving a shortfall of £' . number_format(abs($new_surplus), 2) . '/month. Savings last ' . $months_run . ' months.';
    $state['step'] = 'followup'; $state['expected_next'] = null;
    saveAndRespond($db, $session_id, $state, $bot_reply, null, ['Check another item', 'Reset budget', 'Run another stress test', 'Other']);
  } else {
    $state['step'] = 'stress_test'; $state['expected_next'] = 'stress_pct';
    saveAndRespond($db, $session_id, $state, "Let's run a stress test. What percentage drop in income?", null, ['20% drop', '50% drop', 'Total loss', 'Other']);
  }
}

// Loan question
if ($intent === 'loan_question' || preg_match('/\bloan\b|get.*credit|borrow.*money|finance.*it|buy.*on.*credit/i', $message)) {
  $extra = count($state['checks']) > 0
    ? "User asking about loan for " . end($state['checks'])['item_name'] . " at £" . number_format(end($state['checks'])['item_price'], 2) . ". Surplus £" . number_format(end($state['checks'])['calc']['surplus'], 2) . "/month, " . formatMonths(end($state['checks'])['calc']['months_to_save']) . " to save without loan. Explain interest risk with exact numbers. Show saving alternative. Not a financial adviser. 3 sentences."
    : "User asking about a loan. Explain interest, saving is better, not a financial adviser, ask what they want to buy.";
  $bot_reply = generateCoachReply($message, $state, $history, $extra);
  saveAndRespond($db, $session_id, $state, $bot_reply, null, $quick_replies);
}

// Memory check
if ($intent === 'memory_check') {
  $parts = [];
  if (!empty($state['income']))   $parts[] = 'income £' . number_format($state['income'], 2);
  if (!empty($state['expenses'])) $parts[] = 'expenses £' . number_format($state['expenses'], 2);
  if (isset($state['savings']))   $parts[] = 'savings £' . number_format($state['savings'], 2);
  $bot_reply = !empty($parts) ? 'Here is what I have: ' . implode(', ', $parts) . '. Is anything wrong?' : "I do not have any figures yet. Shall we start with your monthly income?";
  saveAndRespond($db, $session_id, $state, $bot_reply, null, ['That looks right', 'Let me correct something', 'Other']);
}

// Followup with price
if ($state['step'] === 'followup' && $price && $price > 0 && $budget_complete && in_array($intent, ['affordability_request', 'item_price'])) {
  $item_type = isRecurring($message) ? 'recurring' : 'one-time';
  $item_name = !empty($facts['item_name']) ? $facts['item_name'] : cleanItemName($message);
  [$bot_reply, $calculation] = runCalculation($db, $session_id, $user_id, $state, $item_name, $price, $item_type, $history);
  saveAndRespond($db, $session_id, $state, $bot_reply, $calculation, ['Check another item', 'Compare', 'Run a stress test', 'Reset budget']);
}

// Everything else - Groq handles naturally
// Check if Groq response contains [READY_TO_CALCULATE] signal
$bot_reply = generateCoachReply($message, $state, $history);

if (str_contains($bot_reply, '[READY_TO_CALCULATE]')) {
  $bot_reply = trim(str_replace('[READY_TO_CALCULATE]', '', $bot_reply));
  $missing   = nextMissingField($state);
  if ($missing === 'savings') {
    $state['expected_next'] = 'savings';
    $state['step']          = 'savings';
    $bot_reply .= "\n\nOne last thing - how much do you currently have saved?";
    $quick_replies = ['£0', '£200', '£500', '£1000', 'Other'];
  } elseif ($missing === 'income') {
    $state['expected_next'] = 'income'; $state['step'] = 'income';
    $bot_reply .= "\n\nWhat is your monthly income after tax?";
    $quick_replies = ['£1500', '£2000', '£2500', '£3000', 'Other'];
  } elseif ($missing === 'expenses') {
    $state['expected_next'] = 'expenses'; $state['step'] = 'expenses';
    $bot_reply .= "\n\nWhat are your monthly expenses?";
    $quick_replies = ['£500', '£800', '£1200', '£1500', 'Other'];
  } elseif ($missing === 'item_price') {
    $state['expected_next'] = 'item_price';
    $bot_reply .= "\n\nHow much does the " . ($state['pending_item'] ?? 'item') . " cost?";
    $quick_replies = ['£500', '£1000', '£5000', '£10000', 'Other'];
  }
}

if ($state['step'] === 'income')      $quick_replies = ['£1500', '£2000', '£2500', '£3000', 'Other'];
elseif ($state['step'] === 'expenses') $quick_replies = ['£500', '£800', '£1200', '£1500', 'Other'];
elseif ($state['step'] === 'savings')  $quick_replies = ['£0', '£500', '£1000', '£2000', 'Other'];
elseif ($state['step'] === 'item')     $quick_replies = ['A laptop £800', 'A phone £600', 'A car £10k', 'A subscription', 'Other'];

saveAndRespond($db, $session_id, $state, $bot_reply, null, $quick_replies);