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
  $response = curl_exec($ch);
  $error    = curl_error($ch);
  curl_close($ch);
  if ($error) return null;
  $decoded = json_decode($response, true);
  return $decoded['choices'][0]['message']['content'] ?? null;
}

// - Extract structured facts from any message 
// This is the key function - runs on every message to pull out
// income, expenses, savings, item name, price, frequency
function extractFacts(string $message, array $state, array $history = []): array {
  $context = "Current known state: income=" . ($state['income'] ?? 'unknown')
    . ", expenses=" . ($state['expenses'] ?? 'unknown')
    . ", savings=" . ($state['savings'] ?? 'unknown')
    . ", pending_item=" . ($state['pending_item'] ?? 'none')
    . ", expected_next=" . ($state['expected_next'] ?? 'none') . ".";

  $system = 'You are a fact extractor for a UK budget chatbot. Extract structured financial facts from the user message.

Return ONLY a JSON object with these fields (use null if not present):
{
  "income": null,
  "expenses": null,
  "savings": null,
  "item_name": null,
  "item_price": null,
  "item_type": null,
  "is_correction": false,
  "correcting_field": null
}

Rules:
- income: monthly take-home pay in GBP (convert weekly*4.33, annual/12)
- expenses: total monthly outgoings in GBP
- savings: current savings amount in GBP
- item_name: clean purchase name, no filler words (e.g. "Pilates subscription" not "thinking of getting pilates")
- item_price: monthly cost if recurring, total cost if one-time, in GBP
- item_type: "recurring" or "one-time" or null
- For session-based costs: multiply by weekly frequency * 4.33 to get monthly
- is_correction: true if user is correcting a previous figure
- correcting_field: which field is being corrected (income/expenses/savings/item_price)
- Convert k to thousands (5k = 5000)
- If message says "50 per session once a week" -> item_price = 216.5 (50*4.33), item_type = recurring
- If message says "50 per session twice a week" -> item_price = 433, item_type = recurring
- Only extract what is clearly stated - do not guess
- Return ONLY the JSON, no other text';

  $result = callGroq($system, "Context: " . $context . "\nMessage: " . $message, 'llama-3.1-8b-instant', 150, array_slice($history, -4));
  if (!$result) return [];

  $clean = trim(preg_replace('/```json|```/i', '', $result));
  $data  = json_decode($clean, true);
  return is_array($data) ? $data : [];
}

// - Apply extracted facts to state 
function applyFacts(array $facts, array &$state): array {
  $updated = [];

  if ($facts['is_correction'] ?? false) {
    $field = $facts['correcting_field'] ?? null;
    if ($field === 'income' && !empty($facts['income']))         { $state['income']    = $facts['income'];    $updated[] = 'income'; }
    if ($field === 'expenses' && !empty($facts['expenses']))     { $state['expenses']  = $facts['expenses'];  $updated[] = 'expenses'; }
    if ($field === 'savings' && isset($facts['savings']))        { $state['savings']   = $facts['savings'];   $updated[] = 'savings'; }
    if ($field === 'item_price' && !empty($facts['item_price'])) { $state['item_price_override'] = $facts['item_price']; $updated[] = 'item_price'; }
    return $updated;
  }

  if (!empty($facts['income']) && empty($state['income'])) {
    $state['income'] = $facts['income'];
    $updated[] = 'income';
  }
  if (!empty($facts['expenses']) && empty($state['expenses'])) {
    $state['expenses'] = $facts['expenses'];
    $updated[] = 'expenses';
  }
  if (isset($facts['savings']) && $facts['savings'] !== null && !isset($state['savings'])) {
    $state['savings'] = $facts['savings'];
    $updated[] = 'savings';
  }
  if (!empty($facts['item_name']) && empty($state['pending_item'])) {
    $state['pending_item'] = $facts['item_name'];
    $updated[] = 'item_name';
  }
  if (!empty($facts['item_price']) && empty($state['pending_price'])) {
    $state['pending_price'] = $facts['item_price'];
    $updated[] = 'item_price';
  }
  if (!empty($facts['item_type']) && empty($state['pending_type'])) {
    $state['pending_type'] = $facts['item_type'];
    $updated[] = 'item_type';
  }

  return $updated;
}

// - Check if we have enough data to calculate 

function canCalculate(array $state): bool {
  return !empty($state['income'])
    && !empty($state['expenses'])
    && isset($state['savings'])
    && (!empty($state['pending_item']) || !empty($state['checks']))
    && (!empty($state['pending_price']) || !empty($state['pending_item']));
}

// - What is the next missing field 
function nextMissingField(array $state): ?string {
  if (empty($state['income']))                        return 'income';
  if (empty($state['expenses']))                      return 'expenses';
  if (!isset($state['savings']))                      return 'savings';
  if (empty($state['pending_item']) && empty($state['checks'])) return 'item';
  if (empty($state['pending_price']))                 return 'item_price';
  return null;
}

// - Build coach system prompt 
function buildCoachPrompt(array $state): string {
  $prompt = "You are SmartSpend, a friendly UK money coach and budget-aware conversational AI.

You are warm, practical, encouraging, non-judgmental, and clear. You talk naturally but your special focus is budgeting, affordability, saving plans, and realistic financial goals.

You are not a financial adviser. Do not give regulated financial, legal, medical, or investment advice. Use British English.

CORE BEHAVIOUR:
- Always understand and answer the user's latest message first
- If the user corrects something, accept it immediately and naturally
- If the user refers to something previous, use the stored context
- If the user is emotional, respond with empathy before maths
- If the user goes off-topic, answer briefly with personality then gently steer back
- If the user mentions multiple items, treat them separately and compare
- If the user asks about a loan or finance, explain interest implications
- Keep replies concise - 2-4 sentences usually enough
- Ask at most one question at the end
- Sound human, not robotic

NUMBER RULES:
- If there is a pending item waiting for a price, a bare number is the item price
- If user mentions income/salary/wage, the number is income
- If user mentions expenses/bills/rent, the number is expenses
- If user mentions savings/saved, the number is savings
- For session costs: estimate monthly total and confirm with user

IMPORTANT - CALCULATION TRIGGER:
When you have collected income, expenses, savings, and item price, do NOT keep chatting.
Instead end your reply with exactly: [READY_TO_CALCULATE]
This tells the system to show the risk table.

If one field is missing, ask for ONLY that specific field.

STYLE:
- Plain conversational British English
- Concise but complete
- No bullets unless they help
- Always make the user feel heard\n\n";

  $prompt .= "CURRENT USER BUDGET CONTEXT:\n";
  if (!empty($state['income']))      $prompt .= "- Monthly income: £" . $state['income'] . "\n";
  if (!empty($state['expenses']))    $prompt .= "- Monthly expenses: £" . $state['expenses'] . "\n";
  if (isset($state['savings']))      $prompt .= "- Current savings: £" . $state['savings'] . "\n";
  if (!empty($state['pending_item'])) $prompt .= "- Item being checked: " . $state['pending_item'] . "\n";
  if (!empty($state['pending_price'])) $prompt .= "- Item price: £" . $state['pending_price'] . "\n";
  if (!empty($state['pending_type'])) $prompt .= "- Item type: " . $state['pending_type'] . "\n";
  if (!empty($state['pending_goal'])) $prompt .= "- User originally asked about: " . $state['pending_goal'] . "\n";
  if (!empty($state['income']) && !empty($state['expenses'])) {
    $surplus = $state['income'] - $state['expenses'];
    $prompt .= "- Monthly surplus: £" . $surplus . "\n";
  }
  if (!empty($state['checks'])) {
    $prompt .= "- Items checked this session:\n";
    foreach ($state['checks'] as $c) {
      $m = $c['calc']['months_to_save'];
      $y = floor($m / 12); $mo = $m % 12;
      $t = $y > 0 ? $y . 'yr' . ($mo > 0 ? ' ' . $mo . 'mo' : '') : $m . 'mo';
      $prompt .= "  * " . $c['item_name'] . " £" . number_format($c['item_price'], 2) . " - " . $c['risk_level'] . " risk - " . $t . " to save\n";
    }
  }
  $prompt .= "- Current flow step: " . ($state['step'] ?? 'greeting') . "\n";

  return $prompt;
}

// - Generate coach reply 
function generateCoachReply(string $message, array $state, array $history = [], string $extra_context = ''): string {
  $system = buildCoachPrompt($state);
  if ($extra_context) $system .= "\nEXTRA CONTEXT: " . $extra_context . "\n";
  $result = callGroq($system, $message, 'llama-3.3-70b-versatile', 300, array_slice($history, -10));
  return $result ?? "I am having a moment - could you say that again?";
}

// - Classify message intent 
function classifyMessage(string $message, array $state, array $history = []): string {
  $context = "Step: " . ($state['step'] ?? 'greeting') . ". ";
  if (!empty($state['income']))        $context .= "Income: £" . $state['income'] . ". ";
  if (!empty($state['expenses']))      $context .= "Expenses: £" . $state['expenses'] . ". ";
  if (isset($state['savings']))        $context .= "Savings: £" . $state['savings'] . ". ";
  if (!empty($state['pending_item']))  $context .= "Pending item: " . $state['pending_item'] . ". ";
  if (!empty($state['expected_next'])) $context .= "Bot last asked for: " . $state['expected_next'] . ". ";

  $system = 'Classify the user message for a UK budget chatbot. Reply with ONE label only.

Labels:
- correction: user fixing a previous figure
- income_figure: giving income amount
- expenses_figure: giving expenses amount
- savings_figure: giving savings amount
- item_price: giving price for a pending item
- affordability_request: asking to check one item with its price
- multi_item: mentions two or more items or options
- item_without_price: mentions one item they want but no price
- subscription_request: asking about ongoing recurring cost
- comparison: wants to compare two items
- custom_savings_calc: gives a specific monthly saving amount
- stress_test: wants to simulate income drop
- reference_previous: refers to a previously discussed item
- loan_question: asking about borrowing or finance
- emotional_reaction: expressing feelings about money or goals
- complaint: says bot is wrong or not listening
- memory_check: asks what the bot has stored
- refusal_or_unknown: says they do not know a number
- off_topic: unrelated to money
- normal_chat: casual chat or general finance question
- reset_request: wants to start over
- greeting: simple hello

Reply with ONLY the label.';

  $result = callGroq($system, "Context: " . $context . "\nMessage: " . $message, 'llama-3.1-8b-instant', 15, array_slice($history, -4));
  if (!$result) return 'normal_chat';
  return strtolower(trim(preg_replace('/[^a-z_]/', '', $result)));
}

// - Multi-item extraction 
function extractItemsFromMessage(string $message, array $state, array $history = []): array {
  $system = 'Extract all purchase items from the user message. Return ONLY a JSON array:
[{"name":"item name","price":1000,"type":"one-time"},{"name":"other item","price":50,"type":"recurring"}]
- Clean item names: remove filler words
- type: "recurring" if monthly/weekly, otherwise "one-time"
- price: number in GBP, convert k to thousands, null if unknown
- Return [] if no items found
Return ONLY the JSON array.';

  $result = callGroq($system, $message, 'llama-3.1-8b-instant', 150, []);
  if (!$result) return [];
  $clean = trim(preg_replace('/```json|```/i', '', $result));
  $data  = json_decode($clean, true);
  return is_array($data) ? $data : [];
}

// - Multi-item coaching response 
function generateMultiItemResponse(array $items, array $state, array $history = [], string $original_message = ''): string {
  $income   = $state['income'] ?? 0;
  $expenses = $state['expenses'] ?? 0;
  $savings  = $state['savings'] ?? 0;
  $surplus  = $income - $expenses;

  $ctx = "User mentioned multiple items:\n";
  foreach ($items as $item) {
    if (!empty($item['price'])) {
      $months = $item['price'] > $savings && $surplus > 0 ? (int) ceil(($item['price'] - $savings) / $surplus) : 0;
      $y = floor($months / 12); $mo = $months % 12;
      $time = $y > 0 ? $y . ' year' . ($y > 1 ? 's' : '') . ($mo > 0 ? ' and ' . $mo . ' months' : '') : $months . ' months';
      $ctx .= "- " . $item['name'] . ": £" . number_format($item['price'], 2) . " (" . ($item['type'] ?? 'one-time') . ") - " . $time . " to save\n";
    } else {
      $ctx .= "- " . $item['name'] . ": price unknown\n";
    }
  }
  $ctx .= "Surplus: £" . number_format($surplus, 2) . "/month. Savings: £" . number_format($savings, 2) . ".\n";

  $has_loan = preg_match('/loan|finance|credit|borrow/i', $original_message);
  if ($has_loan) $ctx .= "User asked about loan/finance. Explain interest risk, show saving as alternative. ";
  $ctx .= "Give warm practical coaching: compare items, recommend which to prioritise, one clear next step. 3-4 sentences.";

  return generateCoachReply($original_message, $state, $history, $ctx);
}

// - Comparison 
function generateComparison(array $checks, array $state, array $history = []): string {
  if (count($checks) < 2) return "I only have one item checked so far. What would you like to compare it with?";
  $last = $checks[count($checks) - 1];
  $prev = $checks[count($checks) - 2];

  $fmt = function(int $m): string {
    if ($m <= 0) return 'already affordable';
    $y = floor($m / 12); $mo = $m % 12;
    return $y > 0 ? $y . ' year' . ($y > 1 ? 's' : '') . ($mo > 0 ? ' and ' . $mo . ' months' : '') : $m . ' month' . ($m > 1 ? 's' : '');
  };
  $rl = fn(string $r) => $r === 'green' ? 'low risk' : ($r === 'yellow' ? 'moderate risk' : 'high risk');

  $extra = "Compare:\n1. " . $prev['item_name'] . " £" . number_format($prev['item_price'], 2) . " - " . $rl($prev['risk_level']) . " - " . $fmt($prev['calc']['months_to_save']) . " to save\n"
    . "2. " . $last['item_name'] . " £" . number_format($last['item_price'], 2) . " - " . $rl($last['risk_level']) . " - " . $fmt($last['calc']['months_to_save']) . " to save\n"
    . "Surplus: £" . (($state['income'] ?? 0) - ($state['expenses'] ?? 0)) . "/month. Savings: £" . ($state['savings'] ?? 0) . ".\n"
    . "Warm comparison with clear verdict. 3-4 sentences.";

  return generateCoachReply("Compare these items.", $state, $history, $extra);
}

// - Find previous check by keyword 
function findPreviousCheck(string $message, array $checks): ?array {
  if (empty($checks)) return null;
  $lower = strtolower($message);
  foreach (array_reverse($checks) as $check) {
    foreach (explode(' ', strtolower($check['item_name'])) as $word) {
      if (strlen($word) > 2 && str_contains($lower, $word)) return $check;
    }
  }
  return null;
}

// - Custom savings calculation 
function generateCustomSavingsCalc(float $monthly_saving, array $state, array $history = []): string {
  if (count($state['checks']) > 0) {
    $last   = end($state['checks']);
    $ip     = $last['item_price'];
    $iname  = $last['item_name'];
    $sv     = $state['savings'] ?? 0;
    if ($monthly_saving <= 0) return "That saving rate would not make progress. Let us find an amount that works.";
    $months = (int) ceil(($ip - $sv) / $monthly_saving);
    $y      = floor($months / 12); $mo = $months % 12;
    $time   = $y > 0 ? $y . ' year' . ($y > 1 ? 's' : '') . ($mo > 0 ? ' and ' . $mo . ' months' : '') : $months . ' months';
    $extra  = "User saving £" . number_format($monthly_saving, 2) . "/month toward " . $iname . " at £" . number_format($ip, 2) . ". Have £" . number_format($sv, 2) . " saved. Timeline: " . $time . ". Confirm warmly, show month 1/3/6 milestones, encourage. 3 sentences.";
    return generateCoachReply("Calculate my savings at £" . number_format($monthly_saving, 2) . "/month.", $state, $history, $extra);
  }
  return "I do not have an item to calculate toward yet. What are you saving for?";
}

// - Savings concern 
function generateSavingsConcern(string $message, array $state, array $history = []): string {
  $surplus = ($state['income'] ?? 0) - ($state['expenses'] ?? 0);
  if (count($state['checks']) > 0) {
    $last = end($state['checks']);
    $ip   = $last['item_price'];
    $sv   = $state['savings'] ?? 0;
    $r80  = $surplus * 0.8;
    $r50  = $surplus * 0.5;
    $fmt  = function(float $rate) use ($ip, $sv): string {
      if ($rate <= 0) return 'not achievable';
      $m = (int) ceil(($ip - $sv) / $rate);
      $y = floor($m / 12); $mo = $m % 12;
      return $y > 0 ? $y . 'yr' . ($mo > 0 ? ' ' . $mo . 'mo' : '') : $m . 'mo';
    };
    $extra = "User worried about saving full £" . number_format($surplus, 2) . "/month toward " . $last['item_name'] . " at £" . number_format($ip, 2) . ". At 80% (£" . number_format($r80, 2) . "/mo): " . $fmt($r80) . ". At 50% (£" . number_format($r50, 2) . "/mo): " . $fmt($r50) . ". Validate warmly, show scenarios, offer specific amount calculation.";
    return generateCoachReply($message, $state, $history, $extra);
  }
  return generateCoachReply($message, $state, $history);
}

// - AI explanation for result card 
function getAIExplanation(string $context, string $risk_level, array $history = []): string {
  $system = "You are SmartSpend, a friendly UK money coach. Write exactly 2 short sentences using ONLY the numbers explicitly given. Do NOT invent figures. Do NOT say 'some months' - use the exact month count. Sentence 1: state risk and key numbers. Sentence 2: one practical encouraging tip. No specific financial products.";
  $result = callGroq($system, "Context: " . $context . "\nRisk: " . $risk_level . ". Two sentences only.", 'llama-3.3-70b-versatile', 100, []);
  return $result ?? buildFallbackExplanation($risk_level);
}

function buildFallbackExplanation(string $risk_level): string {
  if ($risk_level === 'green')  return "You are in a strong position to afford this. Keep up your surplus and build your emergency fund.";
  if ($risk_level === 'yellow') return "You can afford this but it will take time. Stay consistent with your monthly surplus.";
  return "This purchase would put significant pressure on your budget. Consider reducing expenses or waiting until savings have grown.";
}

// - Typo correction 
function correctTypos(string $msg): string {
  $corrections = [
    'monet' => 'money', 'mony' => 'money',
    'budgte' => 'budget', 'buget' => 'budget',
    'expences' => 'expenses', 'expeses' => 'expenses',
    'savigns' => 'savings', 'savinsg' => 'savings',
    'incone' => 'income', 'incomme' => 'income',
    'affort' => 'afford', 'aford' => 'afford',
    'mortage' => 'mortgage', 'morgage' => 'mortgage',
    'subsciption' => 'subscription', 'subscirption' => 'subscription',
    'salarey' => 'salary', 'salery' => 'salary',
  ];
  $words = explode(' ', $msg);
  $out   = [];
  foreach ($words as $word) {
    $lower = strtolower(trim($word, '.,!?'));
    $out[] = isset($corrections[$lower]) ? $corrections[$lower] : $word;
  }
  return implode(' ', $out);
}