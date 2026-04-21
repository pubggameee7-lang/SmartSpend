const API_BASE = '../tier2-backend/api';

let currentSessionId = null;

// ── Elements ──────────────────────────────────────────────
const chatBox       = document.getElementById('chat-box');
const userInput     = document.getElementById('user-input');
const sendBtn       = document.getElementById('send-btn');
const newSessionBtn = document.getElementById('new-session-btn');
const sessionList   = document.getElementById('session-list');
const logoutBtn     = document.getElementById('logout-btn');
const userEmail     = document.getElementById('user-email');

// ── Check user is logged in ───────────────────────────────
async function checkAuth() {
  const formData = new FormData();
  formData.append('action', 'check');

  try {
    const res  = await fetch(`${API_BASE}/auth.php`, { method: 'POST', body: formData });
    const data = await res.json();

    if (!data.logged_in) {
      window.location.href = 'login.html';
    } else {
      userEmail.textContent = data.email;
    }
  } catch (err) {
    window.location.href = 'login.html';
  }
}

// ── Create a new session without reloading ────────────────
async function createSession() {
  const formData = new FormData();
  formData.append('action', 'new_session');
  formData.append('title', 'Session ' + new Date().toLocaleDateString());

  try {
    const res  = await fetch(`${API_BASE}/history.php`, { method: 'POST', body: formData });
    const data = await res.json();

    if (data.success) {
      currentSessionId = data.session_id;
      await loadSessions();
      clearChat();
      showWelcome();
    }
  } catch (err) {
    console.error('Failed to create session:', err);
  }
}

// ── Load session list in sidebar without reloading ────────
async function loadSessions() {
  try {
    const res  = await fetch(`${API_BASE}/history.php?action=sessions`);
    const data = await res.json();

    sessionList.innerHTML = '';

    if (data.success && data.sessions.length > 0) {
      data.sessions.forEach(session => {
        const item = document.createElement('div');
        item.className = 'session-item' + (session.id == currentSessionId ? ' active' : '');
        item.textContent = session.title;
        item.dataset.sessionId = session.id;
        item.addEventListener('click', () => loadSessionMessages(session.id, item));
        sessionList.appendChild(item);
      });
    }
  } catch (err) {
    console.error('Failed to load sessions:', err);
  }
}

// ── Load messages for a session without reloading ─────────
async function loadSessionMessages(sessionId, el) {
  currentSessionId = sessionId;

  document.querySelectorAll('.session-item').forEach(i => i.classList.remove('active'));
  el.classList.add('active');

  try {
    const res  = await fetch(`${API_BASE}/history.php?action=messages&session_id=${sessionId}`);
    const data = await res.json();

    clearChat();

    if (data.success && data.messages.length > 0) {
      data.messages.forEach(msg => addMessage(msg.role, msg.content));
    } else {
      showWelcome();
    }
  } catch (err) {
    console.error('Failed to load messages:', err);
  }
}

// ── Clear chat area ───────────────────────────────────────
function clearChat() {
  chatBox.innerHTML = '';
}

// ── Show welcome message ──────────────────────────────────
function showWelcome() {
  chatBox.innerHTML = `
    <div class="welcome-msg">
      <h2>Hello, I am SmartSpend</h2>
      <p>Tell me your monthly income, expenses, savings, and what you want to buy — I will tell you if you can afford it.</p>
      <p class="example">Example: "My income is £2500, expenses £1200, savings £800. Can I afford a £400 laptop?"</p>
    </div>
  `;
}

// ── Add message bubble to chat ────────────────────────────
function addMessage(role, content, calculation = null) {
  // Remove welcome message if present
  const welcome = chatBox.querySelector('.welcome-msg');
  if (welcome) welcome.remove();

  const wrap   = document.createElement('div');
  wrap.className = `message ${role}`;

  const bubble = document.createElement('div');
  bubble.className = 'bubble';
  bubble.textContent = content;

  if (calculation) {
    const card = buildResultCard(calculation);
    bubble.appendChild(card);
  }

  wrap.appendChild(bubble);
  chatBox.appendChild(wrap);
  chatBox.scrollTop = chatBox.scrollHeight;
}

// ── Build result card ─────────────────────────────────────
function buildResultCard(calc) {
  const risk = calc.risk_level;

  const card  = document.createElement('div');
  card.className = `result-card ${risk}`;

  const badge = document.createElement('div');
  badge.className = `risk-badge ${risk}`;
  badge.textContent = risk === 'green' ? 'LOW RISK'
                    : risk === 'yellow' ? 'MODERATE RISK'
                    : 'HIGH RISK';
  card.appendChild(badge);

  const rows = [
    { label: 'Monthly surplus',    value: `£${Number(calc.surplus).toFixed(2)}`,       cls: calc.surplus >= 0 ? 'positive' : 'negative' },
    { label: 'Surplus after item', value: `£${Number(calc.surplus_after).toFixed(2)}`, cls: calc.surplus_after >= 0 ? 'positive' : 'negative' },
    { label: 'Months to save',     value: calc.months_to_save === 0 ? 'Already there' : `${calc.months_to_save} months`, cls: 'warning' },
    { label: 'Health score',       value: `${calc.health_score}/100`, cls: 'positive' },
    { label: 'Month 1 projection', value: `£${calc.projections.month_1}`, cls: '' },
    { label: 'Month 2 projection', value: `£${calc.projections.month_2}`, cls: '' },
    { label: 'Month 3 projection', value: `£${calc.projections.month_3}`, cls: '' },
  ];

  rows.forEach(row => {
    const r = document.createElement('div');
    r.className = 'result-row';
    r.innerHTML = `
      <span class="result-label">${row.label}</span>
      <span class="result-value ${row.cls}">${row.value}</span>
    `;
    card.appendChild(r);
  });

  return card;
}

// ── Parse user message for financial data ─────────────────
function parseMessage(text) {
  const income    = text.match(/income[^\d]*£?([\d,]+)/i);
  const expenses  = text.match(/expenses?[^\d]*£?([\d,]+)/i);
  const savings   = text.match(/savings?[^\d]*£?([\d,]+)/i);
  const prices    = text.match(/£([\d,]+)/g);
  const recurring = /per month|monthly|\/mo|subscription/i.test(text);

  return {
    income:     income    ? parseFloat(income[1].replace(',', ''))    : 0,
    expenses:   expenses  ? parseFloat(expenses[1].replace(',', ''))  : 0,
    savings:    savings   ? parseFloat(savings[1].replace(',', ''))   : 0,
    item_price: prices    ? parseFloat(prices[prices.length - 1].replace('£', '').replace(',', '')) : 0,
    item_type:  recurring ? 'recurring' : 'one-time',
    item_name:  text
      .replace(/£[\d,]+/g, '')
      .replace(/income|expenses?|savings?|afford|buy|can i|per month|monthly/gi, '')
      .trim()
      .slice(0, 60),
  };
}

// ── Show typing indicator ─────────────────────────────────
function showTyping() {
  const typing = document.createElement('div');
  typing.className = 'message bot';
  typing.id = 'typing-indicator';
  typing.innerHTML = '<div class="bubble" style="color:#7F8C8D;font-style:italic">SmartSpend is thinking...</div>';
  chatBox.appendChild(typing);
  chatBox.scrollTop = chatBox.scrollHeight;
}

function removeTyping() {
  document.getElementById('typing-indicator')?.remove();
}

// ── Send message ──────────────────────────────────────────
async function sendMessage() {
  const text = userInput.value.trim();
  if (!text) return;

  // Create session on first message if none exists
  if (!currentSessionId) {
    await createSession();
  }

  addMessage('user', text);
  userInput.value = '';
  sendBtn.disabled = true;
  showTyping();

  const parsed   = parseMessage(text);
  const formData = new FormData();
  formData.append('message',    text);
  formData.append('session_id', currentSessionId);
  formData.append('income',     parsed.income);
  formData.append('expenses',   parsed.expenses);
  formData.append('savings',    parsed.savings);
  formData.append('item_name',  parsed.item_name);
  formData.append('item_price', parsed.item_price);
  formData.append('item_type',  parsed.item_type);

  try {
    const res  = await fetch(`${API_BASE}/chat.php`, { method: 'POST', body: formData });
    const data = await res.json();

    removeTyping();

    if (data.success) {
      addMessage('bot', data.bot_reply, data.calculation);
    } else {
      addMessage('bot', data.error || 'Something went wrong. Please try again.');
    }
  } catch (err) {
    removeTyping();
    addMessage('bot', 'Something went wrong. Please try again.');
  } finally {
    sendBtn.disabled = false;
    userInput.focus();
  }
}

// ── Logout without page reload ────────────────────────────
async function handleLogout() {
  const formData = new FormData();
  formData.append('action', 'logout');

  try {
    await fetch(`${API_BASE}/auth.php`, { method: 'POST', body: formData });
    window.location.href = 'login.html';
  } catch (err) {
    window.location.href = 'login.html';
  }
}

// ── Event listeners ───────────────────────────────────────
sendBtn.addEventListener('click', sendMessage);

userInput.addEventListener('keydown', (e) => {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    sendMessage();
  }
});

newSessionBtn.addEventListener('click', createSession);

if (logoutBtn) {
  logoutBtn.addEventListener('click', handleLogout);
}

// ── Init — no page reload, just fetch ────────────────────
(async () => {
  await checkAuth();
  await loadSessions();

  // Only create a new session if there are none yet
  const res  = await fetch(`${API_BASE}/history.php?action=sessions`);
  const data = await res.json();

  if (!data.success || data.sessions.length === 0) {
    await createSession();
  } else {
    currentSessionId = data.sessions[0].id;
    const firstItem = sessionList.querySelector('.session-item');
    if (firstItem) firstItem.classList.add('active');
    await loadSessionMessages(data.sessions[0].id, sessionList.querySelector('.session-item'));
  }
})();