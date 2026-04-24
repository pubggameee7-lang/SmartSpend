<?php
function getAIExplanation(string $context, string $risk_level): string {
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

  $api_key = $env['GROQ_API_KEY'] ?? '';

  if (empty($api_key)) {
    return buildFallbackExplanation($context, $risk_level);
  }

  $system_prompt = "You are SmartSpend, a friendly budget assistant. You are NOT a financial advisor. Using ONLY the exact numbers provided below, write 2-3 sentences explaining the affordability result in plain English. Do not calculate, invent or reference any numbers that are not explicitly given to you. Be encouraging but honest.";

  $user_prompt = "Here is the user's financial situation: {$context} The risk level is {$risk_level}. Please explain this result and give one practical suggestion.";

  $data = [
    'model' => 'llama-3.3-70b-versatile',
    'max_tokens' => 300,
    'messages'   => [
      ['role' => 'system', 'content' => $system_prompt],
      ['role' => 'user',   'content' => $user_prompt]
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

  if ($error) {
    return buildFallbackExplanation($context, $risk_level);
  }

  $decoded = json_decode($response, true);

  if (isset($decoded['choices'][0]['message']['content'])) {
    return $decoded['choices'][0]['message']['content'];
  }

  if (isset($decoded['error'])) {
    return buildFallbackExplanation($context, $risk_level);
  }

  return buildFallbackExplanation($context, $risk_level);
}

function buildFallbackExplanation(string $context, string $risk_level): string {
  if ($risk_level === 'green') {
    return "Great news — based on your current finances you are in a strong position to afford this. Keep maintaining your surplus and consider building up your emergency fund if you have not already.";
  } elseif ($risk_level === 'yellow') {
    return "You can afford this but it will take a little time to save up. Stay consistent with your monthly surplus and you will get there. Consider setting up a dedicated savings pot for this goal.";
  } else {
    return "Based on your current finances this purchase would put you under significant pressure. Consider reducing monthly expenses or waiting until your savings have grown before committing to this purchase.";
  }
}