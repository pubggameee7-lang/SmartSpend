<?php

function loadEnv(): array {
  $env_path     = __DIR__ . '/../../.env';
  $env_contents = file_get_contents($env_path);
  $env          = [];
  if ($env_contents !== false) {
    foreach (explode("\n", $env_contents) as $line) {
      $line = trim($line);
      if (!empty($line) && strpos($line, '=') !== false) {
        [$key, $value] = explode('=', $line, 2);
        $env[trim($key)] = trim($value);
      }
    }
  }
  return $env;
}

function callGroq(string $system, string $user, string $model = 'llama-3.3-70b-versatile', int $max_tokens = 300, array $history = []): ?string {
  $env     = loadEnv();
  $api_key = $env['GROQ_API_KEY'] ?? '';
  if (empty($api_key)) return null;

  $messages = [['role' => 'system', 'content' => $system]];
  foreach ($history as $h) {
    $messages[] = ['role' => $h['role'] === 'bot' ? 'assistant' : 'user', 'content' => $h['content']];
  }
  $messages[] = ['role' => 'user', 'content' => $user];

  $data = [
    'model'       => $model,
    'max_tokens'  => $max_tokens,
    'temperature' => 0.7,
    'messages'    => $messages,
  ];

  $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $api_key,
  ]);
  curl_setopt($ch, CURLOPT_TIMEOUT, 20);
  $response = curl_exec($ch);
  $error    = curl_error($ch);
  curl_close($ch);
  if ($error) return null;
  $decoded = json_decode($response, true);
  return $decoded['choices'][0]['message']['content'] ?? null;
}

// ============================================================
// CALL 1 — EXTRACTION ONLY (fast model, returns JSON only)
// LLM classifies intent and extracts structure.
// PHP does ALL number parsing separately.
// LLM is NOT allowed to calculate or estimate anything.
// ============================================================
function extractIntent(string $message, array $state, array $history = []): array {
  $known  = "income=" . ($state['income'] ?? 'unknown');
  $known .= " expenses=" . ($state['expenses'] ?? 'unknown');
  $known .= " savings=" . ($state['savings'] ?? 'unknown');

  if (!empty($state['active_goal'])) {
    $ag     = $state['active_goal'];
    $known .= " active_goal_name=" . ($ag['name'] ?? 'unknown');
    $known .= " active_goal_cost=" . ($ag['cost'] ?? 'unknown');
    if (!empty($ag['alternative_cost'])) $known .= " alternative_cost=" . $ag['alternative_cost'];
    if (!empty($ag['monthly_saving']))   $known .= " stated_saving_rate=" . $ag['monthly_saving'];
  }

  if (!empty($state['loan'])) {
    $loan   = $state['loan'];
    $known .= " loan_amount=" . ($loan['amount'] ?? 'unknown');
    $known .= " loan_months=" . ($loan['months'] ?? 'unknown');
    $known .= " loan_interest=" . ($loan['interest'] ?? 'unknown');
  }

  $system = 'You are a strict intent classifier for a UK budget chatbot.
Return ONLY valid JSON. Do NOT calculate, estimate or produce any financial figures.
PHP will handle all number parsing separately. Your job is classification only.

Current known state: ' . $known . '

Return this exact JSON:
{
  "intent": [],
  "is_correction": false,
  "correction_field": null,
  "goal_name": null,
  "goal_type": null,
  "income_change_mentioned": false,
  "expense_change_mentioned": false,
  "loan_mentioned": false,
  "subscription_mentioned": false,
  "references_alternative": false,
  "references_previous_goal": false,
  "is_question_only": false,
  "is_emotional": false,
  "is_unrelated": false
}

Intent values (use all that apply):
affordability_check, correction, saving_time, comparison, loan_question,
emotional, unrelated, custom_savings_calc, stress_test, goal_update,
subscription, income_update, expense_update, advice_request, memory_check

goal_name: clean item name only if a NEW purchase goal is mentioned - NO prices, NO filler words. null if no new goal.
goal_type: "one-time" or "recurring" only if clearly determinable, else null
correction_field: ONLY set if user is explicitly correcting a previously stated value.
  Use: "income"|"expenses"|"savings"|"goal_cost"|"loan_amount"|"loan_months"|"loan_interest"
income_change_mentioned: true ONLY if user mentions a raise, promotion, or income increase
expense_change_mentioned: true ONLY if user explicitly says they will reduce/change their expenses
loan_mentioned: true if user is discussing any borrowing or loan
references_alternative: true if user refers to a cheaper or alternative option discussed earlier
references_previous_goal: true if user refers to a goal already in state without introducing a new one
is_question_only: true if user is only asking a question with no new financial data
is_emotional: true if user expresses feelings, confusion, stress, excitement
is_unrelated: true if message has nothing to do with money or budgeting

Return ONLY the JSON. No explanation. No text outside the JSON.';

  $result = callGroq($system, 'Context: ' . $known . "\nMessage: " . $message, 'llama-3.1-8b-instant', 250, array_slice($history, -4));
  if (!$result) return ['intent' => [], 'is_correction' => false];

  $clean = trim(preg_replace('/```json|```/i', '', $result));
  $data  = json_decode($clean, true);
  return is_array($data) ? $data : ['intent' => [], 'is_correction' => false];
}

// ============================================================
// CALL 2 — CONVERSATION ONLY (main model)
// LLM receives finished state + system calculation results.
// LLM outputs natural language ONLY.
// LLM is FORBIDDEN from producing any financial calculations.
// ============================================================
function generateReply(string $message, array $state, array $history = [], array $system_results = []): string {
  $surplus = (!empty($state['income']) && !empty($state['expenses']))
    ? $state['income'] - $state['expenses'] : null;

  // Build a clean key=value fact sheet - LLM cannot reason over numbers it does not see
  $facts = "FACTS (PHP financial engine output - do not recalculate any of these):\n";
  if (!empty($state['income']))   $facts .= "INCOME=£{$state['income']}\n";
  if (!empty($state['expenses'])) $facts .= "EXPENSES=£{$state['expenses']}\n";
  if (isset($state['savings']))   $facts .= "SAVINGS=£{$state['savings']}\n";
  if ($surplus !== null)          $facts .= "SURPLUS=£{$surplus}\n";

  if (!empty($state['active_goal'])) {
    $ag = $state['active_goal'];
    $facts .= "GOAL=" . ($ag['name'] ?? '?') . "\n";
    $facts .= "GOAL_COST=£" . number_format($ag['cost'] ?? 0, 2) . "\n";
    $facts .= "GOAL_TYPE=" . ($ag['type'] ?? 'one-time') . "\n";
    if (!empty($ag['monthly_saving'])) $facts .= "USER_SAVING_RATE=£" . number_format($ag['monthly_saving'], 2) . "/month\n";
  }

  // Loan - only show PHP-calculated result, never raw inputs LLM can misuse
  if (!empty($state['loan']['monthly_payment'])) {
    $l = $state['loan'];
    $facts .= "LOAN_MONTHLY_PAYMENT=£" . number_format($l['monthly_payment'], 2) . "\n";
    $facts .= "LOAN_TOTAL_REPAYMENT=£" . number_format($l['total_repayment'], 2) . "\n";
    $facts .= "LOAN_TOTAL_INTEREST=£" . number_format($l['total_interest'], 2) . "\n";
    $facts .= "LOAN_STATUS=" . ($l['affordable'] ?? 'unknown') . "\n";
  }

  if (!empty($state['checks'])) {
    foreach ($state['checks'] as $c) {
      $m = $c['calc']['months_to_save'];
      $y = (int) floor($m / 12); $mo = $m % 12;
      $t = $m === 0 ? 'already affordable' : ($y > 0 ? $y . 'yr ' . ($mo > 0 ? $mo . 'mo' : '') : $m . 'mo');
      $facts .= "CHECKED_ITEM=" . $c['item_name'] . " £" . number_format($c['item_price'], 2) . " RISK=" . $c['risk_level'] . " TIME=" . $t . "\n";
    }
  }

  // System results from this message's engine run
  $results_block = '';
  if (!empty($system_results)) {
    $results_block = "\nENGINE OUTPUT THIS MESSAGE:\n";
    foreach ($system_results as $k => $v) {
      $results_block .= strtoupper($k) . "=" . $v . "\n";
    }
  }

  $system = "You are SmartSpend, a friendly UK money coach. British English. NOT a financial adviser.

{$facts}{$results_block}

YOUR ONLY JOB: explain the engine output above in plain conversational English.

HARD RULES:
1. You CANNOT calculate, estimate or produce any number not in FACTS or ENGINE OUTPUT
2. You CANNOT recalculate loans - LOAN_MONTHLY_PAYMENT is the only correct figure
3. You CANNOT estimate timelines - only use figures from ENGINE OUTPUT
4. If a number is not in FACTS - say you do not have it, do not guess
5. Never repeat calculations already shown
6. 2-4 sentences max
7. One question max per reply
8. Warm, direct, human tone
9. If emotional - empathise first
10. If off-topic - brief answer then return to budget";

  // NO history passed - LLM gets facts only, zero partial sight of old calculations
  // Full session history - calc lines stripped in chat.php so LLM cannot re-reason numbers
  $result = callGroq($system, $message, 'llama-3.3-70b-versatile', 220, $history);
  return $result ?? "Could you say that again?";
}

// ============================================================
// FINANCIAL ENGINE — deterministic PHP, zero LLM
// ============================================================
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
      ? (int) ceil(($item_price - $savings) / $surplus) : 0;
    $projections    = [];
    if ($months_to_save === 0) {
      for ($i = 1; $i <= 3; $i++) $projections['month_' . $i] = round($savings + ($surplus * $i), 2);
    } elseif ($months_to_save <= 12) {
      for ($i = 1; $i <= $months_to_save; $i++) $projections['month_' . $i] = round($savings + ($surplus * $i), 2);
    } else {
      $y   = (int) floor($months_to_save / 12);
      $rem = $months_to_save % 12;
      $projections['summary'] = 'Approximately ' . $y . ' year' . ($y > 1 ? 's' : '') .
        ($rem > 0 ? ' and ' . $rem . ' month' . ($rem > 1 ? 's' : '') : '');
    }
  }

  $er = ($income > 0) ? ($expenses / $income) : 1;

  if ($item_type === 'recurring') {
    if ($surplus_after >= $surplus * 0.4 && $er < 0.7) $risk = 'green';
    elseif ($surplus_after >= 0 && $er < 0.85)         $risk = 'yellow';
    else                                                 $risk = 'red';
  } else {
    if ($savings >= $item_price && $surplus > 0)        $risk = 'green';
    elseif ($surplus > 0 && $months_to_save <= 12)      $risk = 'yellow';
    else                                                 $risk = 'red';
  }

  $health = 100;
  if ($er > 0.9)          $health -= 40;
  elseif ($er > 0.7)      $health -= 20;
  elseif ($er > 0.5)      $health -= 10;
  if ($savings < $income) $health -= 20;
  if ($surplus <= 0)      $health -= 30;
  if ($risk === 'red')    $health -= 20;
  elseif ($risk === 'yellow') $health -= 10;
  $health = max(0, min(100, $health));

  $ef = round($expenses * 3, 2);
  if ($item_type === 'recurring') {
    if ($risk === 'green')      $sug = 'You can comfortably afford this. Surplus after cost: £' . number_format($surplus_after, 2) . '.';
    elseif ($risk === 'yellow') $sug = 'Affordable but reduces surplus to £' . number_format($surplus_after, 2) . '.';
    else                        $sug = 'This recurring cost puts your finances under pressure.';
  } elseif ($risk === 'red' && $surplus > 0) {
    $sug = 'To afford this in 6 months you need to save £' . number_format(($item_price - $savings) / 6, 2) . '/month extra.';
  } elseif ($risk === 'red') {
    $sug = 'Expenses exceed income so saving is not possible right now.';
  } elseif ($risk === 'yellow') {
    $sug = 'Set aside £' . number_format($item_price / max($months_to_save, 1), 2) . '/month to reach your goal faster.';
  } else {
    $sug = 'Keep at least £' . number_format($ef, 2) . ' as a 3-month emergency fund.';
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

function calculateLoan(float $principal, float $annual_rate, int $months): array {
  if ($annual_rate <= 0) {
    $mp = round($principal / $months, 2);
  } else {
    $r  = $annual_rate / 12;
    $mp = round($principal * ($r * pow(1 + $r, $months)) / (pow(1 + $r, $months) - 1), 2);
  }
  return [
    'monthly_payment'  => $mp,
    'total_repayment'  => round($mp * $months, 2),
    'total_interest'   => round(($mp * $months) - $principal, 2),
  ];
}

// ============================================================
// PHP NUMBER PARSERS — single source of truth for all numbers
// LLM never touches raw input for numerical extraction
// ============================================================
function parseNumber(string $msg): ?float {
  $clean = str_replace([',', '£', '$', '€'], '', $msg);
  // k suffix - number followed by k then non-alpha or end
  if (preg_match('/(\d+(?:\.\d{1,2})?)\s*k(?:[^a-z]|$)/i', $clean, $m)) return floatval($m[1]) * 1000;
  // m suffix
  if (preg_match('/(\d+(?:\.\d{1,2})?)\s*m(?:[^a-z]|$)/i', $clean, $m)) return floatval($m[1]) * 1000000;
  // plain number
  if (preg_match('/\b(\d+(?:\.\d{1,2})?)\b/', $clean, $m)) return floatval($m[1]);
  return null;
}

function parseInterestRate(string $msg): ?float {
  if (preg_match('/(\d+(?:\.\d{1,2})?)\s*%/', $msg, $m)) return floatval($m[1]) / 100;
  return null;
}

function parseLoanTerm(string $msg): ?int {
  if (preg_match('/(\d+)\s*year/i', $msg, $m))  return intval($m[1]) * 12;
  if (preg_match('/(\d+)\s*month/i', $msg, $m)) return intval($m[1]);
  return null;
}

function parseItemName(string $msg): ?string {
  // First strip price patterns: £15k, 15k, £15,000, at 15k, worth 15k, costing 15k
  $name = preg_replace('/(?:£|at|worth|costing|costs?|priced?|for|approximately|around|roughly)?\s*\d[\d,.]*\s*[km]?(?:[^a-z]|$)/i', ' ', $msg);
  // Remove filler words
  $name = preg_replace('/\b(a|an|the|for|at|to|buy|get|want|need|afford|can|i|could|how|much|does|cost|price|is|worth|about|check|my|please|help|me|would|like|some|just|going|gonna|costing|costs|priced|approximately|around|roughly)\b/i', ' ', $name);
  $name = trim(preg_replace('/\s+/', ' ', $name));
  return strlen($name) > 1 ? $name : null;
}

function canCalculate(array $state): bool {
  return !empty($state['income'])
    && !empty($state['expenses'])
    && isset($state['savings'])
    && !empty($state['active_goal']['cost']);
}

function getMissingBudgetField(array $state): ?string {
  if (empty($state['income']))   return 'income';
  if (empty($state['expenses'])) return 'expenses';
  if (!isset($state['savings'])) return 'savings';
  return null;
}

function getAIExplanation(string $context, string $risk_level): string {
  $system = "You are SmartSpend, a friendly UK money coach. Write exactly 2 short sentences using ONLY the numbers given to you. Do NOT calculate or invent any figures. Sentence 1: state the risk and key numbers. Sentence 2: one warm practical tip. No specific financial products.";
  $result = callGroq($system, "Context: {$context}\nRisk: {$risk_level}. Two sentences only.", 'llama-3.3-70b-versatile', 100, []);
  if ($risk_level === 'green')  return $result ?? "You are in a strong position to afford this. Keep building that surplus.";
  if ($risk_level === 'yellow') return $result ?? "This is achievable but will take some time. Stay consistent with your monthly saving.";
  return $result ?? "This would put significant pressure on your budget right now. Consider saving longer or adjusting the target.";
}

function correctTypos(string $msg): string {
  $map = [
    'monet'=>'money','mony'=>'money','budgte'=>'budget','buget'=>'budget',
    'expences'=>'expenses','expeses'=>'expenses','savigns'=>'savings','savinsg'=>'savings',
    'incone'=>'income','incomme'=>'income','affort'=>'afford','aford'=>'afford',
    'mortage'=>'mortgage','morgage'=>'mortgage','subsciption'=>'subscription',
    'subscirption'=>'subscription','salarey'=>'salary','salery'=>'salary',
    'spednings'=>'spendings','spednig'=>'spending','spedning'=>'spending',
  ];
  $words = explode(' ', $msg);
  $out   = [];
  foreach ($words as $w) {
    $lower = strtolower(trim($w, '.,!?'));
    $out[] = isset($map[$lower]) ? $map[$lower] : $w;
  }
  return implode(' ', $out);
}