<?php

// ── Load env ──────────────────────────────────────────────
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

// ── Call Groq API ─────────────────────────────────────────
function callGroq(string $system, string $user, string $model = 'llama-3.3-70b-versatile', int $max_tokens = 150): ?string {
  $env     = loadEnv();
  $api_key = $env['GROQ_API_KEY'] ?? '';

  if (empty($api_key)) return null;

  $data = [
    'model'      => $model,
    'max_tokens' => $max_tokens,
    'messages'   => [
      ['role' => 'system', 'content' => $system],
      ['role' => 'user',   'content' => $user],
    ]
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

// ── Intent classifier ─────────────────────────────────────
// Returns one of: 'provide_price' | 'ask_question' | 'express_concern' | 'confirm' | 'other'
function classifyIntent(string $message, string $current_step, array $state): string {
  $context = "The user is in a budget chatbot. Current step: {$current_step}. ";

  if (!empty($state['income']))    $context .= "Income already given: £{$state['income']}. ";
  if (!empty($state['expenses']))  $context .= "Expenses already given: £{$state['expenses']}. ";
  if (!empty($state['savings']))   $context .= "Savings already given: £{$state['savings']}. ";
  if (count($state['checks']) > 0) {
    $last = end($state['checks']);
    $context .= "Last item checked: {$last['item_name']} at £{$last['item_price']}. ";
  }

  $system = "You are an intent classifier for a budget chatbot. Given the user message and context, respond with ONLY one of these labels:
- provide_price: user is giving a new item name and/or price to check affordability
- provide_number: user is giving a financial figure (income, expenses, or savings) as requested
- express_concern: user is expressing worry, doubt, or concern about their finances (NOT providing a new item)
- ask_question: user is asking a question about their situation
- confirm: user is saying yes, ok, sure, or similar confirmation
- other: anything else

Rules:
- If the message contains a number AND words like 'every month', 'monthly', 'idk if', 'not sure if', 'doubt', 'worried about' - it is express_concern NOT provide_price
- If the message is clearly about a purchaseable item with a price - it is provide_price
- If the step is followup and user mentions uncertainty about saving - it is express_concern
- Respond with only the label, nothing else.";

  $result = callGroq($system, "Context: {$context}\nMessage: {$message}", 'llama-3.1-8b-instant', 20);

  if (!$result) return 'other';

  $result = strtolower(trim($result));

  // Match to valid labels
  if (str_contains($result, 'provide_price'))  return 'provide_price';
  if (str_contains($result, 'provide_number')) return 'provide_number';
  if (str_contains($result, 'express_concern')) return 'express_concern';
  if (str_contains($result, 'ask_question'))   return 'ask_question';
  if (str_contains($result, 'confirm'))        return 'confirm';

  return 'other';
}

// ── Typo correction for key financial words ───────────────
function correctTypos(string $msg): string {
  $corrections = [
    // Money/finance typos
    'monet'    => 'money',
    'mony'     => 'money',
    'mnoy'     => 'money',
    'monney'   => 'money',
    'monies'   => 'money',
    'budgte'   => 'budget',
    'buget'    => 'budget',
    'budgat'   => 'budget',
    'expences' => 'expenses',
    'expeses'  => 'expenses',
    'expnses'  => 'expenses',
    'savigns'  => 'savings',
    'savinsg'  => 'savings',
    'savins'   => 'savings',
    'incone'   => 'income',
    'incomme'  => 'income',
    'affort'   => 'afford',
    'aford'    => 'afford',
    'afored'   => 'afford',
    'dept'     => 'debt',
    'dbet'     => 'debt',
    'mortage'  => 'mortgage',
    'morgage'  => 'mortgage',
    'apartement' => 'apartment',
    'appartment' => 'apartment',
    'aparment' => 'apartment',
    'subsciption' => 'subscription',
    'subscirption' => 'subscription',
    'subcription' => 'subscription',
    'salarey'  => 'salary',
    'salery'   => 'salary',
    'waeg'     => 'wage',
    'waags'    => 'wages',
  ];

  $words  = explode(' ', $msg);
  $result = [];
  foreach ($words as $word) {
    $lower = strtolower(trim($word, '.,!?'));
    $result[] = isset($corrections[$lower]) ? $corrections[$lower] : $word;
  }
  return implode(' ', $result);
}

// ── Handle concern about savings capacity ─────────────────
function handleSavingsConcern(string $message, array $state): string {
  $surplus = $state['income'] - $state['expenses'];

  // Check if they are worried about maintaining the full surplus
  if (preg_match('/(\d+(\.\d{1,2})?)\s*k?/i', $message, $m)) {
    $mentioned = floatval($m[1]);
    if (str_contains(strtolower($message), 'k')) $mentioned *= 1000;
  }

  $last_check = count($state['checks']) > 0 ? end($state['checks']) : null;

  if ($last_check) {
    $item_price   = $last_check['item_price'];
    $reduced_80   = $surplus * 0.8;
    $reduced_50   = $surplus * 0.5;
    $months_80    = $reduced_80 > 0 ? (int) ceil(($item_price - $state['savings']) / $reduced_80) : 0;
    $months_50    = $reduced_50 > 0 ? (int) ceil(($item_price - $state['savings']) / $reduced_50) : 0;

    $format = function(int $m): string {
      if ($m <= 0) return 'not possible';
      $y = floor($m / 12);
      $mo = $m % 12;
      if ($y > 0) return "{$y} year" . ($y > 1 ? 's' : '') . ($mo > 0 ? " and {$mo} month" . ($mo > 1 ? 's' : '') : '');
      return "{$m} month" . ($m > 1 ? 's' : '');
    };

    return "That is a valid concern - life is unpredictable and you may not always be able to save the full £" . number_format($surplus, 2) . " every month.\n\n"
         . "Here is how the timeline changes if your actual savings are lower:\n\n"
         . "- If you save 80% (£" . number_format($reduced_80, 2) . "/month): approximately " . $format($months_80) . "\n"
         . "- If you save 50% (£" . number_format($reduced_50, 2) . "/month): approximately " . $format($months_50) . "\n\n"
         . "Building an emergency fund first helps protect your savings plan - it means unexpected costs come out of the buffer rather than your main savings. Would you like to check something else or run a stress test?";
  }

  return "That is a completely valid concern. Unexpected costs happen and it is unlikely you will save the maximum every single month. Building an emergency fund first gives you a buffer so that when things come up, your savings plan stays on track. Would you like to run a stress test to see what happens if your income drops?";
}

// ── AI explanation ────────────────────────────────────────
function getAIExplanation(string $context, string $risk_level): string {
  $system = "You are SmartSpend, a friendly UK budget assistant. You are NOT a financial advisor. "
          . "Write exactly 2 sentences using ONLY the numbers explicitly provided - do not calculate, invent or reference any other figures. "
          . "Sentence 1: briefly explain what the risk level means for this person using only their surplus and months to save. "
          . "Sentence 2: one short practical suggestion. "
          . "Keep it warm, honest and encouraging. Never mention specific financial products.";

  $result = callGroq($system, "Context: {$context}\nRisk level: {$risk_level}.\nWrite 2 sentences only.");

  return $result ?? buildFallbackExplanation($context, $risk_level);
}

// ── Fallback explanation ──────────────────────────────────
function buildFallbackExplanation(string $context, string $risk_level): string {
  if ($risk_level === 'green') {
    return "You are in a strong position to afford this based on your current finances. Keep maintaining your surplus and consider building up your emergency fund if you have not already.";
  } elseif ($risk_level === 'yellow') {
    return "You can afford this but it will take some time to save up. Stay consistent with your monthly surplus and you will get there.";
  } else {
    return "Based on your current finances this purchase would put significant pressure on your budget. Consider reducing monthly expenses or waiting until your savings have grown before committing.";
  }
}