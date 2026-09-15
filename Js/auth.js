(function () {
  // Authentication settings for the current dashboard page.
  const API = "api.php?action=";
  const workspace = document.currentScript?.dataset.workspace || ({
    'employee-dashboard.html':'employee', 'manager-dashboard.html':'manager',
    'hr-dashboard.html':'hr', 'admin-dashboard.html':'executive',
  }[location.pathname.split('/').pop()] || '');
  const allowed = (document.currentScript?.dataset.roles || "")
    .split(",")
    .map((s) => s.trim())
    .filter(Boolean);
  let currentUser = null;

  // Send an authenticated request and attach CSRF protection to write actions.
  async function request(action, options = {}) {
    const method = String(options.method || "GET").toUpperCase();
    const headers = {
      "Content-Type": "application/json",
      ...(options.headers || {}),
    };
    if (method !== "GET" && window.currentCsrfToken)
      headers["X-CSRF-Token"] = window.currentCsrfToken;
    const response = await fetch(API + action, {
      credentials: "same-origin",
      ...options,
      headers,
    });
    const result = await response
      .json()
      .catch(() => ({ ok: false, error: "Invalid server response" }));
    if (!result.ok) throw new Error(result.error || "Request failed");
    if (result.csrfToken) window.currentCsrfToken = result.csrfToken;
    return result;
  }

  // Create short initials for the signed-in user's avatar.
  function initials(name) {
    return String(name || "U")
      .split(/\s+/)
      .slice(0, 2)
      .map((x) => x[0])
      .join("")
      .toUpperCase();
  }

  // Show a temporary success or error message.
  function showToast(message, isError = false) {
    let toast = document.getElementById("authToast");

    if (!toast) {
      toast = document.createElement("div");
      toast.id = "authToast";
      toast.className = "auth-toast";
      document.body.appendChild(toast);
    }

    toast.textContent = message;
    toast.classList.toggle("error", isError);
    clearTimeout(toast.removeTimer);
    toast.removeTimer = setTimeout(() => toast.remove(), 3500);
  }

  // Build the change-password dialog when a dashboard first needs it.
  function buildPasswordModal() {
    if (document.getElementById("changePasswordModal")) return;
    const modal = document.createElement("div");
    modal.id = "changePasswordModal";
    modal.className = "auth-modal";
    modal.innerHTML = `
      <div
        class="auth-box"
        role="dialog"
        aria-modal="true"
        aria-labelledby="changePasswordTitle"
      >
        <div class="auth-head">
          <h3 id="changePasswordTitle">Change password</h3>
          <button
            class="auth-close"
            id="closePasswordModal"
            aria-label="Close"
          >
            &times;
          </button>
        </div>
        <form id="changePasswordForm">
          <div class="auth-body">
            <div class="auth-field">
              <label for="currentPassword">Current password</label>
              <input
                id="currentPassword"
                type="password"
                autocomplete="current-password"
                required
              >
            </div>
            <div class="auth-field">
              <label for="newPassword">New password</label>
              <input
                id="newPassword"
                type="password"
                autocomplete="new-password"
                minlength="10"
                required
              >
              <small class="auth-hint">
                At least 10 characters with uppercase, lowercase and a number.
              </small>
            </div>
            <div class="auth-field">
              <label for="confirmPassword">Confirm new password</label>
              <input
                id="confirmPassword"
                type="password"
                autocomplete="new-password"
                minlength="10"
                required
              >
            </div>
            <div id="passwordMessage" class="auth-message"></div>
          </div>
          <div class="auth-foot">
            <button type="button" class="auth-secondary" id="cancelPassword">
              Cancel
            </button>
            <button type="submit" class="auth-primary">Change password</button>
          </div>
        </form>
      </div>`;
    document.body.appendChild(modal);
    const close = () => {
      modal.classList.remove("open");
      document.getElementById("changePasswordForm").reset();
      document.getElementById("passwordMessage").textContent = "";
    };
    document.getElementById("closePasswordModal").onclick = close;
    document.getElementById("cancelPassword").onclick = close;
    modal.addEventListener("click", (e) => {
      if (e.target === modal) close();
    });
    modal.addEventListener("keydown", (e) => {
      if (e.key === "Escape") close();
    });
    document
      .getElementById("changePasswordForm")
      .addEventListener("submit", async (e) => {
        e.preventDefault();
        const msg = document.getElementById("passwordMessage");
        msg.className = "auth-message";
        msg.textContent = "Changing password...";
        try {
          const result = await request("change_password", {
            method: "POST",
            body: JSON.stringify({
              currentPassword: document.getElementById("currentPassword").value,
              newPassword: document.getElementById("newPassword").value,
              confirmPassword: document.getElementById("confirmPassword").value,
            }),
          });
          msg.className = "auth-message ok";
          msg.textContent = result.message || "Password changed successfully.";
          document.getElementById("changePasswordForm").reset();
          showToast("Password changed successfully.");
          setTimeout(close, 900);
        } catch (err) {
          msg.className = "auth-message error";
          msg.textContent = err.message;
        }
      });
  }

  // Open and focus the password dialog.
  function openPasswordModal() {
    buildPasswordModal();
    document.getElementById("changePasswordModal").classList.add("open");
    setTimeout(() => document.getElementById("currentPassword")?.focus(), 50);
  }

  // End the current session and return to the login page.
  async function logout() {
    try {
      await request("logout", { method: "POST", body: "{}" });
    } catch (_) {}
    window.location.href = "index.html";
  }

  // Add change-password and sign-out buttons to the current dashboard.
  function addControls() {
    buildPasswordModal();
    const logoutBtn = document.getElementById("logoutBtn");
    if (logoutBtn) {
      logoutBtn.onclick = logout;
      if (!document.getElementById("changePasswordBtn")) {
        const passwordButton = document.createElement("button");
        passwordButton.id = "changePasswordBtn";
        passwordButton.className = logoutBtn.className || "nav-btn";
        passwordButton.textContent = "Change password";
        passwordButton.type = "button";
        passwordButton.onclick = openPasswordModal;
        logoutBtn.parentNode.insertBefore(passwordButton, logoutBtn);
      }
    } else if (!document.getElementById("authControls")) {
      const controls = document.createElement("div");
      controls.id = "authControls";
      controls.className = "auth-controls";
      controls.innerHTML = `
        <button class="auth-btn" id="changePasswordBtn" type="button">
          Change password
        </button>
        <button class="auth-btn danger" id="authLogoutBtn" type="button">
          Sign out
        </button>`;
      const employeeShell=document.querySelector('.employee-shell');
      if(employeeShell) employeeShell.prepend(controls);
      else document.body.appendChild(controls);
      document.getElementById("changePasswordBtn").onclick = openPasswordModal;
      document.getElementById("authLogoutBtn").onclick = logout;
    }
  }

  // Fill user details and hide controls that the role cannot use.
  function applyUser(user) {
    currentUser = user;
    document
      .querySelectorAll("[data-user-name]")
      .forEach((el) => (el.textContent = user.full_name || ""));
    document
      .querySelectorAll("[data-user-role]")
      .forEach((el) => (el.textContent = user.role_name || user.role || ""));
    document
      .querySelectorAll("[data-user-email]")
      .forEach((el) => (el.textContent = user.email || ""));
    document
      .querySelectorAll("[data-user-initials]")
      .forEach((el) => (el.textContent = initials(user.full_name)));
    const permissions = new Set(user.permissions || []);
    document.querySelectorAll("[data-permission]").forEach((el) => {
      const required = String(el.dataset.permission || "")
        .split(",")
        .map((s) => s.trim())
        .filter(Boolean);
      el.hidden = required.length > 0 && !required.some((p) => permissions.has(p));
    });
  }

  function addWorkspaceSwitcher(user) {
    const spaces = user.workspaces || [];
    if (!spaces.length) return;
    let host = document.getElementById('workspaceControls');
    if (!host) {
      host = document.createElement('div'); host.id = 'workspaceControls';
      const target = document.querySelector('.sidebar .brand, main .card, main');
      target?.insertAdjacentElement('afterend', host);
    }
    const label = document.createElement('label'); label.className='workspace-switcher';
    label.textContent='Workspace';
    const select = document.createElement('select'); select.setAttribute('aria-label','Workspace');
    for (const space of spaces) {
      const option=document.createElement('option'); option.value=space.key; option.textContent=space.label;
      option.selected=space.key===workspace; select.append(option);
    }
    select.onchange=()=>{
      const selected=spaces.find(s=>s.key===select.value);
      if(selected){try{localStorage.setItem('pppm.workspace.'+user.id,selected.key);}catch(_){} location.href=selected.path;}
    };
    label.append(select); host.replaceChildren(label);
    if(workspace){try{localStorage.setItem('pppm.workspace.'+user.id,workspace);}catch(_){}}
  }

  // Verify the session and initialise the page.
  async function init() {
    try {
      const result = await request("me");
      const user = result.user;
      window.currentCsrfToken =
        result.csrfToken || window.currentCsrfToken || "";
      const isOrganizationPage=location.pathname.endsWith('/org-structure.html');
      if (workspace ? !(user.workspaces || []).some(s=>s.key===workspace) : (isOrganizationPage ? !(user.permissions || []).includes('org.structure.view') : (allowed.length && !allowed.includes(user.role)))) {
        window.location.href = user.dashboard_path || "index.html";
        return;
      }
      applyUser(user);
      addControls();
      addWorkspaceSwitcher(user);
      document.documentElement.dataset.authenticated = "true";
      window.currentAuthUser = user;
      window.openChangePassword = openPasswordModal;
      window.logout = logout;
      window.applyAuthUser=(freshUser)=>{
        applyUser(freshUser);
        if(freshUser.workspaces && JSON.stringify(freshUser.workspaces)!==JSON.stringify(window.currentAuthUser?.workspaces)) addWorkspaceSwitcher(freshUser);
        window.currentAuthUser=freshUser;
      };
      window.dispatchEvent(new CustomEvent('pppm:authenticated', {detail:user}));
    } catch (err) {
      window.location.href = "index.html";
    }
  }

  window.addEventListener("DOMContentLoaded", init);
})();
