<?php
ini_set('session.cookie_path', '/');
ini_set('session.cookie_samesite', 'Lax');

require_once '../config/db.php';
require_once 'ai.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['success' => false, 'error' => 'Not logged in.']);
  exit;
}

$user_id     = $_SESSION['user_id'];
$raw_message = trim($_POST['message'] ?? '');
$session_id  = intval($_POST['session_id'] ?? 0);

if ($raw_message === '') { echo json_encode(['success'=>false,'error'=>'Empty message.']); exit; }
if ($session_id === 0)   { echo json_encode(['success'=>false,'error'=>'Invalid session.']); exit; }

$db      = getDB();
$message = correctTypos(trim($raw_message));
$lower   = strtolower(trim($message));

$stmt = $db->prepare('INSERT INTO messages (session_id, role, content) VALUES (?, ?, ?)');
$stmt->execute([$session_id, 'user', $raw_message]);

$stmt = $db->prepare('SELECT state FROM conversation_state WHERE session_id = ?');
$stmt->execute([$session_id]);
$row  = $stmt->fetch();

$state = $row ? json_decode($row['state'], true) : [
  'step'                  => 'greeting',
  'income'                => null,
  'expenses'              => null,
  'savings'               => null,
  'active_goal'           => null,
  'loan'                  => null,
  'subscriptions'         => [],
  'checks'                => [],
  'emergency_fund_warned' => false,
];

foreach (['active_goal','loan','subscriptions','checks'] as $k) {
  if (!isset($state[$k])) $state[$k] = in_array($k, ['subscriptions','checks']) ? [] : null;
}

if (empty($state['income']) || empty($state['expenses']) || !isset($state['savings'])) {
  $stmt = $db->prepare('SELECT saved_income, saved_expenses, saved_savings FROM users WHERE id = ?');
  $stmt->execute([$user_id]);
  $saved = $stmt->fetch();
  if ($saved) {
    if (empty($state['income'])    && !empty($saved['saved_income']))   $state['income']   = floatval($saved['saved_income']);
    if (empty($state['expenses'])  && !empty($saved['saved_expenses'])) $state['expenses'] = floatval($saved['saved_expenses']);
    if (!isset($state['savings'])  && $saved['saved_savings'] !== null) $state['savings']  = floatval($saved['saved_savings']);
    if (!empty($state['income']) && !empty($state['expenses']) && isset($state['savings']) && $state['step'] === 'greeting') {
      $state['step'] = 'active';
      $state['needs_memory_confirm'] = true;
    }
  }
}

$stmt = $db->prepare('SELECT role, content FROM messages WHERE session_id = ? ORDER BY created_at DESC LIMIT 16');
$stmt->execute([$session_id]);
$history_raw = array_reverse($stmt->fetchAll());
$history = array_values(array_filter($history_raw, function($m) use ($raw_message) {
  if ($m['role'] === 'user' && $m['content'] === $raw_message) return false;
  if ($m['role'] === 'bot') {
    $m['content'] = preg_replace('/\n\nLoan repayment:.*$/s', '', $m['content']);
    $m['content'] = preg_replace('/\n\nSaving £.*\.$/s', '', $m['content']);
    $m['content'] = preg_replace('/\n\nWith reduced expenses.*\.$/s', '', $m['content']);
    $m['content'] = preg_replace('/\n\nWith new income.*\.$/s', '', $m['content']);
  }
  return true;
}));

function cleanItemName(string $name): string {
  $clean = preg_replace('/[\s\xA0]+£?\d[\d,.]*k?\s*$/iu', '', trim($name));
  $clean = preg_replace('/\s+/', ' ', $clean);
  $clean = trim($clean);
  return strlen($clean) > 0 ? $clean : $name;
}

function emergencySavingsAdvice(array $state, ?float $monthlySaving = null): string {
  $income   = floatval($state['income'] ?? 0);
  $expenses = floatval($state['expenses'] ?? 0);
  $savings  = floatval($state['savings'] ?? 0);
  $surplus  = max(0, $income - $expenses);
  $target3  = $expenses * 3;
  $target6  = $expenses * 6;
  $gap3     = max(0, $target3 - $savings);
  $gap6     = max(0, $target6 - $savings);
  if ($monthlySaving === null) {
    $recommended  = $surplus > 0 ? min($surplus, max($gap3 / 3, $surplus * 0.5)) : 0;
    $monthlySaving = round($recommended / 10) * 10;
  }
  $months3 = $monthlySaving > 0 ? ceil($gap3 / $monthlySaving) : null;
  $months6 = $monthlySaving > 0 ? ceil($gap6 / $monthlySaving) : null;
  $reply   = "Based on your numbers, I would prioritise your emergency fund first.

";
  $reply  .= "Your monthly surplus is £".number_format($surplus,2).". A sensible savings target would be about £".number_format($monthlySaving,2)." per month.

";
  $reply  .= "Your 3-month emergency fund target is £".number_format($target3,2).". ";
  $reply  .= $gap3 <= 0 ? "You are already there.
" : "You have £".number_format($savings,2)." saved, so you need £".number_format($gap3,2)." more - about {$months3} month".($months3 == 1 ? "" : "s")." at £".number_format($monthlySaving,2)."/month.
";
  $reply  .= "Your 6-month target is £".number_format($target6,2).". ";
  $reply  .= $gap6 <= 0 ? "You are already there.

" : "You need £".number_format($gap6,2)." more - about {$months6} month".($months6 == 1 ? "" : "s").".

";
  $reply  .= "After saving £".number_format($monthlySaving,2).", you would still have around £".number_format(max(0,$surplus-$monthlySaving),2)." left each month.";
  return $reply;
}

function respond(PDO $db, int $sid, array $state, string $reply, ?array $calc, array $qr, ?array $comparison_calc = null): void {
  $state['last_quick_replies'] = $qr;
  $stmt = $db->prepare('INSERT INTO conversation_state (session_id, state) VALUES (?, ?) ON DUPLICATE KEY UPDATE state=VALUES(state), updated_at=NOW()');
  $stmt->execute([$sid, json_encode($state)]);
  $save_calc = $calc;
  if ($comparison_calc) $save_calc = array_merge($calc ?? [], ['comparison_calc' => $comparison_calc]);
  $stmt = $db->prepare('INSERT INTO messages (session_id, role, content, calculation) VALUES (?, ?, ?, ?)');
  $stmt->execute([$sid, 'bot', $reply, $save_calc ? json_encode($save_calc) : null]);
  echo json_encode(['success'=>true,'bot_reply'=>$reply,'calculation'=>$calc,'quick_replies'=>$qr,'step'=>$state['step'],'comparison_calc'=>$comparison_calc]);
  exit;
}

function runCalc(PDO $db, int $sid, int $uid, array &$state, string $name, float $cost, string $type, array $history): array {
  $name = cleanItemName($name);
  $calc = calculate($state['income'], $state['expenses'], $state['savings'], $cost, $type);
  $db->prepare('INSERT INTO budgets (session_id,income,expenses,savings) VALUES (?,?,?,?)')->execute([$sid,$state['income'],$state['expenses'],$state['savings']]);
  $db->prepare('INSERT INTO assessments (session_id,item_name,item_price,item_type,risk_level,surplus,surplus_after,months_to_save) VALUES (?,?,?,?,?,?,?,?)')->execute([$sid,$name,$cost,$type,$calc['risk_level'],$calc['surplus'],$calc['surplus_after'],$calc['months_to_save']]);
  $db->prepare('INSERT INTO health_scores (user_id,score,trend) VALUES (?,?,?)')->execute([$uid,$calc['health_score'],'stable']);
  $state['checks'][] = ['item_name'=>$name,'item_price'=>$cost,'item_type'=>$type,'risk_level'=>$calc['risk_level'],'calc'=>$calc];
  $label  = $calc['risk_level']==='green' ? 'Good news' : ($calc['risk_level']==='yellow' ? 'Heads up' : 'Warning');
  $ai_ctx = "Income: £{$state['income']}. Expenses: £{$state['expenses']}. Savings: £{$state['savings']}. Item: {$name} at £".number_format($cost,2)." ({$type}). Surplus: £{$calc['surplus']}. Risk: {$calc['risk_level']}. Months to save: {$calc['months_to_save']}.";
  $ai     = getAIExplanation($ai_ctx, $calc['risk_level']);
  return ['label'=>$label,'name'=>$name,'ai_text'=>$ai,'calculation'=>array_merge($calc,['item_name'=>$name,'item_price'=>$cost,'item_type'=>$type])];
}

// Simple command detection (before any parsing)
if (preg_match('/^(reset|start over|restart)$/i', $lower)) {
  $state = ['step'=>'greeting','income'=>null,'expenses'=>null,'savings'=>null,'active_goal'=>null,'loan'=>null,'subscriptions'=>[],'checks'=>[],'emergency_fund_warned'=>false];
  respond($db,$session_id,$state,"No problem - let's start fresh. What is your monthly income after tax?",null,['£1500','£2000','£2500','£3000','Other']);
}
if (preg_match('/^check another item$/i', $lower)) {
  $state['comparing']        = false;
  $state['compare_base']     = null;
  $state['active_goal']      = null;
  $state['force_name_parse'] = true;
  $state['mode']             = '';
  respond($db,$session_id,$state,'What would you like to check next? Tell me the item and the price.',null,[]);
}
if (preg_match('/^reset budget$/i', $lower)) {
  $state['income']       = null;
  $state['expenses']     = null;
  $state['savings']      = null;
  $state['step']         = 'income';
  $state['comparing']    = false;
  $state['compare_base'] = null;
  $state['active_goal']  = null;
  $state['mode']         = '';
  respond($db,$session_id,$state,"No problem - let's start fresh. What is your monthly income after tax?",null,['£1500','£2000','£2500','£3000','Other']);
}

// Parse numbers and extract intent (ALL in one place)
$num      = parseNumber($message);
$interest = parseInterestRate($message);
$term     = parseLoanTerm($message);

$ix                = extractIntent($message, $state, $history);
$intents           = $ix['intent'] ?? [];
$is_correction     = $ix['is_correction'] ?? false;
$correction_field  = $ix['correction_field'] ?? null;
$goal_name_hint    = $ix['goal_name'] ?? null;
$goal_type_hint    = $ix['goal_type'] ?? null;
$income_change     = $ix['income_change_mentioned'] ?? false;
$expense_change    = $ix['expense_change_mentioned'] ?? false;
$loan_mentioned    = $ix['loan_mentioned'] ?? false;
$sub_mentioned     = $ix['subscription_mentioned'] ?? false;
$refs_alt          = $ix['references_alternative'] ?? false;
$refs_prev_goal    = $ix['references_previous_goal'] ?? false;
$is_question_only  = $ix['is_question_only'] ?? false;
$is_emotional      = $ix['is_emotional'] ?? false;
$is_unrelated      = $ix['is_unrelated'] ?? false;

// Run a stress test button
if (preg_match('/^run a stress test$/i', $lower)) {
  $state['mode']        = '';
  $state['active_goal'] = null;
  $intents[]            = 'stress_test';
}

// Compare trigger
if (preg_match('/compare|something else|alternative|versus|vs/i', $message) && $state['step'] === 'active' && !empty($state['checks']) && !$num) {
  $state['mode']         = '';
  $last = $state['checks'][count($state['checks'])-1];
  $state['comparing']    = true;
  $state['active_goal']  = null;
  if (count($state['checks']) >= 2) {
    $prev_items = [];
    foreach (array_slice($state['checks'], -5) as $c) {
      $prev_items[] = $c['item_name'].' £'.number_format($c['item_price'],0);
    }
    respond($db,$session_id,$state,'Sure - pick an item to compare against, or type a new item and price below:',null,$prev_items);
  } else {
    respond($db,$session_id,$state,'Sure - what item would you like to compare with the '.$last['item_name'].'? Tell me the item name and price.',null,[]);
  }
}

// Handle reset cancellation
if (preg_match('/^(no|nope|cancel|never mind|actually no|no i dont|dont want|changed my mind)/i', $lower) && $state['step'] === 'income' && empty($state['income'])) {
  $stmt2 = $db->prepare('SELECT saved_income, saved_expenses, saved_savings FROM users WHERE id = ?');
  $stmt2->execute([$user_id]);
  $saved2 = $stmt2->fetch();
  if ($saved2 && !empty($saved2['saved_income'])) {
    $state['income']   = floatval($saved2['saved_income']);
    $state['expenses'] = floatval($saved2['saved_expenses']);
    $state['savings']  = floatval($saved2['saved_savings']);
    $state['step']     = 'active';
    respond($db,$session_id,$state,'No problem - your previous figures are still saved. What would you like to check?',null,['Check another item','Run a stress test','Reset budget']);
  }
}

// Memory confirm (must run before conversational guards)
if (!empty($state['needs_memory_confirm'])) {
  $state['needs_memory_confirm'] = false;
  $state['mode'] = '';
  $surplus = $state['income'] - $state['expenses'];
  $bot_reply = "Welcome back! Here are your saved figures:\n\n📊 Income: £".number_format($state['income'],2)." | Expenses: £".number_format($state['expenses'],2)." | Savings: £".number_format($state['savings'],2)."\n💰 Monthly surplus: £".number_format($surplus,2)."\n\nAre these still correct, or would you like to update them?";
  respond($db,$session_id,$state,$bot_reply,null,['Yes, correct','No, update them','Other']);
}

// Stress test fallback (before income_change handler)
$inConvoMode = !empty($state['mode']) && in_array($state['mode'], ['post_stress','item_check']);
if (!$inConvoMode && !in_array('stress_test',$intents) && preg_match('/\bstress test\b|\d+\s*%\s*(drop|loss|cut)|\btotal loss of income\b|income drop|salary cut|redundan|laid off/i', $message)) {
  $intents[] = 'stress_test';
}
if (in_array('stress_test',$intents)) {
  $income_change  = false;
  $expense_change = false;
}

// Block income/expense change in comparison or waiting-for-price context
if (!empty($state['compare_base']) || !empty($state['comparing'])) {
  $income_change  = false;
  $expense_change = false;
}
if (!empty($state['active_goal']['name']) && empty($state['active_goal']['cost']) && $num && $num > 0) {
  $expense_change = false;
  $income_change  = false;
}

// Conversational message guards (all flags now defined)
// Post-stress mode: any non-stress, non-compare message goes to conversation
if (!empty($state['mode']) && in_array($state['mode'], ['stress_test','post_stress']) && !$num && empty($state['comparing']) && empty($state['compare_base'])) {
  $isExplicit = preg_match('/^(run a stress test|20% income drop|50% income drop|total loss of income|\d+% (drop|loss)|compare|something else|check another|reset budget)/i', trim($message));
  if (!$isExplicit && !in_array('stress_test', $intents)) {
    $state['mode'] = 'item_check';
    respond($db,$session_id,$state,generateReply($message,$state,$history),null,['Check another item','Run a stress test','Reset budget']);
  }
}
// LLM flags
if (($is_emotional || $is_unrelated || $is_question_only) && !$num && $state['step'] === 'active' && empty($state['comparing']) && empty($state['compare_base'])) {
  $state['mode'] = 'item_check';
  respond($db,$session_id,$state,generateReply($message,$state,$history),null,['Check another item','Run a stress test','Reset budget']);
}
// Mode set + short reply
if (!empty($state['mode']) && !$num && $state['step'] === 'active' && preg_match('/^(yes|no|yeah|nah|ok|sure|i see|makes sense|i dont know|not sure|maybe|probably|definitely|absolutely|of course|i guess|i think|tell me more|go on)/i', $lower)) {
  respond($db,$session_id,$state,generateReply($message,$state,$history),null,['Check another item','Run a stress test','Reset budget']);
}
// Long messages with numbers are conversations not item checks
if ($num && $state['step'] === 'active' && str_word_count($message) > 5 && !preg_match('/^(check|compare|afford|buy|get|purchase)\b/i', $lower) && empty($state['compare_base'])) {
  respond($db,$session_id,$state,generateReply($message,$state,$history),null,['Check another item','Run a stress test','Reset budget']);
}

// Get last bot message to understand topic context
$last_bot = '';
foreach (array_reverse($history) as $h) {
  if ($h['role'] === 'bot') { $last_bot = $h['content'] ?? ''; break; }
}
$last_was_conversational = !empty($last_bot)
  && !preg_match('/here is your result|side by side|stress test shows|LOW RISK|MODERATE RISK|HIGH RISK/i', $last_bot)
  && preg_match('/\?$|\? |would you like|how does|how do you|what do you|feel|think|consider|explore|focus/i', $last_bot);

// If last bot message was conversational and this message has a number but looks conversational - stay in conversation
if ($num && $state['step'] === 'active' && $last_was_conversational && empty($state['compare_base']) && empty($state['comparing'])) {
  $looksLikeItem = str_word_count($message) <= 3 && !preg_match('/^(so|but|and|or|if|is|it|that|this|yes|no|how|what|why|i |i\')/i', $lower);
  if (!$looksLikeItem) {
    respond($db,$session_id,$state,generateReply($message,$state,$history),null,['Check another item','Run a stress test','Reset budget']);
  }
}

// First-person with number
if ($num && $state['step'] === 'active' && preg_match('/^(i\'?m|i |we |my |maybe i|i think|i could|i would|i might|i should|i can |ill |i\'d )/i', $lower)) {
  respond($db,$session_id,$state,generateReply($message,$state,$history),null,['Check another item','Run a stress test','Reset budget']);
}
// Direct emergency fund calculation
if ($state['step'] === 'active' && !empty($state['expenses']) && preg_match('/(\d+)\s*(?:to|-)\s*(\d+)\s*months?.*(?:expense|saving|fund|emergency)|(?:emergency|fund|saving).*(\d+)\s*(?:to|-)\s*(\d+)\s*months?|how much.*(\d+)\s*(?:to|-)\s*(\d+)\s*months?/i', $message, $em)) {
  $months_a = intval($em[1] ?: $em[3] ?: $em[5]);
  $months_b = intval($em[2] ?: $em[4] ?: $em[6]);
  if ($months_a > 0 && $months_b > 0) {
    $target_a = $state['expenses'] * $months_a;
    $target_b = $state['expenses'] * $months_b;
    $current  = floatval($state['savings'] ?? 0);
    $reply3   = "{$months_a} months of expenses = £".number_format($target_a,2)." | {$months_b} months = £".number_format($target_b,2).".\n\n";
    $reply3  .= "You currently have £".number_format($current,2)." saved.\n";
    $reply3  .= "To reach £".number_format($target_a,2).": you need £".number_format(max(0,$target_a-$current),2)." more.\n";
    $reply3  .= "To reach £".number_format($target_b,2).": you need £".number_format(max(0,$target_b-$current),2)." more.";
    respond($db,$session_id,$state,$reply3,null,['Check another item','Run a stress test','Reset budget']);
  }
}

// Direct savings recommendation - BEFORE conversational opener
if ($state['step'] === 'active' && !empty($state['income']) && !empty($state['expenses'])
  && !$goal_name_hint && empty($state['comparing']) && empty($state['compare_base'])
  && (
    (in_array('saving_time',$intents) || in_array('custom_savings_calc',$intents))
    || preg_match('/how much.*save|how much.*have saved|how much.*need.*saved|what.*save.*month|recommend.*save|good amount.*save|save.*month|should i save|how much should|if i save|how long.*save|how long.*take.*save|how long.*reach|how many months.*save/i', $message)
  )
  && !preg_match('/(buy|afford|purchase|check|compare|costs?|price)/i', $message)
  && !in_array('affordability_check', $intents)) {
  $state['mode']  = 'item_check';
  $surplus_es     = floatval($state['income']) - floatval($state['expenses']);
  $monthlySaving5 = ($num !== null && $num > 0) ? min(floatval($num), max(0, $surplus_es)) : null;
  respond($db,$session_id,$state,emergencySavingsAdvice($state,$monthlySaving5),null,['Check another item','Run a stress test','Reset budget']);
}

// Conversational opener (including "how much" questions with numbers)
if ($state['step'] === 'active' && preg_match('/^(what|why|how|when|where|who|which|ok|sure|great|thanks|thank you|concern|worried|fine|bad|alright|sounds|feel|agree|disagree|not really|absolutely|of course|exactly|thats|that is|i see|i know|makes sense|tell me|explain|really|seriously|omg|wow|oh|ah|hm|hmm)/i', $lower)) {
  respond($db,$session_id,$state,generateReply($message,$state,$history),null,['Check another item','Run a stress test','Reset budget']);
}

// Yes-confirm
if (preg_match('/^(yes|yep|yeah|correct|all correct|they are correct|yes correct|thats correct|looks correct|looks good|right|thats right|same|use these|yes use|confirm|all good|sounds good|perfect|that is correct)/i', $lower) && $state['step'] === 'active' && !empty($state['income']) && empty($state['mode']) && str_word_count($lower) <= 4) {
  $item_hint = !empty($state['active_goal']['name']) ? 'How much does the '.$state['active_goal']['name'].' cost?' : 'What would you like to check? Tell me the item and the price.';
  respond($db,$session_id,$state,'Great - '.$item_hint,null,[]);
}

// Correction handler
$correction_applicable = false;
if ($is_correction && $state['step'] === 'active' && empty($state['comparing']) && empty($state['compare_base'])) {
  if ($correction_field === 'income'   && !empty($state['income']))   $correction_applicable = true;
  if ($correction_field === 'expenses' && !empty($state['expenses'])) $correction_applicable = true;
  if ($correction_field === 'savings'  && $state['savings'] !== null) $correction_applicable = true;
  if (!$correction_field) $correction_applicable = true;
}
if ($correction_applicable) {
  if ($correction_field === 'income' && $num !== null && $num > 0 && !empty($state['income'])) {
    $state['income'] = $num; $state['step'] = 'expenses';
    respond($db,$session_id,$state,'No worries - income corrected to £'.number_format($num,2).'. What are your total monthly expenses?',null,['£500','£800','£1200','£1500','Other']);
  }
  if ($correction_field === 'expenses' && $num !== null && $num >= 0 && !empty($state['expenses'])) {
    $state['expenses'] = $num; $state['step'] = 'savings';
    $surplus = $state['income'] - $num;
    respond($db,$session_id,$state,'No worries - expenses corrected to £'.number_format($num,2).'. Surplus: £'.number_format($surplus,2).'. How much do you currently have saved?',null,['£0','£500','£1000','Other']);
  }
  if ($correction_field === 'savings' && $num !== null && $num >= 0 && $state['savings'] !== null) {
    $state['savings'] = $num; $state['step'] = 'active';
    respond($db,$session_id,$state,'No worries - savings corrected to £'.number_format($num,2).'. What would you like to check?',null,['A laptop £800','A phone £600','A car £10k','Other']);
  }
  $step_map = ['expenses'=>'income','savings'=>'expenses'];
  $prev = isset($step_map[$state['step']]) ? $step_map[$state['step']] : $state['step'];
  $state['step'] = $prev;
  $bot_map = ['income'=>'No problem - what is your monthly income after tax?','expenses'=>'No problem - what are your total monthly expenses?'];
  $bot = isset($bot_map[$prev]) ? $bot_map[$prev] : 'No problem - what would you like to correct?';
  respond($db,$session_id,$state,$bot,null,['Other']);
}

// Greeting / step handlers
if ($state['step'] === 'greeting') {
  $state['step'] = 'income';
  $item_name = $goal_name_hint ?? parseItemName($message);
  if ($item_name) {
    $item_name = preg_replace('/\b(costing|worth|at|for|priced?|costs?|approximately|around|roughly)\b\s*£?[\d,.km]*/i', '', $item_name);
    $item_name = trim($item_name);
  }
  $item_cost  = ($num && $num > 0) ? $num : null;
  $item_type  = $goal_type_hint ?? (preg_match('/per month|monthly|subscription|recurring/i', $message) ? 'recurring' : 'one-time');
  $has_memory = !empty($state['income']) && !empty($state['expenses']) && isset($state['savings']);
  if ($item_name && strlen(trim($item_name)) > 1) {
    $state['active_goal'] = ['name'=>trim($item_name),'cost'=>$item_cost,'type'=>$item_type];
    if ($has_memory) {
      $state['step'] = 'active';
      $bot_reply = $item_cost
        ? "Welcome back! I have your previous figures - income £".number_format($state['income'],2).", expenses £".number_format($state['expenses'],2).", savings £".number_format($state['savings'],2).". Are these still correct for this check?"
        : "Welcome back! I have your previous figures - income £".number_format($state['income'],2).", expenses £".number_format($state['expenses'],2).", savings £".number_format($state['savings'],2).". Are these still correct? And how much does the ".trim($item_name)." cost?";
    } else {
      $cost_str  = $item_cost ? ' at £'.number_format($item_cost,2) : '';
      $bot_reply = "Great - {$item_name}{$cost_str} is a solid goal. To check if that is achievable I need a few numbers first. What is your monthly income after tax?";
    }
  } else {
    if ($has_memory) {
      $state['step'] = 'active';
      $bot_reply = "Welcome back! I have your previous figures - income £".number_format($state['income'],2).", expenses £".number_format($state['expenses'],2).", savings £".number_format($state['savings'],2).". Are these still correct, or would you like to update them?";
    } else {
      $bot_reply = "Hello! I am SmartSpend, your personal money coach. I can help you work out if you can afford something, plan your savings, and give you honest budget guidance.\n\nTo get started - what is your monthly income after tax?";
    }
  }
  respond($db,$session_id,$state,$bot_reply,null,['£1500','£2000','£2500','£3000','Other']);
}

if ($state['step'] === 'income') {
  if ($num && $num > 0) {
    $state['income'] = $num; $state['step'] = 'expenses';
    respond($db,$session_id,$state,'Got it - monthly income of £'.number_format($num,2).'. Now, what are your total monthly expenses? Include rent, food, bills, transport and subscriptions.',null,['£500','£800','£1200','£1500','Other']);
  }
  respond($db,$session_id,$state,generateReply($message,$state,$history),null,['£1500','£2000','£2500','£3000','Other']);
}

if ($state['step'] === 'expenses') {
  if ($num !== null && $num >= 0) {
    $state['expenses'] = $num; $state['step'] = 'savings';
    $surplus = $state['income'] - $num;
    $reply   = $num >= $state['income']
      ? 'Expenses of £'.number_format($num,2).' equal or exceed income - no monthly surplus. How much do you currently have saved?'
      : 'Expenses of £'.number_format($num,2).' - that leaves a surplus of £'.number_format($surplus,2).' per month. How much do you currently have saved?';
    respond($db,$session_id,$state,$reply,null,['£0','£500','£1000','£2000','Other']);
  }
  respond($db,$session_id,$state,generateReply($message,$state,$history),null,['£500','£800','£1200','£1500','Other']);
}

if ($state['step'] === 'savings') {
  if ($num !== null && $num >= 0 && $is_correction && $correction_field === 'expenses') {
    $state['expenses'] = $num;
    $surplus = $state['income'] - $num;
    respond($db,$session_id,$state,'No worries - expenses corrected to £'.number_format($num,2).'. Surplus: £'.number_format($surplus,2).'. How much do you currently have saved?',null,['£0','£500','£1000','Other']);
  }
  if ($num !== null && $num >= 0) {
    $state['savings'] = $num; $state['step'] = 'active';
    $db->prepare('UPDATE users SET saved_income=?, saved_expenses=?, saved_savings=? WHERE id=?')->execute([$state['income'],$state['expenses'],$num,$user_id]);
    $ef_note = '';
    $rec = $state['expenses'] * 3;
    if ($num < $rec && !$state['emergency_fund_warned']) {
      $ef_note = "\n\nYour savings of £".number_format($num,2)." are below the recommended 3-month emergency fund of £".number_format($rec,2).". Worth building this up before large purchases.";
      $state['emergency_fund_warned'] = true;
    }
    if (canCalculate($state)) {
      $ag     = $state['active_goal'];
      $result = runCalc($db,$session_id,$user_id,$state,$ag['name'],floatval($ag['cost']),$ag['type']??'one-time',$history);
      $bot    = 'Savings of £'.number_format($num,2).' noted.'.$ef_note."\n\n".$result['label'].' - here is your result for '.$result['name'].".\n\n".$result['ai_text'];
      respond($db,$session_id,$state,$bot,$result['calculation'],['Check another item','Compare with something else','Run a stress test','Reset budget']);
    }
    $bot = 'Savings of £'.number_format($num,2).' noted.'.$ef_note."\n\nWhat would you like to buy or check? Tell me the item and the price.";
    respond($db,$session_id,$state,$bot,null,['A laptop £800','A phone £600','A car £10k','A subscription','Other']);
  }
  respond($db,$session_id,$state,generateReply($message,$state,$history),null,['£0','£500','£1000','Other']);
}



// Update figures
if (preg_match('/^(no, update|no update|update them|update|change figures|different figures|wrong figures|start fresh|new figures|update all|change all|reset all|all|everything|change|new|different)/i', $lower) && $state['step'] === 'active' && !empty($state['income']) && !$goal_name_hint && !$num) {
  $state['income'] = null; $state['expenses'] = null; $state['savings'] = null; $state['step'] = 'income';
  respond($db,$session_id,$state,"No problem - let's update your figures. What is your monthly income after tax?",null,['£1500','£2000','£2500','£3000','Other']);
}

// System results
$system_results = [];
$calculation    = null;
$quick_replies  = ['Check another item','Run a stress test','Reset budget','Other'];
$action_taken   = false;

if ($income_change && $num && $num > 0 && !$action_taken && !in_array('stress_test',$intents)) {
  if (preg_match('/(\d+(?:\.\d+)?)\s*%/i', $message, $pct) && !empty($state['income'])) {
    $new_income = round($state['income'] * (1 + floatval($pct[1]) / 100), 2);
  } else {
    $new_income = $num;
  }
  $state['income'] = $new_income;
  $new_surplus = $new_income - ($state['expenses'] ?? 0);
  $system_results['income_updated'] = '£'.number_format($new_income,2).'/month';
  $system_results['new_surplus']    = '£'.number_format($new_surplus,2).'/month';
  if (!empty($state['active_goal']['cost'])) {
    $ag = $state['active_goal'];
    $remaining = max(0, $ag['cost'] - ($state['savings'] ?? 0));
    $months    = $new_surplus > 0 ? (int)ceil($remaining / $new_surplus) : 0;
    $y = (int)floor($months/12); $mo = $months%12;
    $t = $months===0 ? 'already there' : ($y>0 ? $y.' year'.($y>1?'s':'').($mo>0?' and '.$mo.' months':'') : $months.' months');
    $system_results['updated_goal_timeline'] = 'With new income of £'.number_format($new_income,2).'/month and surplus of £'.number_format($new_surplus,2).'/month, saving for '.$ag['name'].' (£'.number_format($ag['cost'],2).') now takes approximately '.$t;
  }
}

$is_extra_saving = preg_match('/save.{0,15}extra|extra.{0,15}save|save.{0,10}more|put.{0,10}more.{0,10}aside|save.{0,10}additional|additional.{0,10}saving|extra.{0,10}per month|pay.{0,10}extra|extra.{0,10}month|afford.{0,10}extra|contribute.{0,10}extra/i', $message)
  && $num && $num > 0 && !empty($state['active_goal']['cost']) && $num < ($state['active_goal']['cost'] ?? PHP_INT_MAX) && !$loan_mentioned;

if ($is_extra_saving && !$action_taken) {
  $current_surplus = ($state['income'] ?? 0) - ($state['expenses'] ?? 0);
  $new_saving_rate = $current_surplus + $num;
  $state['active_goal']['monthly_saving'] = $new_saving_rate;
  $ag        = $state['active_goal'];
  $target    = $refs_alt && !empty($ag['alternative_cost']) ? $ag['alternative_cost'] : ($ag['cost'] ?? 0);
  $remaining = max(0, $target - ($state['savings'] ?? 0));
  $months    = $new_saving_rate > 0 ? (int)ceil($remaining / $new_saving_rate) : 0;
  $y = (int)floor($months/12); $mo = $months%12;
  $t = $months===0 ? 'already there' : ($y>0 ? $y.' year'.($y>1?'s':'').($mo>0?' and '.$mo.' months':'') : $months.' months');
  $system_results['saving_timeline'] = 'With your current surplus of £'.number_format($current_surplus,2).'/month plus £'.number_format($num,2).'/month extra, saving £'.number_format($new_saving_rate,2).'/month toward '.($ag['name']??'goal').' (£'.number_format($target,2).'): approximately '.$t;
  $action_taken = true;
}

if ($expense_change && !$is_extra_saving && $num !== null && $num >= 0 && $num < ($state['income'] ?? PHP_INT_MAX) && !$loan_mentioned && !$action_taken) {
  $state['expenses'] = $num;
  $new_surplus = $state['income'] - $num;
  $system_results['expenses_updated']   = '£'.number_format($num,2).'/month';
  $system_results['new_surplus']        = '£'.number_format($new_surplus,2).'/month';
  if (!empty($state['active_goal']['cost'])) {
    $ag = $state['active_goal'];
    $remaining = max(0, $ag['cost'] - ($state['savings'] ?? 0));
    $months    = $new_surplus > 0 ? (int)ceil($remaining / $new_surplus) : 0;
    $y = (int)floor($months/12); $mo = $months%12;
    $t = $months===0 ? 'already there' : ($y>0 ? $y.' year'.($y>1?'s':'').($mo>0?' and '.$mo.' months':'') : $months.' months');
    $system_results['updated_goal_timeline'] = 'With reduced expenses of £'.number_format($num,2).'/month and surplus of £'.number_format($new_surplus,2).'/month, saving for '.$ag['name'].' (£'.number_format($ag['cost'],2).') now takes approximately '.$t;
  }
  $action_taken = true;
}

if ($sub_mentioned && $num && $num > 0 && !$action_taken) {
  $monthly_cost = preg_match('/per week|weekly/i', $message) ? round($num * 4.33, 2) : $num;
  $state['expenses'] = ($state['expenses'] ?? 0) + $monthly_cost;
  $new_surplus = $state['income'] - $state['expenses'];
  $system_results['subscription_added'] = '£'.number_format($monthly_cost,2).'/month added';
  $system_results['new_expenses']       = '£'.number_format($state['expenses'],2).'/month';
  $system_results['new_surplus']        = '£'.number_format($new_surplus,2).'/month';
}

if (!$loan_mentioned && preg_match('/\bloan\b|\bborrow\b|\bfinance\b|\bmortgage\b|\brepayment\b|\bcredit\b/i', $message)) $loan_mentioned = true;

if ($loan_mentioned && !$action_taken) {
  if (!isset($state['loan'])) $state['loan'] = [];
  if (empty($state['loan']['amount']) && !$num) {
    $hint = !empty($state['active_goal']['name']) ? ' for the '.$state['active_goal']['name'] : '';
    respond($db,$session_id,$state,'Sure - to calculate a loan'.$hint.', I need three things: the loan amount, the annual interest rate (%), and the repayment term in years. What are these?',null,['Other']);
  }
  $new_loan_scenario = ($num && $num >= 500) && ($interest !== null || $term !== null);
  if ($correction_field === 'loan_amount' && $num !== null) $state['loan']['amount'] = $num;
  elseif ($num && $num >= 500 && ($new_loan_scenario || empty($state['loan']['amount']))) $state['loan']['amount'] = $num;
  if ($correction_field === 'loan_interest' && $interest !== null) $state['loan']['interest'] = $interest;
  elseif ($interest !== null) $state['loan']['interest'] = $interest;
  if ($correction_field === 'loan_months' && $term !== null) $state['loan']['months'] = $term;
  elseif ($term !== null) $state['loan']['months'] = $term;
  if ($num && $num >= 500 && $interest !== null && $term !== null) $state['loan'] = ['amount'=>$num,'interest'=>$interest,'months'=>$term];
  if (!empty($state['loan']['amount']) && isset($state['loan']['interest']) && !empty($state['loan']['months'])) {
    $lc = calculateLoan(floatval($state['loan']['amount']), floatval($state['loan']['interest']), intval($state['loan']['months']));
    $state['loan']['monthly_payment'] = $lc['monthly_payment'];
    $state['loan']['total_repayment'] = $lc['total_repayment'];
    $state['loan']['total_interest']  = $lc['total_interest'];
    $surplus    = ($state['income'] ?? 0) - ($state['expenses'] ?? 0);
    $affordable = $lc['monthly_payment'] <= $surplus;
    $state['loan']['affordable'] = $affordable ? 'Affordable - within your £'.number_format($surplus,2).' monthly surplus' : 'Not affordable - exceeds surplus by £'.number_format($lc['monthly_payment']-$surplus,2);
    $system_results['loan_monthly_payment'] = '£'.number_format($lc['monthly_payment'],2);
    $system_results['loan_total_repayment'] = '£'.number_format($lc['total_repayment'],2);
    $system_results['loan_total_interest']  = '£'.number_format($lc['total_interest'],2);
    $system_results['loan_affordability']   = $state['loan']['affordable'];
  }
}

$deadline_match = preg_match('/by\s+(?:(\d{1,2})[\/\-](\d{4})|(\w+)\s+(\d{4})|(\d{4}))/i', $message, $dm);
if ($deadline_match && !empty($state['active_goal']['cost']) && !empty($state['income']) && !$action_taken) {
  $target_date = null;
  if (!empty($dm[3]) && !empty($dm[4])) $target_date = date_create($dm[3].' '.$dm[4]);
  elseif (!empty($dm[1]) && !empty($dm[2])) $target_date = date_create($dm[2].'-'.$dm[1].'-01');
  elseif (!empty($dm[5])) $target_date = date_create($dm[5].'-12-01');
  if ($target_date) {
    $now = new DateTime(); $diff = $now->diff($target_date);
    $months_left = ($diff->y * 12) + $diff->m;
    if ($months_left > 0) {
      $ag = $state['active_goal'];
      $remaining = max(0, $ag['cost'] - ($state['savings'] ?? 0));
      $needed    = ceil($remaining / $months_left);
      $surplus   = ($state['income'] ?? 0) - ($state['expenses'] ?? 0);
      $feasible  = $needed <= $surplus;
      $system_results['deadline_calc'] = 'To afford '.$ag['name'].' (£'.number_format($ag['cost'],2).') by '.date_format($target_date,'F Y').': you need to save £'.number_format($needed,2).'/month for '.$months_left.' months. Your current surplus is £'.number_format($surplus,2).'/month. '.($feasible ? 'This is achievable.' : 'This exceeds your surplus by £'.number_format($needed-$surplus,2).'/month.');
      $action_taken = true;
    }
  }
}

if ((in_array('custom_savings_calc',$intents) || in_array('saving_time',$intents)) && !$action_taken && !$is_extra_saving) {
  $ag = $state['active_goal'] ?? null;
  if (!$ag || empty($ag['cost'])) {
    if (!$goal_name_hint && !in_array('affordability_check', $intents)) {
      $monthlySaving6 = ($num !== null && $num > 0) ? min(floatval($num), floatval($state['income']) - floatval($state['expenses'])) : null;
      respond($db,$session_id,$state,emergencySavingsAdvice($state,$monthlySaving6),null,['Check another item','Run a stress test','Reset budget']);
    }
  }
  if ($ag) {
    $rate = null;
    if ($num && $num > 0 && $num < ($state['income'] ?? PHP_INT_MAX) && !$loan_mentioned && !$expense_change) { $rate = $num; $state['active_goal']['monthly_saving'] = $rate; }
    elseif (!empty($ag['monthly_saving'])) { $rate = $ag['monthly_saving']; }
    $target       = $refs_alt && !empty($ag['alternative_cost']) ? $ag['alternative_cost'] : ($ag['cost'] ?? null);
    $target_label = $refs_alt && !empty($ag['alternative_cost']) ? ($ag['name']??'item').' (cheaper option)' : ($ag['name']??'item');
    if ($rate && $target) {
      $remaining = max(0, $target - ($state['savings'] ?? 0));
      $months    = $rate > 0 ? (int)ceil($remaining / $rate) : 0;
      $y = (int)floor($months/12); $mo = $months%12;
      $t = $months===0 ? 'already there' : ($y>0 ? $y.' year'.($y>1?'s':'').($mo>0?' and '.$mo.' months':'') : $months.' months');
      $system_results['saving_timeline'] = 'Saving £'.number_format($rate,2).'/month toward '.$target_label.' (£'.number_format($target,2).'): approximately '.$t;
    }
  }
}

// Stress test handler
if (in_array('stress_test',$intents) && !empty($state['income']) && !empty($state['expenses']) && !$action_taken) {
  $state['mode'] = 'stress_test';
  $pct2        = null;
  $total_loss = (bool)preg_match('/total|100|all|no income|zero|lost.*job|lose.*job|redundan|laid off|total loss/i', $message);
  if (preg_match('/(\d+)\s*%/i', $message, $m2)) $pct2 = intval($m2[1]);
  if (!$total_loss && !$pct2) {
    respond($db,$session_id,$state,"Sure - let's run a stress test. What scenario would you like to test?\n\n- A percentage drop in income (e.g. 20% drop)\n- Total loss of income\n- A specific new income amount",null,['20% income drop','50% income drop','Total loss of income','Other']);
  }
  if ($total_loss || $pct2) {
    $new_inc = $total_loss ? 0 : round($state['income'] * (1 - ($pct2/100)), 2);
    $new_sur = $new_inc - $state['expenses'];
    $runway  = (!empty($state['savings']) && $state['expenses'] > 0) ? round($state['savings']/$state['expenses'],1) : 0;
    $drop    = $total_loss ? 'total loss of income' : $pct2.'% drop';
    $system_results['stress_test'] = $new_sur >= 0
      ? "With {$drop}: income = £".number_format($new_inc,2).", surplus = £".number_format($new_sur,2)."/month"
      : "With {$drop}: income = £".number_format($new_inc,2).", shortfall = £".number_format(abs($new_sur),2)."/month. Savings last approx {$runway} months";
  }
}

// Savings-plan guard (post-stress conversation with a number)
if ($state['step'] === 'active' && !empty($state['mode']) && in_array($state['mode'], ['post_stress','item_check'], true)
  && preg_match('/(save|saving|savings|emergency|fund|pot|monthly|per month|set aside|put aside|build|secure|stressful|worried|anxious)/i', $message)
  && !preg_match('/(buy|afford|purchase|check|compare|costs?|price)/i', $message)) {
  $state['mode'] = 'item_check';
  $surplus2 = floatval($state['income']) - floatval($state['expenses']);
  $monthlySaving = ($num !== null && $num > 0) ? min(floatval($num), max(0, $surplus2)) : null;
  if ($monthlySaving !== null) {
    $target3 = floatval($state['expenses']) * 3;
    $target6 = floatval($state['expenses']) * 6;
    $current2 = floatval($state['savings']);
    $to3 = max(0, $target3 - $current2);
    $to6 = max(0, $target6 - $current2);
    $months3 = $monthlySaving > 0 ? (int)ceil($to3 / $monthlySaving) : null;
    $months6 = $monthlySaving > 0 ? (int)ceil($to6 / $monthlySaving) : null;
    $reply2  = "That sounds like a sensible amount. If you save £".number_format($monthlySaving,2)." per month into your emergency pot:\n\n";
    $reply2 .= "- 3 months of expenses would be £".number_format($target3,2).($to3 <= 0 ? " - you are already there.\n" : " - about {$months3} month".($months3===1?"":"s")." from now.\n");
    $reply2 .= "- 6 months of expenses would be £".number_format($target6,2).($to6 <= 0 ? " - you are already there.\n\n" : " - about {$months6} month".($months6===1?"":"s")." from now.\n\n");
    $reply2 .= "With your current surplus of £".number_format($surplus2,2).", saving £".number_format($monthlySaving,2)." monthly still leaves about £".number_format($surplus2 - $monthlySaving,2)." spare each month.";
    respond($db,$session_id,$state,$reply2,null,['Check another item','Run a stress test','Reset budget']);
  }
  respond($db,$session_id,$state,generateReply($message,$state,$history),null,['Check another item','Run a stress test','Reset budget']);
}

// Item affordability
if (!empty($state['income']) && !empty($state['expenses']) && isset($state['savings']) && !$action_taken) {
  $new_name = null; $new_cost = null; $new_type = 'one-time';

  if (!empty($state['compare_base']) && $num && $num > 0) {
    $expense_change = false; $income_change = false;
    $stripped = preg_replace('/[£$]?\d[\d,.]*k?\b/i', '', $message);
    $stripped = trim($stripped);
    if (strlen($stripped) > 1) $goal_name_hint = $stripped;
  }

  if ($num === 0.0 && $goal_name_hint) {
    respond($db,$session_id,$state,"If the ".$goal_name_hint." is free, you can afford it! Is there anything else you would like to check?",null,['Check another item','Reset budget']);
  }

  $msg_name = preg_replace('/£?\d[\d,.]*k?\b/i', '', $message);
  $msg_name = trim(preg_replace('/\s+/', ' ', $msg_name));
  if (strlen($msg_name) > 1 && (!$goal_name_hint || preg_match('/\d/', $goal_name_hint) || strlen($msg_name) > strlen($goal_name_hint))) {
    $goal_name_hint = $msg_name;
  }

  if ($goal_name_hint && $num && $num > 0 && !$loan_mentioned && !$expense_change && !$sub_mentioned && !$is_extra_saving) {
    $new_name = cleanItemName($goal_name_hint);
    $new_cost = $num;
    $new_type = $goal_type_hint ?? 'one-time';
  } elseif (!$goal_name_hint && $num && $num > 0 && !empty($state['active_goal']['name']) && empty($state['active_goal']['cost']) && !$loan_mentioned && !$expense_change) {
    $new_name = $state['active_goal']['name'];
    $new_cost = $num;
    $new_type = $state['active_goal']['type'] ?? 'one-time';
    if (!empty($state['compare_base']) || !empty($state['comparing'])) $state['comparing'] = true;
  } elseif (!$loan_mentioned && !$expense_change && !$is_extra_saving && !$refs_prev_goal && !$num && !in_array('stress_test',$intents)) {
    $parsed_name = $goal_name_hint;
    if (!$parsed_name && preg_match('/(?:afford|buy|get|purchase|check)\s+(?:a\s+|an\s+)?([a-z][a-z\s]{1,30}?)(?:\s*\?|$)/i', $message, $m3)) {
      $parsed_name = trim($m3[1]);
    }
    $generic = preg_match('/^(another item|something|an item|a thing|item|something else|total loss|loss of income|total loss of income|stress test|run a stress test)$/i', trim($parsed_name ?? ''));
    if ($parsed_name && strlen($parsed_name) > 1 && !$generic && str_word_count($parsed_name) <= 4) {
      $state['active_goal'] = ['name'=>$parsed_name,'cost'=>null,'type'=>$goal_type_hint??'one-time'];
      $state['force_name_parse'] = false;
      $parsed_name = cleanItemName($parsed_name);
      respond($db,$session_id,$state,'Sure - how much does the '.$parsed_name.' cost?',null,[]);
    } elseif (!$parsed_name || $generic) {
      $state['compare_base'] = null; $state['comparing'] = false; $state['active_goal'] = null;
      $state['force_name_parse'] = true;
      respond($db,$session_id,$state,'What item would you like to check next, and how much does it cost?',null,[]);
    }
  } elseif (in_array('affordability_check',$intents) && !$refs_prev_goal && !$loan_mentioned && !$expense_change && !$is_extra_saving && $num && $num > 0) {
    $resolved = $goal_name_hint ?: $msg_name;
    $new_name = $resolved ? cleanItemName($resolved) : '';
    $new_cost = $num;
    $new_type = $goal_type_hint ?? 'one-time';
  }

  if ($new_name && $new_cost) {
    $state['mode'] = '';
    $state['active_goal'] = ['name'=>$new_name,'cost'=>$new_cost,'type'=>$new_type];
    $already_done = false; $matched_check = null;
    foreach ($state['checks'] as $c) {
      if (strtolower($c['item_name']) === strtolower($new_name) && abs($c['item_price']-$new_cost) < 1) {
        $already_done = true; $matched_check = $c; break;
      }
    }
    if ($already_done && !empty($state['comparing']) && $matched_check) {
      $state['compare_base'] = $matched_check; $state['comparing'] = false; $state['active_goal'] = null;
      respond($db,$session_id,$state,'Got it - what would you like to compare the '.$matched_check['item_name'].' against? Tell me the item name and price.',null,[]);
    } elseif (!$already_done) {
      $result        = runCalc($db,$session_id,$user_id,$state,$new_name,$new_cost,$new_type,$history);
      $calculation   = $result['calculation'];
      $system_results['affordability'] = $result['label'].' for '.$new_name;
      $quick_replies = ['Check another item','Compare with something else','Run a stress test','Reset budget'];
      if (!empty($state['compare_base'])) $state['comparing'] = true;
    }
  }
}

if (in_array('comparison',$intents) && count($state['checks']) >= 2 && !$action_taken) {
  $last = $state['checks'][count($state['checks'])-1];
  $prev = $state['checks'][count($state['checks'])-2];
  $fmt  = function(int $m): string { if ($m===0) return 'already affordable'; $y=(int)floor($m/12);$mo=$m%12; return $y>0?$y.'yr '.($mo>0?$mo.'mo':''):$m.'mo'; };
  $system_results['comparison'] = $prev['item_name'].' £'.number_format($prev['item_price'],2).': '.$prev['risk_level'].' risk, '.$fmt($prev['calc']['months_to_save']).' to save | '.$last['item_name'].' £'.number_format($last['item_price'],2).': '.$last['risk_level'].' risk, '.$fmt($last['calc']['months_to_save']).' to save';
}

$show_again = preg_match('/show.{0,30}(table|result|card|risk)|table again|result again|(table|result|card).{0,20}again|show.{0,10}again/i', $message);
if ($show_again && !empty($state['checks'])) {
  $last_check = $state['checks'][count($state['checks'])-1];
  $calc_again = array_merge($last_check['calc'], ['item_name'=>$last_check['item_name'],'item_price'=>$last_check['item_price'],'item_type'=>$last_check['item_type']]);
  respond($db,$session_id,$state,'Here is the result for '.$last_check['item_name'].' again.',$calc_again,$quick_replies);
}

// Track conversation mode only when no item calc, no comparison, not in compare flow
if (empty($system_results) && !$calculation && empty($comparison_calc) && empty($state['comparing']) && empty($state['compare_base'])) $state['mode'] = 'conversation';
$bot_reply = generateReply($message, $state, $history, $system_results);

$comparison_calc = null;
if (!empty($system_results['affordability'])) {
  $r     = $calculation;
  $label = $r['risk_level']==='green' ? 'Good news' : ($r['risk_level']==='yellow' ? 'Heads up' : 'Warning');
  $ai_ctx = "Income: £{$state['income']}. Expenses: £{$state['expenses']}. Savings: £{$state['savings']}. Item: {$r['item_name']} at £".number_format($r['item_price'],2)." ({$r['item_type']}). Surplus: £{$r['surplus']}. Risk: {$r['risk_level']}. Months: {$r['months_to_save']}.";
  if (!empty($state['comparing']) && count($state['checks']) >= 2) {
    $state['comparing'] = false;
    $prev = !empty($state['compare_base']) ? $state['compare_base'] : $state['checks'][count($state['checks'])-2];
    $state['compare_base'] = null;
    $comparison_calc = array_merge($prev['calc'], ['item_name'=>$prev['item_name'],'item_price'=>$prev['item_price'],'item_type'=>$prev['item_type']]);
    $riskOrder = ['green'=>0,'yellow'=>1,'red'=>2];
    $prevRisk = $riskOrder[$prev['risk_level']] ?? 2;
    $currRisk = $riskOrder[$r['risk_level']] ?? 2;
    if ($prevRisk < $currRisk)                                        $winner = $prev['item_name'];
    elseif ($currRisk < $prevRisk)                                    $winner = $r['item_name'];
    elseif ($prev['calc']['months_to_save'] < $r['months_to_save'])  $winner = $prev['item_name'];
    elseif ($r['months_to_save'] < $prev['calc']['months_to_save'])  $winner = $r['item_name'];
    elseif ($prev['item_price'] <= $r['item_price'])                  $winner = $prev['item_name'];
    else                                                              $winner = $r['item_name'];
    $bot_reply = "Here is your side by side comparison:\n\n"
      ."📦 ".$prev['item_name']." — £".number_format($prev['item_price'],2)." · ".strtoupper($prev['risk_level'])." risk · ".($prev['calc']['months_to_save']===0?'Already affordable':$prev['calc']['months_to_save'].' months to save')."\n"
      ."📦 ".$r['item_name']." — £".number_format($r['item_price'],2)." · ".strtoupper($r['risk_level'])." risk · ".($r['months_to_save']===0?'Already affordable':$r['months_to_save'].' months to save');
  } else {
    $bot_reply = $label.' - here is your result for '.$r['item_name'].".\n\n".getAIExplanation($ai_ctx,$r['risk_level']);
  }
}

if (!empty($system_results['loan_monthly_payment']) && $loan_mentioned) {
  $mp = $system_results['loan_monthly_payment'];
  if (strpos($bot_reply, $mp) === false)
    $bot_reply .= "\n\nLoan repayment: {$mp}/month | Total: {$system_results['loan_total_repayment']} | Interest: {$system_results['loan_total_interest']}. {$system_results['loan_affordability']}.";
}
if (!empty($system_results['saving_timeline']) && !preg_match('/\d+ month|\d+ year|already there/i', $bot_reply))
  $bot_reply .= "\n\n".$system_results['saving_timeline'].'.';
if (!empty($system_results['deadline_calc']) && strpos($bot_reply,'month')===false)
  $bot_reply .= "\n\n".$system_results['deadline_calc'];
if (!empty($system_results['updated_goal_timeline']) && !preg_match('/\d+ month|\d+ year|already there/i', $bot_reply))
  $bot_reply .= "\n\n".$system_results['updated_goal_timeline'].'.';

if (!empty($system_results['stress_test']) && strpos(strtolower($bot_reply),'stress')===false) {
  $bot_reply   = $system_results['stress_test'].'.';
  $calculation = null;
  $quick_replies = ['Check another item','Run a stress test','Reset budget'];
  $state['mode'] = 'post_stress';
}

$missing = getMissingBudgetField($state);
if (!$calculation && $missing) {
  if ($missing==='income')       $quick_replies = ['£1500','£2000','£2500','£3000','Other'];
  elseif ($missing==='expenses') $quick_replies = ['£500','£800','£1200','£1500','Other'];
  elseif ($missing==='savings')  $quick_replies = ['£0','£500','£1000','£2000','Other'];
}

respond($db,$session_id,$state,$bot_reply,$calculation,$quick_replies,$comparison_calc ?? null);