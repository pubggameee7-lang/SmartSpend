const API_BASE = '../tier2-backend/api';

// ── Elements ──────────────────────────────────────────────
const scoreNumber    = document.getElementById('score-number');
const scoreTrend     = document.getElementById('score-trend');
const scoreCircle    = document.getElementById('score-circle');
const totalSessions  = document.getElementById('total-sessions');
const lastRisk       = document.getElementById('last-risk');
const lastItem       = document.getElementById('last-item');
const sessionHistory = document.getElementById('session-history');
const logoutBtn      = document.getElementById('logout-btn');
const userEmail      = document.getElementById('user-email');

// ── Check auth ────────────────────────────────────────────
async function checkAuth() {
  try {
    const formData = new FormData();
    formData.append('action', 'check');

    const res  = await fetch(`${API_BASE}/auth.php`, {
      method: 'POST',
      body:   formData
    });
    const data = await res.json();

    if (!data.logged_in) {
      window.location.href = 'login.html';
      return false;
    }

    userEmail.textContent = data.email;
    return true;
  } catch (err) {
    window.location.href = 'login.html';
    return false;
  }
}

// ── Load health score from database ──────────────────────
async function loadHealthScore() {
  try {
    const res  = await fetch(`${API_BASE}/history.php?action=health_score`);
    const data = await res.json();

    if (!data.success || !data.score) {
      scoreNumber.textContent = '--';
      scoreTrend.textContent  = 'No data yet — start a chat session';
      return;
    }

    const score = data.score;
    const trend = data.trend;

    scoreNumber.textContent = score;

    // Colour score circle based on value
    if (score >= 70) {
      scoreCircle.style.borderColor = '#27AE60';
      scoreNumber.style.color       = '#27AE60';
      scoreTrend.style.color        = '#27AE60';
      scoreTrend.textContent        = trend === 'up'
        ? 'Improving — great work'
        : 'Good financial health';
    } else if (score >= 40) {
      scoreCircle.style.borderColor = '#F39C12';
      scoreNumber.style.color       = '#F39C12';
      scoreTrend.style.color        = '#F39C12';
      scoreTrend.textContent        = trend === 'down'
        ? 'Declining — review your spending'
        : 'Room for improvement';
    } else {
      scoreCircle.style.borderColor = '#E74C3C';
      scoreNumber.style.color       = '#E74C3C';
      scoreTrend.style.color        = '#E74C3C';
      scoreTrend.textContent        = 'Low score — reduce expenses';
    }
  } catch (err) {
    scoreNumber.textContent = '--';
    scoreTrend.textContent  = 'Could not load score';
  }
}

// ── Load sessions and last assessment ────────────────────
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

    // Load last assessment from most recent session
    await loadLastAssessment(data.sessions[0].id);

    // Build session list
    data.sessions.forEach(session => {
      const item = document.createElement('div');
      item.className = 'history-item';
      item.innerHTML = `
        <strong>${session.title}</strong>
        <span style="float:right;color:#7F8C8D;font-size:12px">
          ${new Date(session.created_at).toLocaleDateString()}
        </span>
      `;

      // Click loads that session in chat without page reload
      item.addEventListener('click', () => {
        sessionStorage.setItem('load_session', session.id);
        window.location.href = 'index.html';
      });

      sessionHistory.appendChild(item);
    });
  } catch (err) {
    sessionHistory.innerHTML = '<p class="empty-msg">Could not load sessions.</p>';
  }
}

// ── Load last assessment from a session ───────────────────
async function loadLastAssessment(sessionId) {
  try {
    const res  = await fetch(`${API_BASE}/history.php?action=last_assessment&session_id=${sessionId}`);
    const data = await res.json();

    if (data.success && data.assessment) {
      const a    = data.assessment;
      const risk = a.risk_level;

      lastItem.textContent = a.item_name || '--';

      // Colour the risk badge
      lastRisk.textContent  = risk === 'green' ? 'Low Risk'
                            : risk === 'yellow' ? 'Moderate Risk'
                            : 'High Risk';
      lastRisk.style.color  = risk === 'green' ? '#27AE60'
                            : risk === 'yellow' ? '#F39C12'
                            : '#E74C3C';
    } else {
      lastRisk.textContent = '--';
      lastItem.textContent = '--';
    }
  } catch (err) {
    lastRisk.textContent = '--';
    lastItem.textContent = '--';
  }
}

// ── Logout ────────────────────────────────────────────────
if (logoutBtn) {
  logoutBtn.addEventListener('click', async () => {
    try {
      const formData = new FormData();
      formData.append('action', 'logout');
      await fetch(`${API_BASE}/auth.php`, {
        method: 'POST',
        body:   formData
      });
    } finally {
      window.location.href = 'login.html';
    }
  });
}

// ── Init ──────────────────────────────────────────────────
(async () => {
  const authed = await checkAuth();
  if (!authed) return;

  await Promise.all([
    loadHealthScore(),
    loadSessions()
  ]);
})();