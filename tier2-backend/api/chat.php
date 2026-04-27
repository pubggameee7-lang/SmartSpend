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

$db = getDB();

$message = correctTypos(trim($_POST['message'] ?? ''));

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
  'pending_sub'           => null,
  'emergency_fund_warned' => false,
];

if (!isset($state['pending_sub'])) $state['pending_sub'] = null;

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
  return (bool) preg_match('/per month|monthly|\/mo|subscription|recurring|per week|weekly/i', $msg);
}

function extractItemName(string $msg): string {
  $name = preg_replace('/£?[\d,]+(\.\d{1,2})?\s*k?/i', '', $msg);
  $name = preg_replace('/\b(a|an|the|for|at|to|buy|buying|get|want|need|afford|per|month|monthly|week|weekly|costs?|priced?|is|was|id like|i want|can i|could i|how about|purchase|no|its|my|idk|if|every|though|think)\b/i', '', $name);
  $name = trim(preg_replace('/\s+/', ' ', $name));
  return $name ?: 'item';
}

function formatMonths(int $months): string {
  if ($months <= 0) return 'not achievable at this rate';
  $y  = floor($months / 12);
  $mo = $months % 12;
  if ($y > 0) return $y . ' year' . ($y > 1 ? 's' : '') . ($mo > 0 ? ' and ' . $mo . ' month' . ($mo > 1 ? 's' : '') : '');
  return $months . ' month' . ($months > 1 ? 's' : '');
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
      $years            = floor($months_to_save / 12);
      $remaining_months = $months_to_save % 12;
      $projections['summary'] = 'Approximately ' . $years . ' year' . ($years > 1 ? 's' : '') .
        ($remaining_months > 0 ? ' and ' . $remaining_months . ' month' . ($remaining_months > 1 ? 's' : '') : '');
    }
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

  $ef = round($expenses * 3, 2);

  if ($item_type === 'recurring') {
    if ($risk === 'green')        $suggestion = 'You can comfortably afford this. Your monthly surplus after this cost will be £' . number_format($surplus_after, 2) . '.';
    elseif ($risk === 'yellow')   $suggestion = 'You can afford this but it will reduce your monthly surplus to £' . number_format($surplus_after, 2) . '. Keep an eye on your overall spending.';
    else                          $suggestion = 'This recurring cost would put your finances under pressure. Consider whether it is essential or if there is a cheaper alternative.';
  } elseif ($risk === 'red' && $surplus > 0) {
    $needed        = $item_price - $savings;
    $cut_per_month = round($needed / 6, 2);
    $suggestion    = 'To afford this in 6 months you would need to save an extra £' . $cut_per_month . ' per month. Reviewing your subscriptions and direct debits could help free up extra cash.';
  } elseif ($risk === 'red') {
    $suggestion = 'Your expenses currently exceed your income so saving for this is not possible right now. Focus on reducing expenses first.';
  } elseif ($risk === 'yellow') {
    $monthly    = round($item_price / max($months_to_save, 1), 2);
    $suggestion = 'You are on track - consider setting aside £' . $monthly . ' per month into a dedicated savings pot to reach your goal faster.';
  } else {
    $suggestion = 'You are in a strong position. Make sure you have at least 3 months of expenses (£' . $ef . ') set aside as an emergency fund before making large purchases.';
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

function emergencyFundWarning(float $savings, float $expenses): ?string {
  $recommended = $expenses * 3;
  if ($savings < $recommended) {
    return 'I also noticed your savings of £' . number_format($savings, 2) . ' are below the recommended 3-month emergency fund of £' . number_format($recommended, 2) . '. It is worth building this up before making large purchases.';
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

function runCalculation(PDO $db, int $session_id, int $user_id, array &$state, string $message, float $price, string $item_type = 'one-time', string $item_name_override = ''): array {
  $item_name = $item_name_override ?: (isset($state['pending_item']) && $state['pending_item']
    ? $state['pending_item']
    : extractItemName($message));

  $calc = calculate($state['income'], $state['expenses'], $state['savings'], $price, $item_type);
  saveResult($db, $session_id, $user_id, $state, $item_name, $price, $item_type, $calc);

  $state['checks'][]     = ['item_name' => $item_name, 'item_price' => $price, 'item_type' => $item_type, 'risk_level' => $calc['risk_level'], 'calc' => $calc];
  $state['pending_item'] = null;
  $state['pending_sub']  = null;
  $state['step']         = 'followup';

  $ai_context = 'Monthly income: £' . $state['income'] . '. Monthly expenses: £' . $state['expenses'] . '. Savings: £' . $state['savings'] . '. Item: ' . $item_name . ' costing £' . $price . ' (' . $item_type . '). Monthly surplus: £' . $calc['surplus'] . '. Monthly surplus after cost: £' . $calc['surplus_after'] . '. Risk level: ' . $calc['risk_level'] . '. Months to save: ' . $calc['months_to_save'] . '.';
  $ai_text    = getAIExplanation($ai_context, $calc['risk_level']);

  $risk_label  = $calc['risk_level'] === 'green' ? 'Good news' : ($calc['risk_level'] === 'yellow' ? 'Heads up' : 'Warning');
  $bot_reply   = $risk_label . ' - here is your result for ' . $item_name . ".\n\n" . $ai_text;
  $calculation = array_merge($calc, ['item_name' => $item_name, 'item_price' => $price, 'item_type' => $item_type]);

  return [$bot_reply, $calculation];
}

function getResumePrompt(array $state): string {
  switch ($state['step']) {
    case 'income':    return 'Whenever you are ready, just tell me your monthly income after tax.';
    case 'expenses':  return 'Whenever you are ready, tell me your total monthly expenses.';
    case 'savings':   return 'Whenever you are ready, tell me how much you currently have saved.';
    case 'item':      return 'Whenever you are ready, tell me what you would like to check and how much it costs.';
    case 'followup':  return 'Would you like to check another item, run a stress test, or start over?';
    default:          return 'Type anything to get started.';
  }
}

function detectEmotion(string $msg): ?string {
  $has_money = (bool) preg_match('/money|budget|afford|financ|debt|bills|rent|savings|income|expenses|cash|broke|spend|cost|price|salary|wage/i', $msg);
  if (preg_match('/stress|anxious|anxiety|worried|worry|scared|overwhelm|panic|struggling|drowning|confused|dont know what to do|no idea|helpless|hopeless/i', $msg)) {
    return $has_money ? 'stress' : 'general_stress';
  }
  if (preg_match('/happy|excited|great|amazing|doing well|proud|finally|sorted|on track|feeling good/i', $msg)) return 'positive';
  if (preg_match('/embarrass|ashamed|stupid|bad with money|terrible|awful|mess|disaster/i', $msg))            return 'shame';
  return null;
}

function detectContext(string $msg): ?string {
  if (preg_match('/how.*(save|saving)|what.*(do with.*saving|saving.*do)|best way.*save|savings advice|grow.*savings/i', $msg)) return 'savings_advice';
  if (preg_match('/\bhouse\b|first home|buy.*home|property|mortgage|deposit/i', $msg))                                        return 'house';
  if (preg_match('/\binvest\b|investment|stocks|shares|grow.*money/i', $msg))                                                  return 'investment';
  if (preg_match('/\bdebt\b|overdraft|credit card|\bowe\b/i', $msg) && !preg_match('/loan/i', $msg))                          return 'debt';
  if (preg_match('/pension|retirement|retire/i', $msg))                                                                        return 'pension';
  if (preg_match('/redundan|lost.*job|made redundant|unemployed/i', $msg))                                                     return 'redundancy';
  if (preg_match('/\bstudent\b|\buni\b|university|student loan/i', $msg))                                                      return 'student';
  if (preg_match('/self.?employed|freelance|contractor|sole trader/i', $msg))                                                  return 'self_employed';
  return null;
}

function handleLoanQuestion(array $state): string {
  if (count($state['checks']) > 0) {
    $last       = end($state['checks']);
    $item_name  = $last['item_name'];
    $item_price = $last['item_price'];
    $surplus    = $last['calc']['surplus'];
    $months     = $last['calc']['months_to_save'];
    $risk       = $last['risk_level'];

    $save_option = ($surplus > 0 && $months > 0)
      ? 'By saving your £' . number_format($surplus, 2) . ' monthly surplus you could reach £' . number_format($item_price, 2) . ' in about ' . formatMonths($months) . ' without paying any interest.'
      : '';

    if ($risk === 'green') {
      return 'You are already in a strong position to afford the ' . $item_name . ' without a loan - taking one would mean paying interest unnecessarily. ' . $save_option;
    } else {
      return 'Taking a loan for the ' . $item_name . ' would mean paying interest on top of the £' . number_format($item_price, 2) . ' purchase price, making it more expensive overall. ' . $save_option . "\n\nIf you do consider a loan, compare interest rates carefully and factor the monthly repayments into your budget. I am not a financial advisor so please do your own research before committing to any borrowing.";
    }
  }
  return 'Taking a loan means paying interest on top of the purchase price, making it more expensive overall. It is generally worth saving up where possible. If you are considering a loan, compare interest rates carefully and factor monthly repayments into your budget. I am not a financial advisor so please do your own research.';
}

function buildContextualResponse(string $context, array $state): string {
  $resume = getResumePrompt($state);
  switch ($context) {
    case 'savings_advice':
      return "Great question. There are a few ways people in the UK typically save - worth researching each:\n\n"
           . "- A Cash ISA lets you save up to £20,000 per year completely tax-free.\n"
           . "- A dedicated savings pot separate from your main account keeps money out of sight.\n"
           . "- High interest savings accounts are worth comparing as rates vary significantly.\n"
           . "- The pay yourself first method means setting up a standing order on payday.\n"
           . "- Round-up features on banking apps automatically save small amounts per transaction.\n\n"
           . "These are general options worth researching. {$resume}";
    case 'house':
      return "Buying a home is a big goal. A few things worth knowing:\n\n"
           . "- Most lenders require a deposit of at least 5-10% of the property value.\n"
           . "- The Lifetime ISA is worth researching if you are under 40 and saving for your first home - the government adds a 25% bonus on up to £4,000 per year.\n"
           . "- A mortgage in principle can give you a clearer picture of what you might be able to borrow.\n\n"
           . "I am not a financial advisor so please do your own research. Would you like to check if you can afford a deposit? {$resume}";
    case 'investment':
      return "Investing is worth learning about, though it comes with risks saving does not.\n\n"
           . "- A Stocks and Shares ISA lets you invest up to £20,000 per year with gains tax-free.\n"
           . "- Investments can go down as well as up - you could get back less than you put in.\n"
           . "- For personalised investment decisions speak to an independent financial advisor.\n\n"
           . "{$resume}";
    case 'debt':
      return "Dealing with debt is stressful but there are clear strategies:\n\n"
           . "- Generally clear high-interest debt before focusing on savings.\n"
           . "- The snowball method pays smallest debts first. The avalanche method tackles highest interest first.\n"
           . "- Even while paying debt, a small emergency fund is worth building.\n\n"
           . "{$resume}";
    case 'pension':
      return "Pension planning is important, especially the earlier you start.\n\n"
           . "- If employed, your workplace pension likely includes employer contributions - check you are enrolled.\n"
           . "- If self-employed, a personal pension is worth researching.\n"
           . "- Pensions are complex so an independent financial advisor can give personalised guidance.\n\n"
           . "{$resume}";
    case 'redundancy':
      return "Being made redundant is really tough.\n\n"
           . "- If employed for 2 or more years you may be entitled to statutory redundancy pay.\n"
           . "- Universal Credit is available for people on a low income or out of work.\n"
           . "- Your emergency fund is exactly what it is there for.\n\n"
           . "{$resume}";
    case 'student':
      return "A couple of useful things as a student in the UK:\n\n"
           . "- Student loan repayments for Plan 2 only kick in when you earn above £27,295 per year.\n"
           . "- You can open a Cash ISA as a student with the £20,000 annual allowance.\n\n"
           . "{$resume}";
    case 'self_employed':
      return "Being self-employed means your finances work differently.\n\n"
           . "- Set aside 20-30% of income for tax and National Insurance.\n"
           . "- You do not get employer pension contributions so a personal pension is worth considering.\n"
           . "- Budget based on your lowest typical month for safety.\n\n"
           . "{$resume}";
    default:
      return "{$resume}";
  }
}

function buildEmotionalResponse(string $emotion, array $state): string {
  $resume = getResumePrompt($state);
  switch ($emotion) {
    case 'stress':
      return "I hear you - money stress is really common and it does not mean you are bad with money. It usually just means nobody showed you how to track it properly.\n\nSmartSpend can help you get a clear picture of where you stand. There are no wrong answers here - just numbers.\n\n{$resume}";
    case 'general_stress':
      return "I am sorry to hear things are tough right now. I am here to help with the financial side whenever you are ready - sometimes getting clarity on your money situation can help reduce overall stress.\n\n{$resume}";
    case 'shame':
      return "Please do not be hard on yourself - most people were never taught how to manage money and it is not a reflection of your worth.\n\nLet's look at the numbers together, no judgment.\n\n{$resume}";
    case 'positive':
      return "That is great to hear! Let's keep that momentum going.\n\n{$resume}";
    default:
      return "{$resume}";
  }
}

function saveAndRespond(PDO $db, int $session_id, array $state, string $bot_reply, ?array $calculation, array $quick_replies): void {
  $stmt = $db->prepare('INSERT INTO conversation_state (session_id, state) VALUES (?, ?) ON DUPLICATE KEY UPDATE state = VALUES(state), updated_at = NOW()');
  $stmt->execute([$session_id, json_encode($state)]);
  $calc_json = $calculation ? json_encode($calculation) : null;
  $stmt = $db->prepare('INSERT INTO messages (session_id, role, content, calculation) VALUES (?, ?, ?, ?)');
  $stmt->execute([$session_id, 'bot', $bot_reply, $calc_json]);
  echo json_encode(['success' => true, 'bot_reply' => $bot_reply, 'calculation' => $calculation, 'quick_replies' => $quick_replies, 'step' => $state['step']]);
  exit;
}

$bot_reply     = '';
$calculation   = null;
$quick_replies = [];
$lower         = strtolower(trim($message));

// ── Global: Reset ─────────────────────────────────────────
if ($lower === 'reset' || $lower === 'start over' || $lower === 'restart') {
  $state = ['step' => 'income', 'income' => null, 'expenses' => null, 'savings' => null, 'checks' => [], 'pending_item' => null, 'pending_sub' => null, 'emergency_fund_warned' => false];
  saveAndRespond($db, $session_id, $state, "No problem - let's start fresh. What is your monthly income after tax?", null, ['£1500', '£2000', '£2500', '£3000', 'Other']);
}

// ── Global: Other ─────────────────────────────────────────
if ($lower === 'other') {
  $bot_reply = match($state['step']) {
    'income'      => 'Please type your monthly income as a number - for example 2500 or 2.5k.',
    'expenses'    => 'Please type your total monthly expenses as a number - for example 1200.',
    'savings'     => 'Please type your current savings as a number. Type 0 if you have none.',
    'item'        => 'What would you like to buy or check? For example: a laptop for £800, a car for £10k, or say "a subscription" for a monthly cost.',
    'followup'    => 'What would you like to do? You can check another item, run a stress test, or type reset to start over.',
    'stress_test' => 'What percentage income drop would you like to simulate? For example: 20',
    default       => 'Please type your response.',
  };
  saveAndRespond($db, $session_id, $state, $bot_reply, null, []);
}

// ── Global: Loan ──────────────────────────────────────────
if (preg_match('/\bloan\b|get.*credit|borrow.*money|finance.*it|buy.*on.*credit/i', $message)) {
  saveAndRespond($db, $session_id, $state, handleLoanQuestion($state), null, ['Check another item', 'Run a stress test', 'Reset budget', 'Other']);
}

// ── Global: Correction ────────────────────────────────────
if (preg_match('/^no[\s,.!]|^no$|^wait|^sorry|^actually|^i meant|^that.s wrong|^wrong/i', $message)
  && !in_array($state['step'], ['greeting', 'followup', 'stress_test', 'sub_name', 'sub_price', 'sub_frequency', 'sub_duration'])) {
  $prev_step = match($state['step']) {
    'expenses' => 'income',
    'savings'  => 'expenses',
    'item'     => 'savings',
    default    => $state['step'],
  };
  $state['step'] = $prev_step;
  $bot_reply = match($prev_step) {
    'income'   => "No problem - let's correct that. What is your monthly income after tax?",
    'expenses' => "No problem - let's correct that. What are your total monthly expenses?",
    'savings'  => "No problem - let's correct that. How much do you currently have saved?",
    default    => "No problem - please type the correct value.",
  };
  saveAndRespond($db, $session_id, $state, $bot_reply, null, ['Other']);
}

// ── Global: Escape stress test ────────────────────────────
if ($state['step'] === 'stress_test' && !preg_match('/\d|total|loss|drop/i', $message)) {
  $state['step'] = 'item';
  saveAndRespond($db, $session_id, $state, "No problem - let's get back to checking affordability. What would you like to buy and how much does it cost?", null, ['A laptop £800', 'A phone £600', 'A car £10k', 'A subscription', 'Other']);
}

// ── Global: Emotional detection ───────────────────────────
$emotion = detectEmotion($message);
if ($emotion && !in_array($state['step'], ['greeting', 'sub_name', 'sub_price', 'sub_frequency', 'sub_duration'])) {
  saveAndRespond($db, $session_id, $state, buildEmotionalResponse($emotion, $state), null, ["Yes let's continue", 'Other']);
}

// ── Global: Contextual topics ─────────────────────────────
$context = detectContext($message);
if ($context && !in_array($state['step'], ['sub_name', 'sub_price', 'sub_frequency', 'sub_duration'])) {
  saveAndRespond($db, $session_id, $state, buildContextualResponse($context, $state), null, ["Yes let's continue", 'Start budget check', 'Other']);
}

// ── Subscription mini-flow ────────────────────────────────
if ($state['step'] === 'sub_name') {
  $sub_name = trim($message);
  if (strlen($sub_name) < 2 || is_numeric($sub_name)) {
    saveAndRespond($db, $session_id, $state, 'What is the name of the subscription or class? For example: Netflix, Pilates, Gym.', null, ['Netflix', 'Spotify', 'Gym membership', 'Other']);
  }
  $state['pending_sub']['name'] = $sub_name;
  $state['step']                = 'sub_price';
  saveAndRespond($db, $session_id, $state, 'How much does ' . $sub_name . ' cost per session or per month?', null, ['£5', '£10', '£20', '£50', 'Other']);
}

if ($state['step'] === 'sub_price') {
  $price = extractNumber($message);
  if (!$price || $price <= 0) {
    saveAndRespond($db, $session_id, $state, 'How much does it cost? Just type the number - for example 12.99 or 50.', null, ['£5', '£10', '£20', '£50', 'Other']);
  }
  $is_weekly  = preg_match('/per week|weekly|a week|each week/i', $message);
  $is_monthly = preg_match('/per month|monthly/i', $message);
  $state['pending_sub']['raw_price']  = $price;
  $state['pending_sub']['is_weekly']  = (bool) $is_weekly;
  $state['pending_sub']['is_monthly'] = (bool) $is_monthly;
  $state['step'] = 'sub_frequency';

  if ($is_monthly) {
    $state['pending_sub']['monthly_cost'] = $price;
    $state['step'] = 'sub_duration';
    saveAndRespond($db, $session_id, $state, 'Got it - £' . number_format($price, 2) . ' per month. Is this ongoing or for a fixed period - for example 3 months?', null, ['Ongoing', '1 month', '3 months', '6 months', 'Other']);
  } elseif ($is_weekly) {
    saveAndRespond($db, $session_id, $state, 'Got it - £' . number_format($price, 2) . ' per session. Do you go every week or does it vary?', null, ['Every week', 'About 3 times a month', 'About 2 times a month', 'It varies', 'Other']);
  } else {
    saveAndRespond($db, $session_id, $state, 'Got it - £' . number_format($price, 2) . '. Is this per session, per week, or per month?', null, ['Per session', 'Per week', 'Per month', 'Other']);
  }
}

if ($state['step'] === 'sub_frequency') {
  $raw_price    = $state['pending_sub']['raw_price'] ?? 0;
  $monthly_cost = 0;

  if (preg_match('/per month|monthly/i', $message))                      $monthly_cost = $raw_price;
  elseif (preg_match('/per week|weekly|every week|all 4/i', $message))   $monthly_cost = round($raw_price * 4.33, 2);
  elseif (preg_match('/3 time|3 week|three|about 3/i', $message))        $monthly_cost = round($raw_price * 3, 2);
  elseif (preg_match('/2 time|2 week|twice|two|about 2/i', $message))    $monthly_cost = round($raw_price * 2, 2);
  elseif (preg_match('/once|1 time|1 week|one/i', $message))             $monthly_cost = round($raw_price * 1, 2);
  elseif (preg_match('/varies|vary|sometimes|it depends/i', $message))   $monthly_cost = round($raw_price * 3, 2);
  elseif (($n = extractNumber($message)) && $n > 0)                       $monthly_cost = round($raw_price * $n, 2);
  else {
    saveAndRespond($db, $session_id, $state, 'How often do you go per month? For example: every week, 3 times, or it varies?', null, ['Every week', 'About 3 times', 'About 2 times', 'It varies', 'Other']);
  }

  if ($monthly_cost > 0) {
    $state['pending_sub']['monthly_cost'] = $monthly_cost;
    $state['step'] = 'sub_duration';
    saveAndRespond($db, $session_id, $state, 'That works out to approximately £' . number_format($monthly_cost, 2) . ' per month. Is this ongoing or for a fixed period - for example 3 months of classes?', null, ['Ongoing', '1 month', '3 months', '6 months', 'Other']);
  }
}

if ($state['step'] === 'sub_duration') {
  $monthly_cost = $state['pending_sub']['monthly_cost'] ?? 0;
  $sub_name     = $state['pending_sub']['name'] ?? 'subscription';
  $months       = extractNumber($message);
  $is_ongoing   = preg_match('/ongoing|long.?term|permanent|forever|no end|open/i', $message);

  if ($is_ongoing) {
    $state['pending_item'] = $sub_name;
    [$bot_reply, $calculation] = runCalculation($db, $session_id, $user_id, $state, 'recurring monthly', $monthly_cost, 'recurring', $sub_name);
    saveAndRespond($db, $session_id, $state, $bot_reply, $calculation, ['Check another item', 'Run a stress test', 'Reset budget']);
  } elseif ($months && $months > 0) {
    $total_cost = round($monthly_cost * $months, 2);
    $state['pending_item'] = $sub_name;
    [$bot_reply, $calculation] = runCalculation($db, $session_id, $user_id, $state, 'one-time', $total_cost, 'one-time', $sub_name . ' (' . $months . ' months)');
    saveAndRespond($db, $session_id, $state, $bot_reply, $calculation, ['Check another item', 'Run a stress test', 'Reset budget']);
  } else {
    saveAndRespond($db, $session_id, $state, 'Is this an ongoing cost or for a fixed number of months?', null, ['Ongoing', '1 month', '3 months', '6 months', 'Other']);
  }
}

// ── Conversation flow ─────────────────────────────────────
switch ($state['step']) {

  case 'greeting':
    $emotion = detectEmotion($message);
    if ($emotion) {
      $state['step'] = 'income';
      saveAndRespond($db, $session_id, $state, buildEmotionalResponse($emotion, $state), null, ["Yes let's start", 'Other']);
    } elseif (preg_match('/hi|hello|hey|start|help|afford|budget|check|buy|can i/i', $message)) {
      $state['step'] = 'income';
      $bot_reply     = "Hello! I am SmartSpend, your personal budget assistant. I can help you work out if you can afford something, check your financial health, and give you personalised suggestions.\n\nTo get started - what is your monthly income after tax?";
      $quick_replies = ['£1500', '£2000', '£2500', '£3000', 'Other'];
    } else {
      $bot_reply     = "Hi there! I am SmartSpend. I can help you figure out if you can afford something, check your budget health, or run a stress test. What would you like to do?";
      $quick_replies = ['Check if I can afford something', 'Check my budget health', 'Run a stress test', 'Other'];
    }
    break;

  case 'income':
    $num = extractNumber($message);
    if ($num && $num > 0) {
      $state['income'] = $num;
      $state['step']   = 'expenses';
      $bot_reply       = 'Got it - monthly income of £' . number_format($num, 2) . '. Now, what are your total monthly expenses? Include rent, food, bills, transport and subscriptions.';
      $quick_replies   = ['£500', '£800', '£1200', '£1500', 'Other'];
    } else {
      $bot_reply     = 'What is your monthly income after tax? Just type the number - for example 2500 or 2.5k.';
      $quick_replies = ['£1500', '£2000', '£2500', '£3000', 'Other'];
    }
    break;

  case 'expenses':
    $num = extractNumber($message);
    if ($num !== null && $num >= 0) {
      $state['expenses'] = $num;
      $state['step']     = 'savings';
      if ($num >= $state['income']) {
        $bot_reply = 'Your expenses of £' . number_format($num, 2) . ' are equal to or higher than your income - you have no monthly surplus. How much do you currently have saved?';
      } else {
        $surplus   = $state['income'] - $num;
        $bot_reply = 'Monthly expenses of £' . number_format($num, 2) . ' - that leaves you a surplus of £' . number_format($surplus, 2) . ' per month. How much do you currently have saved?';
      }
      $quick_replies = ['£0', '£500', '£1000', '£2000', 'Other'];
    } else {
      $bot_reply     = 'Please enter your total monthly expenses as a number - for example 1200 or 1.2k.';
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
      $bot_reply     = 'Savings of £' . number_format($num, 2) . ' noted.' . $ef_note . "\n\nWhat would you like to buy or check? Tell me the item and the price.";
      $quick_replies = ['A laptop £800', 'A phone £600', 'A car £10k', 'A subscription', 'Other'];
    } else {
      $bot_reply     = 'Please enter your savings as a number. Type 0 if you have none.';
      $quick_replies = ['£0', '£500', '£1000', 'Other'];
    }
    break;

  case 'item':
    if (preg_match('/stress|lost.*job|what if.*lost|redundan/i', $message)) {
      $state['step'] = 'stress_test';
      saveAndRespond($db, $session_id, $state, "Let's run a stress test. What percentage drop in income would you like to simulate?", null, ['20% drop', '50% drop', 'Total loss', 'Other']);
    }
    if (count($state['checks']) > 0 && preg_match('/compare|vs|versus|better|cheaper|which/i', $message)) {
      $last = end($state['checks']);
      saveAndRespond($db, $session_id, $state, 'Your last check was ' . $last['item_name'] . ' at £' . $last['item_price'] . ' with a ' . $last['risk_level'] . ' risk. What would you like to compare it with?', null, ['A laptop £800', 'A phone £600', 'Other']);
    }
    if (preg_match('/\bsubscription\b|\bclass(es)?\b|\bpilates\b|\byoga\b|\bgym\b|\bmembership\b/i', $message) && !extractNumber($message)) {
      $state['step']        = 'sub_name';
      $state['pending_sub'] = [];
      $possible_name        = trim(preg_replace('/\b(a|an|the|subscription|class|classes|my|want|id like|check)\b/i', '', $message));
      $possible_name        = trim(preg_replace('/\s+/', ' ', $possible_name));
      if (strlen($possible_name) > 1) {
        $state['pending_sub']['name'] = $possible_name;
        $state['step'] = 'sub_price';
        saveAndRespond($db, $session_id, $state, 'How much does ' . $possible_name . ' cost per session or per month?', null, ['£5', '£10', '£20', '£50', 'Other']);
      } else {
        saveAndRespond($db, $session_id, $state, 'What is the subscription or class called?', null, ['Netflix', 'Spotify', 'Gym membership', 'Pilates', 'Other']);
      }
    }
    $price = extractNumber($message);
    if (!$price && isset($state['pending_item']) && $state['pending_item']) {
      saveAndRespond($db, $session_id, $state, 'How much does the ' . $state['pending_item'] . ' cost? Just type the price - for example 5000 or 5k.', null, ['£1000', '£5000', '£10000', '£20000', 'Other']);
    }
    if ($price && $price > 0) {
      $item_type = isRecurring($message) ? 'recurring' : 'one-time';
      [$bot_reply, $calculation] = runCalculation($db, $session_id, $user_id, $state, $message, $price, $item_type);
      $quick_replies = ['Check another item', 'Compare with something else', 'Run a stress test', 'Reset budget'];
    } else {
      $possible_item = preg_replace('/\b(a|an|the|buy|want|need|afford|get|id like|i want|can i|could i|how about)\b/i', '', $message);
      $possible_item = trim(preg_replace('/\s+/', ' ', $possible_item));
      if (strlen($possible_item) > 2) {
        $state['pending_item'] = $possible_item;
        $bot_reply             = 'How much does the ' . $possible_item . ' cost?';
        $quick_replies         = ['£500', '£1000', '£5000', '£10000', 'Other'];
      } else {
        $bot_reply     = "What would you like to buy and how much does it cost? For example: a laptop for £800, a car for £10k, or say 'a subscription' for a monthly cost.";
        $quick_replies = ['A laptop £800', 'A phone £600', 'A car £10k', 'A subscription', 'Other'];
      }
    }
    break;

  case 'followup':
    $price  = extractNumber($message);
    $intent = 'other';

    if ($price && $price > 0) {
      $intent = classifyIntent($message, $state['step'], $state);
    }

    if ($intent === 'provide_price' && $price && $price > 0) {
      $item_type = isRecurring($message) ? 'recurring' : 'one-time';
      [$bot_reply, $calculation] = runCalculation($db, $session_id, $user_id, $state, $message, $price, $item_type);
      $quick_replies = ['Check another item', 'Compare with something else', 'Run a stress test', 'Reset budget'];
      break;
    }

    if ($intent === 'express_concern' || preg_match('/not sure|idk|doubt|worried|uncertain|unexpected|things come up|every month though|always save|guarantee/i', $message)) {
      $bot_reply     = handleSavingsConcern($message, $state);
      $quick_replies = ['Check another item', 'Run a stress test', 'Reset budget', 'Other'];
      break;
    }

    // Dream goal / emotional attachment to an item
    if (preg_match('/dream|really want|love it|obsessed|goal|always wanted|my heart|set on|deserve|been wanting/i', $message) && !$price) {
      if (count($state['checks']) > 0) {
        $last      = end($state['checks']);
        $item      = $last['item_name'];
        $months    = $last['calc']['months_to_save'];
        $surplus   = $last['calc']['surplus'];
        $time      = formatMonths($months);
        $bot_reply = "That is completely valid - having a goal you are excited about is actually one of the best motivators for saving consistently.\n\nBased on your numbers, if you set aside your £" . number_format($surplus, 2) . " surplus each month, you could have the {$item} in {$time}. That is genuinely something worth working towards.";
      } else {
        $bot_reply = "Having a dream goal is one of the best motivators for getting your finances in order. Would you like to check if you can afford it and how long it would take to save up?";
      }
      $quick_replies = ['Check another item', 'Run a stress test', 'Reset budget', 'Other'];
      break;
    }

    // Handle casual greetings in followup
    if (preg_match('/^(hi|hello|hey|hiya|sup|yo)[\s!.]*$/i', $message)) {
      $bot_reply     = "Hey! We were just looking at your budget. Would you like to check another item, run a stress test, or start over?";
      $quick_replies = ['Check another item', 'Run a stress test', 'Reset budget', 'Other'];
      break;
    }

    // Subscription trigger in followup
    if (preg_match('/\bsubscription\b|\bclass(es)?\b|\bpilates\b|\byoga\b|\bgym\b|\bmembership\b/i', $message) && !$price) {
      $state['step']        = 'sub_name';
      $state['pending_sub'] = [];
      $possible_name        = trim(preg_replace('/\b(a|an|the|subscription|class|classes|my|want|id like|check|another)\b/i', '', $message));
      $possible_name        = trim(preg_replace('/\s+/', ' ', $possible_name));
      if (strlen($possible_name) > 1) {
        $state['pending_sub']['name'] = $possible_name;
        $state['step'] = 'sub_price';
        saveAndRespond($db, $session_id, $state, 'How much does ' . $possible_name . ' cost per session or per month?', null, ['£5', '£10', '£20', '£50', 'Other']);
      } else {
        saveAndRespond($db, $session_id, $state, 'What is the subscription or class called?', null, ['Netflix', 'Spotify', 'Gym membership', 'Pilates', 'Other']);
      }
    }

    if (preg_match('/another|check|buy|afford|else|more|yes.*continu|continu|start.*budget/i', $message)) {
      $state['step'] = 'item';
      $bot_reply     = "Sure - your income, expenses and savings are still saved. What else would you like to check?";
      $quick_replies = ['A laptop £800', 'A phone £600', 'A car £10k', 'A subscription', 'Other'];

    } elseif (preg_match('/compare|vs|versus|which|better/i', $message)) {
      if (count($state['checks']) > 0) {
        $last          = end($state['checks']);
        $bot_reply     = 'Your last check was ' . $last['item_name'] . ' at £' . $last['item_price'] . ' with a ' . $last['risk_level'] . ' risk. What would you like to compare it with?';
        $state['step'] = 'item';
      } else {
        $bot_reply     = 'Tell me the first item and price and I will help you compare.';
        $state['step'] = 'item';
      }
      $quick_replies = ['A laptop £800', 'A phone £600', 'Other'];

    } elseif (preg_match('/stress|lost.*job|what if.*income|redundan|unemployed/i', $message)) {
      $state['step'] = 'stress_test';
      $bot_reply     = "Let's run a stress test. What percentage drop in income are you worried about?";
      $quick_replies = ['20% drop', '50% drop', 'Total loss', 'Other'];

    } elseif (preg_match('/cut|reduce|save more/i', $message)) {
      $cut_amount = extractNumber($message);
      if ($cut_amount && $state['income'] && $state['expenses']) {
        $new_expenses  = max(0, $state['expenses'] - $cut_amount);
        $new_surplus   = $state['income'] - $new_expenses;
        $bot_reply     = 'If you cut £' . number_format($cut_amount, 2) . ' from your expenses, your new monthly surplus would be £' . number_format($new_surplus, 2) . '. Would you like to re-run an affordability check with these updated figures?';
        $quick_replies = ['Yes recalculate', 'No thanks', 'Other'];
      } else {
        $bot_reply     = 'How much are you thinking of cutting from your expenses?';
        $quick_replies = ['£50', '£100', '£200', 'Other'];
      }

    } elseif (preg_match('/yes.*recalculate|recalculate/i', $message)) {
      $state['step'] = 'item';
      $bot_reply     = "Great - what would you like to check with the updated figures?";
      $quick_replies = ['A laptop £800', 'A phone £600', 'Other'];

    } elseif (preg_match('/reset|start over|new budget|new figures/i', $message)) {
      $state         = ['step' => 'income', 'income' => null, 'expenses' => null, 'savings' => null, 'checks' => [], 'pending_item' => null, 'pending_sub' => null, 'emergency_fund_warned' => false];
      $bot_reply     = "No problem - let's start fresh. What is your monthly income after tax?";
      $quick_replies = ['£1500', '£2000', '£2500', '£3000', 'Other'];

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
      saveAndRespond($db, $session_id, $state, 'What percentage drop would you like to simulate? For example: 20%, 50%, or total loss.', null, ['20% drop', '50% drop', 'Total loss', 'Other']);
    }
    $new_surplus   = $new_income - $state['expenses'];
    $months_runway = ($state['savings'] > 0 && $state['expenses'] > 0) ? round($state['savings'] / $state['expenses'], 1) : 0;
    $drop_label    = $total_loss ? 'a total loss of income' : 'a ' . $pct . '% drop in income';
    if ($new_surplus > 0) {
      $bot_reply = 'Stress test result - with ' . $drop_label . ', your income would drop to £' . number_format($new_income, 2) . ' and your monthly surplus would reduce to £' . number_format($new_surplus, 2) . '. You could still cover expenses but with much less breathing room.';
    } elseif ($new_surplus == 0) {
      $bot_reply = 'Stress test result - with ' . $drop_label . ', your income would exactly cover your expenses with nothing left over. Your savings of £' . number_format($state['savings'], 2) . ' would provide a buffer if needed.';
    } else {
      $shortfall = abs($new_surplus);
      $bot_reply = 'Stress test result - with ' . $drop_label . ', your income would drop to £' . number_format($new_income, 2) . ' and you would have a monthly shortfall of £' . number_format($shortfall, 2) . '. Your savings of £' . number_format($state['savings'], 2) . ' would last approximately ' . $months_runway . ' months.';
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

saveAndRespond($db, $session_id, $state, $bot_reply, $calculation, $quick_replies);