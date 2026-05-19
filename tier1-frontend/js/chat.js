// v4 - with PDF export
const API_BASE = '../tier2-backend/api';

let currentSessionId = null;
let lastBudgetSnapshot = null; // stores latest income/expenses/savings for PDF

const chatBox               = document.getElementById('chat-box');
const userInput             = document.getElementById('user-input');
const sendBtn               = document.getElementById('send-btn');
const newSessionBtn         = document.getElementById('new-session-btn');
const sessionList           = document.getElementById('session-list');
const logoutBtn             = document.getElementById('logout-btn');
const userEmail             = document.getElementById('user-email');
const quickRepliesContainer = document.getElementById('quick-replies-container');

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
    sessionList.innerHTML = '';

    // Load projects
    const projRes  = await fetch(`${API_BASE}/history.php?action=projects`);
    const projData = await projRes.json();

    // PROJECTS section
    // PROJECTS - collapsed header like archive
    var projHeader = document.createElement('div');
    projHeader.className = 'sidebar-section-label sidebar-section-row';
    var projToggleSpan = document.createElement('span');
    projToggleSpan.textContent = '\u25b8 PROJECTS';
    var addProjBtn = document.createElement('button');
    addProjBtn.textContent = '+ New';
    addProjBtn.style.cssText = 'font-size:11px;padding:2px 8px;background:transparent;border:1px solid var(--border);border-radius:4px;color:var(--text-muted);cursor:pointer;font-family:Poppins,sans-serif;';
    addProjBtn.addEventListener('click', async function(e) {
      e.stopPropagation();
      var name = prompt('Project name:');
      if (name && name.trim()) {
        var formData = new FormData();
        formData.append('action', 'create_project');
        formData.append('name', name.trim());
        var res = await fetch(`${API_BASE}/history.php`, { method: 'POST', body: formData });
        var data = await res.json();
        if (data.success) { await loadSessions(); }
        else { alert('Failed to create project.'); }
      }
    });
    projHeader.appendChild(projToggleSpan);
    projHeader.appendChild(addProjBtn);

    var projList = document.createElement('div');
    projList.style.cssText = 'display:none;flex-direction:column;gap:4px;';

    projHeader.addEventListener('click', function() {
      var open = projList.style.display !== 'none';
      projList.style.display = open ? 'none' : 'flex';
      projToggleSpan.textContent = (open ? '\u25b8' : '\u25be') + ' PROJECTS';
    });

    sessionList.appendChild(projHeader);
    sessionList.appendChild(projList);

    if (projData.success && projData.projects.length > 0) {
      projData.projects.forEach(function(project) {
        var folder = document.createElement('div');
        folder.className = 'project-folder';

        var folderHeader = document.createElement('div');
        folderHeader.className = 'project-header';

        var toggle = document.createElement('span');
        toggle.className = 'project-toggle';
        toggle.textContent = '\u25b8';

        var nameSpan = document.createElement('span');
        nameSpan.className = 'project-name';
        nameSpan.textContent = '\uD83D\uDCC1 ' + project.name;

        var folderMenu = document.createElement('div');
        folderMenu.className = 'session-menu';
        folderMenu.textContent = '\u22EF';
        folderMenu.addEventListener('click', function(e) {
          e.stopPropagation();
          var existing = document.querySelector('.session-dropdown');
          if (existing) { existing.remove(); return; }
          showProjectMenu(project.id, project.name, folderMenu);
        });

        folderHeader.appendChild(toggle);
        folderHeader.appendChild(nameSpan);
        folderHeader.appendChild(folderMenu);

        var folderSessions = document.createElement('div');
        folderSessions.className = 'project-sessions';
        folderSessions.style.cssText = 'display:none;flex-direction:column;gap:2px;padding-left:8px;';

        folderHeader.addEventListener('click', function() {
          var isOpen = folderSessions.style.display !== 'none';
          folderSessions.style.display = isOpen ? 'none' : 'flex';
          toggle.textContent = isOpen ? '\u25b8' : '\u25be';
        });

        project.sessions.forEach(function(session) {
          folderSessions.appendChild(buildSessionItem(session, project.id));
        });

        folder.appendChild(folderHeader);
        folder.appendChild(folderSessions);
        projList.appendChild(folder);
      });
    }

    // ARCHIVED section - below projects, above chats
    var archRes  = await fetch(`${API_BASE}/history.php?action=archived_sessions`);
    var archData = await archRes.json();
    if (archData.success && archData.sessions.length > 0) {
      var archHeader = document.createElement('div');
      archHeader.className = 'sidebar-section-label';
      archHeader.textContent = '\u25b8 Archived';
      var archList = document.createElement('div');
      archList.style.cssText = 'display:none;flex-direction:column;gap:4px;';
      archHeader.addEventListener('click', function() {
        var open = archList.style.display !== 'none';
        archList.style.display = open ? 'none' : 'flex';
        archHeader.textContent = (open ? '\u25b8' : '\u25be') + ' Archived';
      });
      archData.sessions.forEach(function(session) {
        var item = document.createElement('div');
        item.className = 'session-item';
        item.style.opacity = '0.7';
        var title = document.createElement('span');
        title.textContent = session.title;
        title.style.cssText = 'flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';
        var restoreBtn = document.createElement('button');
        restoreBtn.textContent = 'Restore';
        restoreBtn.style.cssText = 'font-size:11px;padding:2px 8px;background:var(--primary);color:#fff;border:none;border-radius:4px;cursor:pointer;flex-shrink:0;';
        restoreBtn.addEventListener('click', async function(e) {
          e.stopPropagation();
          var formData = new FormData();
          formData.append('action', 'unarchive_session');
          formData.append('session_id', session.id);
          await fetch(`${API_BASE}/history.php`, { method: 'POST', body: formData });
          await loadSessions();
        });
        item.appendChild(title);
        item.appendChild(restoreBtn);
        item.addEventListener('click', function() { loadSessionMessages(session.id, item); });
        archList.appendChild(item);
      });
      sessionList.appendChild(archHeader);
      sessionList.appendChild(archList);
    }

    // CHATS section
    var res  = await fetch(`${API_BASE}/history.php?action=sessions`);
    var data = await res.json();

    var chatsLabel = document.createElement('div');
    chatsLabel.className = 'sidebar-section-label';
    chatsLabel.textContent = 'CHATS';
    sessionList.appendChild(chatsLabel);

    if (data.success && data.sessions.length > 0) {
      var ungrouped = data.sessions.filter(function(s) { return !s.project_id; });
      ungrouped.forEach(function(session) {
        sessionList.appendChild(buildSessionItem(session, null));
      });
    }





  } catch (err) {
    console.error('Failed to load sessions:', err);
  }
}

function buildSessionItem(session, projectId) {
  var item = document.createElement('div');
  item.className = 'session-item' + (session.id == currentSessionId ? ' active' : '') + (session.pinned ? ' pinned' : '');
  item.dataset.sessionId = session.id;

  var title = document.createElement('span');
  title.textContent = (session.pinned ? '\uD83D\uDCCC ' : '') + session.title;
  title.style.cssText = 'flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';

  var menu = document.createElement('div');
  menu.className = 'session-menu';
  menu.textContent = '\u22EF';
  menu.addEventListener('click', function(e) {
    e.stopPropagation();
    var existing = document.querySelector('.session-dropdown');
    if (existing) { existing.remove(); return; }
    showSessionMenu(session.id, session.title, item, session.pinned, projectId);
  });

  item.appendChild(title);
  item.appendChild(menu);
  item.addEventListener('click', function() { loadSessionMessages(session.id, item); });
  item.setAttribute('tabindex', '0');
  item.setAttribute('role', 'listitem');
  item.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); loadSessionMessages(session.id, item); }
  });
  return item;
}

function showProjectMenu(projectId, projectName, el) {
  document.querySelectorAll('.session-dropdown').forEach(function(d) { d.remove(); });
  var dropdown = document.createElement('div');
  dropdown.className = 'session-dropdown';

  var renameBtn = document.createElement('button');
  renameBtn.textContent = 'Rename folder';
  renameBtn.addEventListener('click', async function(e) {
    e.stopPropagation();
    dropdown.remove();
    // Find folder name span and make it editable
    var nameSpan = el.parentNode.querySelector('.project-name');
    if (!nameSpan) return;
    var input = document.createElement('input');
    input.value = projectName;
    input.style.cssText = 'flex:1;border:1.5px solid var(--primary);border-radius:4px;padding:2px 6px;font-family:Poppins,sans-serif;font-size:13px;color:var(--text);outline:none;width:100%;';
    nameSpan.replaceWith(input);
    input.focus();
    input.select();
    async function saveRename() {
      var newName = input.value.trim();
      if (newName && newName !== projectName) {
        var formData = new FormData();
        formData.append('action', 'rename_project');
        formData.append('project_id', projectId);
        formData.append('name', newName);
        await fetch(API_BASE + '/history.php', { method: 'POST', body: formData });
        await loadSessions();
      } else {
        input.replaceWith(nameSpan);
      }
    }
    input.addEventListener('blur', saveRename);
    input.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
      if (e.key === 'Escape') { input.replaceWith(nameSpan); }
    });
  });

  var deleteBtn = document.createElement('button');
  deleteBtn.textContent = 'Delete folder';
  deleteBtn.style.color = '#E74C3C';
  deleteBtn.addEventListener('click', async function(e) {
    e.stopPropagation();
    if (confirm('Delete folder? Sessions will move to main list.')) {
      var formData = new FormData();
      formData.append('action', 'delete_project');
      formData.append('project_id', projectId);
      await fetch(`${API_BASE}/history.php`, { method: 'POST', body: formData });
      await loadSessions();
    }
    dropdown.remove();
  });

  dropdown.appendChild(renameBtn);
  dropdown.appendChild(deleteBtn);
  document.body.appendChild(dropdown);
  var rect = el.getBoundingClientRect();
  dropdown.style.cssText = 'position:fixed;top:' + rect.bottom + 'px;left:' + rect.left + 'px;z-index:300;min-width:130px;max-width:155px;background:var(--white);border:1.5px solid var(--border);border-radius:var(--radius-sm);box-shadow:var(--shadow-md);overflow:hidden;';
  setTimeout(function() {
    document.addEventListener('click', function() { dropdown.remove(); }, { once: true });
  }, 0);
}

function showSessionMenu(sessionId, currentTitle, el, currentPinned, currentProjectId) {
  document.querySelectorAll('.session-dropdown').forEach(d => d.remove());

  const dropdown = document.createElement('div');
  dropdown.className = 'session-dropdown';

  const renameBtn = document.createElement('button');
  renameBtn.textContent = 'Rename';
  renameBtn.addEventListener('click', async function(e) {
    e.stopPropagation();
    dropdown.remove();
    // Find the title span in the session item and make it editable
    var titleSpan = el.querySelector('span');
    if (!titleSpan) return;
    var oldText = titleSpan.textContent;
    var input = document.createElement('input');
    input.value = currentTitle;
    input.style.cssText = 'flex:1;border:1.5px solid var(--primary);border-radius:4px;padding:2px 6px;font-family:Poppins,sans-serif;font-size:13px;color:var(--text);outline:none;width:100%;';
    titleSpan.replaceWith(input);
    input.focus();
    input.select();
    async function saveRename() {
      var newTitle = input.value.trim();
      if (newTitle && newTitle !== currentTitle) {
        var formData = new FormData();
        formData.append('action', 'rename_session');
        formData.append('session_id', sessionId);
        formData.append('title', newTitle);
        await fetch(API_BASE + '/history.php', { method: 'POST', body: formData });
        await loadSessions();
      } else {
        input.replaceWith(titleSpan);
      }
    }
    input.addEventListener('blur', saveRename);
    input.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
      if (e.key === 'Escape') { input.replaceWith(titleSpan); }
    });
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

  const copyBtn = document.createElement('button');
  copyBtn.textContent = 'Copy chat';
  copyBtn.addEventListener('click', async function(e) {
    e.stopPropagation();
    dropdown.remove();
    try {
      const res  = await fetch(`${API_BASE}/history.php?action=messages&session_id=${sessionId}`);
      const data = await res.json();
      if (data.success && data.messages.length > 0) {
        const text = data.messages.map(function(m) {
          return (m.role === 'user' ? 'You: ' : 'SmartSpend: ') + m.content;
        }).join('\n\n');
        await navigator.clipboard.writeText(text);
        const toast = document.createElement('div');
        toast.textContent = 'Chat copied to clipboard';
        toast.style.cssText = 'position:fixed;bottom:80px;left:50%;transform:translateX(-50%);background:#2C3E50;color:#fff;padding:8px 18px;border-radius:20px;font-size:13px;font-family:Poppins,sans-serif;z-index:999;opacity:1;transition:opacity 0.4s;pointer-events:none;';
        document.body.appendChild(toast);
        setTimeout(function() { toast.style.opacity='0'; setTimeout(function() { toast.remove(); }, 400); }, 2000);
      }
    } catch(err) {
      console.error('Copy failed:', err);
    }
  });

  const exportBtn = document.createElement('button');
  exportBtn.textContent = 'Export as PDF';
  exportBtn.addEventListener('click', async function(e) {
    e.stopPropagation();
    dropdown.remove();
    try {
      const res  = await fetch(`${API_BASE}/history.php?action=messages&session_id=${sessionId}`);
      const data = await res.json();
      if (data.success && data.messages.length > 0) {
        const rows = data.messages.map(function(m) {
          return '<tr style="background:' + (m.role==='user'?'#f8fffe':'#fff') + '"><td style="padding:8px 12px;font-weight:600;color:' + (m.role==='user'?'#00B4A6':'#2C3E50') + ';white-space:nowrap;vertical-align:top">' + (m.role==='user'?'You':'SmartSpend') + '</td><td style="padding:8px 12px;color:#2C3E50">' + m.content.replace(/\n/g,'<br>') + '</td></tr>';
        }).join('');
        const win = window.open('', '_blank');
        win.document.write('<!DOCTYPE html><html><head><title>SmartSpend - ' + currentTitle + '</title><style>body{font-family:Helvetica,sans-serif;margin:0;padding:0;color:#2C3E50}.header{background:#00B4A6;color:#fff;padding:20px 30px}.header h1{margin:0;font-size:22px}.header p{margin:4px 0 0;font-size:12px;opacity:.85}.disclaimer{background:#E0F2F1;color:#00B4A6;font-size:11px;padding:8px 30px}.content{padding:24px 30px}table{width:100%;border-collapse:collapse;font-size:13px}td{border-bottom:1px solid #f0f0f0;vertical-align:top}.footer{margin-top:32px;padding-top:12px;border-top:1px solid #E0F2F1;font-size:10px;color:#7F8C8D}.date{float:right;font-size:11px;opacity:.7}@media print{body{-webkit-print-color-adjust:exact;print-color-adjust:exact}}</style></head><body><div class="header"><span class="date">' + new Date().toLocaleDateString("en-GB") + '</span><h1>SmartSpend</h1><p>' + currentTitle + '</p></div><div class="disclaimer">Not a financial adviser - for educational purposes only.</div><div class="content"><table>' + rows + '</table><div class="footer">SmartSpend - Not a financial adviser. For educational purposes only.</div></div><script>window.onload=function(){window.print();}<\/script></body></html>');
        win.document.close();
      }
    } catch(err) { console.error('Export failed:', err); }
  });

  const pinBtn = document.createElement('button');
  pinBtn.textContent = currentPinned ? 'Unpin' : 'Pin to top';
  pinBtn.addEventListener('click', async function(e) {
    e.stopPropagation();
    dropdown.remove();
    const formData = new FormData();
    formData.append('action', 'pin_session');
    formData.append('session_id', sessionId);
    formData.append('pinned', currentPinned ? 0 : 1);
    await fetch(`${API_BASE}/history.php`, { method: 'POST', body: formData });
    await loadSessions();
  });

  const archiveBtn = document.createElement('button');
  archiveBtn.textContent = 'Archive';
  archiveBtn.addEventListener('click', async function(e) {
    e.stopPropagation();
    if (confirm('Archive this session? It will be hidden from the list.')) {
      dropdown.remove();
      const formData = new FormData();
      formData.append('action', 'archive_session');
      formData.append('session_id', sessionId);
      await fetch(`${API_BASE}/history.php`, { method: 'POST', body: formData });
      if (currentSessionId == sessionId) {
        currentSessionId = null;
        clearChat();
        showWelcome();
      }
      await loadSessions();
    }
  });

  const moveBtn = document.createElement('button');
  moveBtn.textContent = currentProjectId ? 'Remove from project' : 'Move to project';
  moveBtn.addEventListener('click', async function(e) {
    e.stopPropagation();
    dropdown.remove();
    if (currentProjectId) {
      const formData = new FormData();
      formData.append('action', 'assign_project');
      formData.append('session_id', sessionId);
      formData.append('project_id', 'null');
      await fetch(`${API_BASE}/history.php`, { method: 'POST', body: formData });
      await loadSessions();
    } else {
      // Show inline project picker dropdown
      document.querySelectorAll('.project-picker').forEach(function(p) { p.remove(); });
      var pRes  = await fetch(`${API_BASE}/history.php?action=projects`);
      var pData = await pRes.json();
      var picker = document.createElement('div');
      picker.className = 'project-picker session-dropdown';
      picker.style.cssText = 'position:fixed;z-index:300;min-width:150px;max-width:200px;background:var(--white);border:1.5px solid var(--border);border-radius:var(--radius-sm);box-shadow:var(--shadow-md);overflow:hidden;';
      // + New project at top
      var newOpt = document.createElement('button');
      newOpt.textContent = '+ New project';
      newOpt.style.color = 'var(--primary)';
      newOpt.addEventListener('click', async function() {
        picker.remove();
        var name = prompt('Project name:');
        if (name && name.trim()) {
          var fd = new FormData();
          fd.append('action', 'create_project');
          fd.append('name', name.trim());
          var r = await fetch(`${API_BASE}/history.php`, { method: 'POST', body: fd });
          var d = await r.json();
          if (d.success) {
            var fd2 = new FormData();
            fd2.append('action', 'assign_project');
            fd2.append('session_id', sessionId);
            fd2.append('project_id', d.project_id);
            await fetch(`${API_BASE}/history.php`, { method: 'POST', body: fd2 });
            await loadSessions();
          }
        }
      });
      picker.appendChild(newOpt);
      if (pData.projects && pData.projects.length > 0) {
        pData.projects.forEach(function(p) {
          var opt = document.createElement('button');
          opt.textContent = '\uD83D\uDCC1 ' + p.name;
          opt.addEventListener('click', async function() {
            picker.remove();
            var fd = new FormData();
            fd.append('action', 'assign_project');
            fd.append('session_id', sessionId);
            fd.append('project_id', p.id);
            await fetch(`${API_BASE}/history.php`, { method: 'POST', body: fd });
            await loadSessions();
          });
          picker.appendChild(opt);
        });
      }
      document.body.appendChild(picker);
      var rect = el.getBoundingClientRect();
      picker.style.top = rect.top + 'px';
      picker.style.left = (rect.right + 4) + 'px';
      setTimeout(function() {
        document.addEventListener('click', function() { picker.remove(); }, { once: true });
      }, 0);
    }
  });

  dropdown.appendChild(pinBtn);
  dropdown.appendChild(renameBtn);
  dropdown.appendChild(moveBtn);
  dropdown.appendChild(copyBtn);
  dropdown.appendChild(exportBtn);
  dropdown.appendChild(archiveBtn);
  dropdown.appendChild(deleteBtn);

  document.body.appendChild(dropdown);
  var rect = el.getBoundingClientRect();
  var dropH = dropdown.offsetHeight || 220;
  var topPos = rect.top;
  if (topPos + dropH > window.innerHeight - 8) topPos = rect.bottom - dropH;
  if (topPos < 8) topPos = 8;
  dropdown.style.cssText = 'position:fixed;top:' + topPos + 'px;left:' + (rect.right + 4) + 'px;z-index:300;min-width:150px;max-width:180px;background:var(--white);border:1.5px solid var(--border);border-radius:var(--radius-sm);box-shadow:var(--shadow-md);overflow:hidden;';

  setTimeout(function() {
    document.addEventListener('click', function() { dropdown.remove(); }, { once: true });
  }, 0);
}

async function loadSessionMessages(sessionId, el) {
  currentSessionId = sessionId;
  document.querySelectorAll('.session-item').forEach(i => i.classList.remove('active'));
  el.classList.add('active');
  clearQuickReplies();
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

function clearQuickReplies() {
  if (quickRepliesContainer) quickRepliesContainer.innerHTML = '';
  document.querySelectorAll('.quick-replies-inline').forEach(function(el) { el.remove(); });
  document.querySelectorAll('.other-toast').forEach(t => t.remove());
  userInput.style.borderColor = '';
  userInput.style.boxShadow   = '';
  userInput.placeholder       = 'Type your message here...';
}

function showWelcome() {
  chatBox.innerHTML = `
    <div class="welcome-msg">
      <h2>Hello, I am SmartSpend</h2>
      <p>I will guide you step by step to find out if you can afford something.</p>
      <p>Type anything to get started - I will ask you the right questions.</p>
      <p class="example">Try saying: "Hi" or "Can I afford a new car?"</p>
    </div>
  `;
}

// ── PDF Export via print ─────────────────────────────────
function exportResultPDF(calc) {
  var risk      = calc.risk_level;
  var riskLabel = risk === 'green' ? 'LOW RISK' : risk === 'yellow' ? 'MODERATE RISK' : 'HIGH RISK';
  var riskColour= risk === 'green' ? '#27AE60' : risk === 'yellow' ? '#F39C12' : '#E74C3C';
  var months    = parseInt(calc.months_to_save);
  var timeStr   = isNaN(months) || months === 0 ? 'Already affordable'
    : months > 12
      ? Math.floor(months/12) + ' year' + (Math.floor(months/12)>1?'s':'') + (months%12>0?' and '+months%12+' months':'')
      : months + ' month' + (months>1?'s':'');

  var snapshot = '';
  if (lastBudgetSnapshot) {
    var surplus = parseFloat(lastBudgetSnapshot.income) - parseFloat(lastBudgetSnapshot.expenses);
    snapshot = '<div class="section"><h2>Your Budget Snapshot</h2>' +
      '<table><tr><td>Monthly Income</td><td><strong>£' + parseFloat(lastBudgetSnapshot.income).toFixed(2) + '</strong></td></tr>' +
      '<tr><td>Monthly Expenses</td><td><strong>£' + parseFloat(lastBudgetSnapshot.expenses).toFixed(2) + '</strong></td></tr>' +
      '<tr><td>Current Savings</td><td><strong>£' + parseFloat(lastBudgetSnapshot.savings).toFixed(2) + '</strong></td></tr>' +
      '<tr><td>Monthly Surplus</td><td><strong>£' + surplus.toFixed(2) + '</strong></td></tr>' +
      '</table></div>';
  }

  var win = window.open('', '_blank');
  win.document.write(`<!DOCTYPE html><html><head><title>SmartSpend Report</title>
  <style>
    body { font-family: 'Helvetica', sans-serif; color: #2C3E50; margin: 0; padding: 0; }
    .header { background: #00B4A6; color: white; padding: 20px 30px; }
    .header h1 { margin: 0; font-size: 24px; }
    .header p { margin: 4px 0 0; font-size: 12px; opacity: 0.85; }
    .disclaimer { background: #E0F2F1; color: #00B4A6; font-size: 11px; padding: 8px 30px; }
    .content { padding: 24px 30px; }
    .section { margin-bottom: 24px; }
    h2 { font-size: 14px; color: #2C3E50; border-bottom: 1px solid #E0F2F1; padding-bottom: 6px; margin-bottom: 12px; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    td { padding: 6px 4px; border-bottom: 1px solid #f0f0f0; }
    td:last-child { text-align: right; }
    .badge { display: inline-block; padding: 4px 14px; border-radius: 20px; font-size: 12px; font-weight: bold; color: white; background: ${riskColour}; margin-bottom: 12px; }
    .footer { margin-top: 40px; padding-top: 12px; border-top: 1px solid #E0F2F1; font-size: 10px; color: #7F8C8D; }
    .date { float: right; font-size: 11px; opacity: 0.7; }
    @media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
  </style></head><body>
  <div class="header">
    <span class="date">${new Date().toLocaleDateString('en-GB')}</span>
    <h1>SmartSpend</h1>
    <p>Budget Assessment Report</p>
  </div>
  <div class="disclaimer">Not a financial adviser - for educational purposes only.</div>
  <div class="content">
    ${snapshot}
    <div class="section">
      <h2>Assessment Result</h2>
      <div class="badge">${riskLabel}</div>
      <table>
        <tr><td>Item</td><td><strong>${calc.item_name}</strong></td></tr>
        <tr><td>Price</td><td><strong>£${Number(calc.item_price).toFixed(2)}</strong></td></tr>
        <tr><td>Type</td><td>${calc.item_type}</td></tr>
        <tr><td>Monthly Surplus</td><td>£${Number(calc.surplus).toFixed(2)}</td></tr>
        <tr><td>Time to Save</td><td>${timeStr}</td></tr>
        <tr><td>Health Score</td><td>${calc.health_score}/100</td></tr>
      </table>
    </div>
    <div class="footer">SmartSpend - Not a financial adviser. For educational purposes only.</div>
  </div>
  <script>window.onload = function() { window.print(); }</script>
  </body></html>`);
  win.document.close();
}

function addMessage(role, content, calculation = null) {
  const welcome = chatBox.querySelector('.welcome-msg');
  if (welcome) welcome.remove();
  if (calculation && calculation.risk_level === 'green') launchConfetti();

  const wrap     = document.createElement('div');
  wrap.className = `message ${role}`;

  const bubble     = document.createElement('div');
  bubble.className = 'bubble';

  if (role === 'bot') {
    bubble.innerHTML = content.replace(/\n/g, '<br>');
  } else {
    bubble.textContent = content;
  }

  if (calculation) {
    bubble.appendChild(buildResultCard(calculation));

    // Store budget snapshot for PDF
    if (calculation.surplus !== undefined) {
      lastBudgetSnapshot = {
        income:   calculation.surplus + (lastBudgetSnapshot ? lastBudgetSnapshot.expenses : 0),
        expenses: lastBudgetSnapshot ? lastBudgetSnapshot.expenses : 0,
        savings:  lastBudgetSnapshot ? lastBudgetSnapshot.savings : 0,
      };
    }

    // Export PDF button
    const exportBtn = document.createElement('button');
    exportBtn.className   = 'btn-secondary';
    exportBtn.textContent = '⬇ Export PDF';
    exportBtn.style.marginTop  = '10px';
    exportBtn.style.fontSize   = '12px';
    exportBtn.style.padding    = '6px 14px';
    exportBtn.style.display    = 'block';
    exportBtn.addEventListener('click', function() { exportResultPDF(calculation); });
    bubble.appendChild(exportBtn);
  }

  wrap.appendChild(bubble);
  chatBox.appendChild(wrap);
  chatBox.scrollTop = chatBox.scrollHeight;
}

function launchConfetti() {
  var colours = ['#00B4A6','#27AE60','#F39C12','#E0F2F1','#ffffff'];
  for (var i = 0; i < 80; i++) {
    var el = document.createElement('div');
    el.style.cssText = 'position:fixed;top:-10px;width:8px;height:8px;border-radius:2px;opacity:1;z-index:9999;pointer-events:none;';
    el.style.background = colours[Math.floor(Math.random() * colours.length)];
    el.style.left = Math.random() * 100 + 'vw';
    el.style.transform = 'rotate(' + Math.random() * 360 + 'deg)';
    document.body.appendChild(el);
    var duration = 1500 + Math.random() * 1000;
    var drift = (Math.random() - 0.5) * 200;
    el.animate([
      { top: '-10px', opacity: 1, transform: 'rotate(0deg) translateX(0)' },
      { top: '110vh', opacity: 0, transform: 'rotate(' + (Math.random()*720) + 'deg) translateX(' + drift + 'px)' }
    ], { duration: duration, easing: 'ease-in', fill: 'forwards' }).onfinish = function() { el.remove(); };
  }
}

function buildComparisonCard(calc1, calc2) {
  var wrap = document.createElement('div');
  wrap.style.cssText = 'display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px;';

  [calc1, calc2].forEach(function(c, idx) {
    var col = document.createElement('div');
    col.style.cssText = 'border-radius:var(--radius-sm);padding:14px;border:1.5px solid ' +
      (c.risk_level==='green' ? 'var(--risk-green);background:#F0FBF4;' :
       c.risk_level==='yellow' ? 'var(--risk-amber);background:#FEF9EE;' :
       'var(--risk-red);background:#FEF0EE;');

    var badge = document.createElement('div');
    badge.style.cssText = 'display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;margin-bottom:8px;' +
      (c.risk_level==='green' ? 'background:#D4EFDF;color:var(--risk-green);' :
       c.risk_level==='yellow' ? 'background:#FDEBD0;color:var(--risk-amber);' :
       'background:#FADBD8;color:var(--risk-red);');
    badge.textContent = c.risk_level==='green' ? 'LOW RISK' : c.risk_level==='yellow' ? 'MODERATE RISK' : 'HIGH RISK';

    var name = document.createElement('div');
    name.style.cssText = 'font-weight:600;font-size:13px;margin-bottom:6px;';
    name.textContent = c.item_name;

    var rows = [
      ['Price', '£' + Number(c.item_price).toFixed(2)],
      ['Surplus', '£' + Number(c.surplus).toFixed(2) + '/mo'],
      ['Time to save', c.months_to_save === 0 ? 'Already there' : c.months_to_save + ' months'],
      ['Health score', c.health_score + '/100'],
    ];

    var table = document.createElement('div');
    rows.forEach(function(r) {
      var row = document.createElement('div');
      row.style.cssText = 'display:flex;justify-content:space-between;font-size:12px;padding:4px 0;border-bottom:1px solid rgba(0,0,0,0.06);';
      row.innerHTML = '<span style="color:var(--text-muted);">' + r[0] + '</span><span style="font-weight:500;">' + r[1] + '</span>';
      table.appendChild(row);
    });

    col.appendChild(badge);
    col.appendChild(name);
    col.appendChild(table);
    wrap.appendChild(col);
  });

  // Winner banner
  var banner = document.createElement('div');
  banner.style.cssText = 'grid-column:1/-1;text-align:center;font-size:13px;font-weight:600;padding:8px;background:var(--bg);border-radius:var(--radius-sm);border:1.5px solid var(--border);';
  var riskOrder = {green:0, yellow:1, red:2};
  if (riskOrder[calc1.risk_level] < riskOrder[calc2.risk_level]) {
    banner.textContent = '✓ ' + calc1.item_name + ' is the better financial choice';
    banner.style.color = 'var(--risk-green)';
  } else if (riskOrder[calc2.risk_level] < riskOrder[calc1.risk_level]) {
    banner.textContent = '✓ ' + calc2.item_name + ' is the better financial choice';
    banner.style.color = 'var(--risk-green)';
  } else if (calc1.months_to_save <= calc2.months_to_save) {
    banner.textContent = '✓ ' + calc1.item_name + ' is achievable sooner';
    banner.style.color = 'var(--primary)';
  } else {
    banner.textContent = '✓ ' + calc2.item_name + ' is achievable sooner';
    banner.style.color = 'var(--primary)';
  }
  wrap.appendChild(banner);
  return wrap;
}

function buildResultCard(calc) {
  const risk     = calc.risk_level;
  const card     = document.createElement('div');
  card.className = `result-card ${risk}`;

  const badge     = document.createElement('div');
  badge.className = `risk-badge ${risk}`;
  badge.textContent = risk === 'green' ? 'LOW RISK'
                    : risk === 'yellow' ? 'MODERATE RISK'
                    : 'HIGH RISK';
  card.appendChild(badge);

  const mainRows = [
    { label: 'Item',               value: calc.item_name,                                                                cls: '' },
    { label: 'Price',              value: '\u00A3' + Number(calc.item_price).toFixed(2),                                 cls: '' },
    { label: 'Type',               value: calc.item_type,                                                                cls: '' },
    { label: 'Monthly surplus',    value: '\u00A3' + Number(calc.surplus).toFixed(2),                                    cls: calc.surplus >= 0 ? 'positive' : 'negative' },
    { label: 'Surplus after item', value: '\u00A3' + Number(calc.surplus_after).toFixed(2),                              cls: calc.surplus_after >= 0 ? 'positive' : 'negative' },
    { label: 'Months to save',     value: calc.months_to_save === 0 ? 'Already there' : calc.months_to_save + ' months', cls: 'warning' },
    { label: 'Health score',       value: calc.health_score + '/100',                                                    cls: 'positive' },
  ];

  mainRows.forEach(row => {
    const r     = document.createElement('div');
    r.className = 'result-row';
    r.innerHTML = '<span class="result-label">' + row.label + '</span><span class="result-value ' + row.cls + '">' + row.value + '</span>';
    card.appendChild(r);
  });

  if (calc.projections && Object.keys(calc.projections).length > 0) {
    if (calc.projections.summary) {
      const r     = document.createElement('div');
      r.className = 'result-row';
      r.innerHTML = '<span class="result-label">Time to save</span><span class="result-value warning">' + calc.projections.summary + '</span>';
      card.appendChild(r);
    } else {
      Object.entries(calc.projections).forEach(function(entry) {
        var key   = entry[0];
        var value = entry[1];
        if (value === undefined || value === null) return;
        var monthNum = key.replace('month_', '');
        var r        = document.createElement('div');
        r.className  = 'result-row';
        r.innerHTML  = '<span class="result-label">Month ' + monthNum + ' savings</span><span class="result-value">\u00A3' + value + '</span>';
        card.appendChild(r);
      });
    }
  }

  return card;
}

function showTyping() {
  const typing     = document.createElement('div');
  typing.className = 'message bot';
  typing.id        = 'typing-indicator';
  typing.innerHTML = '<div class="bubble" style="color:#7F8C8D;font-style:italic">SmartSpend is thinking...</div>';
  chatBox.appendChild(typing);
  chatBox.scrollTop = chatBox.scrollHeight;
}

function removeTyping() {
  var t = document.getElementById('typing-indicator');
  if (t) t.remove();
}

function renderQuickReplies(replies) {
  clearQuickReplies();
  if (!replies || replies.length === 0) return;

  // Find last bot bubble and append replies inside it
  var botMessages = chatBox.querySelectorAll('.message.bot');
  var lastBotBubble = botMessages.length > 0 ? botMessages[botMessages.length - 1].querySelector('.bubble') : null;

  var container = document.createElement('div');
  container.className = 'quick-replies-inline';
  container.style.display = 'flex';
  container.style.flexWrap = 'wrap';
  container.style.gap = '8px';
  container.style.marginTop = '10px';

  replies.forEach(function(reply) {
    var btn = document.createElement('button');
    btn.className = 'quick-reply-btn';
    btn.textContent = reply;

    if (reply === 'Other') {
      btn.addEventListener('click', function() {
        clearQuickReplies();
        var toast = document.createElement('div');
        toast.className = 'other-toast';
        toast.textContent = 'Type your answer in the box below';
        toast.style.position = 'fixed';
        toast.style.bottom = '110px';
        toast.style.left = '50%';
        toast.style.transform = 'translateX(-50%)';
        toast.style.background = '#2C3E50';
        toast.style.color = '#fff';
        toast.style.padding = '8px 18px';
        toast.style.borderRadius = '20px';
        toast.style.fontSize = '13px';
        toast.style.fontFamily = 'Poppins, sans-serif';
        toast.style.zIndex = '999';
        toast.style.opacity = '1';
        toast.style.transition = 'opacity 0.4s';
        toast.style.pointerEvents = 'none';
        document.body.appendChild(toast);
        userInput.placeholder = 'Type your answer here...';
        userInput.style.borderColor = '#00B4A6';
        userInput.style.boxShadow = '0 0 0 5px rgba(0, 180, 166, 0.5)';
        userInput.focus();
        userInput.addEventListener('input', function() {
          toast.style.opacity = '0';
          setTimeout(function() { if (toast.parentNode) toast.remove(); }, 400);
          userInput.style.borderColor = '';
          userInput.style.boxShadow = '';
        }, { once: true });
        setTimeout(function() {
          toast.style.opacity = '0';
          setTimeout(function() { if (toast.parentNode) toast.remove(); }, 400);
        }, 3000);
      });
    } else {
      btn.addEventListener('click', function() {
        clearQuickReplies();
        userInput.value = reply;
        sendMessage();
      });
    }
    container.appendChild(btn);
  });

  var lastBotMessage = botMessages.length > 0 ? botMessages[botMessages.length - 1] : null;
  if (lastBotMessage) {
    lastBotMessage.parentNode.insertBefore(container, lastBotMessage.nextSibling);
  } else {
    quickRepliesContainer.appendChild(container);
  }
  chatBox.scrollTop = chatBox.scrollHeight;
}

async function sendMessage() {
  var text = userInput.value.trim();
  if (!text) return;

  if (!currentSessionId) {
    await createSession();
  }

  addMessage('user', text);
  userInput.value  = '';
  sendBtn.disabled = true;
  clearQuickReplies();
  showTyping();

  try {
    var formData = new FormData();
    formData.append('message',    text);
    formData.append('session_id', currentSessionId);

    var res  = await fetch(API_BASE + '/message.php', { method: 'POST', body: formData });
    var data = await res.json();

    removeTyping();

    if (data.success) {
      // Update budget snapshot if calculation returned
      if (data.calculation) {
        fetch(API_BASE + '/history.php?action=last_budget').then(r => r.json()).then(function(bd) {
          if (bd.success && bd.budget) lastBudgetSnapshot = bd.budget;
        });
      }
      if (data.calculation && data.comparison_calc) {
        // Show comparison only - text + side by side cards
        addMessage('bot', data.bot_reply, null);
        var compWrap = chatBox.querySelector('.message.bot:last-child .bubble');
        if (compWrap) compWrap.appendChild(buildComparisonCard(data.comparison_calc, data.calculation));
      } else {
        addMessage('bot', data.bot_reply, data.calculation || null);
      }
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
    var formData = new FormData();
    formData.append('action', 'logout');
    await fetch(API_BASE + '/auth.php', { method: 'POST', body: formData });
  } finally {
    window.location.href = 'login.html';
  }
}

// Keyboard navigation - Escape closes dropdowns
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    document.querySelectorAll('.session-dropdown').forEach(function(d) { d.remove(); });
  }
});

// Dark mode toggle
var darkModeBtn = document.getElementById('dark-mode-btn');
if (darkModeBtn) {
  if (localStorage.getItem('darkMode') === 'true') {
    document.body.classList.add('dark-mode');
    darkModeBtn.textContent = '☀️';
  }
  darkModeBtn.addEventListener('click', function() {
    var isDark = document.body.classList.toggle('dark-mode');
    darkModeBtn.textContent = isDark ? '☀️' : '🌙';
    localStorage.setItem('darkMode', isDark);
  });
}

sendBtn.addEventListener('click', sendMessage);

userInput.addEventListener('keydown', function(e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    sendMessage();
  }
});

newSessionBtn.addEventListener('click', function() {
  currentSessionId = null;
  clearChat();
  clearQuickReplies();
  showWelcome();
  document.querySelectorAll('.session-item').forEach(function(i) { i.classList.remove('active'); });
});



// Search - simple title filter
var searchInput = document.getElementById('session-search');
if (searchInput) {
  searchInput.addEventListener('input', function() {
    var q = searchInput.value.toLowerCase().trim();
    document.querySelectorAll('.session-item').forEach(function(item) {
      item.style.display = (!q || item.textContent.toLowerCase().includes(q)) ? '' : 'none';
    });
    document.querySelectorAll('.project-folder').forEach(function(folder) {
      folder.style.display = (!q || folder.textContent.toLowerCase().includes(q)) ? '' : 'none';
    });
  });
}

if (logoutBtn) {
  logoutBtn.addEventListener('click', handleLogout);
}

(async function() {
  await checkAuth();
  await loadSessions();

  var res  = await fetch(API_BASE + '/history.php?action=sessions');
  var data = await res.json();

  if (!data.success || data.sessions.length === 0) {
    await createSession();
  } else {
    currentSessionId = data.sessions[0].id;
    var firstItem    = sessionList.querySelector('.session-item');
    if (firstItem) {
      firstItem.classList.add('active');
      await loadSessionMessages(data.sessions[0].id, firstItem);
    }
  }
})();