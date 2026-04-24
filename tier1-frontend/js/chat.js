const API_BASE = '../tier2-backend/api';

let currentSessionId = null;

const chatBox       = document.getElementById('chat-box');
const userInput     = document.getElementById('user-input');
const sendBtn       = document.getElementById('send-btn');
const newSessionBtn = document.getElementById('new-session-btn');
const sessionList   = document.getElementById('session-list');
const logoutBtn     = document.getElementById('logout-btn');
const userEmail     = document.getElementById('user-email');

async function checkAuth() {
  try {
    const formData = new FormData();
    formData.append('action', 'check');
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

async function createSession() {
  try {
    const formData = new FormData();
    formData.append('action', 'new_session');
    formData.append('title', 'Session ' + new Date().toLocaleDateString());
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

async function loadSessions() {
  try {
    const res  = await fetch(`${API_BASE}/history.php?action=sessions`);
    const data = await res.json();
    sessionList.innerHTML = '';
    if (data.success && data.sessions.length > 0) {
      data.sessions.forEach(session => {
        const item = document.createElement('div');
        item.className = 'session-item' + (session.id == currentSessionId ? ' active' : '');
        item.dataset.sessionId = session.id;

        const title = document.createElement('span');
        title.textContent        = session.title;
        title.style.flex         = '1';
        title.style.overflow     = 'hidden';
        title.style.textOverflow = 'ellipsis';
        title.style.whiteSpace   = 'nowrap';

        const menu = document.createElement('div');
        menu.className   = 'session-menu';
        menu.textContent = '\u22EF';
        menu.addEventListener('click', (e) => {
          e.stopPropagation();
          showSessionMenu(session.id, session.title, item);
        });

        item.appendChild(title);
        item.appendChild(menu);
        item.addEventListener('click', () => loadSessionMessages(session.id, item));
        sessionList.appendChild(item);
      });
    }
  } catch (err) {
    console.error('Failed to load sessions:', err);
  }
}

function showSessionMenu(sessionId, currentTitle, el) {
  document.querySelectorAll('.session-dropdown').forEach(d => d.remove());

  const dropdown = document.createElement('div');
  dropdown.className = 'session-dropdown';

  const renameBtn = document.createElement('button');
  renameBtn.textContent = 'Rename';
  renameBtn.addEventListener('click', async (e) => {
    e.stopPropagation();
    const newTitle = prompt('Enter new session name:', currentTitle);
    if (newTitle && newTitle.trim()) {
      const formData = new FormData();
      formData.append('action',     'rename_session');
      formData.append('session_id', sessionId);
      formData.append('title',      newTitle.trim());
      await fetch(`${API_BASE}/history.php`, { method: 'POST', body: formData });
      await loadSessions();
    }
    dropdown.remove();
  });

  const deleteBtn = document.createElement('button');
  deleteBtn.textContent = 'Delete';
  deleteBtn.style.color = '#E74C3C';
  deleteBtn.addEventListener('click', async (e) => {
    e.stopPropagation();
    if (confirm('Delete this session? This cannot be undone.')) {
      const formData = new FormData();
      formData.append('action',     'delete_session');
      formData.append('session_id', sessionId);
      await fetch(`${API_BASE}/history.php`, { method: 'POST', body: formData });
      if (currentSessionId == sessionId) {
        currentSessionId = null;
        clearChat();
        showWelcome();
      }
      await loadSessions();
    }
    dropdown.remove();
  });

  dropdown.appendChild(renameBtn);
  dropdown.appendChild(deleteBtn);
  el.appendChild(dropdown);

  setTimeout(() => {
    document.addEventListener('click', () => dropdown.remove(), { once: true });
  }, 0);
}

async function loadSessionMessages(sessionId, el) {
  currentSessionId = sessionId;
  document.querySelectorAll('.session-item').forEach(i => i.classList.remove('active'));
  el.classList.add('active');
  try {
    const res  = await fetch(`${API_BASE}/history.php?action=messages&session_id=${sessionId}`);
    const data = await res.json();
    clearChat();
    if (data.success && data.messages.length > 0) {
      data.messages.forEach(msg => addMessage(msg.role, msg.content, msg.calculation || null));
    } else {
      showWelcome();
    }
  } catch (err) {
    console.error('Failed to load messages:', err);
  }
}

function clearChat() {
  chatBox.innerHTML = '';
}

function showWelcome() {
  chatBox.innerHTML = `
    <div class="welcome-msg">
      <h2>Hello, I am SmartSpend</h2>
      <p>I will guide you step by step to find out if you can afford something.</p>
      <p>Type anything to get started — I will ask you the right questions.</p>
      <p class="example">Try saying: "Hi" or "Can I afford a new car?"</p>
    </div>
  `;
}

function addMessage(role, content, calculation = null) {
  const welcome = chatBox.querySelector('.welcome-msg');
  if (welcome) welcome.remove();

  const wrap   = document.createElement('div');
  wrap.className = `message ${role}`;

  const bubble = document.createElement('div');
  bubble.className = 'bubble';

  if (role === 'bot') {
    bubble.innerHTML = content.replace(/\n/g, '<br>');
  } else {
    bubble.textContent = content;
  }

  if (calculation) {
    bubble.appendChild(buildResultCard(calculation));
  }

  wrap.appendChild(bubble);
  chatBox.appendChild(wrap);
  chatBox.scrollTop = chatBox.scrollHeight;
}

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

  const mainRows = [
    { label: 'Item',               value: calc.item_name,                                                                cls: '' },
    { label: 'Price',              value: `\u00A3${Number(calc.item_price).toFixed(2)}`,                                 cls: '' },
    { label: 'Type',               value: calc.item_type,                                                                cls: '' },
    { label: 'Monthly surplus',    value: `\u00A3${Number(calc.surplus).toFixed(2)}`,                                    cls: calc.surplus >= 0 ? 'positive' : 'negative' },
    { label: 'Surplus after item', value: `\u00A3${Number(calc.surplus_after).toFixed(2)}`,                              cls: calc.surplus_after >= 0 ? 'positive' : 'negative' },
    { label: 'Months to save',     value: calc.months_to_save === 0 ? 'Already there' : `${calc.months_to_save} months`, cls: 'warning' },
    { label: 'Health score',       value: `${calc.health_score}/100`,                                                    cls: 'positive' },
  ];

  mainRows.forEach(row => {
    const r = document.createElement('div');
    r.className = 'result-row';
    r.innerHTML = `<span class="result-label">${row.label}</span><span class="result-value ${row.cls}">${row.value}</span>`;
    card.appendChild(r);
  });

  if (calc.projections && Object.keys(calc.projections).length > 0) {
    if (calc.projections.summary) {
      const r = document.createElement('div');
      r.className = 'result-row';
      r.innerHTML = `<span class="result-label">Time to save</span><span class="result-value warning">${calc.projections.summary}</span>`;
      card.appendChild(r);
    } else {
      Object.entries(calc.projections).forEach(([key, value]) => {
        if (value === undefined || value === null) return;
        const monthNum = key.replace('month_', '');
        const r = document.createElement('div');
        r.className = 'result-row';
        r.innerHTML = `<span class="result-label">Month ${monthNum} savings</span><span class="result-value">\u00A3${value}</span>`;
        card.appendChild(r);
      });
    }
  }

  return card;
}

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

function renderQuickReplies(replies) {
  document.querySelectorAll('.quick-replies').forEach(el => el.remove());
  if (!replies || replies.length === 0) return;

  const container = document.createElement('div');
  container.className = 'quick-replies';

  replies.forEach(reply => {
    const btn = document.createElement('button');
    btn.className   = 'quick-reply-btn';
    btn.textContent = reply;

    if (reply === 'Other') {
      btn.addEventListener('click', () => {
        document.querySelectorAll('.quick-replies').forEach(el => el.remove());
        userInput.placeholder = 'Type your answer here...';
        userInput.focus();
      });
    } else {
      btn.addEventListener('click', () => {
        userInput.value = reply;
        sendMessage();
      });
    }

    container.appendChild(btn);
  });

  chatBox.appendChild(container);
  chatBox.scrollTop = chatBox.scrollHeight;
}

async function sendMessage() {
  const text = userInput.value.trim();
  if (!text) return;

  if (!currentSessionId) {
    await createSession();
  }

  addMessage('user', text);
  userInput.value       = '';
  userInput.placeholder = 'Type your message here...';
  sendBtn.disabled      = true;
  document.querySelectorAll('.quick-replies').forEach(el => el.remove());
  showTyping();

  try {
    const formData = new FormData();
    formData.append('message',    text);
    formData.append('session_id', currentSessionId);

    const res  = await fetch(`${API_BASE}/chat.php`, { method: 'POST', body: formData });
    const data = await res.json();

    removeTyping();

    if (data.success) {
      addMessage('bot', data.bot_reply, data.calculation || null);
      renderQuickReplies(data.quick_replies || []);
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

async function handleLogout() {
  try {
    const formData = new FormData();
    formData.append('action', 'logout');
    await fetch(`${API_BASE}/auth.php`, { method: 'POST', body: formData });
  } finally {
    window.location.href = 'login.html';
  }
}

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

(async () => {
  await checkAuth();
  await loadSessions();

  const res  = await fetch(`${API_BASE}/history.php?action=sessions`);
  const data = await res.json();

  if (!data.success || data.sessions.length === 0) {
    await createSession();
  } else {
    currentSessionId = data.sessions[0].id;
    const firstItem  = sessionList.querySelector('.session-item');
    if (firstItem) {
      firstItem.classList.add('active');
      await loadSessionMessages(data.sessions[0].id, firstItem);
    }
  }
})();