(function () {
  const API = "api.php?action=";
  const state = { user: null, people: [], tree: [], departments: [], teams: [], currentPage: "directory" };
  const $ = (selector) => document.querySelector(selector);
  const esc = (value) => String(value ?? "").replace(/[&<>'"]/g, (c) => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", "'": "&#39;", '"': "&quot;",
  })[c]);

  async function request(action, options = {}) {
    const method = String(options.method || "GET").toUpperCase();
    const headers = { "Content-Type": "application/json", ...(options.headers || {}) };
    if (method !== "GET") headers["X-CSRF-Token"] = window.currentCsrfToken || "";
    const response = await fetch(API + action, { credentials: "same-origin", ...options, headers });
    const result = await response.json().catch(() => ({ ok: false, error: "Invalid server response" }));
    if (!result.ok) throw new Error(result.error || "Request failed");
    if (result.csrfToken) window.currentCsrfToken = result.csrfToken;
    return result;
  }

  function message(text, error = false) {
    const box = $("#orgMessage");
    box.textContent = text;
    box.classList.toggle("error", error);
    if (text) setTimeout(() => { if (box.textContent === text) box.textContent = ""; }, 4500);
  }

  const pageCopy = {
    directory: ["Employee Directory", "Find people and view their role, team and direct manager."],
    hierarchy: ["Organization Hierarchy", "Explore reporting relationships across the organization."],
    reporting: ["Reporting Path", "See the management chain for a selected employee."],
    management: ["Manage Organization", "Update reporting lines, memberships, departments and teams."],
  };

  function showPage(requested, updateUrl = true) {
    const canManage = new Set(state.user?.permissions || []).has("org.structure.manage");
    const page = Object.hasOwn(pageCopy, requested) && (requested !== "management" || canManage)
      ? requested
      : "directory";
    state.currentPage = page;
    document.querySelectorAll('[id^="org-page-"].page').forEach((section) => {
      section.classList.toggle("active", section.id === `org-page-${page}`);
    });
    document.querySelectorAll(".sidebar [data-org-page]").forEach((button) => {
      button.classList.toggle("active", button.dataset.orgPage === page);
    });
    $("#orgPageTitle").textContent = pageCopy[page][0];
    $("#orgPageSubtitle").textContent = pageCopy[page][1];
    if (updateUrl) history.replaceState(null, "", `#${page}`);
  }

  function showManagementTab(tab) {
    document.querySelectorAll("[data-org-tab]").forEach((button) => {
      button.classList.toggle("active", button.dataset.orgTab === tab);
      button.setAttribute("aria-selected", button.dataset.orgTab === tab ? "true" : "false");
    });
    document.querySelectorAll('[id^="org-tab-"].tab-panel').forEach((panel) => {
      panel.classList.toggle("active", panel.id === `org-tab-${tab}`);
    });
  }

  function optionList(includeBlank = false) {
    return (includeBlank ? '<option value="">None</option>' : "") + state.people.map((person) =>
      `<option value="${person.id}">${esc(person.full_name)} — ${esc(person.job_title || "No title")} (${esc(person.emp_code)})</option>`,
    ).join("");
  }

  function departmentOptions(includeBlank = false) {
    return (includeBlank ? '<option value="">All departments</option>' : "") + state.departments.map((department) =>
      `<option value="${department.id}">${esc(department.department_name)}</option>`,
    ).join("");
  }

  function teamOptions(departmentId, includeBlank = true) {
    const choices = state.teams.filter((team) => !departmentId || Number(team.department_id) === Number(departmentId));
    return (includeBlank ? '<option value="">No team</option>' : "") + choices.map((team) =>
      `<option value="${team.id}">${esc(team.team_name)}</option>`,
    ).join("");
  }

  function renderDirectory() {
    const search = $("#orgSearch").value.trim().toLowerCase();
    const department = $("#orgDepartmentFilter").value;
    const team = $("#orgTeamFilter").value;
    const rows = state.people.filter((person) => {
      const haystack = [person.full_name, person.emp_code, person.job_title, person.manager_name].join(" ").toLowerCase();
      return (!search || haystack.includes(search))
        && (!department || String(person.department_id) === department)
        && (!team || String(person.team_id) === team);
    });
    const noun = rows.length === 1 ? "employee" : "employees";
    $("#orgDirectorySummary").textContent = rows.length === state.people.length
      ? `${rows.length} ${noun} in your viewing scope`
      : `${rows.length} of ${state.people.length} employees shown`;
    $("#orgEmployeeRows").innerHTML = rows.map((person) => `<tr>
      <td><strong>${esc(person.full_name)}</strong><div class="muted">${esc(person.emp_code)}</div></td>
      <td>${esc(person.job_title || "—")}<div class="muted">System role: ${esc(person.role)}</div></td>
      <td>${esc(person.department_name)}<div class="muted">${esc(person.team_name || "No team")}</div></td>
      <td>${esc(person.manager_name || "Top level")}<div class="muted">${esc(person.manager_job_title || "")}</div></td>
      <td><button class="btn small" type="button" data-view-path="${person.id}">View path</button></td>
    </tr>`).join("") || '<tr><td colspan="5" class="empty">No employees match these filters.</td></tr>';
  }

  function treeNode(person, open = false) {
    const children = person.children || [];
    const details = `<span class="org-node-details">
      <strong>${esc(person.full_name)}</strong>
      <span>${esc(person.job_title || "—")}</span>
      <small>${esc(person.department_name)}${person.team_name ? ` · ${esc(person.team_name)}` : ""}</small>
    </span>`;
    if (!children.length) {
      return `<div class="org-leaf"><button type="button" class="org-node-card" data-view-path="${person.id}">${details}</button></div>`;
    }
    const reportLabel = children.length === 1 ? "1 direct report" : `${children.length} direct reports`;
    return `<details ${open ? "open" : ""}>
      <summary class="org-node-card">
        ${details}
        <span class="org-expand-control"><span>${reportLabel}</span><span class="org-chevron" aria-hidden="true"></span></span>
      </summary>
      <div class="org-parent-action">
        <button class="btn small" type="button" data-view-path="${person.id}">View reporting path</button>
      </div>
      <div class="org-children">${children.map((child) => treeNode(child)).join("")}</div>
    </details>`;
  }

  function renderTree() {
    $("#orgTree").innerHTML = state.tree.map((root) => treeNode(root, true)).join("")
      || '<div class="empty">No hierarchy is available in your scope.</div>';
  }

  async function showPath(employeeId, navigate = true) {
    try {
      const result = await request(`org_path&employeeId=${encodeURIComponent(employeeId)}`);
      const selected = result.path[0];
      $("#orgPathCaption").textContent = selected ? `Reporting path for ${selected.full_name}` : "No reporting path";
      $("#orgPath").innerHTML = result.path.map((person, index) => `<div class="org-path-node">
        <span>${index === 0 ? "Selected employee" : index === 1 ? "Direct manager" : `Manager level ${index}`}</span>
        <strong>${esc(person.full_name)}</strong>
        <small>${esc(person.job_title || "—")} · ${esc(person.department_name)}</small>
      </div>`).join('<div class="org-path-arrow" aria-hidden="true">↑</div>');
      if ($("#managerEmployee")) $("#managerEmployee").value = String(employeeId);
      if ($("#membershipEmployee")) {
        $("#membershipEmployee").value = String(employeeId);
        const person = state.people.find((item) => Number(item.id) === Number(employeeId));
        if (person) {
          $("#membershipDepartment").value = String(person.department_id);
          $("#membershipTeam").innerHTML = teamOptions(person.department_id);
          $("#membershipTeam").value = person.team_id == null ? "" : String(person.team_id);
        }
      }
      if (navigate) showPage("reporting");
    } catch (error) { message(error.message, true); }
  }

  function renderManagement() {
    const permissions = new Set(state.user.permissions || []);
    if (!permissions.has("org.structure.manage")) {
      $("#org-page-management").hidden = true;
      document.querySelector('[data-org-page="management"]')?.setAttribute("hidden", "");
      if (state.currentPage === "management") showPage("directory");
      return;
    }
    ["#managerEmployee", "#managerAccount", "#membershipEmployee"].forEach((id) => { $(id).innerHTML = optionList(); });
    ["#departmentHead", "#teamLead"].forEach((id) => { $(id).innerHTML = optionList(true); });
    $("#membershipDepartment").innerHTML = departmentOptions();
    $("#teamDepartment").innerHTML = departmentOptions();
    $("#membershipTeam").innerHTML = teamOptions($("#membershipDepartment").value);
    $("#departmentList").innerHTML = state.departments.map((item) =>
      `<span><button class="btn small" type="button" data-edit-department="${item.id}">${esc(item.department_code)} · ${esc(item.department_name)}</button>
       <button class="btn small danger" type="button" data-delete-department="${item.id}" aria-label="Delete ${esc(item.department_name)}">×</button></span>`,
    ).join(" ");
    $("#teamList").innerHTML = state.teams.map((item) =>
      `<span><button class="btn small" type="button" data-edit-team="${item.id}">${esc(item.team_code)} · ${esc(item.team_name)}</button>
       <button class="btn small danger" type="button" data-delete-team="${item.id}" aria-label="Delete ${esc(item.team_name)}">×</button></span>`,
    ).join(" ");
  }

  function render() {
    $("#orgDepartmentFilter").innerHTML = departmentOptions(true);
    $("#orgTeamFilter").innerHTML = '<option value="">All teams</option>' + teamOptions(null, false);
    renderDirectory();
    renderTree();
    renderManagement();
  }

  async function reload() {
    const [tree, departments, teams] = await Promise.all([
      request("org_tree"), request("org_departments"), request("org_teams"),
    ]);
    state.people = tree.people || [];
    state.tree = tree.tree || [];
    state.departments = departments.departments || [];
    state.teams = teams.teams || [];
    render();
  }

  async function submitJson(form, action) {
    const values = Object.fromEntries(new FormData(form).entries());
    form.querySelectorAll('input[type="checkbox"]').forEach((input) => { values[input.name] = input.checked; });
    await request(action, { method: "POST", body: JSON.stringify(values) });
    await reload();
  }

  function bindEvents() {
    document.querySelectorAll("[data-org-page]").forEach((button) => {
      button.addEventListener("click", () => showPage(button.dataset.orgPage));
    });
    document.querySelectorAll("[data-org-tab]").forEach((button) => {
      button.addEventListener("click", () => showManagementTab(button.dataset.orgTab));
    });
    $("#orgClearFilters").addEventListener("click", () => {
      $("#orgSearch").value = "";
      $("#orgDepartmentFilter").value = "";
      $("#orgTeamFilter").innerHTML = '<option value="">All teams</option>' + teamOptions(null, false);
      $("#orgTeamFilter").value = "";
      renderDirectory();
      $("#orgSearch").focus();
    });
    $("#orgSearch").addEventListener("input", renderDirectory);
    $("#orgDepartmentFilter").addEventListener("change", () => {
      $("#orgTeamFilter").innerHTML = '<option value="">All teams</option>' + teamOptions($("#orgDepartmentFilter").value, false);
      renderDirectory();
    });
    $("#orgTeamFilter").addEventListener("change", renderDirectory);
    document.addEventListener("click", async (event) => {
      const pathButton = event.target.closest("[data-view-path]");
      if (pathButton) {
        await showPath(pathButton.dataset.viewPath);
        return;
      }
      const departmentButton = event.target.closest("[data-edit-department]");
      if (departmentButton) {
        const item = state.departments.find((x) => Number(x.id) === Number(departmentButton.dataset.editDepartment));
        const form = $("#departmentForm");
        form.elements.id.value = item.id; form.elements.departmentCode.value = item.department_code;
        form.elements.departmentName.value = item.department_name; form.elements.headEmployeeId.value = item.head_employee_id || "";
        form.elements.isActive.checked = Number(item.is_active) === 1;
      }
      const teamButton = event.target.closest("[data-edit-team]");
      if (teamButton) {
        const item = state.teams.find((x) => Number(x.id) === Number(teamButton.dataset.editTeam));
        const form = $("#teamForm");
        form.elements.id.value = item.id; form.elements.departmentId.value = item.department_id;
        form.elements.teamCode.value = item.team_code; form.elements.teamName.value = item.team_name;
        form.elements.teamLeadEmployeeId.value = item.team_lead_employee_id || "";
        form.elements.isActive.checked = Number(item.is_active) === 1;
      }
      const deleteTeam = event.target.closest("[data-delete-team]");
      if (deleteTeam && window.confirm("Delete this unused team? This cannot be undone.")) {
        try {
          await request("org_team_delete", { method: "POST", body: JSON.stringify({ id: deleteTeam.dataset.deleteTeam }) });
          await reload(); message("Team deleted.");
        } catch (error) { message(error.message, true); }
      }
      const deleteDepartment = event.target.closest("[data-delete-department]");
      if (deleteDepartment && window.confirm("Delete this unused department? This cannot be undone.")) {
        try {
          await request("org_department_delete", { method: "POST", body: JSON.stringify({ id: deleteDepartment.dataset.deleteDepartment }) });
          await reload(); message("Department deleted.");
        } catch (error) { message(error.message, true); }
      }
    });
    $("#membershipDepartment").addEventListener("change", () => {
      $("#membershipTeam").innerHTML = teamOptions($("#membershipDepartment").value);
    });
    $("#clearDepartment").addEventListener("click", () => { $("#departmentForm").reset(); $("#departmentForm").elements.id.value = ""; });
    $("#clearTeam").addEventListener("click", () => { $("#teamForm").reset(); $("#teamForm").elements.id.value = ""; });
    [
      ["#managerForm", "org_change_manager", "Reporting relationship saved."],
      ["#membershipForm", "org_assign_membership", "Membership saved."],
      ["#departmentForm", "org_department_save", "Department saved."],
      ["#teamForm", "org_team_save", "Team saved."],
    ].forEach(([selector, action, success]) => $(selector).addEventListener("submit", async (event) => {
      event.preventDefault();
      try { await submitJson(event.currentTarget, action); message(success); }
      catch (error) { message(error.message, true); }
    }));
  }

  async function init() {
    try {
      const me = await request("me");
      state.user = me.user;
      window.currentCsrfToken = me.csrfToken;
      const spaces = state.user.workspaces || [];
      let savedWorkspace = "";
      try { savedWorkspace = localStorage.getItem(`pppm.workspace.${state.user.id}`) || ""; } catch (_) {}
      const preferredWorkspace = spaces.find((space) => space.key === savedWorkspace)
        || spaces.find((space) => space.path === state.user.dashboard_path);
      const backLink = $("#backToDashboard");
      backLink.href = preferredWorkspace?.path || state.user.dashboard_path || "index.html";
      backLink.textContent = preferredWorkspace?.label
        ? `← Back to ${preferredWorkspace.label} dashboard`
        : "← Back to dashboard";
      backLink.hidden = false;
      $("#managerEffectiveDate").value = new Date().toISOString().slice(0, 10);
      bindEvents();
      await reload();
      await showPath(state.user.id, false);
      const initialPage = location.hash.slice(1);
      showPage(initialPage || "directory", false);
    } catch (error) { message(error.message, true); }
  }

  window.addEventListener("DOMContentLoaded", init);
})();
