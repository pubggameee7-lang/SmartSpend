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

function classifyIntent(string $message, string $current_step, array $state): string {
  $context = "The user is in a budget chatbot. Current step: {$current_step}. ";
  if (!empty($state['income']))    $context .= "Income: £{$state['income']}. ";
  if (!empty($state['expenses']))  $context .= "Expenses: £{$state['expenses']}. ";
  if (!empty($state['savings']))   $context .= "Savings: £{$state['savings']}. ";
  if (count($state['checks']) > 0) {
    $last = end($state['checks']);
    $context .= "Last item checked: {$last['item_name']} at £{$last['item_price']}. ";
  }

  $system = "You are an intent classifier for a budget chatbot. Respond with ONLY one label:
- provide_price: user is giving a new item name and price to check affordability
- provide_number: user is giving a financial figure as requested
- express_concern: user is expressing doubt or worry about their finances (NOT providing a new item to check)
- ask_question: user is asking a question
- confirm: user is saying yes or confirming
- other: anything else

Rules:
- If message contains a number AND words like 'every month', 'monthly', 'idk if', 'not sure if', 'can I always' - it is express_concern
- If message is clearly about a purchaseable item with a price - it is provide_price
- Respond with only the label.";

  $result = callGroq($system, "Context: {$context}\nMessage: {$message}", 'llama-3.1-8b-instant', 20);
  if (!$result) return 'other';
  $result = strtolower(trim($result));
  if (str_contains($result, 'provide_price'))   return 'provide_price';
  if (str_contains($result, 'provide_number'))  return 'provide_number';
  if (str_contains($result, 'express_concern')) return 'express_concern';
  if (str_contains($result, 'ask_question'))    return 'ask_question';
  if (str_contains($result, 'confirm'))         return 'confirm';
  return 'other';
}

function correctTypos(string $msg): string {
  $corrections = [
    'monet' => 'money', 'mony' => 'money', 'monney' => 'money',
    'budgte' => 'budget', 'buget' => 'budget',
    'expences' => 'expenses', 'expeses' => 'expenses', 'expnses' => 'expenses',
    'savigns' => 'savings', 'savinsg' => 'savings', 'savins' => 'savings',
    'incone' => 'income', 'incomme' => 'income',
    'affort' => 'afford', 'aford' => 'afford',
    'dept' => 'debt', 'dbet' => 'debt',
    'mortage' => 'mortgage', 'morgage' => 'mortgage',
    'apartement' => 'apartment', 'appartment' => 'apartment', 'aparment' => 'apartment',
    'subsciption' => 'subscription', 'subscirption' => 'subscription', 'subcription' => 'subscription',
    'salarey' => 'salary', 'salery' => 'salary',
  ];

  $words  = explode(' ', $msg);
  $result = [];
  foreach ($words as $word) {
    $lower    = strtolower(trim($word, '.,!?'));
    $result[] = isset($corrections[$lower]) ? $corrections[$lower] : $word;
  }
  return implode(' ', $result);
}

function handleSavingsConcern(string $message, array $state): string {
  $surplus = $state['income'] - $state['expenses'];

  if (count($state['checks']) > 0) {
    $last_check  = end($state['checks']);
    $item_price  = $last_check['item_price'];
    $reduced_80  = $surplus * 0.8;
    $reduced_50  = $surplus * 0.5;
    $months_80   = $reduced_80 > 0 ? (int) ceil(($item_price - $state['savings']) / $reduced_80) : 0;
    $months_50   = $reduced_50 > 0 ? (int) ceil(($item_price - $state['savings']) / $reduced_50) : 0;

    $format = function(int $m): string {
      if ($m <= 0) return 'not achievable at this rate';
      $y  = floor($m / 12);
      $mo = $m % 12;
      if ($y > 0) return "{$y} year" . ($y > 1 ? 's' : '') . ($mo > 0 ? " and {$mo} month" . ($mo > 1 ? 's' : '') : '');
      return "{$m} month" . ($m > 1 ? 's' : '');
    };

    return "That is a valid concern - unexpected costs happen and it is unlikely you will save the full £" . number_format($surplus, 2) . " every single month.\n\n"
         . "Here is how the timeline changes if your actual savings are lower:\n\n"
         . "- Saving 80% (£" . number_format($reduced_80, 2) . "/month): " . $format($months_80) . "\n"
         . "- Saving 50% (£" . number_format($reduced_50, 2) . "/month): " . $format($months_50) . "\n\n"
         . "Building an emergency fund first helps protect your savings plan - unexpected costs come out of the buffer rather than derailing your main goal.";
  }

  return "That is a completely valid concern. Unexpected costs happen and it is unlikely you will save the maximum every month. Building an emergency fund first gives you a buffer so that when things come up, your savings plan stays on track.";
}

function getAIExplanation(string $context, string $risk_level): string {
  $system = "You are SmartSpend, a friendly UK budget assistant. You are NOT a financial advisor. "
          . "Write exactly 2 short sentences using ONLY the numbers explicitly given in the context. "
          . "Do NOT calculate, invent, or approximate any figures not explicitly stated. "
          . "Do NOT use vague phrases like 'some months' - always use the exact months figure given. "
          . "Sentence 1: state the risk level and key figures plainly. "
          . "Sentence 2: one short encouraging practical tip. "
          . "Never mention specific financial products.";

  $result = callGroq($system, "Context: {$context}\nRisk: {$risk_level}. Write 2 sentences only using exact figures given.");
  return $result ?? buildFallbackExplanation($context, $risk_level);
}

function buildFallbackExplanation(string $context, string $risk_level): string {
  if ($risk_level === 'green') {
    return "You are in a strong position to afford this based on your current finances. Keep maintaining your surplus and consider building up your emergency fund if you have not already.";
  } elseif ($risk_level === 'yellow') {
    return "You can afford this but it will take some time to save up. Stay consistent with your monthly surplus and you will get there.";
  } else {
    return "Based on your current finances this purchase would put significant pressure on your budget. Consider reducing monthly expenses or waiting until your savings have grown before committing.";
  }
}