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

function respond(PDO $db, int $sid, array $state, string $reply, ?array $calc, array $qr): void {
  $stmt = $db->prepare('INSERT INTO conversation_state (session_id, state) VALUES (?, ?) ON DUPLICATE KEY UPDATE state=VALUES(state), updated_at=NOW()');
  $stmt->execute([$sid, json_encode($state)]);
  $stmt = $db->prepare('INSERT INTO messages (session_id, role, content, calculation) VALUES (?, ?, ?, ?)');
  $stmt->execute([$sid, 'bot', $reply, $calc ? json_encode($calc) : null]);
  echo json_encode(['success'=>true,'bot_reply'=>$reply,'calculation'=>$calc,'quick_replies'=>$qr,'step'=>$state['step']]);
  exit;
}

function runCalc(PDO $db, int $sid, int $uid, array &$state, string $name, float $cost, string $type, array $history): array {
  $calc = calculate($state['income'], $state['expenses'], $state['savings'], $cost, $type);

  $db->prepare('INSERT INTO budgets (session_id,income,expenses,savings) VALUES (?,?,?,?)')->execute([$sid,$state['income'],$state['expenses'],$state['savings']]);
  $db->prepare('INSERT INTO assessments (session_id,item_name,item_price,item_type,risk_level,surplus,surplus_after,months_to_save) VALUES (?,?,?,?,?,?,?,?)')->execute([$sid,$name,$cost,$type,$calc['risk_level'],$calc['surplus'],$calc['surplus_after'],$calc['months_to_save']]);
  $db->prepare('INSERT INTO health_scores (user_id,score,trend) VALUES (?,?,?)')->execute([$uid,$calc['health_score'],'stable']);

  $state['checks'][] = ['item_name'=>$name,'item_price'=>$cost,'item_type'=>$type,'risk_level'=>$calc['risk_level'],'calc'=>$calc];

  $label  = $calc['risk_level']==='green' ? 'Good news' : ($calc['risk_level']==='yellow' ? 'Heads up' : 'Warning');
  $ai_ctx = "Income: £{$state['income']}. Expenses: £{$state['expenses']}. Savings: £{$state['savings']}. Item: {$name} at £".number_format($cost,2)." ({$type}). Surplus: £{$calc['surplus']}. Risk: {$calc['risk_level']}. Months to save: {$calc['months_to_save']}.";
  $ai     = getAIExplanation($ai_ctx, $calc['risk_level']);

  return [
    'label'       => $label,
    'name'        => $name,
    'ai_text'     => $ai,
    'calculation' => array_merge($calc, ['item_name'=>$name,'item_price'=>$cost,'item_type'=>$type]),
  ];
}

if (preg_match('/^(reset|start over|restart)$/i', $lower)) {
  $state = ['step'=>'greeting','income'=>null,'expenses'=>null,'savings'=>null,'active_goal'=>null,'loan'=>null,'subscriptions'=>[],'checks'=>[],'emergency_fund_warned'=>false];
  respond($db,$session_id,$state,"No problem - let's start fresh. What is your monthly income after tax?",null,['£1500','£2000','£2500','£3000','Other']);
}

$num      = parseNumber($message);
$interest = parseInterestRate($message);
$term     = parseLoanTerm($message);

$ix                    = extractIntent($message, $state, $history);
$intents               = $ix['intent'] ?? [];
$is_correction         = $ix['is_correction'] ?? false;
$correction_field      = $ix['correction_field'] ?? null;
$goal_name_hint        = $ix['goal_name'] ?? null;
$goal_type_hint        = $ix['goal_type'] ?? null;
$income_change         = $ix['income_change_mentioned'] ?? false;
$expense_change        = $ix['expense_change_mentioned'] ?? false;
$loan_mentioned        = $ix['loan_mentioned'] ?? false;
$sub_mentioned         = $ix['subscription_mentioned'] ?? false;
$refs_alt              = $ix['references_alternative'] ?? false;
$refs_prev_goal        = $ix['references_previous_goal'] ?? false;
$is_question_only      = $ix['is_question_only'] ?? false;
$is_emotional          = $ix['is_emotional'] ?? false;
$is_unrelated          = $ix['is_unrelated'] ?? false;

// Corrections ONLY fire in active step - during data collection steps
// PHP just processes the number directly regardless of correction intent
$correction_applicable = false;
if ($is_correction && $state['step'] === 'active') {
  if ($correction_field === 'income'   && !empty($state['income']))   $correction_applicable = true;
  if ($correction_field === 'expenses' && !empty($state['expenses'])) $correction_applicable = true;
  if ($correction_field === 'savings'  && $state['savings'] !== null) $correction_applicable = true;
  if (!$correction_field) $correction_applicable = true;
}
if ($correction_applicable) {
  if ($correction_field === 'income' && $num !== null && $num > 0 && !empty($state['income'])) {
    $state['income'] = $num;
    $state['step']   = 'expenses';
    respond($db,$session_id,$state,'No worries - income corrected to £'.number_format($num,2).'. What are your total monthly expenses?',null,['£500','£800','£1200','£1500','Other']);
  }
  if ($correction_field === 'expenses' && $num !== null && $num >= 0 && !empty($state['expenses'])) {
    $state['expenses'] = $num;
    $state['step']     = 'savings';
    $surplus = $state['income'] - $num;
    respond($db,$session_id,$state,'No worries - expenses corrected to £'.number_format($num,2).'. Surplus: £'.number_format($surplus,2).'. How much do you currently have saved?',null,['£0','£500','£1000','Other']);
  }
  if ($correction_field === 'savings' && $num !== null && $num >= 0 && $state['savings'] !== null) {
    $state['savings'] = $num;
    $state['step']    = 'active';
    respond($db,$session_id,$state,'No worries - savings corrected to £'.number_format($num,2).'. What would you like to check?',null,['A laptop £800','A phone £600','A car £10k','Other']);
  }
  $step_map = ['expenses'=>'income','savings'=>'expenses'];
  $prev = isset($step_map[$state['step']]) ? $step_map[$state['step']] : $state['step'];
  $state['step'] = $prev;
  $bot_map = ['income'=>'No problem - what is your monthly income after tax?','expenses'=>'No problem - what are your total monthly expenses?'];
  $bot = isset($bot_map[$prev]) ? $bot_map[$prev] : 'No problem - what would you like to correct?';
  respond($db,$session_id,$state,$bot,null,['Other']);
}

if ($state['step'] === 'greeting') {
  $state['step'] = 'income';
  $item_name = $goal_name_hint ?? parseItemName($message);
  if ($item_name) {
    $item_name = preg_replace('/\b(costing|worth|at|for|priced?|costs?|approximately|around|roughly)\b\s*£?[\d,.km]*/i', '', $item_name);
    $item_name = trim(preg_replace('/\s+/', ' ', $item_name));
  }
  $item_cost = ($num && $num > 50) ? $num : null;
  $item_type = $goal_type_hint ?? (preg_match('/per month|monthly|subscription|recurring/i', $message) ? 'recurring' : 'one-time');
  $has_memory = !empty($state['income']) && !empty($state['expenses']) && isset($state['savings']);
  if ($item_name && strlen(trim($item_name)) > 1) {
    $state['active_goal'] = ['name' => trim($item_name), 'cost' => $item_cost, 'type' => $item_type];
    $cost_str = $item_cost ? ' at £'.number_format($item_cost,2) : '';
    if ($has_memory) {
      if ($item_cost) {
        $bot_reply = "Welcome back! I have your previous figures - income £".number_format($state['income'],2).", expenses £".number_format($state['expenses'],2).", savings £".number_format($state['savings'],2).". Are these still correct for this check?";
        $state['step'] = 'active';
      } else {
        $bot_reply = "Welcome back! I have your previous figures - income £".number_format($state['income'],2).", expenses £".number_format($state['expenses'],2).", savings £".number_format($state['savings'],2).". Are these still correct? And how much does the ".trim($item_name)." cost?";
        $state['step'] = 'active';
      }
    } else {
      $bot_reply = "Great - {$item_name}{$cost_str} is a solid goal. To check if that is achievable I need a few numbers first. What is your monthly income after tax?";
    }
  } else {
    if ($has_memory) {
      $bot_reply = "Welcome back! I have your previous figures - income £".number_format($state['income'],2).", expenses £".number_format($state['expenses'],2).", savings £".number_format($state['savings'],2).". Are these still correct, or would you like to update them?";
      $state['step'] = 'active';
    } else {
      $bot_reply = "Hello! I am SmartSpend, your personal money coach. I can help you work out if you can afford something, plan your savings, and give you honest budget guidance.\n\nTo get started - what is your monthly income after tax?";
    }
  }
  respond($db,$session_id,$state,$bot_reply,null,['£1500','£2000','£2500','£3000','Other']);
}

if ($state['step'] === 'income') {
  if ($num && $num > 0) {
    $state['income'] = $num;
    $state['step']   = 'expenses';
    respond($db,$session_id,$state,'Got it - monthly income of £'.number_format($num,2).'. Now, what are your total monthly expenses? Include rent, food, bills, transport and subscriptions.',null,['£500','£800','£1200','£1500','Other']);
  }
  respond($db,$session_id,$state,generateReply($message,$state,$history),null,['£1500','£2000','£2500','£3000','Other']);
}

if ($state['step'] === 'expenses') {
  if ($num !== null && $num >= 0) {
    $state['expenses'] = $num;
    $state['step']     = 'savings';
    $surplus = $state['income'] - $num;
    $reply   = $num >= $state['income']
      ? 'Expenses of £'.number_format($num,2).' equal or exceed income - no monthly surplus. How much do you currently have saved?'
      : 'Expenses of £'.number_format($num,2).' - that leaves a surplus of £'.number_format($surplus,2).' per month. How much do you currently have saved?';
    respond($db,$session_id,$state,$reply,null,['£0','£500','£1000','£2000','Other']);
  }
  respond($db,$session_id,$state,generateReply($message,$state,$history),null,['£500','£800','£1200','£1500','Other']);
}

if ($state['step'] === 'savings') {
  if ($num !== null && $num >= 0) {
    $state['savings'] = $num;
    $state['step']    = 'active';
    $db->prepare('UPDATE users SET saved_income=?, saved_expenses=?, saved_savings=? WHERE id=?')
       ->execute([$state['income'], $state['expenses'], $num, $user_id]);
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

// If memory just loaded, confirm figures before doing anything
if (!empty($state['needs_memory_confirm'])) {
  $state['needs_memory_confirm'] = false;
  $surplus = $state['income'] - $state['expenses'];
  $bot_reply = "Welcome back! Here are your saved figures:

📊 Income: £".number_format($state['income'],2)." | Expenses: £".number_format($state['expenses'],2)." | Savings: £".number_format($state['savings'],2)."
💰 Monthly surplus: £".number_format($surplus,2)."

Are these still correct, or would you like to update them?";
  respond($db,$session_id,$state,$bot_reply,null,['Yes, correct','No, update them','Other']);
}

// User confirms memory figures are correct - proceed to active
if (preg_match('/^(yes|yep|yeah|correct|all correct|they are correct|yes correct|thats correct|looks correct|looks good|right|thats right|same|use these|yes use|confirm|all good|sounds good|perfect|that is correct)/i', $lower) && $state['step'] === 'active' && !empty($state['income'])) {
  $item_hint = !empty($state['active_goal']['name']) ? 'How much does the '.$state['active_goal']['name'].' cost?' : 'What would you like to check? Tell me the item and the price.';
  respond($db,$session_id,$state,'Great - '.$item_hint,null,['Other']);
}

// User wants to update all figures - soft reset to income step
if (preg_match('/^(no, update|no update|update them|change figures|different figures|wrong figures|start fresh|new figures|update all|change all|reset all)/i', $lower) && $state['step'] === 'active' && !empty($state['income'])) {
  $state['income']   = null;
  $state['expenses'] = null;
  $state['savings']  = null;
  $state['step']     = 'income';
  respond($db,$session_id,$state,"No problem - let's update your figures. What is your monthly income after tax?",null,['£1500','£2000','£2500','£3000','Other']);
}

$system_results = [];
$calculation    = null;
$quick_replies  = ['Check another item','Run a stress test','Reset budget','Other'];
$action_taken   = false;

if ($income_change && $num && $num > 0 && !$action_taken) {
  if (preg_match('/(\d+(?:\.\d+)?)\s*%/i', $message, $pct) && !empty($state['income'])) {
    $new_income = round($state['income'] * (1 + floatval($pct[1]) / 100), 2);
  } else {
    $new_income = $num;
  }
  $state['income']                   = $new_income;
  $new_surplus                       = $new_income - ($state['expenses'] ?? 0);
  $system_results['income_updated']  = '£'.number_format($new_income,2).'/month';
  $system_results['new_surplus']     = '£'.number_format($new_surplus,2).'/month';
  if (!empty($state['active_goal']['cost'])) {
    $ag        = $state['active_goal'];
    $remaining = max(0, $ag['cost'] - ($state['savings'] ?? 0));
    $months    = $new_surplus > 0 ? (int) ceil($remaining / $new_surplus) : 0;
    $y  = (int)floor($months/12); $mo = $months%12;
    $t  = $months===0 ? 'already there' : ($y>0 ? $y.' year'.($y>1?'s':'').($mo>0?' and '.$mo.' months':'') : $months.' months');
    $system_results['updated_goal_timeline'] = 'With new income of £'.number_format($new_income,2).'/month and surplus of £'.number_format($new_surplus,2).'/month, saving for '.$ag['name'].' (£'.number_format($ag['cost'],2).') now takes approximately '.$t;
  }
}

$is_extra_saving = preg_match('/save.{0,15}extra|extra.{0,15}save|save.{0,10}more|put.{0,10}more.{0,10}aside|save.{0,10}additional|additional.{0,10}saving|extra.{0,10}per month|pay.{0,10}extra|extra.{0,10}month|afford.{0,10}extra|contribute.{0,10}extra/i', $message)
  && $num && $num > 0
  && !empty($state['active_goal']['cost'])
  && $num < ($state['active_goal']['cost'] ?? PHP_INT_MAX)
  && !$loan_mentioned;

if ($is_extra_saving && !$action_taken) {
  $current_surplus = ($state['income'] ?? 0) - ($state['expenses'] ?? 0);
  $new_saving_rate = $current_surplus + $num;
  $state['active_goal']['monthly_saving'] = $new_saving_rate;
  $ag        = $state['active_goal'];
  $target    = $refs_alt && !empty($ag['alternative_cost']) ? $ag['alternative_cost'] : ($ag['cost'] ?? 0);
  $remaining = max(0, $target - ($state['savings'] ?? 0));
  $months    = $new_saving_rate > 0 ? (int) ceil($remaining / $new_saving_rate) : 0;
  $y  = (int)floor($months/12); $mo = $months%12;
  $t  = $months===0 ? 'already there' : ($y>0 ? $y.' year'.($y>1?'s':'').($mo>0?' and '.$mo.' months':'') : $months.' months');
  $system_results['saving_timeline'] = 'With your current surplus of £'.number_format($current_surplus,2).'/month plus £'.number_format($num,2).'/month extra, saving £'.number_format($new_saving_rate,2).'/month toward '.($ag['name']??'goal').' (£'.number_format($target,2).'): approximately '.$t;
  $action_taken = true;
}

if ($expense_change && !$is_extra_saving && $num !== null && $num >= 0 && $num < ($state['income'] ?? PHP_INT_MAX) && !$loan_mentioned && !$action_taken) {
  $state['expenses']                    = $num;
  $new_surplus                          = $state['income'] - $num;
  $system_results['expenses_updated']   = '£'.number_format($num,2).'/month';
  $system_results['new_surplus']        = '£'.number_format($new_surplus,2).'/month';
  if (!empty($state['active_goal']['cost'])) {
    $ag        = $state['active_goal'];
    $remaining = max(0, $ag['cost'] - ($state['savings'] ?? 0));
    $months    = $new_surplus > 0 ? (int) ceil($remaining / $new_surplus) : 0;
    $y  = (int)floor($months/12); $mo = $months%12;
    $t  = $months===0 ? 'already there' : ($y>0 ? $y.' year'.($y>1?'s':'').($mo>0?' and '.$mo.' months':'') : $months.' months');
    $system_results['updated_goal_timeline'] = 'With reduced expenses of £'.number_format($num,2).'/month and surplus of £'.number_format($new_surplus,2).'/month, saving for '.$ag['name'].' (£'.number_format($ag['cost'],2).') now takes approximately '.$t;
  }
  $action_taken = true;
}

if ($sub_mentioned && $num && $num > 0 && !$action_taken) {
  $monthly_cost = preg_match('/per week|weekly/i', $message) ? round($num * 4.33, 2) : $num;
  $state['expenses'] = ($state['expenses'] ?? 0) + $monthly_cost;
  $new_surplus       = $state['income'] - $state['expenses'];
  $system_results['subscription_added'] = '£'.number_format($monthly_cost,2).'/month added';
  $system_results['new_expenses']       = '£'.number_format($state['expenses'],2).'/month';
  $system_results['new_surplus']        = '£'.number_format($new_surplus,2).'/month';
}

// Also detect loan from message directly in case LLM missed it
if (!$loan_mentioned && preg_match('/\bloan\b|\bborrow\b|\bfinance\b|\bmortgage\b|\brepayment\b|\bcredit\b/i', $message)) {
  $loan_mentioned = true;
}

if ($loan_mentioned && !$action_taken) {
  if (!isset($state['loan'])) $state['loan'] = [];
  // If loan mentioned but no amount/rate/term yet - ask for details
  if (empty($state['loan']['amount']) && !$num) {
    $item_name_hint = !empty($state['active_goal']['name']) ? ' for the '.$state['active_goal']['name'] : '';
    respond($db,$session_id,$state,'Sure - to calculate a loan'.$item_name_hint.', I need three things: the loan amount, the annual interest rate (%), and the repayment term in years. What are these?',null,['Other']);
  }
  $new_loan_scenario = ($num && $num >= 500) && ($interest !== null || $term !== null);
  if ($correction_field === 'loan_amount' && $num !== null) {
    $state['loan']['amount'] = $num;
  } elseif ($num && $num >= 500 && ($new_loan_scenario || empty($state['loan']['amount']))) {
    $state['loan']['amount'] = $num;
  }
  if ($correction_field === 'loan_interest' && $interest !== null) {
    $state['loan']['interest'] = $interest;
  } elseif ($interest !== null) {
    $state['loan']['interest'] = $interest;
  }
  if ($correction_field === 'loan_months' && $term !== null) {
    $state['loan']['months'] = $term;
  } elseif ($term !== null) {
    $state['loan']['months'] = $term;
  }
  if ($num && $num >= 500 && $interest !== null && $term !== null) {
    $state['loan'] = ['amount' => $num, 'interest' => $interest, 'months' => $term];
  }
  if (!empty($state['loan']['amount']) && isset($state['loan']['interest']) && !empty($state['loan']['months'])) {
    $lc = calculateLoan(floatval($state['loan']['amount']), floatval($state['loan']['interest']), intval($state['loan']['months']));
    $state['loan']['monthly_payment'] = $lc['monthly_payment'];
    $state['loan']['total_repayment'] = $lc['total_repayment'];
    $state['loan']['total_interest']  = $lc['total_interest'];
    $surplus    = ($state['income'] ?? 0) - ($state['expenses'] ?? 0);
    $affordable = $lc['monthly_payment'] <= $surplus;
    $state['loan']['affordable'] = $affordable
      ? 'Affordable - within your £'.number_format($surplus,2).' monthly surplus'
      : 'Not affordable - exceeds surplus by £'.number_format($lc['monthly_payment']-$surplus,2);
    $system_results['loan_monthly_payment'] = '£'.number_format($lc['monthly_payment'],2);
    $system_results['loan_total_repayment'] = '£'.number_format($lc['total_repayment'],2);
    $system_results['loan_total_interest']  = '£'.number_format($lc['total_interest'],2);
    $system_results['loan_affordability']   = $state['loan']['affordable'];
  }
}

// Savings deadline calculator - "save for X by December 2026"
$deadline_match = preg_match('/by\s+(?:(\d{1,2})[\/\-](\d{4})|(\w+)\s+(\d{4})|(\d{4}))/i', $message, $dm);
if ($deadline_match && !empty($state['active_goal']['cost']) && !empty($state['income']) && !$action_taken) {
  $target_date = null;
  if (!empty($dm[3]) && !empty($dm[4])) {
    // "by December 2026"
    $target_date = date_create($dm[3] . ' ' . $dm[4]);
  } elseif (!empty($dm[1]) && !empty($dm[2])) {
    // "by 12/2026"
    $target_date = date_create($dm[2] . '-' . $dm[1] . '-01');
  } elseif (!empty($dm[5])) {
    // "by 2026"
    $target_date = date_create($dm[5] . '-12-01');
  }
  if ($target_date) {
    $now           = new DateTime();
    $diff          = $now->diff($target_date);
    $months_left   = ($diff->y * 12) + $diff->m;
    if ($months_left > 0) {
      $ag        = $state['active_goal'];
      $remaining = max(0, $ag['cost'] - ($state['savings'] ?? 0));
      $needed    = $months_left > 0 ? ceil($remaining / $months_left) : $remaining;
      $surplus   = ($state['income'] ?? 0) - ($state['expenses'] ?? 0);
      $feasible  = $needed <= $surplus;
      $system_results['deadline_calc'] = 'To afford '.$ag['name'].' (£'.number_format($ag['cost'],2).') by '.date_format($target_date,'F Y').': you need to save £'.number_format($needed,2).'/month for '.$months_left.' months. Your current surplus is £'.number_format($surplus,2).'/month. '.($feasible ? 'This is achievable.' : 'This exceeds your surplus by £'.number_format($needed-$surplus,2).'/month - consider a longer timeline or reducing expenses.');
      $action_taken = true;
    }
  }
}

if ((in_array('custom_savings_calc',$intents) || in_array('saving_time',$intents)) && !$action_taken && !$is_extra_saving) {
  $ag = $state['active_goal'] ?? null;
  if ($ag) {
    $rate = null;
    if ($num && $num > 0 && $num < ($state['income'] ?? PHP_INT_MAX) && !$loan_mentioned && !$expense_change) {
      $rate = $num;
      $state['active_goal']['monthly_saving'] = $rate;
    } elseif (!empty($ag['monthly_saving'])) {
      $rate = $ag['monthly_saving'];
    }
    $target       = $refs_alt && !empty($ag['alternative_cost']) ? $ag['alternative_cost'] : ($ag['cost'] ?? null);
    $target_label = $refs_alt && !empty($ag['alternative_cost']) ? ($ag['name']??'item').' (cheaper option)' : ($ag['name']??'item');
    if ($rate && $target) {
      $remaining = max(0, $target - ($state['savings'] ?? 0));
      $months    = $rate > 0 ? (int) ceil($remaining / $rate) : 0;
      $y  = (int)floor($months/12); $mo = $months%12;
      $t  = $months===0 ? 'already there' : ($y>0 ? $y.' year'.($y>1?'s':'').($mo>0?' and '.$mo.' months':'') : $months.' months');
      $system_results['saving_timeline'] = 'Saving £'.number_format($rate,2).'/month toward '.$target_label.' (£'.number_format($target,2).'): approximately '.$t;
    }
  }
}

if (in_array('stress_test',$intents) && !empty($state['income']) && !empty($state['expenses']) && !$action_taken) {
  $pct        = null;
  $total_loss = (bool)preg_match('/total|100|all|no income|zero/i', $message);
  if (preg_match('/(\d+)\s*%/i', $message, $m)) $pct = intval($m[1]);
  if ($total_loss || $pct) {
    $new_inc  = $total_loss ? 0 : round($state['income'] * (1 - ($pct/100)), 2);
    $new_sur  = $new_inc - $state['expenses'];
    $runway   = (!empty($state['savings']) && $state['expenses'] > 0) ? round($state['savings']/$state['expenses'],1) : 0;
    $drop     = $total_loss ? 'total loss of income' : $pct.'% drop';
    $system_results['stress_test'] = $new_sur >= 0
      ? "With {$drop}: income = £".number_format($new_inc,2).", surplus = £".number_format($new_sur,2)."/month"
      : "With {$drop}: income = £".number_format($new_inc,2).", shortfall = £".number_format(abs($new_sur),2)."/month. Savings last approx {$runway} months";
  }
}

if (!empty($state['income']) && !empty($state['expenses']) && isset($state['savings']) && !$action_taken) {
  $new_name = null;
  $new_cost = null;
  $new_type = 'one-time';

  if ($goal_name_hint && $num && $num > 50 && !$loan_mentioned && !$expense_change && !$sub_mentioned && !$is_extra_saving) {
    $new_name = $goal_name_hint;
    $new_cost = $num;
    $new_type = $goal_type_hint ?? 'one-time';
  } elseif (!$loan_mentioned && !$expense_change && !$is_extra_saving && !$refs_prev_goal && !$num) {
    $parsed_name = $goal_name_hint;
    if (!$parsed_name) {
      if (preg_match('/(?:afford|buy|get|purchase|check)\s+(?:a\s+|an\s+)?([a-z][a-z\s]{1,30}?)(?:\s*\?|$)/i', $message, $m)) {
        $parsed_name = trim($m[1]);
      }
    }
    // Exclude generic phrases like "another item", "something", "an item"
    $generic = preg_match('/^(another item|something|an item|a thing|item|something else)$/i', trim($parsed_name ?? ''));
    if ($parsed_name && strlen($parsed_name) > 1 && !$generic) {
      $state['active_goal'] = ['name' => $parsed_name, 'cost' => null, 'type' => $goal_type_hint ?? 'one-time'];
      $bot_reply = 'Sure - how much does the ' . $parsed_name . ' cost?';
      respond($db,$session_id,$state,$bot_reply,null,['Other']);
    } elseif (!$parsed_name || $generic) {
      respond($db,$session_id,$state,'What item would you like to check next, and how much does it cost?',null,['Other']);
    }
  } elseif (in_array('affordability_check',$intents) && !$refs_prev_goal && !$loan_mentioned && !$expense_change && !$is_extra_saving && $num && $num > 50) {
    $new_name = $goal_name_hint ?? ($state['active_goal']['name'] ?? null);
    $new_cost = $num;
    $new_type = $goal_type_hint ?? 'one-time';
  }

  if ($new_name && $new_cost) {
    $state['active_goal'] = ['name'=>$new_name,'cost'=>$new_cost,'type'=>$new_type];
    $already_done = false;
    foreach ($state['checks'] as $c) {
      if (strtolower($c['item_name'])===strtolower($new_name) && abs($c['item_price']-$new_cost)<1) {
        $already_done = true; break;
      }
    }
    if (!$already_done) {
      $result      = runCalc($db,$session_id,$user_id,$state,$new_name,$new_cost,$new_type,$history);
      $calculation = $result['calculation'];
      $system_results['affordability'] = $result['label'].' for '.$new_name;
      $quick_replies = ['Check another item','Compare with something else','Run a stress test','Reset budget'];
    }
  }
}

if (in_array('comparison',$intents) && count($state['checks']) >= 2 && !$action_taken) {
  $last = $state['checks'][count($state['checks'])-1];
  $prev = $state['checks'][count($state['checks'])-2];
  $fmt = function(int $m): string {
    if ($m === 0) return 'already affordable';
    $y = (int)floor($m/12); $mo = $m%12;
    return $y > 0 ? $y.'yr '.($mo > 0 ? $mo.'mo' : '') : $m.'mo';
  };
  $system_results['comparison'] =
    $prev['item_name'].' £'.number_format($prev['item_price'],2).': '.$prev['risk_level'].' risk, '.$fmt($prev['calc']['months_to_save']).' to save | '.
    $last['item_name'].' £'.number_format($last['item_price'],2).': '.$last['risk_level'].' risk, '.$fmt($last['calc']['months_to_save']).' to save';
}

// Detect "show table again" / "show result again" requests
$show_again = preg_match('/show.{0,30}(table|result|card|risk)|table again|result again|see.{0,20}(table|result|card|risk)|(table|result|card).{0,20}again|show.{0,10}again/i', $message);
if ($show_again && !empty($state['checks'])) {
  $last_check = $state['checks'][count($state['checks'])-1];
  $calc_again = array_merge($last_check['calc'], [
    'item_name'  => $last_check['item_name'],
    'item_price' => $last_check['item_price'],
    'item_type'  => $last_check['item_type'],
  ]);
  respond($db,$session_id,$state,'Here is the result for '.$last_check['item_name'].' again.',$calc_again,$quick_replies);
}

$bot_reply = generateReply($message, $state, $history, $system_results);

if (!empty($system_results['affordability'])) {
  $r      = $calculation;
  $label  = $r['risk_level']==='green' ? 'Good news' : ($r['risk_level']==='yellow' ? 'Heads up' : 'Warning');
  $ai_ctx = "Income: £{$state['income']}. Expenses: £{$state['expenses']}. Savings: £{$state['savings']}. Item: {$r['item_name']} at £".number_format($r['item_price'],2)." ({$r['item_type']}). Surplus: £{$r['surplus']}. Risk: {$r['risk_level']}. Months: {$r['months_to_save']}.";
  $bot_reply = $label.' - here is your result for '.$r['item_name'].".\n\n".getAIExplanation($ai_ctx,$r['risk_level']);
}

if (!empty($system_results['loan_monthly_payment']) && $loan_mentioned) {
  $mp = $system_results['loan_monthly_payment'];
  if (strpos($bot_reply, $mp) === false) {
    $bot_reply .= "\n\nLoan repayment: {$mp}/month | Total: {$system_results['loan_total_repayment']} | Interest: {$system_results['loan_total_interest']}. {$system_results['loan_affordability']}.";
  }
}

if (!empty($system_results['saving_timeline']) && !preg_match('/\d+ month|\d+ year|already there/i', $bot_reply)) {
  $bot_reply .= "\n\n".$system_results['saving_timeline'].'.';
}

if (!empty($system_results['deadline_calc']) && strpos($bot_reply, 'month') === false) {
  $bot_reply .= "\n\n".$system_results['deadline_calc'];
}

if (!empty($system_results['updated_goal_timeline']) && !preg_match('/\d+ month|\d+ year|already there/i', $bot_reply)) {
  $bot_reply .= "\n\n".$system_results['updated_goal_timeline'].'.';
}

if (!empty($system_results['stress_test']) && strpos(strtolower($bot_reply),'stress') === false) {
  $bot_reply .= "\n\n".$system_results['stress_test'].'.';
}

$missing = getMissingBudgetField($state);
if (!$calculation && $missing) {
  if ($missing==='income')       $quick_replies = ['£1500','£2000','£2500','£3000','Other'];
  elseif ($missing==='expenses') $quick_replies = ['£500','£800','£1200','£1500','Other'];
  elseif ($missing==='savings')  $quick_replies = ['£0','£500','£1000','£2000','Other'];
}

respond($db,$session_id,$state,$bot_reply,$calculation,$quick_replies);