<?php
declare(strict_types=1);

/**
 * HR workspace services.
 *
 * HR reads across the organization but writes narrowly. PIP writes still
 * require both `hr.pips` and explicit `hr_owner_id` ownership, and escalation
 * decisions require `hr.cases`. Every write is audited.
 */

function hr_permissions(array $user): array
{
    return $user["permissions"] ?? permissions_for_role($user["role"]);
}

function hr_can(array $user, string $permission): bool
{
    return in_array($permission, hr_permissions($user), true);
}

/** Headline counts for the HR overview page. */
function hr_overview_metrics(): array
{
    $pdo = db();
    $scalar = static fn(string $sql): int => (int) $pdo->query($sql)->fetchColumn();
    return [
        "headcount" => $scalar("SELECT COUNT(*) FROM users WHERE is_active=1"),
        "inactive" => $scalar("SELECT COUNT(*) FROM users WHERE is_active=0"),
        "activePips" => $scalar(
            "SELECT COUNT(*) FROM pips WHERE status IN ('active','extended')",
        ),
        "draftPips" => $scalar("SELECT COUNT(*) FROM pips WHERE status='draft'"),
        "openCases" => $scalar(
            "SELECT COUNT(*) FROM peer_nomination_escalations WHERE status='pending_hr'",
        ),
        "openCycles" => $scalar(
            "SELECT COUNT(*) FROM review_cycles WHERE status NOT IN ('closed','draft')",
        ),
        "pendingFeedback" => $scalar(
            "SELECT COUNT(*) FROM feedback_requests fr
             JOIN review_participants rp ON rp.id=fr.participant_id
             JOIN review_cycles rc ON rc.id=rp.cycle_id
             WHERE fr.status='pending' AND rc.status NOT IN ('closed','released')",
        ),
        "overdueDevelopment" => $scalar(
            "SELECT COUNT(*) FROM pdp_actions
             WHERE status NOT IN ('completed','cancelled') AND due_date < CURRENT_DATE",
        ),
    ];
}

/** Escalated peer-nomination decisions awaiting or holding an HR outcome. */
function hr_cases(string $scope = "open"): array
{
    $where = $scope === "resolved" ? "e.status <> 'pending_hr'" : "e.status = 'pending_hr'";
    $sql = "SELECT e.id,e.status,e.escalation_reason,e.escalated_at,e.resolution_note,
                   e.resolved_at,resolver.full_name AS resolved_by_name,
                   n.id AS nomination_id,n.status AS nomination_status,n.shared_work,
                   n.collaboration_details,n.reviewer_justification,n.decision_reason,
                   n.decided_at,
                   emp.id AS employee_id,emp.full_name AS employee_name,
                   peer.full_name AS peer_name,mgr.full_name AS manager_name,
                   rc.name AS cycle_name,rc.status AS cycle_status,rc.peer_deadline,
                   rp.status AS participant_status
            FROM peer_nomination_escalations e
            JOIN peer_nominations n ON n.id=e.nomination_id
            JOIN review_participants rp ON rp.id=n.participant_id
            JOIN review_cycles rc ON rc.id=rp.cycle_id
            JOIN users emp ON emp.id=e.employee_id
            JOIN users peer ON peer.id=n.peer_id
            JOIN users mgr ON mgr.id=rp.manager_id
            LEFT JOIN users resolver ON resolver.id=e.resolved_by
            WHERE {$where}
            ORDER BY e.escalated_at DESC
            LIMIT 200";
    return db()->query($sql)->fetchAll();
}

/**
 * Employee directory. Sensitive plan detail is summarised as counts only; the
 * per-employee profile applies its own ownership checks before showing more.
 */
function hr_directory(array $filters): array
{
    $sql = "SELECT u.id,u.emp_code,u.full_name,u.email,u.job_title,u.role,u.is_active,
                   u.date_joined,d.department_name,t.team_name,r.display_name AS role_name,
                   mgr.full_name AS manager_name,
                   (SELECT COUNT(*) FROM pips p
                     WHERE p.employee_id=u.id AND p.status IN ('active','extended')) AS active_pips,
                   (SELECT COUNT(*) FROM goals g
                     WHERE g.employee_id=u.id AND g.status NOT IN ('completed')) AS open_goals,
                   (SELECT COUNT(*) FROM pdp_actions pa JOIN pdps p ON p.id=pa.pdp_id
                     WHERE p.employee_id=u.id AND pa.status NOT IN ('completed','cancelled')
                       AND pa.due_date < CURRENT_DATE) AS overdue_actions,
                   (SELECT MAX(rp.final_rating) FROM review_participants rp
                     WHERE rp.employee_id=u.id AND rp.status='released') AS last_rating
            FROM users u
            JOIN roles r ON r.role_code=u.role
            JOIN departments d ON d.id=u.department_id
            LEFT JOIN teams t ON t.id=u.team_id
            LEFT JOIN active_primary_relationships rr ON rr.employee_id=u.id
            LEFT JOIN users mgr ON mgr.id=rr.reports_to_employee_id
            WHERE 1=1";
    $params = [];
    $search = trim((string) ($filters["search"] ?? ""));
    if ($search !== "") {
        $sql .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR u.emp_code LIKE ?)";
        $like = "%" . $search . "%";
        array_push($params, $like, $like, $like);
    }
    $departmentId = (int) ($filters["departmentId"] ?? 0);
    if ($departmentId > 0) {
        $sql .= " AND u.department_id=?";
        $params[] = $departmentId;
    }
    $role = trim((string) ($filters["role"] ?? ""));
    if ($role !== "") {
        $sql .= " AND u.role=?";
        $params[] = $role;
    }
    $status = (string) ($filters["status"] ?? "active");
    if ($status === "active") {
        $sql .= " AND u.is_active=1";
    } elseif ($status === "inactive") {
        $sql .= " AND u.is_active=0";
    }
    $sql .= " ORDER BY u.full_name LIMIT 500";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Improvement plans, flagged with whether this HR user owns each one. */
function hr_pips(int $viewerId): array
{
    $sql = "SELECT p.id,p.employee_id,p.status,p.reason,p.start_date,p.end_date,
                   p.outcome_note,p.hr_owner_id,
                   emp.full_name AS employee_name,emp.job_title,
                   mgr.full_name AS manager_name,hr.full_name AS hr_owner_name,
                   d.department_name,
                   (SELECT COUNT(*) FROM pip_objectives o WHERE o.pip_id=p.id) AS objective_count,
                   (SELECT COUNT(*) FROM pip_objectives o WHERE o.pip_id=p.id AND o.status='met') AS met_count,
                   (SELECT MAX(c.checkin_date) FROM pip_checkins c WHERE c.pip_id=p.id) AS last_checkin
            FROM pips p
            JOIN users emp ON emp.id=p.employee_id
            JOIN users mgr ON mgr.id=p.manager_id
            JOIN users hr ON hr.id=p.hr_owner_id
            JOIN departments d ON d.id=emp.department_id
            ORDER BY FIELD(p.status,'active','extended','draft','successful','unsuccessful','closed'),
                     p.end_date";
    $rows = db()->query($sql)->fetchAll();
    foreach ($rows as &$row) {
        $row["owned"] = (int) $row["hr_owner_id"] === $viewerId;
    }
    return $rows;
}

/** Review-cycle progress used by the HR reporting page. */
function hr_cycles(): array
{
    $sql = "SELECT rc.id,rc.name,rc.status,rc.period_start,rc.period_end,rc.min_peers,
                   rc.self_deadline,rc.peer_deadline,rc.manager_deadline,rc.released_at,
                   (SELECT COUNT(*) FROM review_participants rp WHERE rp.cycle_id=rc.id) AS participants,
                   (SELECT COUNT(*) FROM review_participants rp
                     WHERE rp.cycle_id=rc.id AND rp.status IN ('manager_submitted','released')) AS completed,
                   (SELECT COUNT(*) FROM feedback_requests fr
                     JOIN review_participants rp ON rp.id=fr.participant_id
                     WHERE rp.cycle_id=rc.id AND fr.status='pending') AS pending_forms,
                   (SELECT ROUND(AVG(rp.final_rating),2) FROM review_participants rp
                     WHERE rp.cycle_id=rc.id AND rp.final_rating IS NOT NULL) AS average_rating
            FROM review_cycles rc
            ORDER BY rc.period_start DESC";
    return db()->query($sql)->fetchAll();
}
/** Create a new review cycle and enrol active employees. */
function hr_cycle_create(): array
{
    require_method("POST");
    $user = require_login();

if (!hr_can($user, "hr.reports")) {
        api_error("Forbidden", 403);
    }

    $in = input();

    $name = trim((string) ($in["name"] ?? ""));
    $periodStart = trim((string) ($in["period_start"] ?? ""));
    $periodEnd = trim((string) ($in["period_end"] ?? ""));
    $selfDeadline = trim((string) ($in["self_deadline"] ?? ""));
    $peerDeadline = trim((string) ($in["peer_deadline"] ?? ""));
    $managerDeadline = trim((string) ($in["manager_deadline"] ?? ""));
    $minPeers = max(1, min(10, (int) ($in["min_peers"] ?? 3)));

    if ($name === "" || strlen($name) > 120) {
        api_error("Cycle name is required and must be 120 characters or fewer.", 422);
    }

    $dates = [
        "Start date" => $periodStart,
        "End date" => $periodEnd,
        "Self-review deadline" => $selfDeadline,
        "Peer-review deadline" => $peerDeadline,
        "Manager-review deadline" => $managerDeadline,
    ];

    foreach ($dates as $label => $date) {
        $d = DateTime::createFromFormat("Y-m-d", $date);

        if (!$d || $d->format("Y-m-d") !== $date) {
            api_error($label . " must be a valid date.", 422);
        }
    }

    if ($periodEnd < $periodStart) {
        api_error("End date cannot be before the start date.", 422);
    }

    $pdo = db();

    $stmt = $pdo->prepare(
        "INSERT INTO review_cycles
            (name, status, period_start, period_end, min_peers,
             self_deadline, peer_deadline, manager_deadline)
         VALUES
            (?, 'open', ?, ?, ?, ?, ?, ?)"
    );

    $stmt->execute([
        $name,
        $periodStart,
        $periodEnd,
        $minPeers,
        $selfDeadline,
        $peerDeadline,
        $managerDeadline,
    ]);

    $cycleId = (int) $pdo->lastInsertId();

    /*
     * Add active employees to the new review cycle.
     * HR/admin users are excluded from employee review participation.
     */
    
    $employeeStmt = $pdo->prepare(
    "SELECT u.id,
            r.reports_to_employee_id AS manager_id
     FROM users u
     INNER JOIN reporting_relationships r
         ON r.employee_id = u.id
        AND r.relationship_type = 'primary'
        AND r.effective_to IS NULL
     WHERE u.is_active = 1
       AND u.role = 'employee'
     ORDER BY u.id"
);

    $employeeStmt->execute();
    $employees = $employeeStmt->fetchAll();

    $participantStmt = $pdo->prepare(
        "INSERT INTO review_participants
            (cycle_id, employee_id, manager_id, status)
         VALUES (?, ?, ?, 'not_started')"
    );

    $participantCount = 0;

    foreach ($employees as $employee) {
        $participantStmt->execute([
            $cycleId,
            (int) $employee["id"],
            (int) $employee["manager_id"],
        ]);

        $participantCount++;
    }

    audit(
    (int) $user["id"],
    "CREATE_REVIEW_CYCLE",
    "review_cycle",
    $cycleId,
    json_encode([
        "name" => $name,
        "participants" => $participantCount,
    ])
);

    return [
        "cycle" => [
            "id" => $cycleId,
            "name" => $name,
            "status" => "open",
            "period_start" => $periodStart,
            "period_end" => $periodEnd,
            "participants" => $participantCount,
        ],
    ];
}

/** Aggregate reporting. No individual feedback responses are exposed here. */
function hr_reports(): array
{
    $pdo = db();
    return [
        "departments" => $pdo->query(
            "SELECT d.department_name,
                    COUNT(u.id) AS headcount,
                    SUM(u.is_active=0) AS inactive,
                    (SELECT COUNT(*) FROM pips p JOIN users e ON e.id=p.employee_id
                      WHERE e.department_id=d.id AND p.status IN ('active','extended')) AS active_pips,
                    (SELECT ROUND(AVG(rp.final_rating),2) FROM review_participants rp
                      JOIN users e ON e.id=rp.employee_id
                      WHERE e.department_id=d.id AND rp.final_rating IS NOT NULL) AS average_rating
             FROM departments d LEFT JOIN users u ON u.department_id=d.id
             WHERE d.is_active=1
             GROUP BY d.id,d.department_name ORDER BY d.department_name",
        )->fetchAll(),
        "pipOutcomes" => $pdo->query(
            "SELECT status,COUNT(*) AS total FROM pips GROUP BY status",
        )->fetchAll(),
        "development" => $pdo->query(
            "SELECT
               (SELECT COUNT(*) FROM pdp_actions WHERE status='completed') AS completed_actions,
               (SELECT COUNT(*) FROM pdp_actions WHERE status NOT IN ('completed','cancelled')) AS open_actions,
               (SELECT COUNT(*) FROM pdp_actions
                 WHERE status NOT IN ('completed','cancelled') AND due_date < CURRENT_DATE) AS overdue_actions,
               (SELECT COUNT(*) FROM work_steps WHERE status='blocked') AS blocked_steps",
        )->fetch() ?: [],
        "skillGaps" => $pdo->query(
            "SELECT s.name AS skill_name,COUNT(*) AS action_count
             FROM pdp_actions pa JOIN skills s ON s.id=pa.skill_id
             WHERE pa.status NOT IN ('completed','cancelled')
             GROUP BY s.id,s.name ORDER BY action_count DESC,s.name LIMIT 10",
        )->fetchAll(),
    ];
}

function hr_api(string $action): never
{
    $writes = ["hr_case_resolve", "hr_pip_update", "hr_cycle_create"];
    if (in_array($action, $writes, true)) {
        require_method("POST");
        require_csrf();
        $user = require_login();
        $in = input();
    } else {
        require_method("GET");
        $user = require_permission("hr.dashboard");
        $in = $_GET;
    }
    $user["permissions"] = permissions_for_role($user["role"]);
    $actorId = (int) $user["id"];

    switch ($action) {
        // Everything the HR dashboard renders on load.
        case "hr_dashboard":
            $payload = [
                "ok" => true,
                "user" => $user,
                "metrics" => hr_overview_metrics(),
                "directory" => hr_can($user, "hr.dashboard")
                    ? hr_directory([
                        "search" => (string) ($in["search"] ?? ""),
                        "departmentId" => (int) ($in["departmentId"] ?? 0),
                        "role" => (string) ($in["role"] ?? ""),
                        "status" => (string) ($in["status"] ?? "active"),
                    ])
                    : [],
                "departments" => db()
                    ->query(
                        "SELECT id,department_name FROM departments WHERE is_active=1 ORDER BY department_name",
                    )
                    ->fetchAll(),
                "roles" => db()
                    ->query("SELECT role_code,display_name FROM roles WHERE is_active=1 ORDER BY display_name")
                    ->fetchAll(),
                "cases" => hr_can($user, "hr.cases") ? hr_cases("open") : [],
                "resolvedCases" => hr_can($user, "hr.cases") ? hr_cases("resolved") : [],
                "pips" => hr_can($user, "hr.pips") ? hr_pips($actorId) : [],
                "cycles" => hr_can($user, "hr.reports") ? hr_cycles() : [],
                "reports" => hr_can($user, "hr.reports") ? hr_reports() : null,
                "csrfToken" => csrf_token(),
            ];
            audit($actorId, "VIEW_HR_DASHBOARD", "user", $actorId, "Opened HR workspace");
            json_response($payload);

        // Directory refresh for search and filter changes.
        case "hr_directory":
            json_response([
                "ok" => true,
                "directory" => hr_directory([
                    "search" => (string) ($in["search"] ?? ""),
                    "departmentId" => (int) ($in["departmentId"] ?? 0),
                    "role" => (string) ($in["role"] ?? ""),
                    "status" => (string) ($in["status"] ?? "active"),
                ]),
            ]);

        // One person's record, with the reporting path and plan summaries.
        case "hr_employee":
            $employeeId = (int) ($in["employeeId"] ?? 0);
            $stmt = db()->prepare(
                "SELECT u.id,u.emp_code,u.full_name,u.email,u.job_title,u.role,u.is_active,
                        u.date_joined,d.department_name,t.team_name,r.display_name AS role_name
                 FROM users u JOIN roles r ON r.role_code=u.role
                 JOIN departments d ON d.id=u.department_id
                 LEFT JOIN teams t ON t.id=u.team_id WHERE u.id=?",
            );
            $stmt->execute([$employeeId]);
            $employee = $stmt->fetch();
            if (!$employee) {
                json_response(["ok" => false, "error" => "Employee not found"], 404);
            }
            $goals = db()->prepare(
                "SELECT title,status,due_date FROM goals WHERE employee_id=? ORDER BY due_date DESC LIMIT 20",
            );
            $goals->execute([$employeeId]);
            $reviews = db()->prepare(
                "SELECT rc.name AS cycle_name,rp.status,rp.final_rating,rp.released_at
                 FROM review_participants rp JOIN review_cycles rc ON rc.id=rp.cycle_id
                 WHERE rp.employee_id=? ORDER BY rc.period_start DESC LIMIT 10",
            );
            $reviews->execute([$employeeId]);
            // PIP reasons and outcomes stay hidden unless this HR user owns the plan.
            $pips = db()->prepare(
                "SELECT id,status,start_date,end_date,hr_owner_id,
                        IF(hr_owner_id=?,reason,NULL) AS reason,
                        IF(hr_owner_id=?,outcome_note,NULL) AS outcome_note
                 FROM pips WHERE employee_id=? ORDER BY start_date DESC",
            );
            $pips->execute([$actorId, $actorId, $employeeId]);
            audit($actorId, "VIEW_HR_EMPLOYEE", "user", $employeeId, "Opened HR employee record");
            json_response([
                "ok" => true,
                "employee" => $employee,
                "path" => get_reporting_path($employeeId),
                "goals" => $goals->fetchAll(),
                "reviews" => $reviews->fetchAll(),
                "pips" => hr_can($user, "hr.pips") ? $pips->fetchAll() : [],
            ]);
                // Start a new review cycle.
        case "hr_cycle_create":
            if (!hr_can($user, "hr.reports")) {
                audit($actorId, "DENIED_PERMISSION", "permission", null, "hr.reports");
                json_response(
                    ["ok" => false, "error" => "You do not have permission to create review cycles"],
                    403,
                );
            }

            json_response([
                "ok" => true,
                "cycle" => hr_cycle_create(),
            ]);

        // Uphold or overturn a manager's rejected peer nomination.
        case "hr_case_resolve":
            if (!hr_can($user, "hr.cases")) {
                audit($actorId, "DENIED_PERMISSION", "permission", null, "hr.cases");
                json_response(
                    ["ok" => false, "error" => "You do not have permission to resolve escalations"],
                    403,
                );
            }
            $id = (int) ($in["id"] ?? 0);
            $outcome = (string) ($in["outcome"] ?? "");
            $note = trim((string) ($in["note"] ?? ""));
            if (!in_array($outcome, ["resolved_upheld", "resolved_overturned"], true)) {
                json_response(["ok" => false, "error" => "Choose uphold or overturn"], 422);
            }
            if (strlen($note) < 15 || strlen($note) > 2000) {
                json_response(
                    ["ok" => false, "error" => "A resolution note of 15 to 2,000 characters is required"],
                    422,
                );
            }
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    "SELECT e.id,e.status,e.nomination_id,n.status AS nomination_status,
                            n.participant_id,n.peer_id,rp.status AS participant_status,
                            rc.status AS cycle_status,rc.peer_deadline
                     FROM peer_nomination_escalations e
                     JOIN peer_nominations n ON n.id=e.nomination_id
                     JOIN review_participants rp ON rp.id=n.participant_id
                     JOIN review_cycles rc ON rc.id=rp.cycle_id
                     WHERE e.id=? FOR UPDATE",
                );
                $stmt->execute([$id]);
                $case = $stmt->fetch();
                if (!$case) {
                    $pdo->rollBack();
                    json_response(["ok" => false, "error" => "Escalation not found"], 404);
                }
                if ($case["status"] !== "pending_hr") {
                    $pdo->rollBack();
                    json_response(["ok" => false, "error" => "This escalation is already resolved"], 409);
                }
                if ($outcome === "resolved_overturned") {
                    // Overturning reinstates the nomination, so the same review
                    // window rules that bind a manager also bind HR here.
                    if (
                        in_array($case["cycle_status"], ["released", "closed"], true) ||
                        in_array($case["participant_status"], ["manager_submitted", "released"], true)
                    ) {
                        $pdo->rollBack();
                        json_response(
                            ["ok" => false, "error" => "The review is no longer open, so the rejection cannot be overturned"],
                            409,
                        );
                    }
                    if ($case["peer_deadline"] && $case["peer_deadline"] < date("Y-m-d")) {
                        $pdo->rollBack();
                        json_response(
                            ["ok" => false, "error" => "The peer feedback deadline has passed"],
                            409,
                        );
                    }
                    // The nomination row stays as the manager's own decision
                    // record; the escalation is the durable record of HR's
                    // override. Overwriting it here would break the invariant
                    // that an escalation only ever follows a rejection.
                    $pdo->prepare(
                        "INSERT INTO feedback_requests(participant_id,respondent_id,type,status)
                         VALUES(?,?,'peer','pending') ON DUPLICATE KEY UPDATE participant_id=VALUES(participant_id)",
                    )->execute([(int) $case["participant_id"], (int) $case["peer_id"]]);
                }
                $pdo->prepare(
                    "UPDATE peer_nomination_escalations SET status=?,resolved_by=?,resolution_note=?,
                     resolved_at=NOW() WHERE id=?",
                )->execute([$outcome, $actorId, $note, $id]);
                audit(
                    $actorId,
                    strtoupper("HR_ESCALATION_" . ($outcome === "resolved_upheld" ? "UPHELD" : "OVERTURNED")),
                    "peer_nomination_escalation",
                    $id,
                    "Nomination " . (int) $case["nomination_id"] . ": " . substr($note, 0, 150),
                );
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            json_response(["ok" => true, "message" => "Escalation resolved."]);

        // Move an owned improvement plan to its next state.
        case "hr_pip_update":
            if (!hr_can($user, "hr.pips")) {
                audit($actorId, "DENIED_PERMISSION", "permission", null, "hr.pips");
                json_response(
                    ["ok" => false, "error" => "You do not have permission to manage improvement plans"],
                    403,
                );
            }
            $id = (int) ($in["id"] ?? 0);
            $status = (string) ($in["status"] ?? "");
            $note = trim((string) ($in["outcomeNote"] ?? ""));
            $allowed = ["draft", "active", "extended", "successful", "unsuccessful", "closed"];
            if (!in_array($status, $allowed, true)) {
                json_response(["ok" => false, "error" => "Choose a valid plan status"], 422);
            }
            if (strlen($note) > 2000) {
                json_response(["ok" => false, "error" => "The outcome note is too long"], 422);
            }
            $closing = in_array($status, ["successful", "unsuccessful", "closed"], true);
            if ($closing && strlen($note) < 15) {
                json_response(
                    ["ok" => false, "error" => "Closing a plan needs an outcome note of at least 15 characters"],
                    422,
                );
            }
            // Ownership is the access rule, not the HR permission alone.
            $stmt = db()->prepare("SELECT status FROM pips WHERE id=? AND hr_owner_id=?");
            $stmt->execute([$id, $actorId]);
            $current = $stmt->fetch();
            if (!$current) {
                audit($actorId, "DENIED_PIP_ACCESS", "pip", $id, "Not the assigned HR owner");
                json_response(
                    ["ok" => false, "error" => "This plan is not assigned to you"],
                    403,
                );
            }
            if (in_array($current["status"], ["successful", "unsuccessful", "closed"], true)) {
                json_response(["ok" => false, "error" => "A closed plan is read-only"], 409);
            }
            db()->prepare("UPDATE pips SET status=?,outcome_note=? WHERE id=?")
                ->execute([$status, $note !== "" ? $note : null, $id]);
            audit($actorId, "UPDATE_PIP_STATUS", "pip", $id, "Status " . $status);
            json_response(["ok" => true, "message" => "Improvement plan updated."]);

        default:
            json_response(["ok" => false, "error" => "Unknown action"], 404);
    }
}

