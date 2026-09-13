(function () {
  // Manager API settings.
  const API = "api.php?action=";

  // Send requests to the backend and protect database-changing actions.
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
    if (response.status === 401) {
      window.location.href = "index.html";
      throw new Error("Your session has expired.");
    }
    if (!result.ok) throw new Error(result.error || "Request failed");
    if (result.csrfToken) window.currentCsrfToken = result.csrfToken;
    return result;
  }

  // Formatting and lookup helpers.
  function dbStatus(s) {
    return String(s || "")
      .toLowerCase()
      .replaceAll(" ", "_");
  }

  function titleStatus(s) {
    return String(s || "")
      .replaceAll("_", " ")
      .replace(/\b\w/g, (c) => c.toUpperCase());
  }

  function employeeById(id) {
    return data.employees.find((e) => Number(e.id) === Number(id));
  }
  function can(permission) {
    return new Set(data.manager?.permissions || []).has(permission);
  }
  function reviewComplete(employee) {
    return ["manager_submitted", "released"].includes(
      employee.reviewStatusCode,
    );
  }
  function reviewReady(employee) {
    return ["self_submitted", "peers_complete", "manager_submitted"].includes(
      employee.reviewStatusCode,
    );
  }
  function csvCell(value) {
    let text = String(value ?? "");
    if (/^[=+\-@]/.test(text)) text = "'" + text;
    return '"' + text.replaceAll('"', '""') + '"';
  }

  function progressMarkup(value, extraClass = "") {
    const percentage = Math.max(0, Math.min(100, Number(value) || 0));
    const classes = ["progress", extraClass].filter(Boolean).join(" ");

    return `<div class="${classes}" data-progress="${percentage}">
      <span></span>
    </div>`;
  }

  function applyDynamicMeasurements() {
    document.querySelectorAll("[data-progress]").forEach((progress) => {
      const percentage = Number(progress.dataset.progress) || 0;
      const fill = progress.querySelector("span");

      if (fill) {
        fill.style.width = `${percentage}%`;
      }
    });

    document.querySelectorAll("[data-bar-height]").forEach((bar) => {
      bar.style.height = `${Number(bar.dataset.barHeight) || 0}px`;
    });
  }

  // Convert the dashboard API response into the local page state.
  function mapDashboard(result) {
    if (result.csrfToken) window.currentCsrfToken = result.csrfToken;
    data = {
      employees: result.employees || [],
      peerNominations: result.peerNominations || [],
      goals: result.goals || [],
      pdps: result.pdps || [],
      pips: result.pips || [],
      notifications: result.notifications || [],
      feedback: result.feedback || {},
      managerRatings: result.managerRatings || {},
      competencies: result.competencies || [],
      hrOwners: result.hrOwners || [],
      personal: result.personal || {},
      manager: result.manager || {},
    };
  }

  // Refresh all manager data from the database.
  async function refresh(showToast = false) {
    try {
      const result = await request("dashboard");
      mapDashboard(result);
      renderAll();
      if (showToast) toast("Data refreshed from the database.");
    } catch (err) {
      toast(err.message);
    }
  }

  // Render the overview KPIs, team snapshot and activity feed.
  function renderOverview() {
    const performanceRows = data.employees.filter((e) => e.scope !== "Descendant");
    const ratings = data.employees
      .map((e) => e.rating)
      .filter((v) => v !== null && v !== undefined);
    const avg = ratings.reduce((a, b) => a + b, 0) / (ratings.length || 1);
    $("#kpiTeam").textContent = data.employees.length;
    $("#kpiRating").textContent = ratings.length ? avg.toFixed(1) + "/5" : "—";
    $("#kpiReviews").textContent = performanceRows.filter(
      (e) => !reviewComplete(e),
    ).length;
    const p = data.pdps.length
      ? data.pdps.reduce((a, b) => a + b.progress, 0) / data.pdps.length
      : 0;
    $("#kpiPdp").textContent = Math.round(p) + "%";

    $("#overviewTeam").innerHTML =
      data.employees
        .map((employee) => {
          const rating =
            employee.rating != null ? employee.rating.toFixed(1) : "—";
          const pdpProgress = employee.pdpActionCount ? employee.pdp : 0;
          const pdpLabel = employee.pdpActionCount
            ? `PDP ${employee.pdp}%`
            : "No PDP actions";

          return `
            <div class="employee-row">
              <div class="person">
                <div class="mini-avatar">${initials(employee.name)}</div>
                <div>
                  <strong>${esc(employee.name)}</strong>
                  <div class="muted">${esc(employee.role)} · ${esc(employee.scope || "")}</div>
                </div>
              </div>
              <div>
                <span class="status ${statusClass(employee.review)}">
                  ${esc(employee.review)}
                </span>
              </div>
              <div><strong>${rating}</strong></div>
              <div>
                ${progressMarkup(pdpProgress)}
                <div class="muted activity-time">${pdpLabel}</div>
              </div>
              <div>
                <button
                  class="btn small"
                  onclick="openEmployee(${employee.id})"
                >
                  View
                </button>
              </div>
            </div>`;
        })
        .join("") || '<div class="empty">No organization records found.</div>';

    const selfCount = performanceRows.filter(
      (e) => e.review !== "Not started",
    ).length;
    const peerCount = performanceRows.reduce(
      (n, e) => n + Number(data.feedback[e.id]?.responses || 0),
      0,
    );
    const peerRequired = performanceRows.reduce(
      (n, e) => n + Number(data.feedback[e.id]?.required || 0),
      0,
    );
    const managerCount = performanceRows.filter(reviewComplete).length;
    $("#reviewMetrics").innerHTML = [
      ["Self reviews", selfCount, Math.max(performanceRows.length, 1)],
      ["Peer feedback", peerCount, Math.max(peerRequired, 1)],
      ["Manager reviews", managerCount, Math.max(performanceRows.length, 1)],
    ]
      .map(([label, completed, total]) => {
        const percentage = Math.min(100, (completed / total) * 100);

        return `<div class="metric">
          <span>${label}</span>
          ${progressMarkup(percentage)}
          <strong>${completed}/${total}</strong>
        </div>`;
      })
      .join("");
    const deadline = data.employees.find(
      (e) => e.managerDeadline,
    )?.managerDeadline;
    if (deadline) {
      const formattedDeadline = new Date(
        deadline + "T00:00:00",
      ).toLocaleDateString();
      $("#reviewDeadlineNotice").textContent =
        `Manager deadline: ${formattedDeadline}. ` +
        "Complete pending manager reviews before the cycle can move forward.";
    } else {
      $("#reviewDeadlineNotice").textContent =
        "No manager review deadline is currently available.";
    }

    const attention = data.employees.filter((e) => e.attention).slice(0, 3);
    $("#watchlist").innerHTML =
      attention
        .map(
          (employee) => `<div class="activity-item">
            <span class="dot"></span>
            <div>
              <strong>${esc(employee.name)}</strong> needs development attention.
              <button
                class="btn small"
                onclick="openEmployee(${employee.id})"
              >
                Review
              </button>
            </div>
          </div>`,
        )
        .join("") ||
      '<div class="empty">No employees currently on the watchlist.</div>';
    $("#activityList").innerHTML =
      data.notifications
        .slice(0, 4)
        .map(
          (notification) => `<div class="activity-item">
            <span class="dot"></span>
            <div>
              ${esc(notification.text)}
              <div class="muted activity-time">${esc(notification.time)}</div>
            </div>
          </div>`,
        )
        .join("") || '<div class="empty">No current notifications.</div>';
  }

  // Render the searchable direct-reports table.
  function renderTeam() {
    const searchTerm = ($("#teamSearch")?.value || "").toLowerCase();
    const statusFilter = $("#teamFilter")?.value || "all";
    let rows = data.employees.filter((employee) =>
      employee.name.toLowerCase().includes(searchTerm),
    );

    if (statusFilter === "attention") {
      rows = rows.filter((employee) => employee.attention);
    }

    if (statusFilter === "ontrack") {
      rows = rows.filter((employee) => !employee.attention);
    }

    $("#teamTable").innerHTML =
      rows
        .map((employee) => {
          const rating =
            employee.rating != null ? employee.rating.toFixed(1) : "—";
          const goalButtons = can("manager.goals") && employee.canCreateRecords
            ? `<button
                class="btn small"
                onclick="newGoal(${employee.id})"
              >
                Goal
              </button>
              <button
                class="btn small"
                onclick="newPdp(${employee.id})"
              >
                PDP
              </button>`
            : "";

          return `<tr>
            <td>
              <div class="person">
                <div class="mini-avatar">${initials(employee.name)}</div>
                <div>
                  <strong>${esc(employee.name)}</strong>
                  <div class="muted">${esc(employee.role)} · ${esc(employee.scope || "")}</div>
                </div>
              </div>
            </td>
            <td>${esc(employee.role)}</td>
            <td>
              <span class="status ${statusClass(employee.review)}">
                ${esc(employee.review)}
              </span>
              <div class="muted">${employee.reviewManager ? `Owner: ${esc(employee.reviewManager)}` : `Reports to: ${esc(employee.directManagerName || "Top level")}`}</div>
            </td>
            <td class="score">${rating}</td>
            <td>${employee.goals}</td>
            <td>
              ${progressMarkup(employee.pdp, "team-progress")}
              <small class="muted">${employee.pdp}%</small>
            </td>
            <td>
              <div class="action-group">
                <button
                  class="btn small"
                  onclick="openEmployee(${employee.id})"
                >
                  View
                </button>
                ${goalButtons}
              </div>
            </td>
          </tr>`;
        })
        .join("") ||
      '<tr><td colspan="7" class="empty">No employees match the filter.</td></tr>';

    applyDynamicMeasurements();
  }

  // Render manager reviews, peer nominations and anonymous feedback.
  function renderReviews() {
    const filter = $("#reviewFilter")?.value || "all";
    let rows = data.employees.filter((employee) => employee.participantId);
    if (filter === "pending")
      rows = rows.filter((e) => e.review !== "Manager submitted");
    if (filter === "submitted")
      rows = rows.filter((e) => e.review === "Manager submitted");
    $("#reviewTable").innerHTML =
      rows
        .map((employee) => {
          const feedback = data.feedback[employee.id];
          const feedbackLabel = feedback?.available
            ? `${feedback.responses} submitted`
            : `${feedback?.responses || 0}/${feedback?.required || 3} required`;
          const managerStatus = reviewComplete(employee)
            ? "Complete"
            : "Pending";
          const buttonLabel = reviewComplete(employee)
            ? "View / Edit"
            : reviewReady(employee)
              ? "Review"
              : "Waiting for self-review";
          const reviewButton = can("manager.reviews")
            ? `<button
                class="btn small primary"
                onclick="openReview(${employee.id})"
                ${reviewReady(employee) ? "" : "disabled"}
              >
                ${buttonLabel}
              </button>`
            : '<span class="muted">No permission</span>';

          return `<tr>
            <td>${esc(employee.name)}<div class="muted">Review owner: ${esc(employee.reviewManager || data.manager.full_name || "—")}</div></td>
            <td>${esc(employee.cycle)}</td>
            <td>
              <span class="status ${statusClass(employee.review)}">
                ${esc(employee.review)}
              </span>
            </td>
            <td>${feedbackLabel}</td>
            <td>
              <span class="status ${reviewComplete(employee) ? "green" : "amber"}">
                ${managerStatus}
              </span>
            </td>
            <td>${reviewButton}</td>
          </tr>`;
        })
        .join("") ||
      '<tr><td colspan="6" class="empty">No review records.</td></tr>';

    $("#peerTable").innerHTML =
      data.peerNominations
        .map((nomination) => {
          const actions =
            nomination.status === "pending"
              ? `<button
                  class="btn small primary"
                  onclick="decidePeer(${nomination.id}, 'approved')"
                >
                  Approve
                </button>
                <button
                  class="btn small danger"
                  onclick="decidePeer(${nomination.id}, 'rejected')"
                >
                  Reject
                </button>`
              : '<span class="muted">Decision recorded</span>';

          return `<tr>
            <td>${esc(nomination.employee)}</td>
            <td>${esc(nomination.peer)}</td>
            <td>
              <span class="status ${statusClass(nomination.status)}">
                ${esc(nomination.status)}
              </span>
            </td>
            <td>${actions}</td>
          </tr>`;
        })
        .join("") ||
      '<tr><td colspan="4" class="empty">No peer nominations.</td></tr>';

    $("#feedbackCards").innerHTML =
      data.employees.filter((employee) => employee.participantId)
        .map((e) => {
          const f = data.feedback[e.id];
          if (!f || !f.available) {
            return `<div class="card">
              <div class="section-head">
                <div>
                  <h2>${esc(e.name)}</h2>
                  <p>Anonymous 360° results</p>
                </div>
                <span class="status amber">
                  ${f?.responses || 0}/${f?.required || 3} responses
                </span>
              </div>
              <div class="notice warn">
                Feedback stays hidden until the minimum anonymity threshold
                is reached.
              </div>
            </div>`;
          }

          const competencyRows = f.competencies
            .map(
              (competency) => `<div class="metric">
                <span>${esc(competency.name)}</span>
                ${progressMarkup((competency.score / 5) * 100)}
                <strong>${competency.score.toFixed(1)}</strong>
              </div>`,
            )
            .join("");

          return `<div class="card">
            <div class="section-head">
              <div>
                <h2>${esc(e.name)}</h2>
                <p>Aggregated peer feedback · identities hidden</p>
              </div>
              <span class="status green">${f.responses} responses</span>
            </div>
            <div class="metric-list">${competencyRows}</div>
          </div>`;
        })
        .join("") || '<div class="empty">No 360° feedback data.</div>';

    applyDynamicMeasurements();
  }

  // Render team goals and personal development actions.
  function renderGoals() {
    $("#goalsTable").innerHTML =
      data.goals
        .map(
          (goal) => `<tr>
            <td>${esc(goal.employee)}<div class="muted">Record owner: ${esc(data.manager.full_name || "—")}</div></td>
            <td>
              <strong>${esc(goal.title)}</strong>
              <div class="muted">${esc(goal.target)}</div>
            </td>
            <td>${goal.due}</td>
            <td>
              ${progressMarkup(goal.progress, "table-progress")}
              <small class="muted">${goal.progress}%</small>
            </td>
            <td>
              <span class="status ${statusClass(goal.status)}">
                ${esc(goal.status)}
              </span>
            </td>
            <td>
              <button class="btn small" onclick="editGoal(${goal.id})">
                Update
              </button>
            </td>
          </tr>`,
        )
        .join("") || '<tr><td colspan="6" class="empty">No goals.</td></tr>';
    $("#pdpTable").innerHTML =
      data.pdps
        .map(
          (action) => `<tr>
            <td>${esc(action.employee)}<div class="muted">Record owner: ${esc(data.manager.full_name || "—")}</div></td>
            <td>
              <strong>${esc(action.title)}</strong>
              <div class="muted">${esc(action.description || "")}</div>
            </td>
            <td>${action.due}</td>
            <td>
              ${progressMarkup(action.progress, "table-progress")}
              <small class="muted">${action.progress}%</small>
            </td>
            <td>
              <span class="status ${statusClass(action.status)}">
                ${esc(action.status)}
              </span>
            </td>
            <td>
              <button class="btn small" onclick="editPdp(${action.id})">
                Update
              </button>
            </td>
          </tr>`,
        )
        .join("") ||
      '<tr><td colspan="6" class="empty">No PDP actions.</td></tr>';
  }

  // Render performance improvement plans.
  function renderPips() {
    $("#pipTable").innerHTML =
      data.pips
        .map(
          (pip) => `<tr>
            <td>${esc(pip.employee)}<div class="muted">Manager: ${esc(data.manager.full_name || "—")}</div></td>
            <td>${esc(pip.reason)}</td>
            <td>${pip.start} → ${pip.end}</td>
            <td>${pip.objectives.length}</td>
            <td>
              <span class="status ${statusClass(pip.status)}">
                ${esc(pip.status)}
              </span>
            </td>
            <td>
              <button class="btn small primary" onclick="openPip(${pip.id})">
                Manage
              </button>
            </td>
          </tr>`,
        )
        .join("") || '<tr><td colspan="6" class="empty">No PIPs.</td></tr>';
  }

  // Render team performance and skill-gap reports.
  function renderReports() {
    const performanceRows = data.employees.filter((employee) => employee.scope !== "Descendant");
    const ratings = performanceRows
      .map((e) => e.rating)
      .filter((v) => v != null);
    const avg = ratings.reduce((a, b) => a + b, 0) / (ratings.length || 1);
    const goal = data.goals.length
      ? Math.round(
          data.goals.reduce((a, b) => a + b.progress, 0) / data.goals.length,
        )
      : 0;
    const gaps = performanceRows.flatMap((e) =>
      e.skills
        .filter((s) => s[2] < s[1])
        .map((s) => ({
          employee: e.name,
          skill: s[0],
          required: s[1],
          current: s[2],
          gap: s[1] - s[2],
        })),
    );
    $("#reportAvg").textContent = ratings.length ? avg.toFixed(1) : "—";
    $("#reportGoal").textContent = goal + "%";
    $("#reportGaps").textContent = gaps.length;
    $("#reportPips").textContent = data.pips.filter((p) =>
      ["active", "extended"].includes(p.status),
    ).length;
    $("#performanceChart").innerHTML = performanceRows
      .map((employee) => {
        const rating = employee.rating || 0;
        const barHeight = (rating / 5) * 150;

        return `<div class="bar">
          <strong class="chart-score">
            ${rating ? rating.toFixed(1) : "—"}
          </strong>
          <i class="chart-bar-fill" data-bar-height="${barHeight}"></i>
          <span>${esc(employee.name.split(" ")[0])}</span>
        </div>`;
      })
      .join("");
    $("#skillGapList").innerHTML =
      gaps
        .sort((a, b) => b.gap - a.gap)
        .slice(0, 8)
        .map((gap) => {
          const progress = Math.min(100, (gap.current / gap.required) * 100);

          return `<div class="metric">
            <span>
              ${esc(gap.employee.split(" ")[0])} · ${esc(gap.skill)}
            </span>
            ${progressMarkup(progress)}
            <strong>-${gap.gap}</strong>
          </div>`;
        })
        .join("") || '<div class="empty">No skill gaps found.</div>';
  }

  // Render the manager's personal review, goals and development data.
  function renderPersonal() {
    const p = data.personal || {};
    const goals = p.goals || [];
    const pdpActions = p.pdp || [];
    const activeGoals = goals.filter(
      (g) => !["Completed", "Missed"].includes(g.status),
    );
    const pdpAverage = pdpActions.length
      ? Math.round(
          pdpActions.reduce((sum, a) => sum + Number(a.progress || 0), 0) /
            pdpActions.length,
        )
      : null;
    $("#personalInitials").textContent = initials(
      data.manager.full_name || "Manager",
    );
    $("#personalName").textContent = data.manager.full_name || "Manager";
    $("#personalMeta").textContent =
      [data.manager.job_title, data.manager.department]
        .filter(Boolean)
        .join(" · ") || "Manager profile";
    $("#personalRating").textContent =
      p.review?.final_rating != null
        ? `${Number(p.review.final_rating).toFixed(1)} / 5`
        : "—";
    $("#personalGoalCount").textContent = activeGoals.length;
    $("#personalPdpProgress").textContent =
      pdpAverage == null ? "—" : `${pdpAverage}%`;
    $("#personalTasks").innerHTML = p.review
      ? `<label class="check-row">
          <input
            type="checkbox"
            ${
              [
                "self_submitted",
                "peers_complete",
                "manager_submitted",
                "released",
              ].includes(p.review.status)
                ? "checked"
                : ""
            }
            disabled
          >
          ${esc(p.review.cycle || "Current")} review:
          ${esc(titleStatus(p.review.status))}
        </label>
        <label class="check-row">
          <input
            type="checkbox"
            ${activeGoals.length === 0 ? "checked" : ""}
            disabled
          >
          ${activeGoals.length} active personal
          goal${activeGoals.length === 1 ? "" : "s"}
        </label>
        <label class="check-row">
          <input
            type="checkbox"
            ${pdpActions.length === 0 ? "checked" : ""}
            disabled
          >
          ${pdpActions.length} personal PDP
          action${pdpActions.length === 1 ? "" : "s"}
        </label>`
      : '<div class="notice">No personal review cycle is currently assigned to this manager account.</div>';
    $("#personalGoals").innerHTML = goals.length
      ? goals
          .map(
            (goal) => `<div class="personal-goal">
              <div class="personal-goal-head">
                <strong class="personal-goal-title">${esc(goal.title)}</strong>
                <span class="status ${statusClass(goal.status)}">
                  ${esc(goal.status)}
                </span>
              </div>
              <div class="muted personal-goal-meta">
                ${esc(goal.target || "")} · Due ${goal.due}
              </div>
              ${progressMarkup(goal.progress, "personal-goal-progress")}
              <div class="muted personal-goal-completion">
                ${goal.progress}% complete
              </div>
            </div>`,
          )
          .join("")
      : '<div class="empty">No personal goals have been recorded.</div>';
    const pdpEl = $("#personalPdp");
    if (pdpEl)
      pdpEl.innerHTML =
        pdpActions
          .map(
            (action) => `<div class="activity-item">
              <span class="dot"></span>
              <div>
                <strong>${esc(action.title)}</strong>
                <div class="muted">
                  ${action.progress}% · ${esc(action.status)} · due ${action.due}
                </div>
              </div>
            </div>`,
          )
          .join("") || '<div class="empty">No personal PDP actions.</div>';
    const fbEl = $("#personalFeedback");
    const feedbackMeta = p.feedbackMeta || {
      available: false,
      released: false,
      responses: 0,
      required: 3,
    };
    if (fbEl)
      fbEl.innerHTML = feedbackMeta.available
        ? (p.feedback || [])
            .map(
              (feedback) => `<div class="metric">
                <span>${esc(feedback.competency)}</span>
                ${progressMarkup((Number(feedback.avg_score) / 5) * 100)}
                <strong>${Number(feedback.avg_score).toFixed(1)}</strong>
              </div>`,
            )
            .join("") || '<div class="empty">No feedback available.</div>'
        : !feedbackMeta.released
          ? '<div class="notice">Personal feedback will appear after the review is formally released.</div>'
          : `<div class="notice warn">
              Anonymous feedback is hidden until ${feedbackMeta.required}
              peer responses are submitted
              (${feedbackMeta.responses}/${feedbackMeta.required}).
            </div>`;
  }

  // Render persisted read and unread notifications.
  function renderNotifications() {
    $("#notificationsList").innerHTML =
      data.notifications
        .map(
          (notification) => `<div
            class="activity-item notification-item ${
              notification.unread ? "" : "is-read"
            }"
          >
            <span class="dot"></span>
            <div class="notification-content">
              ${
                notification.unread
                  ? '<strong class="notification-label">New · </strong>'
                  : ""
              }
              ${esc(notification.text)}
              <div class="muted activity-time">${esc(notification.time)}</div>
            </div>
          </div>`,
        )
        .join("") || '<div class="empty">No notifications.</div>';
  }

  // Render every visible manager-dashboard section.
  function renderAll() {
    renderOverview();
    renderTeam();
    renderReviews();
    renderGoals();
    renderPips();
    renderReports();
    renderPersonal();
    renderNotifications();
    applyDynamicMeasurements();
  }

  // Open the direct-report summary dialog.
  function openEmployee(id) {
    const e = employeeById(id);
    if (!e) return;
    const gaps = e.skills.filter((s) => s[2] < s[1]);
    const reviewAction = can("manager.reviews")
      ? `<button
          class="btn"
          ${reviewReady(e) ? "" : "disabled"}
          onclick="closeModal(); showPage('reviews');
            setTimeout(() => openReview(${id}), 100)"
        >
          Review performance
        </button>`
      : "";
    const developmentAction = can("manager.goals")
      ? `<button
          class="btn primary"
          onclick="closeModal(); newPdp(${id})"
        >
          Create PDP
        </button>`
      : "";

    openModal(
      e.name,
      `<div class="profile-card modal-profile">
        <div class="profile-avatar">${initials(e.name)}</div>
        <div>
          <h2 class="profile-name">${esc(e.name)}</h2>
          <p class="muted profile-meta">${esc(e.role)} · Direct report</p>
        </div>
      </div>
      <div class="detail-grid">
        <div class="detail-box">
          <small>Latest rating</small>
          <strong>
            ${e.rating != null ? e.rating.toFixed(1) : "Not rated"}
          </strong>
        </div>
        <div class="detail-box">
          <small>Review status</small>
          <strong>${esc(e.review)}</strong>
        </div>
        <div class="detail-box">
          <small>PDP progress</small>
          <strong>${e.pdpActionCount ? `${e.pdp}%` : "No actions"}</strong>
        </div>
      </div>
      <div class="section-head">
        <div>
          <h2>Skill gaps</h2>
          <p>Required vs current level from the database.</p>
        </div>
      </div>
      <div class="metric-list">
        ${
          gaps.length
            ? gaps
                .map(
                  (skill) => `<div class="metric">
                    <span>${esc(skill[0])}</span>
                    ${progressMarkup(
                      skill[1] ? (skill[2] / skill[1]) * 100 : 0,
                    )}
                    <strong>${skill[2]}/${skill[1]}</strong>
                  </div>`,
                )
                .join("")
            : '<div class="notice">No current skill gaps.</div>'
        }
      </div>`,
      `<button class="btn" onclick="closeModal()">Close</button>
      ${reviewAction}
      ${developmentAction}`,
    );

    applyDynamicMeasurements();
  }

  // Open the manager-review form for one employee.
  function openReview(id) {
    const e = employeeById(id);
    if (!e || !e.participantId)
      return toast(
        "This employee has no review participant for the current cycle.",
      );
    if (!reviewReady(e))
      return toast(
        "The employee self-review must be submitted before the manager review.",
      );
    const existing = e.rating || 3;
    const savedRatings = Object.fromEntries(
      (data.managerRatings[e.id] || []).map((x) => [x.competencyId, x]),
    );
    const peer = data.feedback[e.id];
    const compHtml = data.competencies
      .map((c) => {
        const v = savedRatings[c.id]?.score || 3;
        const options = [1, 2, 3, 4, 5]
          .map(
            (score) => `<option
              value="${score}"
              ${score === v ? "selected" : ""}
            >
              ${score}
            </option>`,
          )
          .join("");

        return `<div class="field">
          <label>${esc(c.name)}</label>
          <select id="comp_${c.id}">${options}</select>
        </div>`;
      })
      .join("");
    const overallOptions = [1, 2, 3, 4, 5]
      .map(
        (score) => `<option
          value="${score}"
          ${Math.round(existing) === score ? "selected" : ""}
        >
          ${score}
        </option>`,
      )
      .join("");
    const peerMessage = peer?.available
      ? `${peer.responses} peer responses are available.`
      : `Peer results are hidden until ${peer?.required || 3} responses are submitted.`;

    openModal(
      "Manager Review — " + e.name,
      `<div class="notice modal-notice">
        Peer feedback is aggregated and anonymous. ${peerMessage}
      </div>
      <div class="form-grid">
        <div class="field">
          <label>Overall rating (1–5)</label>
          <select id="reviewRating">${overallOptions}</select>
        </div>
        <div class="field">
          <label>Review status</label>
          <input value="Manager review" disabled>
        </div>
        ${compHtml}
        <div class="field full">
          <label>Manager summary</label>
          <textarea
            id="reviewSummary"
            placeholder="Summarise strengths, improvement areas and expectations..."
          >${esc(e.managerSummary || "")}</textarea>
        </div>
      </div>`,
      `<button class="btn" onclick="closeModal()">Cancel</button>
      <button class="btn primary" onclick="submitReview(${id})">
        ${reviewComplete(e) ? "Save review changes" : "Submit manager review"}
      </button>`,
    );
  }

  // Submit manager ratings and summary text.
  async function submitReview(id) {
    const e = employeeById(id);
    if (!e) return;
    const competencies = data.competencies.map((c) => ({
      competencyId: c.id,
      score: Number($("#comp_" + c.id)?.value || 0),
      comment: "",
    }));
    try {
      await request("submit_review", {
        method: "POST",
        body: JSON.stringify({
          participantId: e.participantId,
          rating: Number($("#reviewRating").value),
          summary: $("#reviewSummary").value.trim(),
          competencies,
        }),
      });
      closeModal();
      await refresh();
      toast("Manager review saved to the database.");
    } catch (err) {
      toast(err.message);
    }
  }

  // Approve or reject a proposed peer reviewer.
  async function decidePeer(id, status) {
    try {
      await request("decide_peer", {
        method: "POST",
        body: JSON.stringify({ id, status }),
      });
      await refresh();
      toast(`Peer nomination ${status}.`);
    } catch (err) {
      toast(err.message);
    }
  }

  // Open the team-goal form.
  function newGoal(employeeId) {
    const directReports = data.employees.filter((employee) => employee.canCreateRecords);
    if (!directReports.length)
      return toast("No direct reports are available for a team goal.");
    const selectedEmployee = employeeById(employeeId);
    const employeeOptions = directReports
      .map(
        (employee) => `<option
          value="${employee.id}"
          ${selectedEmployee?.id === employee.id ? "selected" : ""}
        >
          ${esc(employee.name)}
        </option>`,
      )
      .join("");

    openModal(
      "Create team goal",
      `<div class="form-grid">
        <div class="field">
          <label>Employee</label>
          <select id="goalEmployee">${employeeOptions}</select>
        </div>
        <div class="field">
          <label>Due date</label>
          <input id="goalDue" type="date">
        </div>
        <div class="field full">
          <label>Goal title</label>
          <input
            id="goalTitle"
            placeholder="e.g. Improve delivery reliability"
          >
        </div>
        <div class="field full">
          <label>Metric / target</label>
          <textarea
            id="goalTarget"
            placeholder="Define a measurable outcome"
          ></textarea>
        </div>
      </div>`,
      `<button class="btn" onclick="closeModal()">Cancel</button>
      <button class="btn primary" onclick="saveGoal()">Create goal</button>`,
    );
  }

  // Save a new team goal.
  async function saveGoal() {
    try {
      await request("create_goal", {
        method: "POST",
        body: JSON.stringify({
          employeeId: Number($("#goalEmployee").value),
          title: $("#goalTitle").value.trim(),
          target: $("#goalTarget").value.trim(),
          due: $("#goalDue").value,
        }),
      });
      closeModal();
      await refresh();
      toast("Goal saved to the database.");
    } catch (err) {
      toast(err.message);
    }
  }

  // Open the goal-progress form.
  function editGoal(id) {
    const g = data.goals.find((x) => x.id === id);
    if (!g) return;
    const statusOptions = ["Not Started", "In Progress", "Completed", "Missed"]
      .map(
        (status) => `<option ${g.status === status ? "selected" : ""}>
          ${status}
        </option>`,
      )
      .join("");

    openModal(
      "Update goal",
      `<div class="form-grid">
        <div class="field full">
          <label>Goal</label>
          <input id="editGoalTitle" value="${esc(g.title)}">
        </div>
        <div class="field">
          <label>Progress (%)</label>
          <input
            id="editGoalProgress"
            type="number"
            min="0"
            max="100"
            value="${g.progress}"
          >
        </div>
        <div class="field">
          <label>Status</label>
          <select id="editGoalStatus">${statusOptions}</select>
        </div>
      </div>`,
      `<button class="btn" onclick="closeModal()">Cancel</button>
      <button class="btn primary" onclick="saveGoalUpdate(${id})">
        Save changes
      </button>`,
    );
  }
  // Save changes to an existing goal.
  async function saveGoalUpdate(id) {
    try {
      await request("update_goal", {
        method: "POST",
        body: JSON.stringify({
          id,
          title: $("#editGoalTitle").value.trim(),
          progress: Number($("#editGoalProgress").value),
          status: $("#editGoalStatus").value,
        }),
      });
      closeModal();
      await refresh();
      toast("Goal updated in the database.");
    } catch (err) {
      toast(err.message);
    }
  }

  // Open the manager's personal-goal form.
  function newPersonalGoal() {
    openModal(
      "Create personal goal",
      `<div class="form-grid">
        <div class="field full">
          <label>Goal title</label>
          <input
            id="personalGoalTitle"
            placeholder="e.g. Improve coaching cadence"
          >
        </div>
        <div class="field full">
          <label>Metric / target</label>
          <textarea
            id="personalGoalTarget"
            placeholder="Define a measurable outcome"
          ></textarea>
        </div>
        <div class="field">
          <label>Due date</label>
          <input id="personalGoalDue" type="date">
        </div>
      </div>`,
      `<button class="btn" onclick="closeModal()">Cancel</button>
      <button class="btn primary" onclick="savePersonalGoal()">
        Create goal
      </button>`,
    );
  }
  // Save the manager's personal goal.
  async function savePersonalGoal() {
    try {
      await request("create_goal", {
        method: "POST",
        body: JSON.stringify({
          employeeId: Number(data.manager.id),
          title: $("#personalGoalTitle").value.trim(),
          target: $("#personalGoalTarget").value.trim(),
          due: $("#personalGoalDue").value,
        }),
      });
      closeModal();
      await refresh();
      toast("Personal goal saved to the database.");
    } catch (err) {
      toast(err.message);
    }
  }

  // Open the personal-development action form.
  function newPdp(employeeId) {
    const directReports = data.employees.filter((employee) => employee.canCreateRecords);
    if (!directReports.length)
      return toast("No direct reports are available for a PDP.");
    const selectedEmployee = employeeById(employeeId);
    const employeeOptions = directReports
      .map(
        (employee) => `<option
          value="${employee.id}"
          ${selectedEmployee?.id === employee.id ? "selected" : ""}
        >
          ${esc(employee.name)}
        </option>`,
      )
      .join("");

    openModal(
      "Create PDP action",
      `<div class="form-grid">
        <div class="field">
          <label>Employee</label>
          <select id="pdpEmployee">${employeeOptions}</select>
        </div>
        <div class="field">
          <label>Due date</label>
          <input id="pdpDue" type="date">
        </div>
        <div class="field full">
          <label>Development action</label>
          <input id="pdpTitle" placeholder="e.g. Complete PHP OOP course">
        </div>
        <div class="field full">
          <label>Action description</label>
          <textarea
            id="pdpDescription"
            placeholder="Steps, evidence and expected outcome"
          ></textarea>
        </div>
      </div>`,
      `<button class="btn" onclick="closeModal()">Cancel</button>
      <button class="btn primary" onclick="savePdp()">
        Create PDP action
      </button>`,
    );
  }
  // Save a new personal-development action.
  async function savePdp() {
    try {
      await request("create_pdp", {
        method: "POST",
        body: JSON.stringify({
          employeeId: Number($("#pdpEmployee").value),
          title: $("#pdpTitle").value.trim(),
          description: $("#pdpDescription").value.trim(),
          due: $("#pdpDue").value,
        }),
      });
      closeModal();
      await refresh();
      toast("PDP action saved to the database.");
    } catch (err) {
      toast(err.message);
    }
  }

  // Open the development-progress form.
  function editPdp(id) {
    const p = data.pdps.find((x) => x.id === id);
    if (!p) return;
    const statusOptions = [
      "Not Started",
      "In Progress",
      "Completed",
      "Overdue",
      "Cancelled",
    ]
      .map(
        (status) => `<option ${p.status === status ? "selected" : ""}>
          ${status}
        </option>`,
      )
      .join("");

    openModal(
      "Update PDP action",
      `<div class="form-grid">
        <div class="field full">
          <label>Action</label>
          <input id="editPdpTitle" value="${esc(p.title)}">
        </div>
        <div class="field">
          <label>Progress (%)</label>
          <input
            id="editPdpProgress"
            type="number"
            min="0"
            max="100"
            value="${p.progress}"
          >
        </div>
        <div class="field">
          <label>Status</label>
          <select id="editPdpStatus">${statusOptions}</select>
        </div>
        <div class="field full">
          <label>Progress note</label>
          <textarea
            id="editPdpNote"
            placeholder="Add a manager progress note"
          ></textarea>
        </div>
      </div>`,
      `<button class="btn" onclick="closeModal()">Cancel</button>
      <button class="btn primary" onclick="savePdpUpdate(${id})">
        Save progress
      </button>`,
    );
  }
  // Save progress on an existing development action.
  async function savePdpUpdate(id) {
    try {
      await request("update_pdp", {
        method: "POST",
        body: JSON.stringify({
          id,
          title: $("#editPdpTitle").value.trim(),
          progress: Number($("#editPdpProgress").value),
          status: $("#editPdpStatus").value,
          note: $("#editPdpNote").value.trim(),
        }),
      });
      closeModal();
      await refresh();
      toast("PDP progress saved to the database.");
    } catch (err) {
      toast(err.message);
    }
  }

  // Open the performance-improvement plan form.
  function newPip() {
    const directReports = data.employees.filter((employee) => employee.canCreateRecords);
    if (!directReports.length)
      return toast("No direct reports are available for a PIP.");
    if (!data.hrOwners.length)
      return toast(
        "An active HR owner is required before a PIP can be created.",
      );
    const employeeOptions = directReports
      .map(
        (employee) => `<option value="${employee.id}">
          ${esc(employee.name)}
        </option>`,
      )
      .join("");
    const hrOptions = data.hrOwners
      .map(
        (owner) => `<option value="${owner.id}">
          ${esc(owner.name)}
        </option>`,
      )
      .join("");

    openModal(
      "Start Performance Improvement Plan",
      `<div class="notice warn modal-notice compact">
        Every PIP must have an HR owner and measurable success criteria.
      </div>
      <div class="form-grid">
        <div class="field">
          <label>Employee</label>
          <select id="pipEmployee">${employeeOptions}</select>
        </div>
        <div class="field">
          <label>HR owner</label>
          <select id="pipHr">${hrOptions}</select>
        </div>
        <div class="field">
          <label>Start date</label>
          <input id="pipStart" type="date">
        </div>
        <div class="field">
          <label>End date</label>
          <input id="pipEnd" type="date">
        </div>
        <div class="field full">
          <label>Reason</label>
          <textarea
            id="pipReason"
            placeholder="Document the performance issue clearly"
          ></textarea>
        </div>
        <div class="field full">
          <label>First measurable objective</label>
          <input
            id="pipObjective"
            placeholder="e.g. Meet 90% of sprint commitments"
          >
        </div>
        <div class="field full">
          <label>Measurable success criteria</label>
          <textarea
            id="pipCriteria"
            placeholder="How will achievement be objectively measured?"
          ></textarea>
        </div>
        <div class="field">
          <label>Objective due date</label>
          <input id="pipObjectiveDue" type="date">
        </div>
      </div>`,
      `<button class="btn" onclick="closeModal()">Cancel</button>
      <button class="btn primary" onclick="savePip()">Create PIP</button>`,
    );
  }
  // Save a new performance-improvement plan.
  async function savePip() {
    try {
      await request("create_pip", {
        method: "POST",
        body: JSON.stringify({
          employeeId: Number($("#pipEmployee").value),
          hrOwnerId: Number($("#pipHr").value),
          start: $("#pipStart").value,
          end: $("#pipEnd").value,
          reason: $("#pipReason").value.trim(),
          objective: $("#pipObjective").value.trim(),
          criteria: $("#pipCriteria").value.trim(),
          objectiveDue: $("#pipObjectiveDue").value,
        }),
      });
      closeModal();
      await refresh();
      toast("PIP saved to the database.");
    } catch (err) {
      toast(err.message);
    }
  }

  // Open an existing performance-improvement plan.
  function openPip(id) {
    const p = data.pips.find((x) => x.id === id);
    if (!p) return;
    const locked = ["successful", "unsuccessful", "closed"].includes(p.status);
    const objectiveRows = p.objectives
      .map((objective) => {
        const statusColour =
          objective.status === "met"
            ? "green"
            : objective.status === "partially_met"
              ? "amber"
              : "red";
        const actionButtons = locked
          ? ""
          : `<div class="toolbar objective-actions">
              <button
                class="btn small"
                onclick="setObjective(${objective.id}, 'not_met', ${id})"
              >
                Not met
              </button>
              <button
                class="btn small"
                onclick="setObjective(
                  ${objective.id},
                  'partially_met',
                  ${id}
                )"
              >
                Partially met
              </button>
              <button
                class="btn small"
                onclick="setObjective(${objective.id}, 'met', ${id})"
              >
                Met
              </button>
            </div>`;

        return `<div class="notice">
          <div class="objective-head">
            <strong>${esc(objective.text)}</strong>
            <span class="status ${statusColour}">
              ${esc(objective.status.replace("_", " "))}
            </span>
          </div>
          <div class="muted objective-meta">
            ${esc(objective.criteria || "")}
          </div>
          <div class="muted objective-meta">Due ${objective.due || "—"}</div>
          ${actionButtons}
        </div>`;
      })
      .join("");
    const checkinRows = p.checkins
      .map(
        (checkin) => `<div class="activity-item">
          <span class="dot"></span>
          <div>
            <strong>${esc(checkin.date)}</strong>
            <div>${esc(checkin.notes)}</div>
          </div>
        </div>`,
      )
      .join("");
    const addObjectiveButton = locked
      ? ""
      : `<button class="btn small" onclick="addPipObjective(${id})">
          + Objective
        </button>`;
    const addCheckinButton = locked
      ? ""
      : `<button class="btn small" onclick="addPipCheckin(${id})">
          + Check-in
        </button>`;

    openModal(
      "Manage PIP — " + p.employee,
      `<div class="detail-grid">
        <div class="detail-box">
          <small>Status</small>
          <strong>${esc(p.status)}</strong>
        </div>
        <div class="detail-box">
          <small>Period</small>
          <strong>${p.start} → ${p.end}</strong>
        </div>
        <div class="detail-box">
          <small>HR owner</small>
          <strong>${esc(p.hrOwner)}</strong>
        </div>
        <div class="detail-box">
          <small>Objectives</small>
          <strong>${p.objectives.length}</strong>
        </div>
      </div>
      <div class="section-head">
        <div>
          <h2>Objectives</h2>
          <p>Success criteria must be measurable.</p>
        </div>
        ${addObjectiveButton}
      </div>
      <div class="metric-list">
        ${objectiveRows || '<div class="empty">No objectives.</div>'}
      </div>
      <div class="section-head">
        <div>
          <h2>Check-ins</h2>
          <p>Record weekly or biweekly manager notes.</p>
        </div>
        ${addCheckinButton}
      </div>
      <div class="activity">
        ${checkinRows || '<div class="empty">No check-ins recorded.</div>'}
      </div>`,
      `<button class="btn" onclick="closeModal()">Close</button>
      ${
        p.status === "closed"
          ? ""
          : `<button
              class="btn primary"
              onclick="changePipStatus(${id})"
            >
              Update outcome
            </button>`
      }`,
    );
  }

  // Add a measurable objective to an existing PIP.
  function addPipObjective(id) {
    openModal(
      "Add PIP objective",
      `<div class="form-grid">
        <div class="field full">
          <label>Objective</label>
          <input id="newPipObjective">
        </div>
        <div class="field full">
          <label>Measurable success criteria</label>
          <textarea id="newPipCriteria"></textarea>
        </div>
        <div class="field">
          <label>Due date</label>
          <input id="newPipDue" type="date">
        </div>
      </div>`,
      `<button class="btn" onclick="closeModal()">Cancel</button>
      <button class="btn primary" onclick="savePipObjective(${id})">
        Add objective
      </button>`,
    );
  }
  // Save a new PIP objective.
  async function savePipObjective(id) {
    try {
      await request("add_pip_objective", {
        method: "POST",
        body: JSON.stringify({
          pipId: id,
          objective: $("#newPipObjective").value.trim(),
          criteria: $("#newPipCriteria").value.trim(),
          due: $("#newPipDue").value,
        }),
      });
      closeModal();
      await refresh();
      openPip(id);
    } catch (err) {
      toast(err.message);
    }
  }
  // Update whether a PIP objective has been met.
  async function setObjective(id, status, pipId) {
    try {
      await request("update_pip_objective", {
        method: "POST",
        body: JSON.stringify({ id, status }),
      });
      await refresh();
      openPip(pipId);
      toast("PIP objective updated.");
    } catch (err) {
      toast(err.message);
    }
  }
  // Open the PIP check-in form.
  function addPipCheckin(id) {
    openModal(
      "Record PIP check-in",
      `<div class="form-grid">
        <div class="field">
          <label>Check-in date</label>
          <input
            id="checkinDate"
            type="date"
            value="${new Date().toISOString().slice(0, 10)}"
          >
        </div>
        <div class="field full">
          <label>Manager notes</label>
          <textarea
            id="checkinNotes"
            placeholder="Record progress, blockers, evidence and next steps"
          ></textarea>
        </div>
      </div>`,
      `<button class="btn" onclick="closeModal()">Cancel</button>
      <button class="btn primary" onclick="savePipCheckin(${id})">
        Record check-in
      </button>`,
    );
  }
  // Save a manager PIP check-in.
  async function savePipCheckin(id) {
    try {
      await request("add_pip_checkin", {
        method: "POST",
        body: JSON.stringify({
          pipId: id,
          date: $("#checkinDate").value,
          notes: $("#checkinNotes").value.trim(),
        }),
      });
      closeModal();
      await refresh();
      openPip(id);
    } catch (err) {
      toast(err.message);
    }
  }
  // Open the PIP outcome form with valid workflow transitions.
  function changePipStatus(id) {
    const p = data.pips.find((x) => x.id === id);
    if (!p) return;
    const allowed = {
      draft: ["draft", "active", "closed"],
      active: ["active", "extended", "successful", "unsuccessful", "closed"],
      extended: ["extended", "successful", "unsuccessful", "closed"],
      successful: ["successful", "closed"],
      unsuccessful: ["unsuccessful", "closed"],
      closed: ["closed"],
    }[p.status] || [p.status];
    const statusOptions = allowed
      .map(
        (status) => `<option ${p.status === status ? "selected" : ""}>
          ${status}
        </option>`,
      )
      .join("");

    openModal(
      "Update PIP outcome",
      `<div class="form-grid">
        <div class="field">
          <label>Status</label>
          <select id="pipStatus">${statusOptions}</select>
        </div>
        <div class="field full">
          <label>Outcome note</label>
          <textarea
            id="pipOutcomeNote"
            placeholder="Required when extending, completing or closing a PIP"
          ></textarea>
        </div>
      </div>`,
      `<button class="btn" onclick="closeModal()">Cancel</button>
      <button class="btn primary" onclick="savePipStatus(${id})">
        Save outcome
      </button>`,
    );
  }
  // Save a PIP workflow outcome.
  async function savePipStatus(id) {
    try {
      await request("update_pip_status", {
        method: "POST",
        body: JSON.stringify({
          id,
          status: $("#pipStatus").value,
          note: $("#pipOutcomeNote").value.trim(),
        }),
      });
      closeModal();
      await refresh();
      toast("PIP outcome saved to the database.");
    } catch (err) {
      toast(err.message);
    }
  }

  // Generate a plain-language manager report from the current data.
  function generateReport() {
    const performanceRows = data.employees.filter((employee) => employee.scope !== "Descendant");
    const directReports = data.employees.filter((employee) => employee.scope === "Direct report");
    const ratings = performanceRows
      .map((e) => e.rating)
      .filter((v) => v != null);
    const avg = ratings.reduce((a, b) => a + b, 0) / (ratings.length || 1);
    const goal = data.goals.length
      ? Math.round(
          data.goals.reduce((a, b) => a + b.progress, 0) / data.goals.length,
        )
      : 0;
    const active = data.pips.filter((p) =>
      ["active", "extended"].includes(p.status),
    ).length;
    const ratingLabel = ratings.length
      ? `${avg.toFixed(1)}/5`
      : "No ratings available";
    const pendingReviews = performanceRows.filter(
      (employee) => employee.participantId && !reviewComplete(employee),
    ).length;

    $("#reportOutput").innerHTML = `
      <strong>
        Team performance report generated — ${new Date().toLocaleDateString()}
      </strong>
      <br><br>
      Average available rating: <strong>${ratingLabel}</strong><br>
      Average goal progress: <strong>${goal}%</strong><br>
      Direct reports: <strong>${directReports.length}</strong><br>
      Active/extended PIPs: <strong>${active}</strong><br>
      Pending manager reviews: <strong>${pendingReviews}</strong>`;
    toast("Report generated from live database data.");
  }
  // Export the current team snapshot as a safe CSV file.
  function exportCSV() {
    const header = "Employee,Role,Rating,Review Status,Goals,PDP Progress\n";
    const body = data.employees.filter((employee) => employee.scope !== "Descendant")
      .map((e) =>
        [e.name, e.role, e.rating ?? "", e.review, e.goals, e.pdp]
          .map(csvCell)
          .join(","),
      )
      .join("\n");
    const blob = new Blob(["\uFEFF" + header + body], {
      type: "text/csv;charset=utf-8",
    });
    const downloadLink = document.createElement("a");
    downloadLink.href = URL.createObjectURL(blob);
    downloadLink.download = "manager-team-performance.csv";
    downloadLink.click();
    setTimeout(() => URL.revokeObjectURL(downloadLink.href), 0);
  }

  // Persist the manager's read-notification state.
  async function markNotifications() {
    const ids = data.notifications.filter((n) => n.unread).map((n) => n.id);
    if (!ids.length) return toast("There are no unread notifications.");
    try {
      await request("mark_notifications_read", {
        method: "POST",
        body: JSON.stringify({ ids }),
      });
      await refresh();
      toast("Notifications marked as read.");
    } catch (err) {
      toast(err.message);
    }
  }

  // Connect manager buttons and filters to their functions.
  function wire() {
    $("#generateReportBtn").onclick = generateReport;
    $("#exportTeamBtn").onclick = exportCSV;
    $("#printReportBtn").onclick = () => window.print();
    $("#refreshFeedback").onclick = () => refresh(true);
    $("#markNotifications").onclick = markNotifications;
    $("#logoutBtn").onclick = async () => {
      if (confirm("Sign out of the manager dashboard?")) {
        await request("logout", { method: "POST" }).catch(() => {});
        window.location.href = "index.html";
      }
    };
    $("#personalGoalBtn").onclick = newPersonalGoal;
    $("#newGoalBtn").onclick = () => newGoal();
    $("#newPdpBtn").onclick = () => newPdp();
    $("#newPipBtn").onclick = newPip;
    $("#addGoalFromTeam").onclick = () => newGoal();
    $("#teamSearch").addEventListener("input", renderTeam);
    $("#teamFilter").addEventListener("change", renderTeam);
    $("#reviewFilter").addEventListener("change", renderReviews);
    $("#quickActionBtn").onclick = () => {
      const goalActions = can("manager.goals")
        ? `<button class="btn" onclick="closeModal(); newGoal()">
            Create goal
          </button>
          <button class="btn" onclick="closeModal(); newPdp()">
            Create PDP
          </button>`
        : "";
      const pipAction = can("manager.pips")
        ? `<button class="btn" onclick="closeModal(); newPip()">
            Start PIP
          </button>`
        : "";
      const reviewAction = can("manager.reviews")
        ? `<button class="btn" onclick="closeModal(); showPage('reviews')">
            Open reviews
          </button>`
        : "";
      const reportAction = can("manager.reports")
        ? `<button class="btn" onclick="closeModal(); showPage('reports')">
            Team report
          </button>`
        : "";

      openModal(
        "Quick actions",
        `<div class="grid three">
          ${goalActions}
          ${pipAction}
          ${reviewAction}
          ${reportAction}
          <button class="btn" onclick="closeModal(); showPage('personal')">
            My dashboard
          </button>
        </div>`,
        '<button class="btn" onclick="closeModal()">Close</button>',
      );
    };
  }

  // Expose DB-backed functions used by generated action buttons.
  Object.assign(window, {
    openEmployee,
    openReview,
    submitReview,
    decidePeer,
    newGoal,
    saveGoal,
    editGoal,
    saveGoalUpdate,
    newPdp,
    savePdp,
    editPdp,
    savePdpUpdate,
    newPip,
    savePip,
    openPip,
    addPipObjective,
    savePipObjective,
    setObjective,
    addPipCheckin,
    savePipCheckin,
    changePipStatus,
    savePipStatus,
    generateReport,
    exportCSV,
    renderAll,
    renderTeam,
    renderReviews,
  });
  // Keep global bindings for navigation and modal buttons.
  globalThis.renderAll = renderAll;
  globalThis.renderTeam = renderTeam;
  globalThis.renderReviews = renderReviews;
  globalThis.openEmployee = openEmployee;
  globalThis.openReview = openReview;
  globalThis.submitReview = submitReview;
  globalThis.decidePeer = decidePeer;
  globalThis.newGoal = newGoal;
  globalThis.saveGoal = saveGoal;
  globalThis.editGoal = editGoal;
  globalThis.saveGoalUpdate = saveGoalUpdate;
  globalThis.newPdp = newPdp;
  globalThis.newPersonalGoal = newPersonalGoal;
  globalThis.savePersonalGoal = savePersonalGoal;
  globalThis.savePdp = savePdp;
  globalThis.editPdp = editPdp;
  globalThis.savePdpUpdate = savePdpUpdate;
  globalThis.newPip = newPip;
  globalThis.savePip = savePip;
  globalThis.openPip = openPip;
  globalThis.addPipObjective = addPipObjective;
  globalThis.savePipObjective = savePipObjective;
  globalThis.setObjective = setObjective;
  globalThis.addPipCheckin = addPipCheckin;
  globalThis.savePipCheckin = savePipCheckin;
  globalThis.changePipStatus = changePipStatus;
  globalThis.savePipStatus = savePipStatus;

  // Load the manager dashboard after every event handler is ready.
  (async function init() {
    wire();
    await refresh();
  })();
})();
