<?php
// Financial calculation functions ─
// Called by message.php. No endpoint code here.

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