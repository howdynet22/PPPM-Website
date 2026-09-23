(function () {
  // Administration workspace behaviour. Sections the signed-in role cannot use
  // come back empty from the server and stay hidden here.
  let data = {
    metrics: {},
    users: [],
    roles: [],
    departments: [],
    teams: [],
    rolePermissions: null,
    audit: [],
    security: [],
  };
  let viewerId = 0;
  let viewerRole = "";
  let searchTimer = null;
  let auditTimer = null;

  const pageMeta = {
    overview: [
      "System Administrator Dashboard",
      "Accounts, access levels and the record of who did what.",
    ],
    users: [
      "User accounts",
      "Create accounts, correct details and control who can sign in.",
    ],
    roles: [
      "Roles & permissions",
      "Grant reusable permission sets rather than job titles.",
    ],
    audit: ["Audit log", "Every sensitive read and write, newest first."],
    security: [
      "Sign-in security",
      "Recent sign-in attempts grouped per email address.",
    ],
  };

  function sync(message, isError = false) {
    const host = $("#adminSync");
    if (!host) return;
    host.textContent = message;
    host.classList.toggle("error", isError);
  }

  function setText(selector, value) {
    const host = $(selector);
    if (host) host.textContent = value ?? "—";
  }

  function userFilters() {
    return new URLSearchParams({
      search: $("#userSearch")?.value || "",
      role: $("#userRole")?.value || "",
      status: $("#userStatus")?.value || "",
    }).toString();
  }

  async function load(announce = false) {
    try {
      const result = await request("admin_dashboard&" + userFilters());
      window.applyAuthUser?.(result.user);
      viewerId = Number(result.user?.id || 0);
      viewerRole = String(result.user?.role || "");
      data = {
        metrics: result.metrics || {},
        users: result.users || [],
        roles: result.roles || [],
        departments: result.departments || [],
        teams: result.teams || [],
        rolePermissions: result.rolePermissions || null,
        audit: result.audit || [],
        security: result.security || [],
      };
      fillRoleFilter();
      renderAll();
      sync(
        announce
          ? "Updated " + new Date().toLocaleTimeString()
          : "System data loaded.",
      );
    } catch (error) {
      sync(error.message, true);
      toast(error.message);
    }
  }

  function fillRoleFilter() {
    const select = $("#userRole");
    if (!select || select.options.length > 1) return;
    data.roles.forEach((role) => {
      const option = document.createElement("option");
      option.value = role.role_code;
      option.textContent = role.display_name;
      select.append(option);
    });
  }

  function renderOverview() {
    const metrics = data.metrics;
    setText("#kpiActive", metrics.activeUsers ?? "—");
    setText("#kpiActiveSub", `${metrics.users ?? 0} registered in total`);
    setText("#kpiAdmins", metrics.administrators ?? "—");
    setText("#kpiFailed", metrics.failedLogins24h ?? "—");
    setText(
      "#kpiFailedSub",
      `${metrics.successfulLogins24h ?? 0} successful sign-in(s)`,
    );
    setText("#kpiAudit", metrics.auditEvents7d ?? "—");
    setText(
      "#kpiAuditSub",
      `${metrics.deniedEvents7d ?? 0} permission denial(s)`,
    );

    const busiest = Math.max(
      1,
      ...data.roles.map((role) => Number(role.active_user_count) || 0),
    );
    $("#overviewRoles").innerHTML = data.roles.length
      ? data.roles
          .map((role) =>
            metricRow(
              role.display_name,
              ((Number(role.active_user_count) || 0) / busiest) * 100,
              String(role.active_user_count ?? 0),
            ),
          )
          .join("")
      : '<p class="muted">No roles are defined.</p>';

    const recent = data.audit.slice(0, 8);
    const auditHost = $("#overviewAudit");
    if (auditHost) {
      auditHost.innerHTML = recent.length
        ? recent
            .map(
              (row) => `<tr>
                <td>${fmtDateTime(row.created_at)}</td>
                <td>${esc(row.user_name || "System")}</td>
                <td>${statusTag(row.action)}</td>
              </tr>`,
            )
            .join("")
        : emptyRow(3, "No audit entries yet.");
    }

    $("#overviewChecks").innerHTML = [
      metricRow(
        "Inactive accounts",
        0,
        String(data.metrics.inactiveUsers ?? 0),
      ),
      metricRow(
        "Active people with no manager",
        0,
        String(data.metrics.usersWithoutManager ?? 0),
      ),
      metricRow("Active roles", 0, String(data.metrics.roles ?? 0)),
      metricRow("Active permissions", 0, String(data.metrics.permissions ?? 0)),
    ].join("");
  }

  function renderUsers() {
    if (!can("admin.users")) return;
    setText("#userCount", `${data.users.length} account(s) shown.`);
    $("#usersTable").innerHTML = data.users.length
      ? data.users
          .map((row) => {
            const isSelf = Number(row.id) === viewerId;
            const active = Number(row.is_active) === 1;
            return `<tr>
              <td>
                <strong>${esc(row.full_name)}${isSelf ? " (you)" : ""}</strong>
                <div class="muted">${esc(row.email)} · ${esc(row.emp_code)}</div>
              </td>
              <td>${esc(row.role_name)}</td>
              <td>${esc(row.department_name)}${row.team_name ? " / " + esc(row.team_name) : ""}</td>
              <td>${esc(row.manager_name || "—")}</td>
              <td>${fmtDate(row.last_sign_in)}</td>
              <td>${statusTag(active ? "active" : "inactive")}</td>
              <td>
                <button class="btn small" data-edit-user="${row.id}">Edit</button>
                <button class="btn small" data-reset-user="${row.id}">Reset password</button>
                ${
                  isSelf
                    ? ""
                    : `<button class="btn small ${active ? "danger" : ""}" data-status-user="${row.id}" data-active="${active ? "0" : "1"}">
                         ${active ? "Deactivate" : "Activate"}
                       </button>`
                }
              </td>
            </tr>`;
          })
          .join("")
      : emptyRow(7, "No accounts match these filters.");
  }

  function renderRoles() {
    if (!can("admin.roles") || !data.rolePermissions) return;
    const matrix = data.rolePermissions.matrix || {};
    $("#rolesTable").innerHTML = data.roles
      .map((role) => {
        const granted = matrix[role.role_code] || [];
        return `<tr>
          <td>
            <strong>${esc(role.display_name)}</strong>
            <div class="muted">${esc(role.role_code)}</div>
          </td>
          <td>${esc(role.dashboard_path)}</td>
          <td>${role.active_user_count} active / ${role.user_count} total</td>
          <td>${granted.length}</td>
          <td>${statusTag(Number(role.is_active) === 1 ? "active" : "inactive")}</td>
          <td>
            <button class="btn small" data-edit-role="${esc(role.role_code)}">Details</button>
            <button class="btn small" data-permissions-role="${esc(role.role_code)}">Permissions</button>
          </td>
        </tr>`;
      })
      .join("");
  }

  function renderAudit() {
    if (!can("admin.audit")) return;
    setText("#auditCount", `${data.audit.length} entr(ies) shown.`);
    $("#auditTable").innerHTML = data.audit.length
      ? data.audit
          .map(
            (row) => `<tr>
              <td>${fmtDateTime(row.created_at)}</td>
              <td>${esc(row.user_name || "System")}</td>
              <td>${statusTag(row.action)}</td>
              <td>${esc(row.entity_type || "—")}${row.entity_id ? " #" + row.entity_id : ""}</td>
              <td>${esc(row.detail || "—")}</td>
              <td>${esc(row.ip_address || "—")}</td>
            </tr>`,
          )
          .join("")
      : emptyRow(6, "No audit entries match these filters.");
  }

  function renderSecurity() {
    if (!can("admin.audit")) return;
    $("#securityTable").innerHTML = data.security.length
      ? data.security
          .map(
            (row) => `<tr>
              <td>${esc(row.email)}</td>
              <td>${row.failures}</td>
              <td>${row.successes}</td>
              <td>${fmtDateTime(row.last_attempt)}</td>
              <td>${esc(row.last_failed_ip || "—")}</td>
              <td>${
                row.is_active === null
                  ? '<span class="muted">No account</span>'
                  : statusTag(Number(row.is_active) === 1 ? "active" : "inactive")
              }</td>
            </tr>`,
          )
          .join("")
      : emptyRow(6, "No sign-in attempts in the last seven days.");
  }

  function renderAll() {
    renderOverview();
    renderUsers();
    renderRoles();
    renderAudit();
    renderSecurity();
    applyPermissions();
    applyDynamicMeasurements();
  }

  // Create or edit an account.
  function openUserForm(id) {
    const record = id
      ? data.users.find((row) => Number(row.id) === Number(id))
      : null;
    const roleOptions = data.roles
      .filter((role) => Number(role.is_active) === 1)
      .map(
        (role) =>
          `<option value="${esc(role.role_code)}"${record && role.role_code === record.role ? " selected" : ""}>${esc(role.display_name)}</option>`,
      )
      .join("");
    const departmentOptions = data.departments
      .map(
        (department) =>
          `<option value="${department.id}"${record && Number(record.department_id) === Number(department.id) ? " selected" : ""}>${esc(department.department_name)}</option>`,
      )
      .join("");
    const managerOptions = [
      '<option value="">No change</option>',
      ...data.users
        .filter(
          (row) => Number(row.is_active) === 1 && Number(row.id) !== Number(id),
        )
        .map(
          (row) =>
            `<option value="${row.id}">${esc(row.full_name)} — ${esc(row.job_title || row.role_name)}</option>`,
        ),
    ].join("");
    const isSelf = record && Number(record.id) === viewerId;

    openModal(
      record ? "Edit " + record.full_name : "New account",
      `<div class="form-grid">
         <div class="field">
           <label for="userFullName">Full name</label>
           <input id="userFullName" type="text" maxlength="120" value="${esc(record?.full_name || "")}" />
         </div>
         <div class="field">
           <label for="userEmail">Email</label>
           <input id="userEmail" type="email" maxlength="120" value="${esc(record?.email || "")}" />
         </div>
         <div class="field">
           <label for="userEmpCode">Employee code</label>
           <input id="userEmpCode" type="text" maxlength="20" value="${esc(record?.emp_code || "")}" />
         </div>
         <div class="field">
           <label for="userJobTitle">Job title</label>
           <input id="userJobTitle" type="text" maxlength="100" value="${esc(record?.job_title || "")}" />
         </div>
         <div class="field">
           <label for="userRoleSelect">Access level</label>
           <select id="userRoleSelect"${isSelf ? " disabled" : ""}>${roleOptions}</select>
           ${isSelf ? '<small class="muted">You cannot change your own role.</small>' : ""}
         </div>
         <div class="field">
           <label for="userDepartment">Department</label>
           <select id="userDepartment">${departmentOptions}</select>
         </div>
         <div class="field">
           <label for="userTeam">Team</label>
           <select id="userTeam"><option value="">No team</option></select>
         </div>
         <div class="field">
           <label for="userDateJoined">Date joined</label>
           <input id="userDateJoined" type="date" value="${esc(record?.date_joined || "")}" />
         </div>
         <div class="field">
           <label for="userManager">Reports to</label>
           <select id="userManager">${managerOptions}</select>
           <small class="muted">Sets a primary reporting line from today.</small>
         </div>
         <div class="field"><label><input id="userReviewEligible" type="checkbox" ${record&&Number(record.review_eligible)===0?'':'checked'}> Include in performance review cycles</label><small class="muted">Eligible people must have an active primary manager before a cycle can be published.</small></div>
       </div>
       ${record ? "" : '<p class="modal-notice">A temporary password is generated and shown once after saving.</p>'}`,
      `<button class="btn" onclick="closeModal()">Cancel</button>
       <button class="btn primary" data-save-user="${record?.id || 0}">
         ${record ? "Save changes" : "Create account"}
       </button>`,
    );
    populateTeams(record?.team_id);
    $("#userDepartment")?.addEventListener("change", () => populateTeams(null));
  }

  function populateTeams(selectedTeamId) {
    const select = $("#userTeam");
    const departmentId = Number($("#userDepartment")?.value || 0);
    if (!select) return;
    const options = data.teams
      .filter((team) => Number(team.department_id) === departmentId)
      .map(
        (team) =>
          `<option value="${team.id}"${Number(selectedTeamId) === Number(team.id) ? " selected" : ""}>${esc(team.team_name)}</option>`,
      )
      .join("");
    select.innerHTML = '<option value="">No team</option>' + options;
  }

  async function saveUser(id) {
    const payload = {
      id: Number(id) || 0,
      fullName: $("#userFullName")?.value || "",
      email: $("#userEmail")?.value || "",
      empCode: $("#userEmpCode")?.value || "",
      jobTitle: $("#userJobTitle")?.value || "",
      role: $("#userRoleSelect")?.value || "",
      departmentId: $("#userDepartment")?.value || "",
      teamId: $("#userTeam")?.value || "",
      dateJoined: $("#userDateJoined")?.value || "",
      managerId: $("#userManager")?.value || "",
      reviewEligible: $("#userReviewEligible")?.checked ?? true,
    };
    // A disabled select submits nothing, so keep the account's current role.
    if (!payload.role && Number(id)) {
      payload.role =
        data.users.find((row) => Number(row.id) === Number(id))?.role || "";
    }
    try {
      const result = await request("admin_user_save", {
        method: "POST",
        body: JSON.stringify(payload),
      });
      closeModal();
      await load();
      if (result.temporaryPassword) {
        showTemporaryPassword(payload.email, result.temporaryPassword);
      } else {
        toast(result.message || "Account updated.");
      }
    } catch (error) {
      toast(error.message);
    }
  }

  function showTemporaryPassword(email, password) {
    openModal(
      "Temporary password",
      `<p>Give this to <strong>${esc(email)}</strong>. It is shown once and is not stored in readable form.</p>
       <p class="modal-notice"><strong>${esc(password)}</strong></p>
       <p class="muted">Ask them to sign in and change it from their dashboard.</p>`,
      '<button class="btn primary" onclick="closeModal()">Done</button>',
    );
  }

  async function setUserStatus(id, active) {
    const record = data.users.find((row) => Number(row.id) === Number(id));
    if (
      !confirm(
        `${active ? "Activate" : "Deactivate"} ${record?.full_name || "this account"}?`,
      )
    )
      return;
    try {
      const result = await request("admin_user_status", {
        method: "POST",
        body: JSON.stringify({ id: Number(id), isActive: active }),
      });
      toast(result.message || "Account updated.");
      await load();
    } catch (error) {
      toast(error.message);
    }
  }

  async function resetPassword(id) {
    const record = data.users.find((row) => Number(row.id) === Number(id));
    if (!confirm(`Issue a temporary password for ${record?.full_name}?`)) return;
    try {
      const result = await request("admin_user_password", {
        method: "POST",
        body: JSON.stringify({ id: Number(id) }),
      });
      showTemporaryPassword(record?.email || "", result.temporaryPassword);
      await load();
    } catch (error) {
      toast(error.message);
    }
  }

  // Role label, destination and availability.
  function openRoleForm(roleCode) {
    const role = data.roles.find((row) => row.role_code === roleCode);
    if (!role) return;
    const paths = [
      "admin-dashboard.html",
      "hr-dashboard.html",
      "manager-dashboard.html",
      "employee-dashboard.html",
    ]
      .map(
        (path) =>
          `<option value="${path}"${path === role.dashboard_path ? " selected" : ""}>${path}</option>`,
      )
      .join("");
    openModal(
      "Role — " + role.display_name,
      `<div class="field">
         <label for="roleDisplayName">Display name</label>
         <input id="roleDisplayName" type="text" maxlength="60" value="${esc(role.display_name)}" />
       </div>
       <div class="field">
         <label for="roleDashboard">Destination after sign-in</label>
         <select id="roleDashboard">${paths}</select>
       </div>
       <div class="field">
         <label class="check-row">
           <input id="roleActive" type="checkbox"${Number(role.is_active) === 1 ? " checked" : ""} />
           Available for assignment
         </label>
         <small class="muted">A role with active accounts cannot be retired.</small>
       </div>`,
      `<button class="btn" onclick="closeModal()">Cancel</button>
       <button class="btn primary" data-save-role="${esc(role.role_code)}">Save role</button>`,
    );
  }

  async function saveRole(roleCode) {
    try {
      const result = await request("admin_role_save", {
        method: "POST",
        body: JSON.stringify({
          roleCode,
          displayName: $("#roleDisplayName")?.value || "",
          dashboardPath: $("#roleDashboard")?.value || "",
          isActive: !!$("#roleActive")?.checked,
        }),
      });
      closeModal();
      toast(result.message || "Role updated.");
      await load();
    } catch (error) {
      toast(error.message);
    }
  }

  // The permission grid for one role.
  function openRolePermissions(roleCode) {
    const role = data.roles.find((row) => row.role_code === roleCode);
    const catalogue = data.rolePermissions?.permissions || [];
    const granted = new Set(data.rolePermissions?.matrix?.[roleCode] || []);
    if (!role) return;
    const rows = catalogue
      .filter((permission) => Number(permission.is_active) === 1)
      .map(
        (permission) => `<label class="check-row">
          <input type="checkbox" class="permission-box" value="${esc(permission.permission_code)}"${granted.has(permission.permission_code) ? " checked" : ""} />
          <span>
            <strong>${esc(permission.permission_code)}</strong>
            <div class="muted">${esc(permission.description || "")}</div>
          </span>
        </label>`,
      )
      .join("");
    openModal(
      "Permissions — " + role.display_name,
      `<p class="modal-notice compact">
         Saving replaces this role's whole permission set. Accounts using the role
         pick up the change on their next request.
       </p>
       <div class="checklist">${rows}</div>`,
      `<button class="btn" onclick="closeModal()">Cancel</button>
       <button class="btn primary" data-save-permissions="${esc(roleCode)}">Save permissions</button>`,
    );
  }

  async function saveRolePermissions(roleCode) {
    const permissions = [...document.querySelectorAll(".permission-box")]
      .filter((box) => box.checked)
      .map((box) => box.value);
    try {
      const result = await request("admin_role_permissions", {
        method: "POST",
        body: JSON.stringify({ roleCode, permissions }),
      });
      closeModal();
      toast(result.message || "Permissions updated.");
      await load();
    } catch (error) {
      toast(error.message);
    }
  }

  async function refreshUsers() {
    try {
      const result = await request("admin_users&" + userFilters());
      data.users = result.users || [];
      renderUsers();
      applyPermissions();
    } catch (error) {
      toast(error.message);
    }
  }

  async function refreshAudit() {
    const params = new URLSearchParams({
      search: $("#auditSearch")?.value || "",
      from: $("#auditFrom")?.value || "",
      to: $("#auditTo")?.value || "",
      limit: "300",
    }).toString();
    try {
      const result = await request("admin_audit&" + params);
      data.audit = result.audit || [];
      renderAudit();
    } catch (error) {
      toast(error.message);
    }
  }

  function exportUsers() {
    downloadCsv(
      "system-accounts.csv",
      [
        "Employee code",
        "Name",
        "Email",
        "Access level",
        "Job title",
        "Department",
        "Team",
        "Manager",
        "Active",
        "Last sign-in",
      ],
      data.users.map((row) => [
        row.emp_code,
        row.full_name,
        row.email,
        row.role_name,
        row.job_title || "",
        row.department_name,
        row.team_name || "",
        row.manager_name || "",
        Number(row.is_active) === 1 ? "Yes" : "No",
        row.last_sign_in || "",
      ]),
    );
  }

  function exportAudit() {
    downloadCsv(
      "audit-log.csv",
      ["When", "Who", "Email", "Action", "Entity", "Entity ID", "Detail", "IP"],
      data.audit.map((row) => [
        row.created_at,
        row.user_name || "System",
        row.email || "",
        row.action,
        row.entity_type || "",
        row.entity_id || "",
        row.detail || "",
        row.ip_address || "",
      ]),
    );
  }

  function wire() {
    setupNavigation(pageMeta);
    $("#refreshBtn")?.addEventListener("click", () => load(true));
    $("#newUserBtn")?.addEventListener("click", () => openUserForm(0));
    $("#exportUsersBtn")?.addEventListener("click", exportUsers);
    $("#exportAuditBtn")?.addEventListener("click", exportAudit);
    $("#userSearch")?.addEventListener("input", () => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(refreshUsers, 300);
    });
    ["#userRole", "#userStatus"].forEach((selector) =>
      $(selector)?.addEventListener("change", refreshUsers),
    );
    $("#auditSearch")?.addEventListener("input", () => {
      clearTimeout(auditTimer);
      auditTimer = setTimeout(refreshAudit, 300);
    });
    ["#auditFrom", "#auditTo"].forEach((selector) =>
      $(selector)?.addEventListener("change", refreshAudit),
    );

    // Generated buttons are handled by delegation.
    document.addEventListener("click", (event) => {
      const target = event.target.closest(
        "[data-edit-user],[data-save-user],[data-status-user],[data-reset-user],[data-edit-role],[data-save-role],[data-permissions-role],[data-save-permissions]",
      );
      if (!target) return;
      const dataset = target.dataset;
      if (dataset.editUser) openUserForm(dataset.editUser);
      else if (dataset.saveUser !== undefined) saveUser(dataset.saveUser);
      else if (dataset.statusUser)
        setUserStatus(dataset.statusUser, dataset.active === "1");
      else if (dataset.resetUser) resetPassword(dataset.resetUser);
      else if (dataset.editRole) openRoleForm(dataset.editRole);
      else if (dataset.saveRole) saveRole(dataset.saveRole);
      else if (dataset.permissionsRole)
        openRolePermissions(dataset.permissionsRole);
      else if (dataset.savePermissions)
        saveRolePermissions(dataset.savePermissions);
    });
  }

  // Background refreshes only once the session is confirmed.
  let ready = false;
  const backgroundLoad = () => {
    if (ready && !document.hidden && !$("#modalBackdrop")?.classList.contains("open"))
      load();
  };
  window.addEventListener("focus", backgroundLoad);
  document.addEventListener("visibilitychange", backgroundLoad);
  setInterval(backgroundLoad, 60000);

  window.addEventListener("pppm:authenticated", () => {
    ready = true;
    wire();
    load();
  });
})();
