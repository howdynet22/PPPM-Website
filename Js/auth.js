(function () {
  const API = 'api.php?action=';
  const allowed = (document.currentScript?.dataset.roles || '').split(',').map(s => s.trim()).filter(Boolean);
  let currentUser = null;

  async function request(action, options = {}) {
    const response = await fetch(API + action, {
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/json', ...(options.headers || {})},
      ...options
    });
    const result = await response.json().catch(() => ({ok:false,error:'Invalid server response'}));
    if (!result.ok) throw new Error(result.error || 'Request failed');
    return result;
  }

  function esc(value) {
    return String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  }

  function initials(name) {
    return String(name || 'U').split(/\s+/).slice(0,2).map(x => x[0]).join('').toUpperCase();
  }

  function showToast(message, error = false) {
    let el = document.getElementById('authToast');
    if (!el) {
      el = document.createElement('div');
      el.id = 'authToast';
      el.style.cssText = 'position:fixed;right:22px;bottom:22px;z-index:9999;padding:12px 16px;border-radius:8px;background:#111827;color:white;font:13px Arial;box-shadow:0 12px 30px rgba(0,0,0,.18)';
      document.body.appendChild(el);
    }
    el.textContent = message;
    el.style.background = error ? '#991b1b' : '#111827';
    clearTimeout(el._timer);
    el._timer = setTimeout(() => el.remove(), 3500);
  }

  function injectStyles() {
    if (document.getElementById('authStyles')) return;
    const style = document.createElement('style');
    style.id = 'authStyles';
    style.textContent = `
      .auth-controls{display:flex;gap:8px;align-items:center;position:fixed;right:18px;top:16px;z-index:40}
      .auth-btn{border:1px solid #d1d5db;background:#fff;color:#111827;padding:8px 11px;border-radius:7px;cursor:pointer;font:12px Arial}
      .auth-btn:hover{background:#f8fafc}.auth-btn.danger{color:#b91c1c}
      .auth-modal{display:none;position:fixed;inset:0;background:rgba(15,23,42,.48);z-index:10000;align-items:center;justify-content:center;padding:20px}
      .auth-modal.open{display:flex}.auth-box{background:#fff;width:min(430px,100%);border-radius:12px;box-shadow:0 20px 70px rgba(0,0,0,.25);overflow:hidden}
      .auth-head{padding:17px 20px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center}
      .auth-head h3{margin:0;font:700 17px Arial}.auth-close{border:0;background:transparent;font-size:22px;color:#6b7280;cursor:pointer}
      .auth-body{padding:20px}.auth-field{margin-bottom:14px}.auth-field label{display:block;font:700 12px Arial;margin-bottom:6px;color:#374151}
      .auth-field input{width:100%;box-sizing:border-box;border:1px solid #d1d5db;border-radius:7px;padding:10px;font:14px Arial}
      .auth-message{font:12px Arial;margin-top:10px;min-height:16px}.auth-message.error{color:#b91c1c}.auth-message.ok{color:#166534}
      .auth-foot{padding:14px 20px;border-top:1px solid #e5e7eb;display:flex;justify-content:flex-end;gap:8px}
      .auth-primary{border:1px solid #111827;background:#111827;color:white;padding:9px 13px;border-radius:7px;cursor:pointer;font:13px Arial}
      .auth-secondary{border:1px solid #d1d5db;background:#fff;color:#111827;padding:9px 13px;border-radius:7px;cursor:pointer;font:13px Arial}
    `;
    document.head.appendChild(style);
  }

  function buildPasswordModal() {
    if (document.getElementById('changePasswordModal')) return;
    const modal = document.createElement('div');
    modal.id = 'changePasswordModal';
    modal.className = 'auth-modal';
    modal.innerHTML = `
      <div class="auth-box" role="dialog" aria-modal="true" aria-labelledby="changePasswordTitle">
        <div class="auth-head"><h3 id="changePasswordTitle">Change password</h3><button class="auth-close" id="closePasswordModal" aria-label="Close">&times;</button></div>
        <form id="changePasswordForm">
          <div class="auth-body">
            <div class="auth-field"><label for="currentPassword">Current password</label><input id="currentPassword" type="password" autocomplete="current-password" required></div>
            <div class="auth-field"><label for="newPassword">New password</label><input id="newPassword" type="password" autocomplete="new-password" minlength="8" required><small style="display:block;margin-top:5px;color:#6b7280;font:11px Arial">Minimum 8 characters.</small></div>
            <div class="auth-field"><label for="confirmPassword">Confirm new password</label><input id="confirmPassword" type="password" autocomplete="new-password" minlength="8" required></div>
            <div id="passwordMessage" class="auth-message"></div>
          </div>
          <div class="auth-foot"><button type="button" class="auth-secondary" id="cancelPassword">Cancel</button><button type="submit" class="auth-primary">Change password</button></div>
        </form>
      </div>`;
    document.body.appendChild(modal);
    const close = () => { modal.classList.remove('open'); document.getElementById('changePasswordForm').reset(); document.getElementById('passwordMessage').textContent=''; };
    document.getElementById('closePasswordModal').onclick = close;
    document.getElementById('cancelPassword').onclick = close;
    modal.addEventListener('click', e => { if (e.target === modal) close(); });
    document.getElementById('changePasswordForm').addEventListener('submit', async e => {
      e.preventDefault();
      const msg = document.getElementById('passwordMessage');
      msg.className = 'auth-message'; msg.textContent = 'Changing password...';
      try {
        const result = await request('change_password', {method:'POST', body:JSON.stringify({
          currentPassword: document.getElementById('currentPassword').value,
          newPassword: document.getElementById('newPassword').value,
          confirmPassword: document.getElementById('confirmPassword').value
        })});
        msg.className = 'auth-message ok'; msg.textContent = result.message || 'Password changed successfully.';
        document.getElementById('changePasswordForm').reset();
        showToast('Password changed successfully.');
        setTimeout(close, 900);
      } catch (err) { msg.className = 'auth-message error'; msg.textContent = err.message; }
    });
  }

  function openPasswordModal() {
    buildPasswordModal();
    document.getElementById('changePasswordModal').classList.add('open');
    setTimeout(() => document.getElementById('currentPassword')?.focus(), 50);
  }

  async function logout() {
    try { await request('logout', {method:'POST', body:'{}'}); }
    catch (_) {}
    window.location.href = 'index.html';
  }

  function addControls() {
    buildPasswordModal();
    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
      logoutBtn.onclick = logout;
      if (!document.getElementById('changePasswordBtn')) {
        const b = document.createElement('button');
        b.id = 'changePasswordBtn'; b.className = logoutBtn.className || 'nav-btn'; b.textContent = 'Change password'; b.type='button';
        b.onclick = openPasswordModal;
        logoutBtn.parentNode.insertBefore(b, logoutBtn);
      }
    } else if (!document.getElementById('authControls')) {
      const wrap = document.createElement('div'); wrap.id='authControls'; wrap.className='auth-controls';
      wrap.innerHTML='<button class="auth-btn" id="changePasswordBtn" type="button">Change password</button><button class="auth-btn danger" id="authLogoutBtn" type="button">Sign out</button>';
      document.body.appendChild(wrap);
      document.getElementById('changePasswordBtn').onclick=openPasswordModal;
      document.getElementById('authLogoutBtn').onclick=logout;
    }
  }

  function applyUser(user) {
    currentUser = user;
    document.querySelectorAll('[data-user-name]').forEach(el => el.textContent = user.full_name || '');
    document.querySelectorAll('[data-user-role]').forEach(el => el.textContent = user.role_name || user.role || '');
    document.querySelectorAll('[data-user-email]').forEach(el => el.textContent = user.email || '');
    document.querySelectorAll('[data-user-initials]').forEach(el => el.textContent = initials(user.full_name));
    const permissions = new Set(user.permissions || []);
    document.querySelectorAll('[data-permission]').forEach(el => {
      const required = String(el.dataset.permission || '').split(',').map(s=>s.trim()).filter(Boolean);
      if (required.length && !required.some(p => permissions.has(p))) el.style.display = 'none';
    });
  }

  async function init() {
    injectStyles();
    try {
      const result = await request('me');
      const user = result.user;
      if (allowed.length && !allowed.includes(user.role)) {
        window.location.href = user.dashboard_path || 'index.html';
        return;
      }
      applyUser(user);
      addControls();
      document.documentElement.dataset.authenticated = 'true';
      window.currentAuthUser = user;
      window.openChangePassword = openPasswordModal;
      window.logout = logout;
    } catch (err) {
      window.location.href = 'index.html';
    }
  }

  window.addEventListener('DOMContentLoaded', init);
})();
