(function () {
  // Shared chrome for the HR and administrator workspaces. The manager
  // dashboard keeps its own inline copy, so nothing there changes.
  const API = "api.php?action=";
  const $ = (selector) => document.querySelector(selector);
  const $$ = (selector) => [...document.querySelectorAll(selector)];

  // Send a request and attach CSRF protection to database-changing actions.
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

  // Text helpers used by the generated table rows.
  function esc(value) {
    return String(value ?? "").replace(
      /[&<>'"]/g,
      (character) =>
        ({
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          "'": "&#39;",
          '"': "&quot;",
        })[character],
    );
  }

  function titleCase(value) {
    return String(value ?? "")
      .replaceAll("_", " ")
      .replace(/\b\w/g, (character) => character.toUpperCase());
  }

  function statusClass(value) {
    const text = String(value ?? "").toLowerCase();
    // Negative states are tested first: "unsuccessful" contains "successful"
    // and "inactive" contains "active".
    if (
      /reject|overdue|unsuccessful|blocked|denied|inactive|failed|not_met|missed|upheld/.test(
        text,
      )
    )
      return "red";
    if (/complete|approved|successful|released|overturned|^met$|^active$/.test(text))
      return "green";
    if (/pending|draft|progress|extended|not_started|open|partially/.test(text))
      return "amber";
    return "blue";
  }

  function statusTag(value) {
    if (value === null || value === undefined || value === "") return "—";
    return `<span class="status ${statusClass(value)}">${esc(titleCase(value))}</span>`;
  }

  function fmtDate(value) {
    if (!value) return "—";
    const date = new Date(String(value).replace(" ", "T"));
    if (Number.isNaN(date.getTime())) return esc(value);
    return date.toLocaleDateString(undefined, {
      year: "numeric",
      month: "short",
      day: "numeric",
    });
  }

  function fmtDateTime(value) {
    if (!value) return "—";
    const date = new Date(String(value).replace(" ", "T"));
    if (Number.isNaN(date.getTime())) return esc(value);
    return date.toLocaleString(undefined, {
      dateStyle: "medium",
      timeStyle: "short",
    });
  }

  function localDateValue(date = new Date()) {
    const local = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
    return local.toISOString().slice(0, 10);
  }

  // Progress bars are sized after render so no inline width is generated.
  function progressMarkup(value) {
    const percentage = Math.max(0, Math.min(100, Number(value) || 0));
    return `<div class="progress" data-progress="${percentage}"><span></span></div>`;
  }

  function applyDynamicMeasurements(root = document) {
    root.querySelectorAll("[data-progress]").forEach((bar) => {
      const fill = bar.querySelector("span");
      if (fill) fill.style.width = `${Number(bar.dataset.progress) || 0}%`;
    });
  }

  function metricRow(label, percentage, value) {
    return `<div class="metric"><span>${esc(label)}</span>${progressMarkup(
      percentage,
    )}<strong>${esc(value)}</strong></div>`;
  }

  function emptyRow(columns, message) {
    return `<tr><td colspan="${columns}" class="muted">${esc(message)}</td></tr>`;
  }

  function toast(message) {
    const host = $("#toast");
    if (!host) return;
    host.textContent = message;
    host.classList.add("show");
    clearTimeout(window.dashboardToastTimer);
    window.dashboardToastTimer = setTimeout(
      () => host.classList.remove("show"),
      3000,
    );
  }

  // Shared modal controls.
  function openModal(title, body, footer = "") {
    $("#modalTitle").textContent = title;
    $("#modalBody").innerHTML = body;
    $("#modalFoot").innerHTML = footer;
    $("#modalBackdrop").classList.add("open");
    setTimeout(
      () =>
        $("#modalBody").querySelector("input,select,textarea,button")?.focus(),
      0,
    );
  }

  function closeModal() {
    $("#modalBackdrop")?.classList.remove("open");
  }

  // Navigation between the dashboard sections.
  function setupNavigation(pageMeta, onChange) {
    const apply = (page) => {
      $$(".nav-btn[data-page]").forEach((button) =>
        button.classList.toggle("active", button.dataset.page === page),
      );
      $$(".page").forEach((section) =>
        section.classList.toggle("active", section.id === "page-" + page),
      );
      const meta = pageMeta[page];
      if (meta) {
        $("#pageTitle").textContent = meta[0];
        $("#pageSubtitle").textContent = meta[1];
      }
      window.scrollTo({ top: 0, behavior: "smooth" });
      if (typeof onChange === "function") onChange(page);
    };
    $$(".nav-btn[data-page]").forEach((button) =>
      button.addEventListener("click", () => apply(button.dataset.page)),
    );
    $$("[data-page-jump]").forEach((button) =>
      button.addEventListener("click", () => apply(button.dataset.pageJump)),
    );
    $$(".tab").forEach((button) =>
      button.addEventListener("click", () => {
        const card = button.closest(".card");
        card.querySelectorAll(".tab").forEach((t) => t.classList.remove("active"));
        card
          .querySelectorAll(".tab-panel")
          .forEach((panel) => panel.classList.remove("active"));
        button.classList.add("active");
        card.querySelector("#" + button.dataset.tab)?.classList.add("active");
      }),
    );
    window.showPage = apply;
    return apply;
  }

  // Export the rows currently on screen.
  function downloadCsv(filename, header, rows) {
    const cell = (value) => {
      let text = String(value ?? "");
      if (/^[=+\-@]/.test(text)) text = "'" + text;
      return '"' + text.replaceAll('"', '""') + '"';
    };
    const csv = [header, ...rows]
      .map((row) => row.map(cell).join(","))
      .join("\r\n");
    const link = document.createElement("a");
    link.href = URL.createObjectURL(
      new Blob(["\ufeff" + csv], { type: "text/csv;charset=utf-8;" }),
    );
    link.download = filename;
    link.click();
    setTimeout(() => URL.revokeObjectURL(link.href), 1000);
  }

  // Hide any control the signed-in role cannot use. auth.js does this once on
  // load; generated markup needs it again after every render.
  function applyPermissions(root = document) {
    const permissions = new Set(window.currentAuthUser?.permissions || []);
    root.querySelectorAll("[data-permission]").forEach((element) => {
      const required = String(element.dataset.permission || "")
        .split(",")
        .map((code) => code.trim())
        .filter(Boolean);
      element.hidden =
        required.length > 0 && !required.some((code) => permissions.has(code));
    });
  }

  function can(permission) {
    return (window.currentAuthUser?.permissions || []).includes(permission);
  }

  // Wire the modal once the shared markup is present.
  function wireModal() {
    const backdrop = $("#modalBackdrop");
    if (!backdrop) return;
    $("#modalClose").onclick = closeModal;
    backdrop.addEventListener("click", (event) => {
      if (event.target.id === "modalBackdrop") closeModal();
    });
    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && backdrop.classList.contains("open"))
        closeModal();
    });
  }
  wireModal();

  Object.assign(window, {
    $,
    $$,
    request,
    esc,
    titleCase,
    statusClass,
    statusTag,
    fmtDate,
    fmtDateTime,
    localDateValue,
    emptyRow,
    progressMarkup,
    applyDynamicMeasurements,
    metricRow,
    toast,
    openModal,
    closeModal,
    setupNavigation,
    downloadCsv,
    applyPermissions,
    can,
  });
})();
