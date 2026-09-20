(function () {
  // HR workspace behaviour. All data comes from api.php; nothing is invented
  // on the client, and every section respects the permissions the server sent.
  let data = {
    metrics: {},
    directory: [],
    departments: [],
    roles: [],
    cases: [],
    resolvedCases: [],
    pips: [],
    cycles: [],
    reports: null,
  };
  let viewerId = 0;
  let directoryTimer = null;

  const pageMeta = {
    overview: [
      "HR Dashboard",
      "People data, escalated cases and improvement plans in one place.",
    ],
    directory: [
      "Employee directory",
      "Search people and open a record for the reporting path and plan history.",
    ],
    cases: [
      "Escalated cases",
      "Decide whether a manager's rejected peer nomination stands.",
    ],
    pips: [
      "Improvement plans",
      "Oversight of every plan; edits are limited to the plans you own.",
    ],
    cycles: [
      "Review cycles",
      "Participation, outstanding forms and released ratings per cycle.",
    ],
    reports: [
      "Reports & insights",
      "Aggregated people data. Individual feedback responses stay private.",
    ],
  };

  function sync(message, isError = false) {
    const host = $("#hrSync");
    if (!host) return;
    host.textContent = message;
    host.classList.toggle("error", isError);
  }

  function setText(selector, value) {
    const host = $(selector);
    if (host) host.textContent = value ?? "—";
  }

  function filters() {
    return new URLSearchParams({
      search: $("#directorySearch")?.value || "",
      departmentId: $("#directoryDepartment")?.value || "",
      role: $("#directoryRole")?.value || "",
      status: $("#directoryStatus")?.value || "active",
    }).toString();
  }

  // Load everything the dashboard shows in one request.
  async function load(announce = false) {
    try {
      const result = await request("hr_dashboard&" + filters());
      viewerId = Number(result.user?.id || 0);
      data = {
        metrics: result.metrics || {},
        directory: result.directory || [],
        departments: result.departments || [],
        roles: result.roles || [],
        cases: result.cases || [],
        resolvedCases: result.resolvedCases || [],
        pips: result.pips || [],
        cycles: result.cycles || [],
        reports: result.reports || null,
      };
      fillFilterOptions();
      renderAll();
      sync(
        announce
          ? "Updated " + new Date().toLocaleTimeString()
          : "HR data loaded.",
      );
    } catch (error) {
      sync(error.message, true);
      toast(error.message);
    }
  }

  function fillFilterOptions() {
    const department = $("#directoryDepartment");
    if (department && department.options.length <= 1) {
      data.departments.forEach((row) => {
        const option = document.createElement("option");
        option.value = row.id;
        option.textContent = row.department_name;
        department.append(option);
      });
    }
    const role = $("#directoryRole");
    if (role && role.options.length <= 1) {
      data.roles.forEach((row) => {
        const option = document.createElement("option");
        option.value = row.role_code;
        option.textContent = row.display_name;
        role.append(option);
      });
    }
  }

  function renderOverview() {
    const metrics = data.metrics;
    setText("#kpiHeadcount", metrics.headcount ?? "—");
    setText(
      "#kpiHeadcountSub",
      (metrics.inactive ?? 0) + " inactive account(s)",
    );
    setText("#kpiCases", metrics.openCases ?? "—");
    setText("#kpiPips", metrics.activePips ?? "—");
    setText("#kpiPipsSub", (metrics.draftPips ?? 0) + " still in draft");
    setText("#kpiFeedback", metrics.pendingFeedback ?? "—");

    const caseRows = data.cases.slice(0, 5);
    $("#overviewCases").innerHTML = caseRows.length
      ? caseRows
          .map(
            (row) => `<tr>
              <td>${esc(row.employee_name)}</td>
              <td>${esc(row.peer_name)}</td>
              <td>${esc(row.manager_name)}</td>
              <td>${fmtDate(row.escalated_at)}</td>
              <td><button class="btn small" data-case="${row.id}">Review</button></td>
            </tr>`,
          )
          .join("")
      : emptyRow(5, "No escalations are waiting for a decision.");

    const owned = data.pips.filter((row) => row.owned).slice(0, 5);
    $("#overviewPips").innerHTML = owned.length
      ? owned
          .map(
            (row) => `<tr>
              <td>${esc(row.employee_name)}</td>
              <td>${fmtDate(row.end_date)}</td>
              <td>${row.met_count}/${row.objective_count}</td>
              <td>${statusTag(row.status)}</td>
              <td><button class="btn small" data-pip="${row.id}">Open</button></td>
            </tr>`,
          )
          .join("")
      : emptyRow(5, "No improvement plans are assigned to you.");

    const openCycles = data.cycles.filter((row) => row.status !== "closed");
    $("#overviewCycles").innerHTML = openCycles.length
      ? openCycles
          .map((row) => {
            const total = Number(row.participants) || 0;
            const done = Number(row.completed) || 0;
            return metricRow(
              row.name,
              total ? (done / total) * 100 : 0,
              `${done}/${total}`,
            );
          })
          .join("")
      : '<p class="muted">No open review cycles.</p>';
  }

  function renderDirectory() {
    const rows = data.directory;
    setText("#directoryCount", `${rows.length} employee(s) shown.`);
    $("#directoryTable").innerHTML = rows.length
      ? rows
          .map((row) => {
            const flags = [
              Number(row.active_pips) > 0
                ? '<span class="status red">On a plan</span>'
                : "",
              Number(row.overdue_actions) > 0
                ? `<span class="status amber">${row.overdue_actions} overdue</span>`
                : "",
              Number(row.is_active) === 0
                ? '<span class="status red">Inactive</span>'
                : "",
            ]
              .filter(Boolean)
              .join(" ");
            return `<tr>
              <td>
                <strong>${esc(row.full_name)}</strong>
                <div class="muted">${esc(row.job_title || "Job title not set")} · ${esc(row.emp_code)}</div>
              </td>
              <td>${esc(row.department_name)}${row.team_name ? " / " + esc(row.team_name) : ""}</td>
              <td>${esc(row.manager_name || "No primary manager")}</td>
              <td>${esc(row.role_name)}</td>
              <td>${flags || '<span class="muted">—</span>'}</td>
              <td><button class="btn small" data-employee="${row.id}">Open</button></td>
            </tr>`;
          })
          .join("")
      : emptyRow(6, "No employees match these filters.");
  }

  function renderCases() {
    if (!can("hr.cases")) return;
    $("#casesOpen").innerHTML = data.cases.length
      ? data.cases
          .map(
            (row) => `<div class="card nomination-item">
              <div class="section-head">
                <div>
                  <h3>${esc(row.employee_name)} → ${esc(row.peer_name)}</h3>
                  <p class="muted">
                    ${esc(row.cycle_name)} · rejected by ${esc(row.manager_name)}
                    on ${fmtDate(row.decided_at)} · raised ${fmtDate(row.escalated_at)}
                  </p>
                </div>
                ${statusTag(row.cycle_status)}
              </div>
              <div class="detail-box">
                <strong>Shared work</strong>
                <p>${esc(row.shared_work)}</p>
                <strong>What they worked on together</strong>
                <p>${esc(row.collaboration_details)}</p>
                <strong>What the peer observed</strong>
                <p>${esc(row.reviewer_justification)}</p>
                <strong>Manager's rejection reason</strong>
                <p>${esc(row.decision_reason || "No reason recorded.")}</p>
                <strong>Employee's escalation reason</strong>
                <p>${esc(row.escalation_reason)}</p>
              </div>
              <div class="action-group">
                <button class="btn" data-resolve="${row.id}" data-outcome="resolved_upheld">
                  Uphold rejection
                </button>
                <button class="btn primary" data-resolve="${row.id}" data-outcome="resolved_overturned">
                  Overturn and request peer feedback
                </button>
              </div>
            </div>`,
          )
          .join("")
      : '<p class="muted">No escalations are waiting for a decision.</p>';

    $("#casesResolved").innerHTML = data.resolvedCases.length
      ? data.resolvedCases
          .map(
            (row) => `<tr>
              <td>${esc(row.employee_name)}</td>
              <td>${esc(row.peer_name)}</td>
              <td>${statusTag(row.status.replace("resolved_", ""))}</td>
              <td>${esc(row.resolved_by_name || "—")}</td>
              <td>${fmtDateTime(row.resolved_at)}</td>
            </tr>`,
          )
          .join("")
      : emptyRow(5, "No escalations have been resolved yet.");
  }

  function visiblePips() {
    const mode = $("#pipFilter")?.value || "mine";
    if (mode === "mine") return data.pips.filter((row) => row.owned);
    if (mode === "live")
      return data.pips.filter((row) =>
        ["active", "extended"].includes(row.status),
      );
    return data.pips;
  }

  function renderPips() {
    if (!can("hr.pips")) return;
    const rows = visiblePips();
    $("#pipsTable").innerHTML = rows.length
      ? rows
          .map(
            (row) => `<tr>
              <td>
                <strong>${esc(row.employee_name)}</strong>
                <div class="muted">${esc(row.department_name)}</div>
              </td>
              <td>${esc(row.manager_name)}</td>
              <td>${esc(row.hr_owner_name)}${row.owned ? " (you)" : ""}</td>
              <td>${fmtDate(row.start_date)} – ${fmtDate(row.end_date)}</td>
              <td>${row.met_count}/${row.objective_count} met</td>
              <td>${statusTag(row.status)}</td>
              <td>
                ${
                  row.owned
                    ? `<button class="btn small" data-pip="${row.id}">Open</button>`
                    : '<span class="muted">Not your plan</span>'
                }
              </td>
            </tr>`,
          )
          .join("")
      : emptyRow(7, "No improvement plans match this filter.");
  }

  function renderCycles() {
    if (!can("hr.reports")) return;
    $("#cyclesTable").innerHTML = data.cycles.length
      ? data.cycles
          .map((row) => {
            const total = Number(row.participants) || 0;
            const done = Number(row.completed) || 0;
            const percentage = total ? Math.round((done / total) * 100) : 0;
            return `<tr>
              <td><strong>${esc(row.name)}</strong></td>
              <td>${fmtDate(row.period_start)} – ${fmtDate(row.period_end)}</td>
              <td>${statusTag(row.status)}</td>
              <td>${progressMarkup(percentage)}<span class="muted">${done}/${total}</span></td>
              <td>${row.pending_forms}</td>
              <td>${row.average_rating ?? "—"}</td>
            </tr>`;
          })
          .join("")
      : emptyRow(6, "No review cycles exist yet.");
  }

  function renderReports() {
    if (!data.reports) return;
    const reports = data.reports;
    $("#reportDepartments").innerHTML = reports.departments.length
      ? reports.departments
          .map(
            (row) => `<tr>
              <td>${esc(row.department_name)}</td>
              <td>${row.headcount}</td>
              <td>${row.inactive ?? 0}</td>
              <td>${row.active_pips}</td>
              <td>${row.average_rating ?? "—"}</td>
            </tr>`,
          )
          .join("")
      : emptyRow(5, "No departments are active.");

    const development = reports.development || {};
    const completed = Number(development.completed_actions) || 0;
    const open = Number(development.open_actions) || 0;
    const total = completed + open;
    $("#reportDevelopment").innerHTML = [
      metricRow(
        "Completed actions",
        total ? (completed / total) * 100 : 0,
        String(completed),
      ),
      metricRow("Open actions", total ? (open / total) * 100 : 0, String(open)),
      metricRow(
        "Overdue actions",
        open ? ((Number(development.overdue_actions) || 0) / open) * 100 : 0,
        String(development.overdue_actions ?? 0),
      ),
      metricRow("Blocked steps", 0, String(development.blocked_steps ?? 0)),
    ].join("");

    const skills = reports.skillGaps || [];
    const topSkill = Number(skills[0]?.action_count) || 1;
    $("#reportSkills").innerHTML = skills.length
      ? skills
          .map((row) =>
            metricRow(
              row.skill_name,
              (Number(row.action_count) / topSkill) * 100,
              String(row.action_count),
            ),
          )
          .join("")
      : '<p class="muted">No open development actions target a named skill.</p>';

    const outcomes = reports.pipOutcomes || [];
    const topOutcome = Math.max(
      1,
      ...outcomes.map((row) => Number(row.total) || 0),
    );
    $("#reportPipOutcomes").innerHTML = outcomes.length
      ? outcomes
          .map((row) =>
            metricRow(
              titleCase(row.status),
              (Number(row.total) / topOutcome) * 100,
              String(row.total),
            ),
          )
          .join("")
      : '<p class="muted">No improvement plans have been created.</p>';
  }

  function renderAll() {
    renderOverview();
    renderDirectory();
    renderCases();
    renderPips();
    renderCycles();
    renderReports();
    applyPermissions();
    applyDynamicMeasurements();
  }

  // One person's record, including the reporting path.
  async function openEmployee(id) {
    try {
      const result = await request("hr_employee&employeeId=" + Number(id));
      const employee = result.employee;
      const path = (result.path || [])
        .map((node) => esc(node.full_name))
        .join(" → ");
      const goals = result.goals.length
        ? result.goals
            .map(
              (goal) =>
                `<tr><td>${esc(goal.title)}</td><td>${fmtDate(goal.due_date)}</td><td>${statusTag(goal.status)}</td></tr>`,
            )
            .join("")
        : emptyRow(3, "No goals recorded.");
      const reviews = result.reviews.length
        ? result.reviews
            .map(
              (review) =>
                `<tr><td>${esc(review.cycle_name)}</td><td>${statusTag(review.status)}</td><td>${review.final_rating ?? "—"}</td></tr>`,
            )
            .join("")
        : emptyRow(3, "No review participation recorded.");
      const pips = result.pips.length
        ? result.pips
            .map(
              (pip) => `<tr>
                <td>${fmtDate(pip.start_date)} – ${fmtDate(pip.end_date)}</td>
                <td>${statusTag(pip.status)}</td>
                <td>${pip.reason ? esc(pip.reason) : '<span class="muted">Visible to the assigned HR owner only</span>'}</td>
              </tr>`,
            )
            .join("")
        : emptyRow(3, "No improvement plans recorded.");
      openModal(
        employee.full_name,
        `<div class="detail-grid">
          <div><span class="muted">Employee code</span><strong>${esc(employee.emp_code)}</strong></div>
          <div><span class="muted">Email</span><strong>${esc(employee.email)}</strong></div>
          <div><span class="muted">Job title</span><strong>${esc(employee.job_title || "Not set")}</strong></div>
          <div><span class="muted">Department</span><strong>${esc(employee.department_name)}${employee.team_name ? " / " + esc(employee.team_name) : ""}</strong></div>
          <div><span class="muted">Access level</span><strong>${esc(employee.role_name)}</strong></div>
          <div><span class="muted">Joined</span><strong>${fmtDate(employee.date_joined)}</strong></div>
        </div>
        <p class="modal-notice compact"><strong>Reporting path:</strong> ${path || "No recorded reporting line."}</p>
        <h4>Goals</h4>
        <div class="table-wrap"><table class="table"><thead><tr><th>Goal</th><th>Due</th><th>Status</th></tr></thead><tbody>${goals}</tbody></table></div>
        <h4 class="spaced-top">Review history</h4>
        <div class="table-wrap"><table class="table"><thead><tr><th>Cycle</th><th>Status</th><th>Rating</th></tr></thead><tbody>${reviews}</tbody></table></div>
        <h4 class="spaced-top">Improvement plans</h4>
        <div class="table-wrap"><table class="table"><thead><tr><th>Dates</th><th>Status</th><th>Reason</th></tr></thead><tbody>${pips}</tbody></table></div>`,
        '<button class="btn" onclick="closeModal()">Close</button>',
      );
    } catch (error) {
      toast(error.message);
    }
  }

  // Ask for the resolution note before recording an escalation decision.
  function resolveCase(id, outcome) {
    const record = data.cases.find((row) => Number(row.id) === Number(id));
    if (!record) return;
    const overturning = outcome === "resolved_overturned";
    openModal(
      overturning ? "Overturn the rejection" : "Uphold the rejection",
      `<p>
         ${esc(record.employee_name)} asked HR to review the rejected nomination of
         ${esc(record.peer_name)} in ${esc(record.cycle_name)}.
       </p>
       <p class="modal-notice">
         ${
           overturning
             ? "Overturning creates the peer's feedback form. The manager's decision stays on record; this escalation becomes the record of the override. It is refused if the cycle has closed or the peer deadline has passed."
             : "Upholding leaves the manager's decision in place. No feedback form is created."
         }
       </p>
       <div class="field">
         <label for="caseNote">Resolution note (15–2,000 characters)</label>
         <textarea id="caseNote" rows="5" placeholder="Explain the decision. The employee and manager can see this."></textarea>
       </div>`,
      `<button class="btn" onclick="closeModal()">Cancel</button>
       <button class="btn primary" data-confirm-case="${id}" data-outcome="${outcome}">
         ${overturning ? "Overturn" : "Uphold"}
       </button>`,
    );
  }

  async function confirmCase(id, outcome) {
    const note = ($("#caseNote")?.value || "").trim();
    if (note.length < 15) {
      toast("Add a resolution note of at least 15 characters.");
      return;
    }
    try {
      const result = await request("hr_case_resolve", {
        method: "POST",
        body: JSON.stringify({ id: Number(id), outcome, note }),
      });
      closeModal();
      toast(result.message || "Escalation resolved.");
      await load();
    } catch (error) {
      toast(error.message);
    }
  }

  // Change the status of a plan this HR user owns.
  function openPip(id) {
    const record = data.pips.find((row) => Number(row.id) === Number(id));
    if (!record) return;
    if (!record.owned) {
      toast("Only the assigned HR owner can change this plan.");
      return;
    }
    const options = [
      "draft",
      "active",
      "extended",
      "successful",
      "unsuccessful",
      "closed",
    ]
      .map(
        (value) =>
          `<option value="${value}"${value === record.status ? " selected" : ""}>${titleCase(value)}</option>`,
      )
      .join("");
    openModal(
      "Improvement plan — " + record.employee_name,
      `<div class="detail-grid">
         <div><span class="muted">Manager</span><strong>${esc(record.manager_name)}</strong></div>
         <div><span class="muted">Dates</span><strong>${fmtDate(record.start_date)} – ${fmtDate(record.end_date)}</strong></div>
         <div><span class="muted">Objectives met</span><strong>${record.met_count}/${record.objective_count}</strong></div>
         <div><span class="muted">Last check-in</span><strong>${fmtDate(record.last_checkin)}</strong></div>
       </div>
       <p class="modal-notice compact">${esc(record.reason || "")}</p>
       <div class="field">
         <label for="pipStatus">Status</label>
         <select id="pipStatus">${options}</select>
       </div>
       <div class="field">
         <label for="pipNote">Outcome note</label>
         <textarea id="pipNote" rows="4" placeholder="Required when the plan is closed.">${esc(record.outcome_note || "")}</textarea>
       </div>`,
      `<button class="btn" onclick="closeModal()">Cancel</button>
       <button class="btn primary" data-save-pip="${id}">Save plan</button>`,
    );
  }

  async function savePip(id) {
    const status = $("#pipStatus")?.value || "";
    const outcomeNote = ($("#pipNote")?.value || "").trim();
    try {
      const result = await request("hr_pip_update", {
        method: "POST",
        body: JSON.stringify({ id: Number(id), status, outcomeNote }),
      });
      closeModal();
      toast(result.message || "Improvement plan updated.");
      await load();
    } catch (error) {
      toast(error.message);
    }
  }

  function exportDirectory() {
    downloadCsv(
      "hr-directory.csv",
      [
        "Employee code",
        "Name",
        "Email",
        "Job title",
        "Department",
        "Team",
        "Manager",
        "Access level",
        "Active",
        "Live plans",
        "Overdue actions",
      ],
      data.directory.map((row) => [
        row.emp_code,
        row.full_name,
        row.email,
        row.job_title || "",
        row.department_name,
        row.team_name || "",
        row.manager_name || "",
        row.role_name,
        Number(row.is_active) === 1 ? "Yes" : "No",
        row.active_pips,
        row.overdue_actions,
      ]),
    );
  }

  function exportPips() {
    downloadCsv(
      "hr-improvement-plans.csv",
      [
        "Employee",
        "Department",
        "Manager",
        "HR owner",
        "Start",
        "End",
        "Objectives met",
        "Objectives",
        "Status",
      ],
      visiblePips().map((row) => [
        row.employee_name,
        row.department_name,
        row.manager_name,
        row.hr_owner_name,
        row.start_date,
        row.end_date,
        row.met_count,
        row.objective_count,
        row.status,
      ]),
    );
  }

  function exportDepartments() {
    const rows = data.reports?.departments || [];
    downloadCsv(
      "hr-departments.csv",
      ["Department", "Headcount", "Inactive", "Live plans", "Average rating"],
      rows.map((row) => [
        row.department_name,
        row.headcount,
        row.inactive ?? 0,
        row.active_pips,
        row.average_rating ?? "",
      ]),
    );
  }

  // Refresh only the directory when a filter changes.
  async function refreshDirectory() {
    try {
      const result = await request("hr_directory&" + filters());
      data.directory = result.directory || [];
      renderDirectory();
      applyDynamicMeasurements();
    } catch (error) {
      toast(error.message);
    }
  }

  function wire() {
    setupNavigation(pageMeta);
    $("#refreshBtn")?.addEventListener("click", () => load(true));
    $("#exportDirectoryBtn")?.addEventListener("click", exportDirectory);
    $("#exportPipsBtn")?.addEventListener("click", exportPips);
    $("#exportDepartmentsBtn")?.addEventListener("click", exportDepartments);
    $("#startCycleBtn")?.addEventListener("click", openStartCycleForm);
    $("#pipFilter")?.addEventListener("change", () => {
      renderPips();
      applyDynamicMeasurements();
    });
    $("#directorySearch")?.addEventListener("input", () => {
      clearTimeout(directoryTimer);
      directoryTimer = setTimeout(refreshDirectory, 300);
    });
    ["#directoryDepartment", "#directoryRole", "#directoryStatus"].forEach(
      (selector) =>
        $(selector)?.addEventListener("change", refreshDirectory),
    );

    // Generated buttons are handled by delegation, so no inline handlers are
    // written into table markup.
    document.addEventListener("click", (event) => {
      const target = event.target.closest("[data-employee],[data-case],[data-pip],[data-resolve],[data-confirm-case],[data-save-pip]");
      if (!target) return;
      if (target.dataset.employee) openEmployee(target.dataset.employee);
      else if (target.dataset.case) resolveCaseFromOverview(target.dataset.case);
      else if (target.dataset.pip) openPip(target.dataset.pip);
      else if (target.dataset.resolve)
        resolveCase(target.dataset.resolve, target.dataset.outcome);
      else if (target.dataset.confirmCase)
        confirmCase(target.dataset.confirmCase, target.dataset.outcome);
      else if (target.dataset.savePip) savePip(target.dataset.savePip);
    });
  }

  function openStartCycleForm() {
  const today = new Date().toISOString().slice(0, 10);

  openModal(
    "Start review cycle",
    `
      <form id="startCycleForm">
        <div class="form-grid">
          <label>
            Cycle name
            <input
              id="cycleName"
              name="name"
              type="text"
              maxlength="120"
              placeholder="e.g. Q4 2026 Performance Review"
              required
            />
          </label>

          <label>
            Minimum peer reviews
            <input
              id="cycleMinPeers"
              name="min_peers"
              type="number"
              min="1"
              max="10"
              value="3"
              required
            />
          </label>

          <label>
            Period start
            <input
              id="cycleStart"
              name="period_start"
              type="date"
              value="${today}"
              required
            />
          </label>

          <label>
            Period end
            <input
              id="cycleEnd"
              name="period_end"
              type="date"
              required
            />
          </label>

          <label>
            Self-review deadline
            <input
              id="cycleSelfDeadline"
              name="self_deadline"
              type="date"
              required
            />
          </label>

          <label>
            Peer-review deadline
            <input
              id="cyclePeerDeadline"
              name="peer_deadline"
              type="date"
              required
            />
          </label>

          <label>
            Manager-review deadline
            <input
              id="cycleManagerDeadline"
              name="manager_deadline"
              type="date"
              required
            />
          </label>
        </div>
      </form>
    `,
    `
      <button class="btn" id="cancelCycleBtn">Cancel</button>
      <button class="btn primary" id="saveCycleBtn">Start review cycle</button>
    `,
  );

  $("#cancelCycleBtn")?.addEventListener("click", closeModal);

  $("#saveCycleBtn")?.addEventListener("click", async () => {
    const form = $("#startCycleForm");

    if (!form?.reportValidity()) {
      return;
    }

    const formData = new FormData(form);
    const payload = Object.fromEntries(formData.entries());

    payload.min_peers = Number(payload.min_peers);

    const button = $("#saveCycleBtn");

    if (button) {
      button.disabled = true;
      button.textContent = "Starting...";
    }

    try {
      const result = await request("hr_cycle_create", {
        method: "POST",
        body: JSON.stringify(payload),
      });

      closeModal();

      toast(
        `Review cycle "${result.cycle.name}" started successfully.`,
        "success",
      );

      await load(true);
      showPage("cycles");
    } catch (error) {
      toast(error.message || "Unable to start review cycle.");

      if (button) {
        button.disabled = false;
        button.textContent = "Start review cycle";
      }
    }
  });
}

  function resolveCaseFromOverview(id) {
    showPage("cases");
    setTimeout(() => {
      document
        .querySelector(`[data-resolve="${id}"]`)
        ?.scrollIntoView({ behavior: "smooth", block: "center" });
    }, 120);
  }

  // Keep the view current the way the rest of the app does.
  let ready = false;
  const backgroundLoad = () => {
    if (ready && !document.hidden) load();
  };
  window.addEventListener("focus", backgroundLoad);
  document.addEventListener("visibilitychange", backgroundLoad);
  setInterval(backgroundLoad, 60000);

  function start() {
  if (ready) return;
  ready = true;
  wire();
  load();
}

window.addEventListener("pppm:authenticated", start);

if (window.currentAuthUser) {
  start();
}
})();



