<?php

function getAIExplanation(string $context, string $risk_level): string {
   $env = parse_ini_file(__DIR__ . '/../../.env');
$api_key = $env['CLAUDE_API_KEY'] ?? '';

    $system_prompt = "You are SmartSpend, a friendly budget assistant. 
You are NOT a financial advisor and must never give investment advice. 
Given the user's financial data and affordability result, provide a short 
2-3 sentence explanation of the result in plain simple English, and one 
practical suggestion. Be encouraging but honest. Never recommend specific 
financial products or investments.";

    $user_prompt = "Here is the user's financial situation: {$context} 
The risk level is {$risk_level}. Please explain this result and give one 
practical suggestion.";

    $data = [
        'model'      => 'claude-sonnet-4-20250514',
        'max_tokens' => 300,
        'system'     => $system_prompt,
        'messages'   => [
            ['role' => 'user', 'content' => $user_prompt]
        ]
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-api-key: ' . $api_key,
        'anthropic-version: 2023-06-01'
    ]);

    $response = curl_exec($ch);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return 'Unable to generate AI explanation at this time.';
    }

    $decoded = json_decode($response, true);

    if (isset($decoded['content'][0]['text'])) {
        return $decoded['content'][0]['text'];
    }

    return 'Unable to generate AI explanation at this time.';
}