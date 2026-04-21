const API_BASE = '../tier2-backend/api';

const errorMsg     = document.getElementById('error-msg');
const loginForm    = document.getElementById('login-form');
const registerForm = document.getElementById('register-form');
const logoutBtn    = document.getElementById('logout-btn');

// ── Helper: show/hide error ───────────────────────────────
function showError(msg) {
  errorMsg.textContent = msg;
  errorMsg.classList.remove('hidden');
}

function hideError() {
  errorMsg.classList.add('hidden');
}

// ── Get CSRF token from server ────────────────────────────
async function getCSRFToken() {
  const res  = await fetch(`${API_BASE}/auth.php?action=csrf`);
  const data = await res.json();
  return data.token || '';
}

// ── Login ─────────────────────────────────────────────────
if (loginForm) {
  loginForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    hideError();

    const email    = document.getElementById('email').value.trim();
    const password = document.getElementById('password').value;
    const btn      = loginForm.querySelector('button[type="submit"]');

    if (!email || !password) {
      showError('Please fill in all fields.');
      return;
    }

    btn.disabled     = true;
    btn.textContent  = 'Logging in...';

    try {
      const token    = await getCSRFToken();
      const formData = new FormData();
      formData.append('action',     'login');
      formData.append('email',      email);
      formData.append('password',   password);
      formData.append('csrf_token', token);

      const res  = await fetch(`${API_BASE}/auth.php`, {
        method: 'POST',
        body:   formData
      });
      const data = await res.json();

      if (data.success) {
        window.location.href = 'index.html';
      } else {
        showError(data.error || 'Login failed. Please try again.');
        btn.disabled    = false;
        btn.textContent = 'Log In';
      }
    } catch (err) {
      showError('Something went wrong. Please try again.');
      btn.disabled    = false;
      btn.textContent = 'Log In';
    }
  });
}

// ── Register ──────────────────────────────────────────────
if (registerForm) {
  registerForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    hideError();

    const email           = document.getElementById('email').value.trim();
    const password        = document.getElementById('password').value;
    const confirmPassword = document.getElementById('confirm-password').value;
    const btn             = registerForm.querySelector('button[type="submit"]');

    if (!email || !password || !confirmPassword) {
      showError('Please fill in all fields.');
      return;
    }

    if (password.length < 8) {
      showError('Password must be at least 8 characters.');
      return;
    }

    if (password !== confirmPassword) {
      showError('Passwords do not match.');
      return;
    }

    btn.disabled    = true;
    btn.textContent = 'Creating account...';

    try {
      const token    = await getCSRFToken();
      const formData = new FormData();
      formData.append('action',     'register');
      formData.append('email',      email);
      formData.append('password',   password);
      formData.append('csrf_token', token);

      const res  = await fetch(`${API_BASE}/auth.php`, {
        method: 'POST',
        body:   formData
      });
      const data = await res.json();

      if (data.success) {
        window.location.href = 'index.html';
      } else {
        showError(data.error || 'Registration failed. Please try again.');
        btn.disabled    = false;
        btn.textContent = 'Create Account';
      }
    } catch (err) {
      showError('Something went wrong. Please try again.');
      btn.disabled    = false;
      btn.textContent = 'Create Account';
    }
  });
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