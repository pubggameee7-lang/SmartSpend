const API_BASE = '../tier2-backend/api';

// ── Elements ──────────────────────────────────────────────
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

// ── Spending personality engine ───────────────────────────
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

// ── Health score colour helper ────────────────────────────
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

// ── Draw trend chart ──────────────────────────────────────
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

// ── Render goal progress bars ─────────────────────────────
function renderGoalProgress(assessments, lastBudget) {
  if (!assessments || assessments.length === 0) return;
  if (!lastBudget) return;

  const savings = parseFloat(lastBudget.savings) || 0;
  const surplus = parseFloat(lastBudget.income) - parseFloat(lastBudget.expenses);

  // Group by item name, take most recent per item
  const seen = {};
  assessments.forEach(a => {
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

// ── Render all items checked ──────────────────────────────
function renderItems(assessments) {
  if (!assessments || assessments.length === 0) {
    itemsList.innerHTML = '<p class="empty-msg">No items checked yet.</p>';
    return;
  }

  itemsList.innerHTML = '';
  assessments.forEach(a => {
    const risk      = a.risk_level;
    const riskLabel = risk === 'green' ? 'Low Risk' : risk === 'yellow' ? 'Moderate Risk' : 'High Risk';
    const months    = parseInt(a.months_to_save);
    const timeStr   = isNaN(months) || months === 0 ? 'Already affordable' :
                      months > 12
                        ? Math.floor(months / 12) + 'yr ' + (months % 12 > 0 ? months % 12 + 'mo' : '')
                        : months + ' month' + (months > 1 ? 's' : '');

    itemsList.innerHTML += `
      <div class="item-row">
        <div class="item-row-left">
          <span class="item-name">${a.item_name}</span>
          <span class="item-meta">£${parseFloat(a.item_price).toLocaleString()} · ${a.item_type} · ${timeStr} to save</span>
        </div>
        <span class="item-badge ${risk}">${riskLabel}</span>
      </div>`;
  });
}

// ── Check auth ────────────────────────────────────────────
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

// ── Load health score + trend ─────────────────────────────
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

// ── Load sessions ─────────────────────────────────────────
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

// ── Load last assessment + all items ─────────────────────
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

// ── Logout ────────────────────────────────────────────────
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

// ── Init ──────────────────────────────────────────────────
(async () => {
  const authed = await checkAuth();
  if (!authed) return;
  await Promise.all([loadHealthScore(), loadSessions()]);
})();