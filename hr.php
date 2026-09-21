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
    $rows = db()->query($sql)->fetchAll();
    foreach ($rows as &$row) {
        $row["can_overturn"] = in_array($row["cycle_status"], ["open", "peer_review", "manager_review"], true)
            && !in_array($row["participant_status"], ["manager_submitted", "released"], true);
        $row['needs_response_extension'] = $row['peer_deadline'] && $row['peer_deadline'] < date('Y-m-d');
    }
    unset($row);
    return $rows;
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
                   (SELECT COUNT(*) FROM review_participants rp
                     WHERE rp.cycle_id=rc.id
                       AND (SELECT COUNT(*) FROM feedback_requests fr
                            WHERE fr.participant_id=rp.id AND fr.type='peer') >= rc.min_peers) AS peer_ready_participants,
                   (SELECT COUNT(*) FROM review_participants rp
                     WHERE rp.cycle_id=rc.id
                       AND (SELECT COUNT(*) FROM feedback_requests fr
                            WHERE fr.participant_id=rp.id AND fr.type='peer' AND fr.status='submitted') < rc.min_peers) AS below_peer_response_threshold,
                   (SELECT ROUND(AVG(rp.final_rating),2) FROM review_participants rp
                     WHERE rp.cycle_id=rc.id AND rp.final_rating IS NOT NULL) AS average_rating
            FROM review_cycles rc
            ORDER BY rc.period_start DESC";
    $rows=db()->query($sql)->fetchAll();
    foreach($rows as &$row) $row['readiness']=cycle_readiness((int)$row['id']);
    unset($row);
    return $rows;
}
/** Save a configurable draft. Publication performs preflight and enrolment. */
function hr_cycle_create(array $in, array $user): array
{
    if (!hr_can($user, "hr.cycles.manage")) {
        api_error("Forbidden", 403);
    }


    $name = trim((string) ($in["name"] ?? ""));
    $periodStart = trim((string) ($in["period_start"] ?? ""));
    $periodEnd = trim((string) ($in["period_end"] ?? ""));
    $selfDeadline = trim((string) ($in["self_deadline"] ?? ""));
    $peerDeadline = trim((string) ($in["peer_deadline"] ?? ""));
    $managerDeadline = trim((string) ($in["manager_deadline"] ?? ""));
    $minPeers = (int) ($in["min_peers"] ?? 3);
    if ($minPeers < 3 || $minPeers > 10) {
        api_error("Minimum peer reviews must be between 3 and 10.", 422);
    }

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
    if ($selfDeadline > $peerDeadline || $peerDeadline > $managerDeadline) {
        api_error(
            "Review deadlines must be ordered: self review, then peer review, then manager review.",
            422,
        );
    }
    $retrospective = filter_var($in['retrospective'] ?? false,FILTER_VALIDATE_BOOLEAN);
    if (!$retrospective && $managerDeadline < date('Y-m-d')) {
        api_error('The manager deadline cannot already be past unless this is explicitly marked as a retrospective cycle.',422);
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO review_cycles
                (name, status, period_start, period_end, min_peers,
                 self_deadline, peer_deadline, manager_deadline, created_by)
             VALUES
                (?, 'draft', ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $name,
            $periodStart,
            $periodEnd,
            $minPeers,
            $selfDeadline,
            $peerDeadline,
            $managerDeadline,
            (int) $user["id"],
        ]);

        $cycleId = (int) $pdo->lastInsertId();

        audit(
            (int) $user["id"],
            "CREATE_REVIEW_CYCLE",
            "review_cycle",
            $cycleId,
            json_encode([
                "name" => $name,
                "status" => 'draft',
            ]),
        );

        $pdo->commit();

        return [
            "id" => $cycleId,
            "name" => $name,
            "status" => "draft",
            "period_start" => $periodStart,
            "period_end" => $periodEnd,
            "participants" => 0,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Move a visible review cycle through the stages used by the Personal and
 * Manager workspaces. Releasing a cycle publishes manager results to employees.
 */
function hr_cycle_advance(array $in, int $actorId): array
{
    $cycleId = (int) ($in["id"] ?? 0);
    if ($cycleId < 1) {
        api_error("Choose a valid review cycle.", 422);
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            "SELECT id,name,status FROM review_cycles WHERE id=? FOR UPDATE",
        );
        $stmt->execute([$cycleId]);
        $cycle = $stmt->fetch();
        if (!$cycle) {
            $pdo->rollBack();
            api_error("Review cycle not found.", 404);
        }

        $nextByStatus = [
            "open" => "peer_review",
            "peer_review" => "manager_review",
            "manager_review" => "released",
            "released" => "closed",
        ];
        $next = $nextByStatus[$cycle["status"]] ?? null;
        if (!$next) {
            $pdo->rollBack();
            api_error("This review cycle cannot move forward from its current status.", 409);
        }

        if ($next === "peer_review") {
            $pendingSelfStmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM review_participants rp
                 LEFT JOIN feedback_requests fr
                   ON fr.participant_id=rp.id
                  AND fr.respondent_id=rp.employee_id
                  AND fr.type='self'
                 WHERE rp.cycle_id=? AND (fr.id IS NULL OR fr.status<>'submitted')
                   AND NOT EXISTS (SELECT 1 FROM review_participant_exceptions x WHERE x.participant_id=rp.id AND x.revoked_at IS NULL AND x.exception_type IN ('excluded','withdrawn','waive_self'))",
            );
            $pendingSelfStmt->execute([$cycleId]);
            $pendingSelf = (int) $pendingSelfStmt->fetchColumn();
            if ($pendingSelf > 0) {
                $pdo->rollBack();
                api_error(
                    $pendingSelf . " self review" . ($pendingSelf === 1 ? " is" : "s are") .
                    " still pending. Complete them before moving the cycle to peer review.",
                    409,
                );
            }

            // Every participant must have the cycle's minimum number of
            // approved/assigned peer reviewers before peer review opens. A
            // pending nomination does not count yet; manager approval or an
            // HR-overturned rejection creates the actual peer feedback request.
            $missingApprovedStmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM review_participants rp
                 JOIN review_cycles rc ON rc.id=rp.cycle_id
                 WHERE rp.cycle_id=?
                   AND NOT EXISTS (SELECT 1 FROM review_participant_exceptions x WHERE x.participant_id=rp.id AND x.revoked_at IS NULL AND x.exception_type IN ('excluded','withdrawn','waive_peer'))
                   AND (SELECT COUNT(*) FROM feedback_requests fr
                        WHERE fr.participant_id=rp.id AND fr.type='peer' AND fr.status IN ('pending','submitted')) < rc.min_peers",
            );
            $missingApprovedStmt->execute([$cycleId]);
            $missingApproved = (int) $missingApprovedStmt->fetchColumn();
            if ($missingApproved > 0) {
                $pdo->rollBack();
                api_error(
                    $missingApproved . " participant" . ($missingApproved === 1 ? " still needs" : "s still need") .
                    " the required number of approved peer reviewers before peer review can open.",
                    409,
                );
            }
            $openCases=$pdo->prepare("SELECT COUNT(*) FROM peer_nomination_escalations e JOIN peer_nominations n ON n.id=e.nomination_id JOIN review_participants rp ON rp.id=n.participant_id WHERE rp.cycle_id=? AND e.status='pending_hr'");
            $openCases->execute([$cycleId]);
            if((int)$openCases->fetchColumn()>0){
                $pdo->rollBack();
                api_error('Resolve every open peer-nomination escalation before moving to peer review.',409);
            }
        }

        if ($next === "manager_review") {
            // Keep participant status aligned with completed peer forms, including
            // older cycles that may have been created before the workflow was wired together.
            $pdo->prepare(
                "UPDATE review_participants rp
                 JOIN review_cycles rc ON rc.id=rp.cycle_id
                 SET rp.status='peers_complete'
                 WHERE rp.cycle_id=? AND rp.status='self_submitted'
                   AND (SELECT COUNT(*) FROM feedback_requests fr
                        WHERE fr.participant_id=rp.id
                          AND fr.type='peer'
                          AND fr.status='submitted') >= rc.min_peers"
            )->execute([$cycleId]);

            // Peer responses do not block manager review. The min_peers value
            // remains the anonymity threshold for revealing aggregates.
        }

        if ($next === "released") {
            $readiness=cycle_readiness($cycleId);
            if($readiness['peerResponseShortfall']>0){
                $pdo->rollBack();
                api_error($readiness['peerResponseShortfall'].' participant(s) still need peer responses or an explicit HR peer-feedback waiver.',409);
            }
            if($readiness['unresolvedEscalations']>0 || $readiness['inactiveManagers']>0){
                $pdo->rollBack();
                api_error('Resolve open escalations and reassign inactive managers before release.',409);
            }
            $pendingStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM review_participants
                 WHERE cycle_id=? AND status NOT IN ('manager_submitted','released')
                   AND NOT EXISTS (SELECT 1 FROM review_participant_exceptions x WHERE x.participant_id=review_participants.id AND x.revoked_at IS NULL AND x.exception_type IN ('excluded','withdrawn'))",
            );
            $pendingStmt->execute([$cycleId]);
            $pending = (int) $pendingStmt->fetchColumn();
            if ($pending > 0) {
                $pdo->rollBack();
                api_error(
                    $pending . " participant" . ($pending === 1 ? "" : "s") .
                    " still need a manager review before this cycle can be released.",
                    409,
                );
            }

            $pdo->prepare(
                "UPDATE review_participants
                 SET status='released', released_at=COALESCE(released_at,NOW())
                 WHERE cycle_id=? AND status='manager_submitted'
                   AND NOT EXISTS (SELECT 1 FROM review_participant_exceptions x WHERE x.participant_id=review_participants.id AND x.revoked_at IS NULL AND x.exception_type IN ('excluded','withdrawn'))",
            )->execute([$cycleId]);

            $pdo->prepare("UPDATE feedback_requests fr JOIN review_participants rp ON rp.id=fr.participant_id SET fr.status=CASE WHEN EXISTS (SELECT 1 FROM review_participant_exceptions x WHERE x.participant_id=rp.id AND x.exception_type LIKE 'waive_%' AND x.revoked_at IS NULL) THEN 'waived' ELSE 'expired' END WHERE rp.cycle_id=? AND fr.status='pending'")->execute([$cycleId]);

            $pdo->prepare(
                "UPDATE review_cycles SET status='released',released_at=COALESCE(released_at,NOW()) WHERE id=?",
            )->execute([$cycleId]);
        } else {
            $pdo->prepare(
                "UPDATE review_cycles SET status=? WHERE id=?",
            )->execute([$next, $cycleId]);
        }

        audit(
            $actorId,
            "ADVANCE_REVIEW_CYCLE",
            "review_cycle",
            $cycleId,
            $cycle["status"] . " -> " . $next,
        );

        $pdo->commit();
        return [
            "id" => $cycleId,
            "name" => $cycle["name"],
            "status" => $next,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
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
        "developmentSkills" => $pdo->query(
            "SELECT s.name AS skill_name,COUNT(*) AS action_count
             FROM pdp_actions pa JOIN skills s ON s.id=pa.skill_id
             WHERE pa.status NOT IN ('completed','cancelled')
             GROUP BY s.id,s.name ORDER BY action_count DESC,s.name LIMIT 10",
        )->fetchAll(),
    ];
}

function hr_api(string $action): never
{
    $writes = ["hr_case_resolve", "hr_pip_update", "hr_cycle_create", "hr_cycle_update", "hr_cycle_publish", "hr_cycle_advance", "hr_participant_exception", "hr_record_reassign", "hr_competency_save"];
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
                "directory" => hr_can($user, "hr.directory.read")
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
                "competencies" => hr_can($user,"hr.competencies.manage") ? db()->query("SELECT id,name,description,is_active FROM competencies ORDER BY is_active DESC,name")->fetchAll() : [],
                "reports" => hr_can($user, "hr.reports") ? hr_reports() : null,
                "csrfToken" => csrf_token(),
            ];
            audit($actorId, "VIEW_HR_DASHBOARD", "user", $actorId, "Opened HR workspace");
            json_response($payload);

        // Directory refresh for search and filter changes.
        case "hr_directory":
            if(!hr_can($user,'hr.directory.read')) json_response(['ok'=>false,'error'=>'You do not have permission to read the employee directory'],403);
            json_response([
                "ok" => true,
                "directory" => hr_directory([
                    "search" => (string) ($in["search"] ?? ""),
                    "departmentId" => (int) ($in["departmentId"] ?? 0),
                    "role" => (string) ($in["role"] ?? ""),
                    "status" => (string) ($in["status"] ?? "active"),
                ]),
            ]);

        case 'hr_cycle_participants':
            if(!hr_can($user,'hr.cycles.manage')) json_response(['ok'=>false,'error'=>'You do not have permission to manage cycle participants'],403);
            $cycleId=(int)($in['id']??0);
            $stmt=db()->prepare("SELECT rp.id,u.full_name employee,m.full_name manager,m.is_active manager_active,rp.status,
              (SELECT status FROM feedback_requests fr WHERE fr.participant_id=rp.id AND fr.type='self' LIMIT 1) self_status,
              (SELECT COUNT(*) FROM feedback_requests fr WHERE fr.participant_id=rp.id AND fr.type='peer' AND fr.status='submitted') peer_responses,
              rc.min_peers,
              (SELECT GROUP_CONCAT(x.exception_type ORDER BY x.exception_type) FROM review_participant_exceptions x WHERE x.participant_id=rp.id AND x.revoked_at IS NULL) exceptions
              FROM review_participants rp JOIN review_cycles rc ON rc.id=rp.cycle_id JOIN users u ON u.id=rp.employee_id JOIN users m ON m.id=rp.action_manager_id WHERE rp.cycle_id=? ORDER BY u.full_name");
            $stmt->execute([$cycleId]);
            json_response(['ok'=>true,'participants'=>$stmt->fetchAll()]);

        // One person's record, with the reporting path and plan summaries.
        case "hr_employee":
            if(!hr_can($user,'hr.directory.read')) json_response(['ok'=>false,'error'=>'You do not have permission to read the employee directory'],403);
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
                "SELECT rp.id AS participant_id,rc.name AS cycle_name,rp.status,rp.final_rating,rp.released_at
                 FROM review_participants rp JOIN review_cycles rc ON rc.id=rp.cycle_id
                 WHERE rp.employee_id=? ORDER BY rc.period_start DESC LIMIT 10",
            );
            $reviewRows=[];
            if(hr_can($user,'hr.reviews.read')){
                $reviews->execute([$employeeId]);
                $reviewRows = $reviews->fetchAll();
            }
            foreach ($reviewRows as &$reviewRow) {
                $self = db()->prepare(
                    "SELECT id,status,submitted_at FROM feedback_requests " .
                    "WHERE participant_id=? AND respondent_id=? AND type='self' LIMIT 1",
                );
                $self->execute([(int) $reviewRow["participant_id"], $employeeId]);
                $selfRequest = $self->fetch() ?: null;
                $reviewRow["self_review"] = null;
                if ($selfRequest !== null) {
                    $ratings = [];
                    if ($selfRequest["status"] === "submitted") {
                        $ratingStmt = db()->prepare(
                            "SELECT rcc.competency_id,rcc.name,fr.score,fr.comment " .
                            "FROM feedback_ratings fr JOIN feedback_requests req ON req.id=fr.request_id JOIN review_participants rp ON rp.id=req.participant_id JOIN review_cycle_competencies rcc ON rcc.cycle_id=rp.cycle_id AND rcc.competency_id=fr.competency_id " .
                            "WHERE fr.request_id=? ORDER BY rcc.display_order",
                        );
                        $ratingStmt->execute([(int) $selfRequest["id"]]);
                        $ratings = $ratingStmt->fetchAll();
                    }
                    $reviewRow["self_review"] = [
                        "status" => $selfRequest["status"],
                        "submitted_at" => $selfRequest["submitted_at"],
                        "ratings" => $ratings,
                    ];
                }
            }
            unset($reviewRow);
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
                "reviews" => $reviewRows,
                "pips" => hr_can($user, "hr.pips") ? $pips->fetchAll() : [],
            ]);
        case 'hr_cycle_publish':
            if(!hr_can($user,'hr.cycles.manage')) json_response(['ok'=>false,'error'=>'You do not have permission to publish review cycles'],403);
            try {
                json_response(['ok'=>true,'cycle'=>review_publish_cycle((int)($in['id']??0),$actorId)]);
            } catch(InvalidArgumentException $e){ json_response(['ok'=>false,'error'=>$e->getMessage()],404); }
              catch(DomainException $e){ json_response(['ok'=>false,'error'=>$e->getMessage()],409); }

        case 'hr_cycle_update':
            if(!hr_can($user,'hr.cycles.manage')) json_response(['ok'=>false,'error'=>'You do not have permission to configure review cycles'],403);
            $id=(int)($in['id']??0); $reason=trim((string)($in['reason']??''));
            $stmt=db()->prepare('SELECT * FROM review_cycles WHERE id=?'); $stmt->execute([$id]); $cycle=$stmt->fetch();
            if(!$cycle) json_response(['ok'=>false,'error'=>'Review cycle not found'],404);
            $name=trim((string)($in['name']??$cycle['name']));
            $periodStart=(string)($in['period_start']??$cycle['period_start']);
            $periodEnd=(string)($in['period_end']??$cycle['period_end']);
            $self=(string)($in['self_deadline']??$cycle['self_deadline']);
            $peer=(string)($in['peer_deadline']??$cycle['peer_deadline']);
            $manager=(string)($in['manager_deadline']??$cycle['manager_deadline']);
            $min=(int)($in['min_peers']??$cycle['min_peers']);
            if($name===''||strlen($name)>120||$min<3||$min>10||!valid_date($periodStart)||!valid_date($periodEnd)||$periodEnd<$periodStart||!valid_date($self)||!valid_date($peer)||!valid_date($manager)||$self>$peer||$peer>$manager) json_response(['ok'=>false,'error'=>'Enter a valid name, performance period, peer threshold, and ordered deadlines'],422);
            if($cycle['status']!=='draft' && strlen($reason)<15) json_response(['ok'=>false,'error'=>'A deadline extension needs a reason of at least 15 characters'],422);
            if($cycle['status']!=='draft' && ($name!==$cycle['name']||$min!==(int)$cycle['min_peers']||$periodStart!==$cycle['period_start']||$periodEnd!==$cycle['period_end'])) json_response(['ok'=>false,'error'=>'Name, performance period and peer threshold are frozen after publication'],409);
            if($cycle['status']!=='draft' && ($self<$cycle['self_deadline']||$peer<$cycle['peer_deadline']||$manager<$cycle['manager_deadline'])) json_response(['ok'=>false,'error'=>'Published-cycle deadlines may be extended, not shortened'],409);
            db()->prepare('UPDATE review_cycles SET name=?,period_start=?,period_end=?,self_deadline=?,peer_deadline=?,manager_deadline=?,min_peers=? WHERE id=?')->execute([$name,$periodStart,$periodEnd,$self,$peer,$manager,$min,$id]);
            if($cycle['status']!=='draft'){
                db()->prepare("UPDATE feedback_requests fr JOIN review_participants rp ON rp.id=fr.participant_id SET fr.response_deadline=CASE fr.type WHEN 'self' THEN ? WHEN 'peer' THEN ? ELSE fr.response_deadline END WHERE rp.cycle_id=? AND fr.status='pending'")->execute([$self,$peer,$id]);
                audit($actorId,'EXTEND_REVIEW_DEADLINE','review_cycle',$id,substr($reason,0,200));
            } else audit($actorId,'UPDATE_REVIEW_DRAFT','review_cycle',$id,'Updated draft configuration');
            json_response(['ok'=>true]);

        case 'hr_participant_exception':
            if(!hr_can($user,'hr.cycles.manage')) json_response(['ok'=>false,'error'=>'You do not have permission to manage participant exceptions'],403);
            $participantId=(int)($in['participantId']??0); $type=(string)($in['type']??''); $reason=trim((string)($in['reason']??''));
            if(!in_array($type,['excluded','withdrawn','waive_self','waive_peer'],true)||strlen($reason)<15||strlen($reason)>1000) json_response(['ok'=>false,'error'=>'Choose a valid exception and record a reason of 15 to 1,000 characters'],422);
            $stmt=db()->prepare("SELECT rp.id FROM review_participants rp JOIN review_cycles rc ON rc.id=rp.cycle_id WHERE rp.id=? AND rc.status IN ('open','peer_review','manager_review')"); $stmt->execute([$participantId]);
            if(!$stmt->fetch()) json_response(['ok'=>false,'error'=>'Active review participant not found'],404);
            $pdo=db(); $pdo->beginTransaction();
            try{
                $pdo->prepare('INSERT INTO review_participant_exceptions(participant_id,exception_type,reason,granted_by) VALUES(?,?,?,?)')->execute([$participantId,$type,$reason,$actorId]);
                if(in_array($type,['excluded','withdrawn'],true)) $pdo->prepare("UPDATE feedback_requests SET status='cancelled' WHERE participant_id=? AND status='pending'")->execute([$participantId]);
                if($type==='waive_self') {
                    $pdo->prepare("UPDATE feedback_requests SET status='waived' WHERE participant_id=? AND type='self' AND status='pending'")->execute([$participantId]);
                    $pdo->prepare("UPDATE review_participants SET status='self_submitted' WHERE id=? AND status='not_started'")->execute([$participantId]);
                }
                if($type==='waive_peer') $pdo->prepare("UPDATE feedback_requests SET status='waived' WHERE participant_id=? AND type='peer' AND status='pending'")->execute([$participantId]);
                audit($actorId,'REVIEW_PARTICIPANT_EXCEPTION','review_participant',$participantId,$type.': '.substr($reason,0,180)); $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            json_response(['ok'=>true]);

        case 'hr_record_reassign':
            if(!hr_can($user,'hr.records.reassign')) json_response(['ok'=>false,'error'=>'You do not have permission to reassign active records'],403);
            $type=(string)($in['recordType']??''); $id=(int)($in['recordId']??0); $newOwner=(int)($in['newOwnerId']??0); $reason=trim((string)($in['reason']??''));
            $map=['review_participant'=>['review_participants','action_manager_id',"status NOT IN ('released')"],'goal'=>['goals','manager_id',"status NOT IN ('completed','missed')"],'pdp'=>['pdps','manager_id',"status NOT IN ('completed','cancelled')"],'pip'=>['pips','manager_id',"status NOT IN ('successful','unsuccessful','closed')"]];
            if(!isset($map[$type])||strlen($reason)<15||strlen($reason)>1000) json_response(['ok'=>false,'error'=>'Choose an active record and provide a reassignment reason'],422);
            $valid=db()->prepare("SELECT COUNT(*) FROM users WHERE id=? AND is_active=1 AND EXISTS(SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_code=users.role AND p.permission_code='manager.dashboard')"); $valid->execute([$newOwner]);
            if(!(bool)$valid->fetchColumn()) json_response(['ok'=>false,'error'=>'The new owner must be an active manager-capable user'],422);
            [$table,$column,$open]=$map[$type]; $pdo=db(); $pdo->beginTransaction();
            try{$stmt=$pdo->prepare("SELECT $column owner_id FROM $table WHERE id=? AND $open FOR UPDATE");$stmt->execute([$id]);$old=$stmt->fetchColumn();if($old===false){$pdo->rollBack();json_response(['ok'=>false,'error'=>'Active record not found'],404);} $pdo->prepare("UPDATE $table SET $column=? WHERE id=?")->execute([$newOwner,$id]);$pdo->prepare('INSERT INTO active_record_reassignments(record_type,record_id,previous_owner_id,new_owner_id,reason,reassigned_by) VALUES(?,?,?,?,?,?)')->execute([$type,$id,$old,$newOwner,$reason,$actorId]);audit($actorId,'REASSIGN_ACTIVE_RECORD',$type,$id,"{$old} -> {$newOwner}: ".substr($reason,0,150));$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            json_response(['ok'=>true]);

        case 'hr_competency_save':
            if(!hr_can($user,'hr.competencies.manage')) json_response(['ok'=>false,'error'=>'You do not have permission to manage competencies'],403);
            $id=(int)($in['id']??0);$name=trim((string)($in['name']??''));$description=trim((string)($in['description']??''));$active=filter_var($in['isActive']??true,FILTER_VALIDATE_BOOLEAN);
            if($name===''||strlen($name)>80||strlen($description)>255) json_response(['ok'=>false,'error'=>'Enter a competency name and optional description within the limits'],422);
            if($id){db()->prepare('UPDATE competencies SET name=?,description=?,is_active=? WHERE id=?')->execute([$name,$description?:null,$active?1:0,$id]);}else{db()->prepare('INSERT INTO competencies(name,description,is_active) VALUES(?,?,?)')->execute([$name,$description?:null,$active?1:0]);$id=(int)db()->lastInsertId();}
            audit($actorId,'SAVE_COMPETENCY','competency',$id,$name);json_response(['ok'=>true,'id'=>$id]);

                // Start a new review cycle.
        case "hr_cycle_create":
            if (!hr_can($user, "hr.cycles.manage")) {
                audit($actorId, "DENIED_PERMISSION", "permission", null, "hr.cycles.manage");
                json_response(
                    ["ok" => false, "error" => "You do not have permission to create review cycles"],
                    403,
                );
            }

            json_response([
                "ok" => true,
                "cycle" => hr_cycle_create($in, $user),
            ]);

        // Move a review cycle to the next visible stage.
        case "hr_cycle_advance":
            if (!hr_can($user, "hr.cycles.manage")) {
                audit($actorId, "DENIED_PERMISSION", "permission", null, "hr.cycles.manage");
                json_response(
                    ["ok" => false, "error" => "You do not have permission to manage review cycles"],
                    403,
                );
            }
            json_response([
                "ok" => true,
                "cycle" => hr_cycle_advance($in, $actorId),
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
            $revisedDeadline = trim((string)($in['responseDeadline']??''));
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
                    if (
                        !in_array($case["cycle_status"], ["open", "peer_review", "manager_review"], true) ||
                        in_array($case["participant_status"], ["manager_submitted", "released"], true)
                    ) {
                        $pdo->rollBack();
                        json_response(
                            ["ok" => false, "error" => "The peer-review window is closed, so the rejection cannot be overturned"],
                            409,
                        );
                    }
                    $deadline=$case['peer_deadline'];
                    if ($deadline && $deadline < date('Y-m-d')) {
                        if(!valid_date($revisedDeadline)||$revisedDeadline<date('Y-m-d')){
                            $pdo->rollBack();
                            json_response(['ok'=>false,'error'=>'Set a revised peer-response deadline when overturning a case after the ordinary deadline'],422);
                        }
                        $deadline=$revisedDeadline;
                    }
                    if (!$deadline || $deadline < date("Y-m-d")) {
                        $pdo->rollBack();
                        json_response(
                            ["ok" => false, "error" => "Set a valid response deadline for the reinstated peer request"],422
                        );
                    }
                    // The nomination row stays as the manager's own decision
                    // record; the escalation is the durable record of HR's
                    // override. Overwriting it here would break the invariant
                    // that an escalation only ever follows a rejection.
                    $pdo->prepare(
                        "INSERT INTO feedback_requests(participant_id,respondent_id,type,status,response_deadline)
                         VALUES(?,?,'peer','pending',?) ON DUPLICATE KEY UPDATE response_deadline=VALUES(response_deadline),status=IF(status='submitted','submitted','pending')",
                    )->execute([(int) $case["participant_id"], (int) $case["peer_id"],$deadline]);
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
            if(!pip_transition_allowed('hr',$current['status'],$status)){
                json_response(['ok'=>false,'error'=>'That PIP governance transition is not allowed'],409);
            }
            db()->prepare("UPDATE pips SET status=?,outcome_note=? WHERE id=?")
                ->execute([$status, $note !== "" ? $note : null, $id]);
            audit($actorId, "UPDATE_PIP_STATUS", "pip", $id, "Status " . $status);
            json_response(["ok" => true, "message" => "Improvement plan updated."]);

        default:
            json_response(["ok" => false, "error" => "Unknown action"], 404);
    }
}
