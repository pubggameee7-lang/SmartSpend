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

// ── Intent classifier ─────────────────────────────────────
function classifyIntent(string $message, string $current_step, array $state): string {
  $context = 'Current step: ' . $current_step . '. ';
  if (!empty($state['income']))    $context .= 'Income: £' . $state['income'] . '. ';
  if (!empty($state['expenses']))  $context .= 'Expenses: £' . $state['expenses'] . '. ';
  if (!empty($state['savings']))   $context .= 'Savings: £' . $state['savings'] . '. ';
  if (count($state['checks']) > 0) {
    $last = end($state['checks']);
    $context .= 'Last item checked: ' . $last['item_name'] . ' at £' . $last['item_price'] . '. Months to save: ' . $last['calc']['months_to_save'] . '. ';
  }

  $system = 'You are an intent classifier for a UK budget chatbot. Respond with ONLY one label from this list:
- provide_price: user is giving a new item name and/or price to check affordability
- custom_savings_calc: user wants to calculate based on a specific monthly savings amount they can actually save
- express_concern: user is expressing doubt or worry about saving the full surplus
- ask_question: user is asking a general question
- comparison: user wants to compare two items
- dream_goal: user is expressing emotional attachment to a goal or item
- confirm: user is saying yes or confirming
- stress_test: user wants to test what happens if income drops
- other: anything else

Rules:
- "calculate based on X monthly" or "if I save X a month" or "I can only save X" = custom_savings_calc
- "compare X and Y" or "which is better" = comparison
- "I really want" or "dream" or "always wanted" = dream_goal
- A number with words like "every month", "idk if I can save", "not sure if" = express_concern
- A clear item name with price = provide_price
Respond with ONLY the label, nothing else.';

  $result = callGroq($system, 'Context: ' . $context . "\nMessage: " . $message, 'llama-3.1-8b-instant', 20);
  if (!$result) return 'other';
  $result = strtolower(trim($result));
  foreach (['provide_price', 'custom_savings_calc', 'express_concern', 'ask_question', 'comparison', 'dream_goal', 'confirm', 'stress_test'] as $label) {
    if (str_contains($result, $label)) return $label;
  }
  return 'other';
}

// ── Generative fallback ───────────────────────────────────
function generateContextualReply(string $message, array $state): string {
  $context = 'You are SmartSpend, a friendly UK budget assistant chatbot. You are NOT a financial advisor. ';
  $context .= 'The user is in a conversation about their budget. ';

  if (!empty($state['income']))    $context .= 'Their monthly income is £' . $state['income'] . '. ';
  if (!empty($state['expenses']))  $context .= 'Their monthly expenses are £' . $state['expenses'] . '. ';
  if (!empty($state['savings']))   $context .= 'Their savings are £' . $state['savings'] . '. ';
  if (!empty($state['income']) && !empty($state['expenses'])) {
    $surplus = $state['income'] - $state['expenses'];
    $context .= 'Their monthly surplus is £' . $surplus . '. ';
  }
  if (count($state['checks']) > 0) {
    $last = end($state['checks']);
    $context .= 'They last checked affordability of ' . $last['item_name'] . ' at £' . $last['item_price'] . ' with a ' . $last['risk_level'] . ' risk level and ' . $last['calc']['months_to_save'] . ' months to save. ';
  }

  $system = $context
    . 'The user has said something that does not match a standard flow. '
    . 'Respond naturally and helpfully in 2-3 sentences max. '
    . 'Always gently steer back toward their budget goal or offer to check something specific. '
    . 'Be warm, encouraging and non-judgmental. '
    . 'Never give specific investment or legal advice. '
    . 'Never make up financial figures not given to you. '
    . 'End with a short question or offer to help with something specific.';

  $result = callGroq($system, $message, 'llama-3.3-70b-versatile', 120);
  return $result ?? "That is a great point. Would you like to check another item, run a stress test on your finances, or explore how to reach your savings goal faster?";
}

// ── Typo correction ───────────────────────────────────────
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

// ── Savings concern handler ───────────────────────────────
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
      if ($y > 0) return $y . ' year' . ($y > 1 ? 's' : '') . ($mo > 0 ? ' and ' . $mo . ' month' . ($mo > 1 ? 's' : '') : '');
      return $m . ' month' . ($m > 1 ? 's' : '');
    };
    return "That is a valid concern - unexpected costs happen and it is unlikely you will save the full £" . number_format($surplus, 2) . " every single month.\n\n"
         . "Here is how the timeline changes if your actual savings are lower:\n\n"
         . "- Saving 80% (£" . number_format($reduced_80, 2) . "/month): " . $format($months_80) . "\n"
         . "- Saving 50% (£" . number_format($reduced_50, 2) . "/month): " . $format($months_50) . "\n\n"
         . "If you have a specific monthly amount in mind you can actually save, just tell me and I will calculate it exactly for you.";
  }
  return "That is a completely valid concern. Unexpected costs happen and it is unlikely you will save the maximum every month. If you have a specific monthly savings amount in mind, tell me and I will calculate how long it would take.";
}

// ── Custom savings calculation ────────────────────────────
function handleCustomSavingsCalc(float $monthly_saving, array $state): string {
  if (count($state['checks']) > 0) {
    $last       = end($state['checks']);
    $item_price = $last['item_price'];
    $item_name  = $last['item_name'];
    $savings    = $state['savings'];

    if ($monthly_saving <= 0) {
      return "That saving rate would not be enough to reach your goal. Try to find ways to increase your monthly savings.";
    }

    $months_needed = (int) ceil(($item_price - $savings) / $monthly_saving);
    $years         = floor($months_needed / 12);
    $mo            = $months_needed % 12;

    if ($years > 0) {
      $time_str = $years . ' year' . ($years > 1 ? 's' : '') . ($mo > 0 ? ' and ' . $mo . ' month' . ($mo > 1 ? 's' : '') : '');
    } else {
      $time_str = $months_needed . ' month' . ($months_needed > 1 ? 's' : '');
    }

    $projection = [];
    for ($i = 1; $i <= min(3, $months_needed); $i++) {
      $projection[] = 'Month ' . $i . ': £' . number_format($savings + ($monthly_saving * $i), 2);
    }

    return "Based on saving £" . number_format($monthly_saving, 2) . " per month, here is how it looks for the " . $item_name . ":\n\n"
         . "- Time to reach £" . number_format($item_price, 2) . ": approximately " . $time_str . "\n"
         . "- Starting from your current savings of £" . number_format($savings, 2) . "\n\n"
         . (count($projection) > 0 ? implode("\n", $projection) . "\n\n" : '')
         . "That is still a realistic goal - even saving less than your full surplus gets you there.";
  }
  return "I do not have an item to calculate against yet. What are you saving for and how much does it cost?";
}

// ── AI explanation ────────────────────────────────────────
function getAIExplanation(string $context, string $risk_level): string {
  $system = 'You are SmartSpend, a friendly UK budget assistant. You are NOT a financial advisor. '
          . 'Write exactly 2 short sentences using ONLY the numbers explicitly given. '
          . 'Do NOT calculate, invent, or approximate any figures not explicitly stated. '
          . 'Do NOT use vague phrases like "some months" - always use the exact months figure given. '
          . 'Sentence 1: state the risk level and key figures plainly. '
          . 'Sentence 2: one short encouraging practical tip. '
          . 'Never mention specific financial products.';

  $result = callGroq($system, 'Context: ' . $context . "\nRisk: " . $risk_level . '. Write 2 sentences only using exact figures given.');
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