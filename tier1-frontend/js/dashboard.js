const API_BASE = '../tier2-backend/api';

// Elements 
const scoreNumber      = document.getElementById('score-number');
const scoreTrend       = document.getElementById('score-trend');
const scoreCircle      = document.getElementById('score-circle');
const totalSessions    = document.getElementById('total-sessions');
const lastRisk         = document.getElementById('last-risk');
const lastItem         = document.getElementById('last-item');
const sessionHistory   = document.getElementById('session-history');
const logoutBtn        = document.getElementById('logout-btn');
const userEmail        = document.getElementById('user-email');
const personalityCard  = document.getElementById('personality-card');
const personalityIcon  = document.getElementById('personality-icon');
const personalityLabel = document.getElementById('personality-label');
const personalityDesc  = document.getElementById('personality-desc');
const goalCard         = document.getElementById('goal-card');
const goalProgressList = document.getElementById('goal-progress-list');
const itemsList        = document.getElementById('items-list');

let scoreChart = null;

// Spending personality engine
function getPersonality(expenseRatio, savingsRatio) {
  if (expenseRatio === null) return null;

  if (expenseRatio > 0.85) {
    return {
      icon: '⚠️',
      label: 'High Spender',
      desc: 'Most of your income goes on expenses. Reducing outgoings by even 10% could significantly improve your financial health.'
    };
  }
  if (expenseRatio > 0.7) {
    return {
      icon: '⚖️',
      label: 'Balanced Spender',
      desc: 'You cover your costs and have some surplus. Building your savings buffer will give you more flexibility.'
    };
  }
  if (expenseRatio > 0.5) {
    return {
      icon: '📈',
      label: 'Careful Planner',
      desc: 'Good expense management with a solid surplus. You are in a strong position to save toward your goals.'
    };
  }
  return {
    icon: '🏆',
    label: 'Smart Saver',
    desc: 'Excellent financial discipline. Your low expense ratio gives you strong capacity to save and invest.'
  };
}

//  Health score colour helper 
function applyScoreColour(score) {
  let colour;
  if (score >= 70)      colour = '#27AE60';
  else if (score >= 40) colour = '#F39C12';
  else                  colour = '#E74C3C';

  scoreCircle.style.borderColor = colour;
  scoreNumber.style.color       = colour;
  scoreTrend.style.color        = colour;
  return colour;
}

//  Draw trend chart 
function drawChart(labels, scores) {
  const ctx = document.getElementById('scoreChart').getContext('2d');

  if (scoreChart) scoreChart.destroy();

  const gradient = ctx.createLinearGradient(0, 0, 0, 220);
  gradient.addColorStop(0, 'rgba(0, 180, 166, 0.18)');
  gradient.addColorStop(1, 'rgba(0, 180, 166, 0)');

  scoreChart = new Chart(ctx, {
    type: 'line',
    data: {
      labels: labels,
      datasets: [{
        label: 'Health Score',
        data: scores,
        borderColor: '#00B4A6',
        backgroundColor: gradient,
        borderWidth: 2.5,
        pointBackgroundColor: '#00B4A6',
        pointBorderColor: '#ffffff',
        pointBorderWidth: 2,
        pointRadius: 5,
        tension: 0.4,
        fill: true,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#2C3E50',
          titleColor: '#ffffff',
          bodyColor: '#ffffff',
          padding: 10,
          callbacks: {
            label: ctx => ' Score: ' + ctx.parsed.y
          }
        }
      },
      scales: {
        y: {
          min: 0,
          max: 100,
          grid: { color: '#E0F2F1' },
          ticks: {
            font: { family: 'Poppins', size: 11 },
            color: '#7F8C8D',
            stepSize: 20
          }
        },
        x: {
          grid: { display: false },
          ticks: {
            font: { family: 'Poppins', size: 11 },
            color: '#7F8C8D'
          }
        }
      }
    }
  });
}

//  Render goal progress bars 
function renderGoalProgress(assessments, lastBudget) {
  if (!assessments || assessments.length === 0) return;
  if (!lastBudget) return;

  const savings = parseFloat(lastBudget.savings) || 0;
  const surplus = parseFloat(lastBudget.income) - parseFloat(lastBudget.expenses);

  // Group by item name, take most recent per item
  const seen = {};
  assessments.forEach((a, i) => {
    if (!seen[a.item_name]) seen[a.item_name] = a;
  });

  const goals = Object.values(seen).slice(0, 4);
  goalProgressList.innerHTML = '';

  goals.forEach(goal => {
    const cost      = parseFloat(goal.item_price);
    const pct       = Math.min(100, Math.round((savings / cost) * 100));
    const remaining = Math.max(0, cost - savings);
    const months    = surplus > 0 ? Math.ceil(remaining / surplus) : null;
    const risk      = goal.risk_level;

    const fillClass = risk === 'green' ? '' : (risk === 'yellow' ? 'yellow' : 'red');
    const timeStr   = months === null ? 'No surplus' :
                      months === 0   ? 'Already affordable' :
                      months + ' month' + (months > 1 ? 's' : '') + ' to save';

    goalProgressList.innerHTML += `
      <div class="progress-section" style="margin-bottom:16px">
        <div class="progress-goal-name">
          <span>${goal.item_name} <span style="color:var(--text-muted);font-weight:400">£${parseFloat(goal.item_price).toLocaleString()}</span></span>
          <span style="color:var(--primary);font-weight:600">${pct}%</span>
        </div>
        <div class="progress-bar-track">
          <div class="progress-bar-fill ${fillClass}" style="width:${pct}%"></div>
        </div>
        <div class="progress-meta">
          <span>£${savings.toLocaleString()} saved</span>
          <span>${timeStr}</span>
        </div>
      </div>`;
  });

  goalCard.style.display = 'block';
}

//  Render all items checked 
function renderItems(assessments) {
  if (!assessments || assessments.length === 0) {
    itemsList.innerHTML = '<p class="empty-msg">No items checked yet.</p>';
    return;
  }

  itemsList.innerHTML = '';
  assessments.forEach((a, i) => {
    const risk      = a.risk_level;
    const riskLabel = risk === 'green' ? 'Low Risk' : risk === 'yellow' ? 'Moderate Risk' : 'High Risk';
    const months    = parseInt(a.months_to_save);
    const timeStr   = isNaN(months) || months === 0 ? 'Already affordable' :
                      months > 12
                        ? Math.floor(months / 12) + 'yr ' + (months % 12 > 0 ? months % 12 + 'mo' : '')
                        : months + ' month' + (months > 1 ? 's' : '');

    const exportId = 'export-' + i;
    itemsList.innerHTML += `
      <div class="item-row">
        <div class="item-row-left">
          <span class="item-name">${a.item_name}</span>
          <span class="item-meta">£${parseFloat(a.item_price).toLocaleString()} · ${a.item_type} · ${timeStr} to save</span>
        </div>
        <div style="display:flex;align-items:center;gap:8px">
          <span class="item-badge ${risk}">${riskLabel}</span>
          <button id="${exportId}" class="btn-outline" style="font-size:11px;padding:3px 10px" title="Export PDF">⬇ PDF</button>
        </div>
      </div>`;
    // Store assessment ref for export button
    (function(assessment, idx) {
      setTimeout(function() {
        const btn = document.getElementById('export-' + idx);
        if (btn) btn.addEventListener('click', function() {
          fetch('../tier2-backend/api/history.php?action=last_budget').then(r=>r.json()).then(function(bd) {
            const budget = bd.success ? bd.budget : null;
            const pLabel = document.getElementById('personality-label');
            const pDesc  = document.getElementById('personality-desc');
            const pers   = pLabel && pLabel.textContent !== 'Loading...' ? {label: pLabel.textContent, desc: pDesc.textContent} : null;
            exportPDF(assessment, budget, pers);
          });
        });
      }, 100);
    })(a, i);
  });
}


//  Export PDF from dashboard 
function exportPDF(assessment, budget, personality) {
  var risk      = assessment.risk_level;
  var riskLabel = risk === 'green' ? 'LOW RISK' : risk === 'yellow' ? 'MODERATE RISK' : 'HIGH RISK';
  var riskColour= risk === 'green' ? '#27AE60' : risk === 'yellow' ? '#F39C12' : '#E74C3C';
  var months    = parseInt(assessment.months_to_save);
  var timeStr   = isNaN(months) || months === 0 ? 'Already affordable'
    : months > 12
      ? Math.floor(months/12) + ' year' + (Math.floor(months/12)>1?'s':'') + (months%12>0?' and '+months%12+' months':'')
      : months + ' month' + (months>1?'s':'');

  var snapshotHtml = '';
  if (budget) {
    var surplus = parseFloat(budget.income) - parseFloat(budget.expenses);
    snapshotHtml = '<div class="section"><h2>Your Budget Snapshot</h2>' +
      '<table><tr><td>Monthly Income</td><td><strong>£' + parseFloat(budget.income).toFixed(2) + '</strong></td></tr>' +
      '<tr><td>Monthly Expenses</td><td><strong>£' + parseFloat(budget.expenses).toFixed(2) + '</strong></td></tr>' +
      '<tr><td>Current Savings</td><td><strong>£' + parseFloat(budget.savings).toFixed(2) + '</strong></td></tr>' +
      '<tr><td>Monthly Surplus</td><td><strong>£' + surplus.toFixed(2) + '</strong></td></tr>' +
      '</table></div>';
  }

  var personalityHtml = '';
  if (personality) {
    personalityHtml = '<div class="section personality"><strong>Spending Personality: ' + personality.label + '</strong><p>' + personality.desc + '</p></div>';
  }

  var win = window.open('', '_blank');
  win.document.write('<!DOCTYPE html><html><head><title>SmartSpend Report</title>' +
  '<style>' +
  'body { font-family: Helvetica, sans-serif; color: #2C3E50; margin: 0; padding: 0; }' +
  '.header { background: #00B4A6; color: white; padding: 20px 30px; }' +
  '.header h1 { margin: 0; font-size: 24px; }' +
  '.header p { margin: 4px 0 0; font-size: 12px; opacity: 0.85; }' +
  '.disclaimer { background: #E0F2F1; color: #00B4A6; font-size: 11px; padding: 8px 30px; }' +
  '.content { padding: 24px 30px; }' +
  '.section { margin-bottom: 24px; }' +
  'h2 { font-size: 14px; color: #2C3E50; border-bottom: 1px solid #E0F2F1; padding-bottom: 6px; margin-bottom: 12px; }' +
  'table { width: 100%; border-collapse: collapse; font-size: 13px; }' +
  'td { padding: 6px 4px; border-bottom: 1px solid #f0f0f0; }' +
  'td:last-child { text-align: right; }' +
  '.badge { display: inline-block; padding: 4px 14px; border-radius: 20px; font-size: 12px; font-weight: bold; color: white; background: ' + riskColour + '; margin-bottom: 12px; }' +
  '.personality { background: #E0F2F1; border-radius: 8px; padding: 12px 16px; font-size: 13px; }' +
  '.personality p { margin: 4px 0 0; color: #555; }' +
  '.footer { margin-top: 40px; padding-top: 12px; border-top: 1px solid #E0F2F1; font-size: 10px; color: #7F8C8D; }' +
  '.date { float: right; font-size: 11px; opacity: 0.7; }' +
  '@media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }' +
  '</style></head><body>' +
  '<div class="header"><span class="date">' + new Date().toLocaleDateString('en-GB') + '</span><h1>SmartSpend</h1><p>Budget Assessment Report</p></div>' +
  '<div class="disclaimer">Not a financial adviser - for educational purposes only.</div>' +
  '<div class="content">' +
  snapshotHtml +
  '<div class="section"><h2>Assessment Result</h2>' +
  '<div class="badge">' + riskLabel + '</div>' +
  '<table>' +
  '<tr><td>Item</td><td><strong>' + assessment.item_name + '</strong></td></tr>' +
  '<tr><td>Price</td><td><strong>£' + parseFloat(assessment.item_price).toFixed(2) + '</strong></td></tr>' +
  '<tr><td>Type</td><td>' + assessment.item_type + '</td></tr>' +
  '<tr><td>Time to Save</td><td>' + timeStr + '</td></tr>' +
  '</table></div>' +
  personalityHtml +
  '<div class="footer">SmartSpend - Not a financial adviser. For educational purposes only.</div>' +
  '</div>' +
  '<script>window.onload = function() { window.print(); }<\/script>' +
  '</body></html>');
  win.document.close();
}

//  Check auth 
async function checkAuth() {
  try {
    const fd = new FormData();
    fd.append('action', 'check');
    const res  = await fetch(`${API_BASE}/auth.php`, { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.logged_in) { window.location.href = 'login.html'; return false; }
    userEmail.textContent = data.email;
    return true;
  } catch {
    window.location.href = 'login.html';
    return false;
  }
}

//  Load health score + trend 
async function loadHealthScore() {
  try {
    const res  = await fetch(`${API_BASE}/history.php?action=health_score`);
    const data = await res.json();

    if (!data.success || !data.score) {
      scoreNumber.textContent = '--';
      scoreTrend.textContent  = 'No data yet - start a chat session';
      drawChart(['No data'], [0]);
      return;
    }

    scoreNumber.textContent = data.score;
    const colour = applyScoreColour(data.score);

    scoreTrend.textContent = data.score >= 70
      ? (data.trend === 'up' ? 'Improving - great work' : 'Good financial health')
      : data.score >= 40
        ? (data.trend === 'down' ? 'Declining - review your spending' : 'Room for improvement')
        : 'Low score - reduce expenses';

    // Draw trend chart
    if (data.history && data.history.length > 0) {
      const labels = data.history.map((h, i) => 'Session ' + (i + 1));
      const scores = data.history.map(h => parseInt(h.score));
      drawChart(labels, scores);
    } else {
      drawChart(['Now'], [data.score]);
    }

    // Personality from expense ratio
    if (data.expense_ratio !== undefined) {
      const p = getPersonality(data.expense_ratio / 100, null);
      if (p) {
        personalityIcon.textContent  = p.icon;
        personalityLabel.textContent = p.label;
        personalityDesc.textContent  = p.desc;
        personalityCard.style.display = 'flex';
      }
    }
  } catch (err) {
    scoreNumber.textContent = '--';
    scoreTrend.textContent  = 'Could not load score';
  }
}

//  Load sessions 
async function loadSessions() {
  try {
    const res  = await fetch(`${API_BASE}/history.php?action=sessions`);
    const data = await res.json();

    if (!data.success || data.sessions.length === 0) {
      sessionHistory.innerHTML  = '<p class="empty-msg">No sessions yet. Start a chat to see your history here.</p>';
      totalSessions.textContent = '0';
      lastRisk.textContent      = '--';
      lastItem.textContent      = '--';
      return;
    }

    totalSessions.textContent = data.sessions.length;
    sessionHistory.innerHTML  = '';

    await loadLastAssessment(data.sessions[0].id);

    data.sessions.forEach(session => {
      const item = document.createElement('div');
      item.className = 'history-item';
      item.innerHTML = `
        <strong>${session.title}</strong>
        <span style="float:right;color:#7F8C8D;font-size:12px">
          ${new Date(session.created_at).toLocaleDateString()}
        </span>`;
      item.addEventListener('click', () => {
        sessionStorage.setItem('load_session', session.id);
        window.location.href = 'index.html';
      });
      sessionHistory.appendChild(item);
    });
  } catch {
    sessionHistory.innerHTML = '<p class="empty-msg">Could not load sessions.</p>';
  }
}

//  Load last assessment + all items 
async function loadLastAssessment(sessionId) {
  try {
    const res  = await fetch(`${API_BASE}/history.php?action=all_assessments`);
    const data = await res.json();

    if (data.success && data.assessments && data.assessments.length > 0) {
      const latest = data.assessments[0];
      lastItem.textContent = latest.item_name || '--';
      const risk = latest.risk_level;
      lastRisk.textContent = risk === 'green' ? 'Low Risk' : risk === 'yellow' ? 'Moderate Risk' : 'High Risk';
      lastRisk.style.color = risk === 'green' ? '#27AE60' : risk === 'yellow' ? '#F39C12' : '#E74C3C';

      renderItems(data.assessments);

      // Load latest budget for progress bars
      const budgetRes  = await fetch(`${API_BASE}/history.php?action=last_budget`);
      const budgetData = await budgetRes.json();
      if (budgetData.success && budgetData.budget) {
        renderGoalProgress(data.assessments, budgetData.budget);
      }
    } else {
      lastRisk.textContent = '--';
      lastItem.textContent = '--';
    }
  } catch {
    lastRisk.textContent = '--';
    lastItem.textContent = '--';
  }
}

//  Logout 
if (logoutBtn) {
  logoutBtn.addEventListener('click', async () => {
    try {
      const fd = new FormData();
      fd.append('action', 'logout');
      await fetch(`${API_BASE}/auth.php`, { method: 'POST', body: fd });
    } finally {
      window.location.href = 'login.html';
    }
  });
}

// Init 
(async () => {
  const authed = await checkAuth();
  if (!authed) return;
  await Promise.all([loadHealthScore(), loadSessions()]);
})();


//  Savings Goal Tracker
const API_BASE_DASH = '../tier2-backend/api';

async function loadSavingsGoals() {
  try {
    var res  = await fetch(API_BASE_DASH + '/history.php?action=savings_goals');
    var data = await res.json();
    var list = document.getElementById('savings-goals-list');
    if (!list) return;
    if (!data.success || !data.goals.length) {
      list.innerHTML = '<p class="empty-msg">No savings goals yet. Add one to track your progress.</p>';
      return;
    }
    list.innerHTML = '';
    data.goals.forEach(function(g) {
      var card = document.createElement('div');
      card.style.cssText = 'background:var(--bg);border-radius:var(--radius-sm);padding:16px;margin-bottom:12px;border:1.5px solid var(--border);';

      var deadline = new Date(g.deadline);
      var deadlineStr = deadline.toLocaleDateString('en-GB', {month:'long', year:'numeric'});

      var feasible = g.monthly_needed <= g.surplus;
      var statusColour = g.months_left === 0 ? 'var(--risk-red)' : (feasible ? 'var(--risk-green)' : 'var(--risk-amber)');

      // Calculate natural months at current surplus
      var naturalMonths = g.surplus > 0 ? Math.ceil(g.remaining / g.surplus) : 0;
      var naturalDate = '';
      if (naturalMonths > 0) {
        var nd = new Date();
        nd.setMonth(nd.getMonth() + naturalMonths);
        naturalDate = nd.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
      }
      var monthsOver = naturalMonths - g.months_left;

      var statusMsg = '';
      if (g.months_left === 0) {
        statusMsg = 'Deadline has passed.';
      } else if (g.surplus <= 0) {
        statusMsg = '⚠ No surplus available. Reduce expenses to start saving.';
      } else if (naturalMonths <= g.months_left) {
        statusMsg = '✓ Saving your full surplus of £' + g.surplus.toFixed(2) + '/month, you will reach your goal in ' + naturalMonths + ' month' + (naturalMonths > 1 ? 's' : '') + ' by ' + naturalDate + '.';
      } else {
        statusMsg = '⚠ Saving your full surplus of £' + g.surplus.toFixed(2) + '/month, you will reach your goal in ' + naturalMonths + ' months by ' + naturalDate + ' - ' + monthsOver + ' month' + (monthsOver > 1 ? 's' : '') + ' after your deadline.';
      }

      card.innerHTML =
        // Header
        '<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;">' +
          '<div>' +
            '<div style="font-weight:600;font-size:15px;color:var(--text);">' + g.item_name + '</div>' +
            '<div style="font-size:12px;color:var(--text-muted);">Target: £' + g.target_amount.toFixed(2) + ' · Deadline: ' + deadlineStr + '</div>' +
          '</div>' +
          '<button onclick="deleteGoal(' + g.id + ')" style="background:none;border:none;cursor:pointer;color:var(--risk-red);font-size:20px;line-height:1;">×</button>' +
        '</div>' +

        // Savings display
        '<div style="display:flex;justify-content:space-between;font-size:13px;font-weight:500;margin-bottom:6px;">' +
          '<span style="color:var(--primary);">💰 £' + g.current_savings.toFixed(2) + ' saved</span>' +
          '<span style="color:var(--text-muted);">£' + g.remaining.toFixed(2) + ' remaining · ' + g.pct + '%</span>' +
        '</div>' +

        // Progress bar
        '<div style="background:#E0F2F1;border-radius:20px;height:10px;margin-bottom:10px;">' +
          '<div style="background:var(--primary);border-radius:20px;height:10px;width:' + Math.min(g.pct,100) + '%;transition:width 0.5s;"></div>' +
        '</div>' +

        // Status message
        '<div style="font-size:12px;color:' + statusColour + ';font-weight:500;margin-bottom:12px;">' + statusMsg + '</div>' +

        // Budget figures
        '<div style="font-size:11px;color:var(--text-muted);margin-bottom:10px;">Based on: Income £' + g.income.toFixed(2) + ' · Expenses £' + g.expenses.toFixed(2) + ' · Surplus £' + g.surplus.toFixed(2) + '</div>' +

        // Action buttons
        '<div style="display:flex;gap:8px;flex-wrap:wrap;">' +
          '<button onclick="showAddSavings(' + g.id + ',' + g.current_savings + ')" style="font-size:12px;padding:5px 12px;background:var(--primary);color:#fff;border:none;border-radius:var(--radius-sm);cursor:pointer;">+ Add to savings</button>' +
          '<button onclick="showUpdateDetails(' + g.id + ',' + g.current_savings + ',' + g.income + ',' + g.expenses + ')" style="font-size:12px;padding:5px 12px;background:transparent;border:1.5px solid var(--border);border-radius:var(--radius-sm);cursor:pointer;color:var(--text);">Update details</button>' +
        '</div>' +

        // Inline form (hidden by default)
        '<div id="goal-inline-' + g.id + '" style="display:none;margin-top:12px;background:var(--white);border:1.5px solid var(--border);border-radius:var(--radius-sm);padding:12px;">' +
          '<div id="goal-add-savings-' + g.id + '" style="display:none;">' +
            '<label style="font-size:12px;font-weight:500;display:block;margin-bottom:4px;">Amount to add (£)</label>' +
            '<div style="display:flex;gap:8px;">' +
              '<input type="number" id="add-savings-input-' + g.id + '" placeholder="e.g. 200" style="flex:1;padding:6px 10px;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-family:Poppins,sans-serif;font-size:13px;outline:none;" />' +
              '<button onclick="saveAddSavings(' + g.id + ',' + g.current_savings + ')" class="btn-primary" style="font-size:12px;padding:6px 12px;">Add</button>' +
              '<button onclick="hideGoalForm(' + g.id + ')" class="btn-outline" style="font-size:12px;padding:6px 12px;">Cancel</button>' +
            '</div>' +
          '</div>' +
          '<div id="goal-update-details-' + g.id + '" style="display:none;">' +
            '<label style="font-size:12px;font-weight:500;display:block;margin-bottom:4px;">Current savings (£)</label>' +
            '<input type="number" id="ud-savings-' + g.id + '" value="' + g.current_savings + '" style="width:100%;padding:6px 10px;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-family:Poppins,sans-serif;font-size:13px;outline:none;margin-bottom:8px;" />' +
            '<label style="font-size:12px;font-weight:500;display:block;margin-bottom:4px;">Monthly income (£)</label>' +
            '<input type="number" id="ud-income-' + g.id + '" value="' + g.income + '" style="width:100%;padding:6px 10px;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-family:Poppins,sans-serif;font-size:13px;outline:none;margin-bottom:8px;" />' +
            '<label style="font-size:12px;font-weight:500;display:block;margin-bottom:4px;">Monthly expenses (£)</label>' +
            '<input type="number" id="ud-expenses-' + g.id + '" value="' + g.expenses + '" style="width:100%;padding:6px 10px;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-family:Poppins,sans-serif;font-size:13px;outline:none;margin-bottom:10px;" />' +
            '<div style="display:flex;gap:8px;">' +
              '<button onclick="saveUpdateDetails(' + g.id + ')" class="btn-primary" style="font-size:12px;padding:6px 14px;">Save</button>' +
              '<button onclick="hideGoalForm(' + g.id + ')" class="btn-outline" style="font-size:12px;padding:6px 14px;">Cancel</button>' +
            '</div>' +
          '</div>' +
        '</div>';

      list.appendChild(card);
    });
  } catch(e) { console.error('Goals error:', e); }
}

function deleteGoal(id) {
  if (!confirm('Delete this goal?')) return;
  var fd = new FormData();
  fd.append('action', 'delete_savings_goal');
  fd.append('goal_id', id);
  fetch(API_BASE_DASH + '/history.php', { method:'POST', body:fd })
    .then(function() { loadSavingsGoals(); });
}

function showAddSavings(id, current) {
  document.getElementById('goal-inline-' + id).style.display = 'block';
  document.getElementById('goal-add-savings-' + id).style.display = 'block';
  document.getElementById('goal-update-details-' + id).style.display = 'none';
  document.getElementById('add-savings-input-' + id).focus();
}

function showUpdateDetails(id, savings, income, expenses) {
  document.getElementById('goal-inline-' + id).style.display = 'block';
  document.getElementById('goal-add-savings-' + id).style.display = 'none';
  document.getElementById('goal-update-details-' + id).style.display = 'block';
}

function hideGoalForm(id) {
  document.getElementById('goal-inline-' + id).style.display = 'none';
}

function saveAddSavings(id, current) {
  var extra = parseFloat(document.getElementById('add-savings-input-' + id).value) || 0;
  var total = current + extra;
  var fd = new FormData();
  fd.append('action', 'update_savings_goal');
  fd.append('goal_id', id);
  fd.append('current_savings', total);
  fetch(API_BASE_DASH + '/history.php', { method:'POST', body:fd })
    .then(function() { loadSavingsGoals(); });
}

function saveUpdateDetails(id) {
  var savings  = document.getElementById('ud-savings-' + id).value;
  var income   = document.getElementById('ud-income-' + id).value;
  var expenses = document.getElementById('ud-expenses-' + id).value;
  var fd = new FormData();
  fd.append('action', 'update_savings_goal');
  fd.append('goal_id', id);
  fd.append('current_savings', savings);
  fd.append('custom_income', income);
  fd.append('custom_expenses', expenses);
  fetch(API_BASE_DASH + '/history.php', { method:'POST', body:fd })
    .then(function() { loadSavingsGoals(); });
}

// Wire up add goal form
var addGoalBtn    = document.getElementById('add-goal-btn');
var goalForm      = document.getElementById('goal-form');
var saveGoalBtn   = document.getElementById('save-goal-btn');
var cancelGoalBtn = document.getElementById('cancel-goal-btn');

if (addGoalBtn) addGoalBtn.addEventListener('click', function() {
  goalForm.style.display = goalForm.style.display === 'none' ? 'block' : 'none';
});

if (cancelGoalBtn) cancelGoalBtn.addEventListener('click', function() {
  goalForm.style.display = 'none';
});

if (saveGoalBtn) saveGoalBtn.addEventListener('click', async function() {
  var name     = document.getElementById('goal-name').value.trim();
  var target   = document.getElementById('goal-target').value;
  var deadline = document.getElementById('goal-deadline').value;
  var savings  = document.getElementById('goal-savings').value || 0;
  if (!name || !target || !deadline) { alert('Please fill in all fields.'); return; }
  var fd = new FormData();
  fd.append('action', 'create_savings_goal');
  fd.append('item_name', name);
  fd.append('target_amount', target);
  fd.append('deadline', deadline);
  fd.append('current_savings', savings);
  var res  = await fetch(API_BASE_DASH + '/history.php', { method:'POST', body:fd });
  var data = await res.json();
  if (data.success) {
    goalForm.style.display = 'none';
    document.getElementById('goal-name').value = '';
    document.getElementById('goal-target').value = '';
    document.getElementById('goal-deadline').value = '';
    document.getElementById('goal-savings').value = '0';
    loadSavingsGoals();
  } else {
    alert('Failed to save goal: ' + (data.error || 'Unknown error'));
  }
});

loadSavingsGoals();