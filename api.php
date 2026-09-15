<?php
declare(strict_types=1);
require __DIR__ . "/config.php";

// Start the session and choose the requested API action.
start_app_session();
$action = $_GET["action"] ?? "";

require_once __DIR__ . "/work-steps.php";
require_once __DIR__ . "/workspaces.php";
require_once __DIR__ . "/peer-feedback.php";

try {
    if (in_array($action, ["peer_candidates", "nominate_peer"], true)) peer_feedback_api($action);
    if (str_starts_with((string) $action, "org_")) { organization_api((string) $action); }
    if (in_array($action, ["workspace", "work_item", "set_step_status", "submit_personal_feedback", "feedback_form"], true)) { workspace_api($action); }
    switch ($action) {
        // Login functions.
        case "login":
            require_method("POST");
            $in = input();
            $email = strtolower(trim((string) ($in["email"] ?? "")));
            $password = (string) ($in["password"] ?? "");
            if ($email === "" || $password === "") {
                json_response(
                    [
                        "ok" => false,
                        "error" => "Email and password are required",
                    ],
                    422,
                );
            }
            if (
                !filter_var($email, FILTER_VALIDATE_EMAIL) ||
                strlen($email) > 120
            ) {
                json_response(
                    ["ok" => false, "error" => "Enter a valid email address"],
                    422,
                );
            }
            $ip = client_ip() ?? "unknown";
            $attempts = db()->prepare(
                "SELECT COUNT(*) FROM login_attempts WHERE success=0 AND attempted_at >= " .
                    "DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND email=?",
            );
            $attempts->execute([$email]);
            $emailFailures = (int) $attempts->fetchColumn();
            $attempts = db()->prepare(
                "SELECT COUNT(*) FROM login_attempts WHERE success=0 AND attempted_at >= " .
                    "DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND ip_address=?",
            );
            $attempts->execute([$ip]);
            $ipFailures = (int) $attempts->fetchColumn();
            if ($emailFailures >= 5 || $ipFailures >= 25) {
                header("Retry-After: 900");
                json_response(
                    [
                        "ok" => false,
                        "error" =>
                            "Too many failed sign-in attempts. Try again in 15 minutes.",
                    ],
                    429,
                );
            }
            $stmt = db()->prepare(
                "SELECT u.id, u.emp_code, u.full_name, u.email, u.password_hash, u.role, " .
                    "u.job_title, (SELECT department_name FROM departments WHERE id=u.department_id) AS department, r.display_name AS role_name, r.dashboard_path FROM " .
                    "users u JOIN roles r ON r.role_code = u.role WHERE u.email = ? AND u.is_active = 1 " .
                    "AND r.is_active = 1 LIMIT 1",
            );
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if (!$user || !password_verify($password, $user["password_hash"])) {
                db()
                    ->prepare(
                        "INSERT INTO login_attempts(email,ip_address,success) VALUES(?,?,0)",
                    )
                    ->execute([$email, $ip]);
                json_response(
                    ["ok" => false, "error" => "Invalid email or password"],
                    401,
                );
            }
            session_regenerate_id(true);
            $_SESSION["user_id"] = (int) $user["id"];
            $_SESSION["role"] = $user["role"];
            $_SESSION["last_activity"] = time();
            $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
            db()
                ->prepare(
                    "DELETE FROM login_attempts WHERE email=? AND success=0",
                )
                ->execute([$email]);
            db()->exec(
                "DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
            );
            db()
                ->prepare(
                    "INSERT INTO login_attempts(email,ip_address,success) VALUES(?,?,1)",
                )
                ->execute([$email, $ip]);
            unset($user["password_hash"]);
            $user["permissions"] = permissions_for_role($user["role"]);
            $user["workspaces"] = available_workspaces($user);
            audit(
                (int) $user["id"],
                "LOGIN",
                "user",
                (int) $user["id"],
                "Successful login",
            );
            json_response([
                "ok" => true,
                "user" => $user,
                "csrfToken" => csrf_token(),
            ]);

        // Logout functions.
        case "logout":
            require_method("POST");
            require_csrf();
            $userId = (int) ($_SESSION["user_id"] ?? 0);
            if ($userId > 0) {
                try {
                    audit(
                        $userId,
                        "LOGOUT",
                        "user",
                        $userId,
                        "User signed out",
                    );
                } catch (Throwable $ignored) {
                }
            }
            destroy_app_session();
            json_response(["ok" => true]);

        // Current-user session details.
        case "me":
            require_method("GET");
            $user = require_login();
            $user["permissions"] = permissions_for_role($user["role"]);
            $user["workspaces"] = available_workspaces($user);
            json_response([
                "ok" => true,
                "user" => $user,
                "csrfToken" => csrf_token(),
            ]);

        // Signed-in password changes.
        case "change_password":
            require_method("POST");
            require_csrf();
            $user = require_permission("password.change");
            $in = input();
            $current = (string) ($in["currentPassword"] ?? "");
            $new = (string) ($in["newPassword"] ?? "");
            $confirm = (string) ($in["confirmPassword"] ?? "");
            if ($current === "" || $new === "" || $confirm === "") {
                json_response(
                    [
                        "ok" => false,
                        "error" => "All password fields are required",
                    ],
                    422,
                );
            }
            if (
                strlen($new) < 10 ||
                !preg_match("/[a-z]/", $new) ||
                !preg_match("/[A-Z]/", $new) ||
                !preg_match("/\d/", $new)
            ) {
                json_response(
                    [
                        "ok" => false,
                        "error" =>
                            "New password must be at least 10 characters and include uppercase, lowercase and a number",
                    ],
                    422,
                );
            }
            if ($new !== $confirm) {
                json_response(
                    ["ok" => false, "error" => "New passwords do not match"],
                    422,
                );
            }
            if ($current === $new) {
                json_response(
                    [
                        "ok" => false,
                        "error" =>
                            "New password must be different from the current password",
                    ],
                    422,
                );
            }
            $stmt = db()->prepare(
                "SELECT password_hash FROM users WHERE id = ? AND is_active = 1",
            );
            $stmt->execute([(int) $user["id"]]);
            $row = $stmt->fetch();
            if (!$row || !password_verify($current, $row["password_hash"])) {
                json_response(
                    ["ok" => false, "error" => "Current password is incorrect"],
                    401,
                );
            }
            $hash = password_hash($new, PASSWORD_DEFAULT);
            db()
                ->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                ->execute([$hash, (int) $user["id"]]);
            session_regenerate_id(true);
            $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
            audit(
                (int) $user["id"],
                "CHANGE_PASSWORD",
                "user",
                (int) $user["id"],
                "Password changed",
            );
            json_response([
                "ok" => true,
                "message" => "Password changed successfully",
                "csrfToken" => csrf_token(),
            ]);

        // Manager dashboard data.
        case "dashboard":
            require_method("GET");
            $manager = require_permission("manager.dashboard");
            $manager["permissions"] = permissions_for_role($manager["role"]);
            $manager["workspaces"] = available_workspaces($manager);
            $pdo = db();
            $mid = (int) $manager["id"];
            if (empty($_SESSION["manager_dashboard_audited"])) {
                audit(
                    $mid,
                    "VIEW_MANAGER_DASHBOARD",
                    "dashboard",
                    null,
                    "Opened manager dashboard",
                );
                $_SESSION["manager_dashboard_audited"] = true;
            }

            // Show descendants as directory-only rows. Sensitive performance data
            // remains limited to records explicitly owned by this manager.
            $descendantIds = has_permission($mid, "org.descendants.view")
                ? get_descendant_ids($mid)
                : [];
            $descendantClause = $descendantIds
                ? " OR u.id IN (" . implode(",", array_fill(0, count($descendantIds), "?")) . ")"
                : "";
            $stmt = $pdo->prepare(
                "SELECT u.id,u.full_name AS name,u.job_title AS role,u.email,d.department_name AS department,
                 CASE WHEN ar.reports_to_employee_id=? THEN 'Direct report' ELSE 'Assigned records' END AS scope,
                 ar.reports_to_employee_id AS directManagerId,direct_manager.full_name AS directManagerName
                 FROM users u JOIN departments d ON d.id=u.department_id
                 LEFT JOIN active_primary_relationships ar ON ar.employee_id=u.id
                 LEFT JOIN users direct_manager ON direct_manager.id=ar.reports_to_employee_id
                 WHERE u.is_active=1 AND u.id<>? AND (ar.reports_to_employee_id=?
                   OR u.id IN (SELECT employee_id FROM review_participants WHERE manager_id=?)
                   OR u.id IN (SELECT employee_id FROM goals WHERE manager_id=?)
                   OR u.id IN (SELECT employee_id FROM pdps WHERE manager_id=?)
                   OR u.id IN (SELECT employee_id FROM pips WHERE manager_id=?)" .
                   $descendantClause . ") ORDER BY u.full_name"
            );
            $stmt->execute(array_merge([$mid,$mid,$mid,$mid,$mid,$mid,$mid], $descendantIds));
            $employees = $stmt->fetchAll();

            foreach ($employees as &$employeeScope) {
                $employeeId = (int) $employeeScope["id"];
                if ($employeeScope["scope"] !== "Direct report"
                    && in_array($employeeId, $descendantIds, true)) {
                    $employeeScope["scope"] = "Descendant";
                }
                $employeeScope["canCreateRecords"] = manager_employee($mid, $employeeId);
            }
            unset($employeeScope);

            foreach ($employees as &$e) {
                $eid = (int) $e["id"];
                $stmt = $pdo->prepare(
                    "SELECT rp.id participant_id, rp.cycle_id, rc.name cycle_name, rc.manager_deadline, rc.status cycle_status, " .
                        "rp.status review_status, rp.final_rating rating, rp.manager_summary, " .
                        "owner.full_name review_manager FROM review_participants rp " .
                        "JOIN review_cycles rc ON rc.id=rp.cycle_id JOIN users owner ON owner.id=rp.manager_id WHERE " .
                        "rp.employee_id=? AND rp.manager_id=? ORDER BY rp.id DESC LIMIT 1",
                );
                $stmt->execute([$eid, $mid]);
                $review = $stmt->fetch() ?: null;
                $e["participantId"] = $review["participant_id"] ?? null;
                $e["cycleId"] = $review["cycle_id"] ?? null;
                $e["cycle"] = $review["cycle_name"] ?? "No review cycle";
                $e["managerDeadline"] = $review["manager_deadline"] ?? null;
                $e["cycleStatus"] = $review["cycle_status"] ?? null;
                $e["reviewStatusCode"] =
                    $review["review_status"] ?? "not_started";
                $e["managerSummary"] = $review["manager_summary"] ?? "";
                $e["reviewManager"] = $review["review_manager"] ?? null;
                $e["review"] = match (
                    $review["review_status"] ?? "not_started"
                ) {
                    "self_submitted" => "Self submitted",
                    "peers_complete" => "Peers complete",
                    "manager_submitted" => "Manager submitted",
                    "released" => "Released",
                    default => "Not started",
                };
                if (!$review) $e["review"] = "No review assigned";
                $e["rating"] =
                    $review !== null && $review["rating"] !== null
                        ? (float) $review["rating"]
                        : null;
                $stmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM goals WHERE employee_id=? AND manager_id=?",
                );
                $stmt->execute([$eid, $mid]);
                $e["goals"] = (int) $stmt->fetchColumn();
                $stmt = $pdo->prepare(
                    "SELECT COUNT(DISTINCT pa.id) action_count,COUNT(ws.id) step_count," .
                        "COALESCE(SUM(ws.is_completed),0) completed_steps FROM pdp_actions pa " .
                        "JOIN pdps p ON p.id=pa.pdp_id LEFT JOIN work_steps ws ON " .
                        "ws.pdp_action_id=pa.id WHERE p.employee_id=? AND p.manager_id=? " .
                        "AND pa.status <> 'cancelled' AND p.status <> 'cancelled'",
                );
                $stmt->execute([$eid, $mid]);
                $pdp = $stmt->fetch();
                $e["pdpActionCount"] = (int) $pdp["action_count"];
                $e["pdpCompletedSteps"] = (int) $pdp["completed_steps"];
                $e["pdpTotalSteps"] = (int) $pdp["step_count"];
                $stmt = $pdo->prepare(
                    "SELECT s.name, r.required_level, COALESCE(es.current_level,0) current_level FROM " .
                        "role_skill_requirements r JOIN skills s ON s.id=r.skill_id LEFT JOIN " .
                        "employee_skills es ON es.employee_id=? AND es.skill_id=r.skill_id WHERE " .
                        "r.job_title=? ORDER BY (r.required_level-COALESCE(es.current_level,0)) DESC, " .
                        "s.name",
                );
                $stmt->execute([$eid, $e["role"]]);
                $skills = [];
                foreach ((manager_employee($mid, $eid) ? $stmt->fetchAll() : []) as $s) {
                    $skills[] = [
                        $s["name"],
                        (int) $s["required_level"],
                        (int) $s["current_level"],
                    ];
                }
                $e["skills"] = $skills;
                $reviewNeedsAction = $review !== null && !in_array(
                    $e["reviewStatusCode"],
                    ["manager_submitted", "released"],
                    true,
                );
                $e["attention"] =
                    $e["scope"] !== "Descendant" && (
                        ($e["rating"] !== null && $e["rating"] < 3.5) ||
                        $reviewNeedsAction ||
                        ($e["pdpTotalSteps"] > $e["pdpCompletedSteps"]) ||
                        count(array_filter($skills, fn($s) => $s[2] < $s[1])) > 0
                    );
            }
            unset($e);

            $stmt = $pdo->prepare(
                "SELECT pn.id, pn.status, rp.employee_id, emp.full_name employee, pn.peer_id, " .
                    "peer.full_name peer FROM peer_nominations pn JOIN review_participants rp ON " .
                    "rp.id=pn.participant_id JOIN users emp ON emp.id=rp.employee_id JOIN users peer ON " .
                    "peer.id=pn.peer_id WHERE rp.manager_id=? ORDER BY pn.created_at DESC",
            );
            $stmt->execute([$mid]);
            $peerNominations = array_map(
                fn($r) => [
                    "id" => (int) $r["id"],
                    "employeeId" => (int) $r["employee_id"],
                    "employee" => $r["employee"],
                    "peerId" => (int) $r["peer_id"],
                    "peer" => $r["peer"],
                    "status" => $r["status"],
                ],
                $stmt->fetchAll(),
            );

            $stmt = $pdo->prepare(
                "SELECT g.id,g.employee_id, u.full_name employee,g.title,g.description " .
                    "target,g.due_date due,g.status FROM goals g JOIN users u " .
                    "ON u.id=g.employee_id WHERE g.manager_id=? AND g.employee_id<>? ORDER BY " .
                    "g.due_date",
            );
            $stmt->execute([$mid, $mid]);
            $goals = array_map(
                fn($r) => [
                    "id" => (int) $r["id"],
                    "employeeId" => (int) $r["employee_id"],
                    "employee" => $r["employee"],
                    "title" => $r["title"],
                    "target" => $r["target"],
                    "due" => $r["due"],
                    "status" => ucwords(str_replace("_", " ", $r["status"])),
                ],
                $stmt->fetchAll(),
            );
            foreach ($goals as &$goal) {
                $goal["steps"] = work_steps_for(
                    $pdo,
                    "goal",
                    (int) $goal["id"],
                    $mid,
                );
                $goal["progress"]=work_progress($goal["steps"],$goal["due"]);
            }
            unset($goal);

            $stmt = $pdo->prepare(
                "SELECT pa.id, p.employee_id, u.full_name employee, pa.title, pa.description, " .
                    "pa.due_date due, pa.status, p.id pdp_id FROM pdp_actions " .
                    "pa JOIN pdps p ON p.id=pa.pdp_id JOIN users u ON u.id=p.employee_id WHERE " .
                    "p.manager_id=? AND p.employee_id<>? AND p.status<>'cancelled' AND pa.status <> 'cancelled' ORDER BY pa.due_date",
            );
            $stmt->execute([$mid,$mid]);
            $pdps = array_map(
                fn($r) => [
                    "id" => (int) $r["id"],
                    "pdpId" => (int) $r["pdp_id"],
                    "employeeId" => (int) $r["employee_id"],
                    "employee" => $r["employee"],
                    "title" => $r["title"],
                    "description" => $r["description"],
                    "due" => $r["due"],
                    "status" => ucwords(str_replace("_", " ", $r["status"])),
                ],
                $stmt->fetchAll(),
            );
            foreach ($pdps as &$pdpAction) {
                $pdpAction["steps"] = work_steps_for(
                    $pdo,
                    "pdp_action",
                    (int) $pdpAction["id"],
                    $mid,
                );
                $pdpAction["progress"]=work_progress($pdpAction["steps"],$pdpAction["due"]);
            }
            unset($pdpAction);

            $stmt = $pdo->prepare(
                "SELECT p.id,p.employee_id,u.full_name employee,p.reason,p.start_date " .
                    "start,p.end_date end,p.status,hr.full_name hr_owner FROM pips p JOIN users u ON " .
                    "u.id=p.employee_id JOIN users hr ON hr.id=p.hr_owner_id WHERE p.manager_id=? ORDER " .
                    "BY p.end_date DESC",
            );
            $stmt->execute([$mid]);
            $pips = [];
            foreach ($stmt->fetchAll() as $p) {
                $pip = [
                    "id" => (int) $p["id"],
                    "employeeId" => (int) $p["employee_id"],
                    "employee" => $p["employee"],
                    "reason" => $p["reason"],
                    "start" => $p["start"],
                    "end" => $p["end"],
                    "status" => $p["status"],
                    "hrOwner" => $p["hr_owner"],
                    "objectives" => [],
                    "checkins" => [],
                ];
                $s = $pdo->prepare(
                    "SELECT id,objective,success_criteria,due_date due,status FROM pip_objectives WHERE " .
                        "pip_id=? ORDER BY id",
                );
                $s->execute([$pip["id"]]);
                foreach ($s->fetchAll() as $o) {
                    $pip["objectives"][] = [
                        "id" => (int) $o["id"],
                        "text" => $o["objective"],
                        "criteria" => $o["success_criteria"],
                        "due" => $o["due"],
                        "status" => $o["status"],
                        "steps" => work_steps_for(
                            $pdo,
                            "pip_objective",
                            (int) $o["id"],
                            $mid,
                        ),
                    ];
                }
                $s = $pdo->prepare(
                    "SELECT checkin_date date,notes FROM pip_checkins WHERE pip_id=? ORDER BY " .
                        "checkin_date DESC,id DESC",
                );
                $s->execute([$pip["id"]]);
                foreach ($s->fetchAll() as $c) {
                    $pip["checkins"][] = [
                        "date" => $c["date"],
                        "notes" => $c["notes"],
                    ];
                }
                $pips[] = $pip;
            }

            $stmt = $pdo->prepare(
                "SELECT id,name FROM competencies WHERE is_active=1 ORDER BY id",
            );
            $stmt->execute();
            $competencies = $stmt->fetchAll();

            $managerRatings = [];
            $feedback = [];
            foreach ($employees as $e) {
                if ($e["participantId"]) {
                    $mr = $pdo->prepare(
                        "SELECT frt.competency_id, frt.score, frt.comment FROM feedback_requests fr JOIN " .
                            "feedback_ratings frt ON frt.request_id=fr.id WHERE fr.participant_id=? AND " .
                            "fr.respondent_id=? AND fr.type='manager' AND fr.status='submitted'",
                    );
                    $mr->execute([(int) $e["participantId"], $mid]);
                    $managerRatings[(string) $e["id"]] = array_map(
                        fn($r) => [
                            "competencyId" => (int) $r["competency_id"],
                            "score" => (int) $r["score"],
                            "comment" => $r["comment"],
                        ],
                        $mr->fetchAll(),
                    );
                }
                if (!$e["participantId"]) {
                    continue;
                }
                $s = $pdo->prepare(
                    "SELECT competency,avg_score,responses FROM v_360_summary WHERE participant_id=? " .
                        "AND type='peer' ORDER BY competency",
                );
                $s->execute([(int) $e["participantId"]]);
                $rows = $s->fetchAll();
                $s2 = $pdo->prepare(
                    "SELECT COUNT(DISTINCT fr.id) FROM feedback_requests fr WHERE fr.participant_id=? " .
                        "AND fr.type='peer' AND fr.status='submitted'",
                );
                $s2->execute([(int) $e["participantId"]]);
                $count = (int) $s2->fetchColumn();
                $s3 = $pdo->prepare(
                    "SELECT COALESCE(min_peers,3) FROM review_cycles WHERE id=?",
                );
                $s3->execute([(int) $e["cycleId"]]);
                $min = (int) $s3->fetchColumn();
                $feedback[(string) $e["id"]] = [
                    "available" => $count >= $min,
                    "responses" => $count,
                    "required" => $min,
                    "competencies" =>
                        $count >= $min
                            ? array_map(
                                fn($r) => [
                                    "name" => $r["competency"],
                                    "score" => (float) $r["avg_score"],
                                    "responses" => (int) $r["responses"],
                                ],
                                $rows,
                            )
                            : [],
                ];
            }

            $s = $pdo->query(
                "SELECT DISTINCT u.id,u.full_name name FROM users u JOIN role_permissions rp ON rp.role_code=u.role JOIN permissions p ON p.id=rp.permission_id WHERE p.permission_code='hr.pips' AND p.is_active=1 AND u.is_active=1 ORDER BY u.full_name",
            );
            $hrOwners = array_map(
                fn($r) => ["id" => (int) $r["id"], "name" => $r["name"]],
                $s->fetchAll(),
            );

            $notifications = [];
            foreach ($employees as $e) {
                if (
                    $e["participantId"] &&
                    !in_array(
                        $e["reviewStatusCode"],
                        ["manager_submitted", "released"],
                        true,
                    )
                ) {
                    $notifications[] = [
                        "id" => "review-" . $e["id"],
                        "text" =>
                            "Manager review is pending for " . $e["name"] . ".",
                        "time" => "Current review cycle",
                        "unread" => true,
                    ];
                }
            }
            foreach ($peerNominations as $n) {
                if ($n["status"] === "pending") {
                    $notifications[] = [
                        "id" => "peer-" . $n["id"],
                        "text" =>
                            "A peer nomination requires approval for " .
                            $n["employee"] .
                            ".",
                        "time" => "Current review cycle",
                        "unread" => true,
                    ];
                }
            }
            foreach ($pdps as $p) {
                if (
                    $p["due"] <= date("Y-m-d", strtotime("+14 days")) &&
                    $p["status"] !== "Completed"
                ) {
                    $notifications[] = [
                        "id" => "pdp-" . $p["id"],
                        "text" =>
                            "PDP action for " .
                            $p["employee"] .
                            " is due soon.",
                        "time" => $p["due"],
                        "unread" => true,
                    ];
                }
            }
            $s = $pdo->prepare(
                "SELECT notification_key FROM notification_reads WHERE user_id=?",
            );
            $s->execute([$mid]);
            $readKeys = array_flip(
                array_column($s->fetchAll(), "notification_key"),
            );
            foreach ($notifications as &$notification) {
                $notification["unread"] = !isset(
                    $readKeys[$notification["id"]],
                );
            }
            unset($notification);
            $notifications=array_values(array_filter($notifications,fn($n)=>has_permission($mid,str_starts_with($n["id"],"pdp-")?"manager.goals":"manager.reviews")));

            foreach ($employees as &$row) {
                if (!has_permission($mid, "manager.reviews")) {
                    foreach (["participantId", "cycleId", "rating", "managerSummary"] as $key) $row[$key] = null;
                    $row["review"] = "Unavailable";
                    $row["reviewStatusCode"] = "unavailable";
                }
                if (!has_permission($mid, "manager.goals")) {
                    foreach (["goals", "pdpActionCount", "pdpCompletedSteps", "pdpTotalSteps"] as $key) $row[$key] = 0;
                }
            }
            unset($row);
            if (!has_permission($mid, "manager.reviews")) { $peerNominations=[]; $feedback=[]; $managerRatings=[]; }
            if (!has_permission($mid, "manager.goals")) { $goals=[]; $pdps=[]; }
            if (!has_permission($mid, "manager.pips")) { $pips=[]; $hrOwners=[]; }
            json_response([
                "ok" => true,
                "manager" => $manager,
                "employees" => $employees,
                "peerNominations" => $peerNominations,
                "goals" => $goals,
                "pdps" => $pdps,
                "pips" => $pips,
                "feedback" => $feedback,
                "managerRatings" => $managerRatings,
                "competencies" => $competencies,
                "hrOwners" => $hrOwners,
                "notifications" => $notifications,
                "csrfToken" => csrf_token(),
            ]);

        // Manager review submission.
        case "submit_review":
            require_method("POST");
            require_csrf();
            $manager = require_permission("manager.reviews");
            $in = input();
            $participantId = (int) ($in["participantId"] ?? 0);
            $participant = participant_for_manager(
                (int) $manager["id"],
                $participantId,
            );
            if (!$participant) {
                json_response(
                    ["ok" => false, "error" => "Review is not assigned to you"],
                    403,
                );
            }
            if ($participant["cycle_status"] !== "manager_review" ||
                ($participant["manager_deadline"] && $participant["manager_deadline"] < date("Y-m-d"))) {
                json_response(
                    [
                        "ok" => false,
                        "error" =>
                            "This review cycle is not accepting manager reviews",
                    ],
                    409,
                );
            }
            if (
                !in_array(
                    $participant["status"],
                    ["self_submitted", "peers_complete", "manager_submitted"],
                    true,
                )
            ) {
                json_response(
                    [
                        "ok" => false,
                        "error" =>
                            "The employee self-review must be submitted first",
                    ],
                    409,
                );
            }
            $rating = (float) ($in["rating"] ?? 0);
            if ($rating < 1 || $rating > 5) {
                json_response(
                    [
                        "ok" => false,
                        "error" => "Rating must be between 1 and 5",
                    ],
                    422,
                );
            }
            $summary = trim((string) ($in["summary"] ?? ""));
            if ($summary === "" || strlen($summary) > 5000) {
                json_response(
                    [
                        "ok" => false,
                        "error" =>
                            "Manager summary is required and must be under 5,000 characters",
                    ],
                    422,
                );
            }
            $competencyRatings = $in["competencies"] ?? [];
            if (!is_array($competencyRatings)) {
                json_response(
                    [
                        "ok" => false,
                        "error" => "Competency ratings are required",
                    ],
                    422,
                );
            }
            $activeIds = array_map(
                "intval",
                db()
                    ->query(
                        "SELECT id FROM competencies WHERE is_active=1 ORDER BY id",
                    )
                    ->fetchAll(PDO::FETCH_COLUMN),
            );
            $submittedIds = [];
            foreach ($competencyRatings as $c) {
                $cid = (int) ($c["competencyId"] ?? 0);
                $score = (int) ($c["score"] ?? 0);
                if ($cid < 1 || $score < 1 || $score > 5) {
                    json_response(
                        [
                            "ok" => false,
                            "error" =>
                                "Every active competency requires a score from 1 to 5",
                        ],
                        422,
                    );
                }
                $submittedIds[] = $cid;
            }
            sort($submittedIds);
            if ($submittedIds !== $activeIds) {
                json_response(
                    [
                        "ok" => false,
                        "error" =>
                            "Every active competency must be rated exactly once",
                    ],
                    422,
                );
            }
            $pdo = db();
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                "UPDATE review_participants SET final_rating=?, manager_summary=?, " .
                    "status='manager_submitted' WHERE id=? AND manager_id=?",
            );
            $stmt->execute([$rating, $summary, $participantId, $manager["id"]]);
            $stmt = $pdo->prepare(
                "SELECT id FROM feedback_requests WHERE participant_id=? AND respondent_id=? AND " .
                    "type='manager' LIMIT 1",
            );
            $stmt->execute([$participantId, $manager["id"]]);
            $req = $stmt->fetch();
            if (!$req) {
                $stmt = $pdo->prepare(
                    "INSERT INTO " .
                        "feedback_requests(participant_id,respondent_id,type,status,submitted_at) " .
                        "VALUES(?,?, 'manager','submitted',NOW())",
                );
                $stmt->execute([$participantId, $manager["id"]]);
                $reqId = (int) $pdo->lastInsertId();
            } else {
                $reqId = (int) $req["id"];
                $pdo->prepare(
                    "UPDATE feedback_requests SET status='submitted',submitted_at=NOW() WHERE id=?",
                )->execute([$reqId]);
            }
            foreach ($competencyRatings as $c) {
                $cid = (int) $c["competencyId"];
                $score = (int) $c["score"];
                $comment = trim((string) ($c["comment"] ?? ""));
                $stmt = $pdo->prepare(
                    "INSERT INTO feedback_ratings(request_id,competency_id,score,comment) " .
                        "VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE " .
                        "score=VALUES(score),comment=VALUES(comment)",
                );
                $stmt->execute([$reqId, $cid, $score, $comment]);
            }
            audit(
                (int) $manager["id"],
                "SUBMIT_MANAGER_REVIEW",
                "review_participant",
                $participantId,
                "Submitted manager review",
            );
            $pdo->commit();
            json_response(["ok" => true]);

        // Peer nomination decisions.
        case "decide_peer":
            require_method("POST");
            require_csrf();
            $manager = require_permission("manager.reviews");
            $in = input();
            decide_peer_nomination($manager, $in);

        // Goal creation.
        case "create_goal":
            require_method("POST");
            require_csrf();
            $manager = require_login();
            $in = input();
            $eid = (int) ($in["employeeId"] ?? 0);
            if ($eid === (int) $manager["id"] && !has_permission($eid,"employee.dashboard")) { json_response(["ok"=>false,"error"=>"Personal development is unavailable"],403); }
            if (
                $eid !== (int) $manager["id"] &&
                (!has_permission((int) $manager["id"], "manager.goals") ||
                    !manager_employee((int) $manager["id"], $eid))
            ) {
                json_response(
                    [
                        "ok" => false,
                        "error" => "Employee is not your direct report",
                    ],
                    403,
                );
            }
            $title = trim((string) ($in["title"] ?? ""));
            $target = trim((string) ($in["target"] ?? ""));
            $due = (string) ($in["due"] ?? "");
            $steps = normalize_work_steps($in["steps"] ?? null);
            if (
                $title === "" ||
                strlen($title) > 200 ||
                $target === "" ||
                strlen($target) > 5000 ||
                !valid_date($due)
            ) {
                json_response(
                    [
                        "ok" => false,
                        "error" =>
                            "A valid goal title, outcome and due date are required",
                    ],
                    422,
                );
            }
            if ($due < date("Y-m-d")) {
                json_response(
                    [
                        "ok" => false,
                        "error" => "Goal due date cannot be in the past",
                    ],
                    422,
                );
            }
            $pdo = db();
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                "INSERT INTO " .
                    "goals(employee_id,manager_id,title,description,due_date,status) " .
                    "VALUES(?,?,?,?,?,'not_started')",
            );
            $stmt->execute([$eid, $manager["id"], $title, $target, $due]);
            $id = (int) $pdo->lastInsertId();
            insert_work_steps(
                $pdo,
                "goal",
                $id,
                $steps,
                (int) $manager["id"],
            );
            audit(
                (int) $manager["id"],
                "CREATE_GOAL",
                "goal",
                $id,
                "Created employee goal",
            );
            $pdo->commit();
            json_response(["ok" => true, "id" => $id]);

        // Goal metadata updates. Step completion determines ordinary progress.
        case "update_goal":
            require_method("POST");
            require_csrf();
            $manager = require_login();
            $in = input();
            $id = (int) ($in["id"] ?? 0);
            $context=work_item_context(db(),"goal",$id);
            if (!$context || !can_access_work_item($context,(int)$manager['id'])) json_response(['ok'=>false,'error'=>'Work item not found'],404);
            assert_work_item_open($context);
            $status = strtolower(
                str_replace(
                    " ",
                    "_",
                    trim((string) ($in["status"] ?? "not_started")),
                ),
            );
            $title = trim((string) ($in["title"] ?? ""));
            if ($title === "" || strlen($title) > 200) {
                json_response(
                    [
                        "ok" => false,
                        "error" =>
                            "Goal title is required and must be under 200 characters",
                    ],
                    422,
                );
            }
            $stmt = db()->prepare(
                "SELECT id,status FROM goals WHERE id=? AND manager_id=?",
            );
            $stmt->execute([$id, $manager["id"]]);
            if (!$stmt->fetch()) {
                json_response(
                    ["ok" => false, "error" => "Goal not found"],
                    404,
                );
            }
            $allowed = ["not_started", "in_progress", "completed", "missed"];
            if (!in_array($status, $allowed, true)) {
                json_response(
                    ["ok" => false, "error" => "Invalid goal status"],
                    422,
                );
            }
            if ($status === "completed") {
                $count = db()->prepare(
                    "SELECT COUNT(*) total,COALESCE(SUM(is_completed),0) completed " .
                        "FROM work_steps WHERE goal_id=?",
                );
                $count->execute([$id]);
                $steps = $count->fetch();
                if (
                    (int) ($steps["total"] ?? 0) === 0 ||
                    (int) $steps["total"] !== (int) $steps["completed"]
                ) {
                    json_response(
                        [
                            "ok" => false,
                            "error" =>
                                "Complete every goal step before marking the goal completed",
                        ],
                        409,
                    );
                }
            }
            $pdo = db();
            $pdo->prepare(
                "UPDATE goals SET title=?,status=? WHERE id=? AND manager_id=?",
            )->execute([$title, $status, $id, $manager["id"]]);
            sync_work_item_status($pdo, work_item_context($pdo, "goal", $id));
            audit(
                (int) $manager["id"],
                "UPDATE_GOAL",
                "goal",
                $id,
                "Updated goal metadata/status",
            );
            json_response(["ok" => true]);

        // Personal development plan creation.
        case "create_pdp":
            require_method("POST");
            require_csrf();
            $manager = require_login();
            $in = input();
            $eid = (int) ($in["employeeId"] ?? 0);
            if ($eid === (int) $manager["id"] && !has_permission($eid,"employee.dashboard")) { json_response(["ok"=>false,"error"=>"Personal development is unavailable"],403); }
            if (
                $eid !== (int) $manager["id"] &&
                (!has_permission((int) $manager["id"], "manager.goals") ||
                    !manager_employee((int) $manager["id"], $eid))
            ) {
                json_response(
                    [
                        "ok" => false,
                        "error" => "Employee is not your direct report",
                    ],
                    403,
                );
            }
            $title = trim((string) ($in["title"] ?? ""));
            $description = trim((string) ($in["description"] ?? ""));
            $due = (string) ($in["due"] ?? "");
            $steps = normalize_work_steps($in["steps"] ?? null);
            if (
                $title === "" ||
                strlen($title) > 200 ||
                $description === "" ||
                strlen($description) > 5000 ||
                !valid_date($due)
            ) {
                json_response(
                    [
                        "ok" => false,
                        "error" =>
                            "PDP action, description and a valid due date are required",
                    ],
                    422,
                );
            }
            if ($due < date("Y-m-d")) {
                json_response(
                    [
                        "ok" => false,
                        "error" => "PDP due date cannot be in the past",
                    ],
                    422,
                );
            }
            $pdo = db();
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                "SELECT id FROM pdps WHERE employee_id=? AND manager_id=? AND status IN " .
                    "('draft','agreed') ORDER BY id DESC LIMIT 1",
            );
            $stmt->execute([$eid, $manager["id"]]);
            $p = $stmt->fetch();
            if ($p) {
                $pdpId = (int) $p["id"];
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO pdps(employee_id,manager_id,summary,status) VALUES(?,?,?,'draft')",
                );
                $stmt->execute([$eid, $manager["id"], "Development plan"]);
                $pdpId = (int) $pdo->lastInsertId();
            }
            $stmt = $pdo->prepare(
                "INSERT INTO pdp_actions(pdp_id,title,description,due_date,status) " .
                    "VALUES(?,?,?,?,'not_started')",
            );
            $stmt->execute([$pdpId, $title, $description, $due]);
            $id = (int) $pdo->lastInsertId();
            insert_work_steps(
                $pdo,
                "pdp_action",
                $id,
                $steps,
                (int) $manager["id"],
            );
            audit(
                (int) $manager["id"],
                "CREATE_PDP_ACTION",
                "pdp_action",
                $id,
                "Created PDP action",
            );
            $pdo->commit();
            json_response(["ok" => true, "id" => $id]);

        // Personal development metadata updates. Steps determine progress.
        case "update_pdp":
            require_method("POST");
            require_csrf();
            $manager = require_login();
            $in = input();
            $id = (int) ($in["id"] ?? 0);
            $context=work_item_context(db(),"pdp_action",$id);
            if (!$context || !can_access_work_item($context,(int)$manager['id'])) json_response(['ok'=>false,'error'=>'Work item not found'],404);
            assert_work_item_open($context);
            $status = strtolower(
                str_replace(
                    " ",
                    "_",
                    trim((string) ($in["status"] ?? "not_started")),
                ),
            );
            $title = trim((string) ($in["title"] ?? ""));
            $note = trim((string) ($in["note"] ?? ""));
            if ($title === "" || strlen($title) > 200 || strlen($note) > 5000) {
                json_response(
                    [
                        "ok" => false,
                        "error" =>
                            "A valid PDP title and note under 5,000 characters are required",
                    ],
                    422,
                );
            }
            $allowed = [
                "not_started",
                "in_progress",
                "completed",
                "overdue",
                "cancelled",
            ];
            if (!in_array($status, $allowed, true)) {
                json_response(
                    ["ok" => false, "error" => "Invalid PDP status"],
                    422,
                );
            }
            if ($status === "completed") {
                $count = db()->prepare(
                    "SELECT COUNT(*) total,COALESCE(SUM(is_completed),0) completed " .
                        "FROM work_steps WHERE pdp_action_id=?",
                );
                $count->execute([$id]);
                $steps = $count->fetch();
                if (
                    (int) ($steps["total"] ?? 0) === 0 ||
                    (int) $steps["total"] !== (int) $steps["completed"]
                ) {
                    json_response(
                        [
                            "ok" => false,
                            "error" =>
                                "Complete every PDP step before marking the action completed",
                        ],
                        409,
                    );
                }
            }
            $pdo = db();
            $stmt = $pdo->prepare(
                "SELECT pa.id FROM pdp_actions pa JOIN pdps p ON p.id=pa.pdp_id WHERE pa.id=? AND p.manager_id=?",
            );
            $stmt->execute([$id, $manager["id"]]);
            if (!$stmt->fetch()) {
                json_response(
                    ["ok" => false, "error" => "PDP action not found"],
                    404,
                );
            }
            $completedAt = $status === "completed" ? "NOW()" : "NULL";
            $pdo->prepare(
                "UPDATE pdp_actions SET title=?,status=?,completed_at=$completedAt WHERE id=?",
            )->execute([$title, $status, $id]);
            if ($note !== "") {
                $pdo->prepare(
                    "INSERT INTO action_updates(action_id,author_id,note,new_status) VALUES(?,?,?,?)",
                )->execute([$id, $manager["id"], $note, $status]);
            }
            sync_work_item_status($pdo, work_item_context($pdo, "pdp_action", $id));
            audit(
                (int) $manager["id"],
                "UPDATE_PDP_ACTION",
                "pdp_action",
                $id,
                "Updated PDP action",
            );
            json_response(["ok" => true]);

        // Performance improvement plan creation.
        case "create_pip":
            require_method("POST");
            require_csrf();
            $manager = require_permission("manager.pips");
            $in = input();
            $eid = (int) ($in["employeeId"] ?? 0);
            if (!manager_employee((int) $manager["id"], $eid)) {
                json_response(
                    [
                        "ok" => false,
                        "error" => "Employee is not your direct report",
                    ],
                    403,
                );
            }
            $hr = (int) ($in["hrOwnerId"] ?? 0);
            $stmt = db()->prepare(
                "SELECT DISTINCT u.id FROM users u JOIN role_permissions rp ON rp.role_code=u.role JOIN permissions p ON p.id=rp.permission_id WHERE u.id=? AND u.is_active=1 AND p.permission_code='hr.pips' AND p.is_active=1",
            );
            $stmt->execute([$hr]);
            if (!$stmt->fetch()) {
                json_response(
                    ["ok" => false, "error" => "A valid HR owner is required"],
                    422,
                );
            }
            $reason = trim((string) ($in["reason"] ?? ""));
            $start = (string) ($in["start"] ?? "");
            $end = (string) ($in["end"] ?? "");
            $objective = trim((string) ($in["objective"] ?? ""));
            $criteria = trim((string) ($in["criteria"] ?? ""));
            $due = (string) ($in["objectiveDue"] ?? "");
            $steps = normalize_work_steps($in["steps"] ?? null);
            if (
                $reason === "" ||
                strlen($reason) > 5000 ||
                !valid_date($start) ||
                !valid_date($end) ||
                $objective === "" ||
                strlen($objective) > 255 ||
                $criteria === "" ||
                strlen($criteria) > 5000 ||
                !valid_date($due)
            ) {
                json_response(
                    [
                        "ok" => false,
                        "error" =>
                            "Reason, valid dates, objective and expected evidence are required",
                    ],
                    422,
                );
            }
            if ($end < $start) {
                json_response(
                    [
                        "ok" => false,
                        "error" =>
                            "PIP end date must be on or after the start date",
                    ],
                    422,
                );
            }
            if ($due < $start || $due > $end) {
                json_response(
                    [
                        "ok" => false,
                        "error" =>
                            "Objective due date must fall within the PIP period",
                    ],
                    422,
                );
            }
            $pdo = db();
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                "INSERT INTO " .
                    "pips(employee_id,manager_id,hr_owner_id,reason,start_date,end_date,status) " .
                    "VALUES(?,?,?,?,?,?,'draft')",
            );
            $stmt->execute([$eid, $manager["id"], $hr, $reason, $start, $end]);
            $id = (int) $pdo->lastInsertId();
            $pdo->prepare(
                "INSERT INTO pip_objectives(pip_id,objective,success_criteria,due_date) VALUES(?,?,?,?)",
            )->execute([$id, $objective, $criteria, $due]);
            $objectiveId = (int) $pdo->lastInsertId();
            insert_work_steps(
                $pdo,
                "pip_objective",
                $objectiveId,
                $steps,
                (int) $manager["id"],
            );
            audit(
                (int) $manager["id"],
                "CREATE_PIP",
                "pip",
                $id,
                "Created PIP draft",
            );
            $pdo->commit();
            json_response(["ok" => true, "id" => $id]);

        // Performance improvement objectives.
        case "add_pip_objective":
            require_method("POST");
            require_csrf();
            $manager = require_permission("manager.pips");
            $in = input();
            $id = (int) ($in["pipId"] ?? 0);
            $objective = trim((string) ($in["objective"] ?? ""));
            $criteria = trim((string) ($in["criteria"] ?? ""));
            $due = (string) ($in["due"] ?? "");
            $steps = normalize_work_steps($in["steps"] ?? null);
            $stmt = db()->prepare(
                "SELECT id,start_date,end_date,status FROM pips WHERE id=? AND manager_id=?",
            );
            $stmt->execute([$id, $manager["id"]]);
            $pip = $stmt->fetch();
            if (!$pip) {
                json_response(["ok" => false, "error" => "PIP not found"], 404);
            }
            if (
                in_array(
                    $pip["status"],
                    ["successful", "unsuccessful", "closed"],
                    true,
                )
            ) {
                json_response(
                    [
                        "ok" => false,
                        "error" =>
                            "Objectives cannot be added to a completed PIP",
                    ],
                    409,
                );
            }
            if (
                $objective === "" ||
                strlen($objective) > 255 ||
                $criteria === "" ||
                strlen($criteria) > 5000 ||
                !valid_date($due)
            ) {
                json_response(
                    [
                        "ok" => false,
                        "error" =>
                            "Objective, expected evidence and a valid due date are required",
                    ],
                    422,
                );
            }
            if ($due < $pip["start_date"] || $due > $pip["end_date"]) {
                json_response(
                    [
                        "ok" => false,
                        "error" =>
                            "Objective due date must fall within the PIP period",
                    ],
                    422,
                );
            }
            $stmt = db()->prepare(
                "INSERT INTO pip_objectives(pip_id,objective,success_criteria,due_date) VALUES(?,?,?,?)",
            );
            $stmt->execute([$id, $objective, $criteria, $due]);
            $objectiveId = (int) db()->lastInsertId();
            insert_work_steps(
                db(),
                "pip_objective",
                $objectiveId,
                $steps,
                (int) $manager["id"],
            );
            audit(
                (int) $manager["id"],
                "ADD_PIP_OBJECTIVE",
                "pip",
                $id,
                "Added PIP objective",
            );
            json_response(["ok" => true]);

        // Performance improvement objective status updates.
        case "update_pip_objective":
            require_method("POST");
            require_csrf();
            require_login();
            json_response(
                [
                    "ok" => false,
                    "error" =>
                        "PIP objective status is calculated from its completed steps",
                ],
                409,
            );

        // Performance improvement check-ins.
        case "add_pip_checkin":
            require_method("POST");
            require_csrf();
            $manager = require_permission("manager.pips");
            $in = input();
            $pipId = (int) ($in["pipId"] ?? 0);
            $notes = trim((string) ($in["notes"] ?? ""));
            $date = (string) ($in["date"] ?? date("Y-m-d"));
            $stmt = db()->prepare(
                "SELECT id,start_date,end_date,status FROM pips WHERE id=? AND manager_id=?",
            );
            $stmt->execute([$pipId, $manager["id"]]);
            $pip = $stmt->fetch();
            if (!$pip) {
                json_response(["ok" => false, "error" => "PIP not found"], 404);
            }
            if (
                in_array(
                    $pip["status"],
                    ["successful", "unsuccessful", "closed"],
                    true,
                )
            ) {
                json_response(
                    [
                        "ok" => false,
                        "error" =>
                            "Check-ins cannot be added to a completed PIP",
                    ],
                    409,
                );
            }
            if ($notes === "" || strlen($notes) > 5000 || !valid_date($date)) {
                json_response(
                    [
                        "ok" => false,
                        "error" =>
                            "Check-in date and notes under 5,000 characters are required",
                    ],
                    422,
                );
            }
            if ($date < $pip["start_date"] || $date > $pip["end_date"]) {
                json_response(
                    [
                        "ok" => false,
                        "error" =>
                            "Check-in date must fall within the PIP period",
                    ],
                    422,
                );
            }
            $pdo = db();
            $pdo->prepare(
                "INSERT INTO pip_checkins(pip_id,checkin_date,author_id,notes) VALUES(?,?,?,?)",
            )->execute([$pipId, $date, $manager["id"], $notes]);
            $pdo->prepare(
                "UPDATE pips SET status=IF(status='draft','active',status) WHERE id=?",
            )->execute([$pipId]);
            audit(
                (int) $manager["id"],
                "ADD_PIP_CHECKIN",
                "pip",
                $pipId,
                "Recorded PIP check-in",
            );
            json_response(["ok" => true]);

        // Performance improvement workflow outcomes.
        case "update_pip_status":
            require_method("POST");
            require_csrf();
            $manager = require_permission("manager.pips");
            $in = input();
            $id = (int) ($in["id"] ?? 0);
            $status = (string) ($in["status"] ?? "");
            $note = trim((string) ($in["note"] ?? ""));
            if (
                !in_array(
                    $status,
                    [
                        "draft",
                        "active",
                        "extended",
                        "successful",
                        "unsuccessful",
                        "closed",
                    ],
                    true,
                )
            ) {
                json_response(
                    ["ok" => false, "error" => "Invalid PIP status"],
                    422,
                );
            }
            if (strlen($note) > 5000) {
                json_response(
                    [
                        "ok" => false,
                        "error" =>
                            "Outcome note must be under 5,000 characters",
                    ],
                    422,
                );
            }
            $pdo = db();
            $stmt = $pdo->prepare(
                "SELECT id,status FROM pips WHERE id=? AND manager_id=?",
            );
            $stmt->execute([$id, $manager["id"]]);
            $pip = $stmt->fetch();
            if (!$pip) {
                json_response(["ok" => false, "error" => "PIP not found"], 404);
            }
            $transitions = [
                "draft" => ["draft", "active", "closed"],
                "active" => [
                    "active",
                    "extended",
                    "successful",
                    "unsuccessful",
                    "closed",
                ],
                "extended" => [
                    "extended",
                    "successful",
                    "unsuccessful",
                    "closed",
                ],
                "successful" => ["successful", "closed"],
                "unsuccessful" => ["unsuccessful", "closed"],
                "closed" => ["closed"],
            ];
            if (!in_array($status, $transitions[$pip["status"]] ?? [], true)) {
                json_response(
                    [
                        "ok" => false,
                        "error" => "That PIP status transition is not allowed",
                    ],
                    409,
                );
            }
            if (
                in_array(
                    $status,
                    ["extended", "successful", "unsuccessful", "closed"],
                    true,
                ) &&
                $note === ""
            ) {
                json_response(
                    [
                        "ok" => false,
                        "error" =>
                            "An outcome note is required for this status",
                    ],
                    422,
                );
            }
            $pdo->prepare(
                "UPDATE pips SET status=?,outcome_note=? WHERE id=?",
            )->execute([$status, $note ?: null, $id]);
            audit(
                (int) $manager["id"],
                "UPDATE_PIP_STATUS",
                "pip",
                $id,
                "Updated PIP status from " . $pip["status"] . " to " . $status,
            );
            json_response(["ok" => true]);

        // Mark one actionable step complete or incomplete. Both the assignee
        // and the assigning person can record completion.
        case "toggle_work_step":
            workspace_api("set_step_status");

        // Add a step only when the signed-in user authored the task's steps.
        case "add_work_step":
            require_method("POST");
            require_csrf();
            $user = require_login();
            $in = input();
            $type = (string) ($in["type"] ?? "");
            $workId = (int) ($in["workId"] ?? 0);
            $title = trim((string) ($in["title"] ?? ""));
            if ($title === "" || strlen($title) > 500) {
                json_response(
                    [
                        "ok" => false,
                        "error" =>
                            "Step text is required and must be under 500 characters",
                    ],
                    422,
                );
            }
            $pdo = db();
            $context = work_item_context($pdo, $type, $workId);
            if (!$context) {
                json_response(
                    ["ok" => false, "error" => "Work item not found"],
                    404,
                );
            }
            $userId = (int) $user["id"];
            if (!can_access_work_item($context, $userId)) {
                json_response(
                    ["ok" => false, "error" => "Work item not found"],
                    404,
                );
            }
            assert_work_item_open($context);
            $pdo->exec("SET TRANSACTION ISOLATION LEVEL READ COMMITTED");
            $pdo->beginTransaction();
            $context=lock_work_item($pdo,$context);
            $column = work_step_column($type);
            $stmt = $pdo->prepare(
                "SELECT created_by FROM work_steps WHERE $column=? ORDER BY step_order,id LIMIT 1",
            );
            $stmt->execute([$workId]);
            $creator = $stmt->fetchColumn();
            if (
                ($creator !== false && (int) $creator !== $userId) ||
                ($creator === false &&
                    !in_array(
                        $userId,
                        [
                            (int) $context["employee_id"],
                            (int) $context["manager_id"],
                        ],
                        true,
                    ))
            ) {
                json_response(
                    [
                        "ok" => false,
                        "error" =>
                            "Only the person who set up these steps can edit them",
                    ],
                    403,
                );
            }
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) total,COALESCE(MAX(step_order),0) last_order " .
                    "FROM work_steps WHERE $column=?",
            );
            $stmt->execute([$workId]);
            $position = $stmt->fetch();
            if ((int) $position["total"] >= 20) {
                json_response(
                    ["ok" => false, "error" => "A work item can have up to 20 steps"],
                    409,
                );
            }
            $pdo->prepare(
                "INSERT INTO work_steps($column,title,step_order,created_by) VALUES(?,?,?,?)",
            )->execute([
                $workId,
                $title,
                (int) $position["last_order"] + 1,
                $userId,
            ]);
            $newId = (int) $pdo->lastInsertId();
            sync_work_item_status($pdo, $context);
            audit(
                $userId,
                "ADD_WORK_STEP",
                "work_step",
                $newId,
                "Added actionable step",
            );
            $pdo->commit();
            json_response(["ok" => true, "id" => $newId]);

        // Rewrite a step only when the signed-in user originally authored it.
        case "update_work_step":
            require_method("POST");
            require_csrf();
            $user = require_login();
            $in = input();
            $stepId = (int) ($in["id"] ?? 0);
            $title = trim((string) ($in["title"] ?? ""));
            if ($title === "" || strlen($title) > 500) {
                json_response(
                    [
                        "ok" => false,
                        "error" =>
                            "Step text is required and must be under 500 characters",
                    ],
                    422,
                );
            }
            $pdo = db();
            $context = work_step_context($pdo, $stepId);
            if (!$context) {
                json_response(["ok" => false, "error" => "Step not found"], 404);
            }
            $userId = (int) $user["id"];
            if ((int) $context["created_by"] !== $userId || !can_access_work_item($context, $userId)) {
                json_response(
                    [
                        "ok" => false,
                        "error" =>
                            "Only the person who set up this step can edit it",
                    ],
                    403,
                );
            }
            assert_work_item_open($context);
            $pdo->prepare("UPDATE work_steps SET title=?,version=version+1 WHERE id=?")->execute([
                $title,
                $stepId,
            ]);
            audit(
                $userId,
                "UPDATE_WORK_STEP",
                "work_step",
                $stepId,
                "Updated actionable step",
            );
            json_response(["ok" => true]);

        // Remove a step only when its author owns the complete step set.
        case "delete_work_step":
            require_method("POST");
            require_csrf();
            $user = require_login();
            $in = input();
            $stepId = (int) ($in["id"] ?? 0);
            $pdo = db();
            $context = work_step_context($pdo, $stepId);
            if (!$context) {
                json_response(["ok" => false, "error" => "Step not found"], 404);
            }
            $userId = (int) $user["id"];
            if ((int) $context["created_by"] !== $userId || !can_access_work_item($context, $userId)) {
                json_response(
                    [
                        "ok" => false,
                        "error" =>
                            "Only the person who set up this step can remove it",
                    ],
                    403,
                );
            }
            assert_work_item_open($context);
            $pdo->exec("SET TRANSACTION ISOLATION LEVEL READ COMMITTED");
            $pdo->beginTransaction();
            $context=lock_work_item($pdo,$context);
            $column = work_step_column((string) $context["work_type"]);
            $stmt = $pdo->prepare(
                "SELECT id FROM work_steps WHERE $column=? ORDER BY step_order,id",
            );
            $stmt->execute([(int) $context["work_id"]]);
            $stepIds = array_map("intval", array_column($stmt->fetchAll(), "id"));
            if (count($stepIds) <= 1) {
                json_response(
                    [
                        "ok" => false,
                        "error" => "A work item must keep at least one step",
                    ],
                    409,
                );
            }
            $pdo->prepare("DELETE FROM work_steps WHERE id=?")->execute([$stepId]);
            $stepIds = array_values(array_filter($stepIds, fn($id) => $id !== $stepId));
            $reorder = $pdo->prepare(
                "UPDATE work_steps SET step_order=? WHERE id=?",
            );
            foreach ($stepIds as $index => $remainingId) {
                $reorder->execute([$index + 1, $remainingId]);
            }
            sync_work_item_status($pdo, $context);
            audit(
                $userId,
                "DELETE_WORK_STEP",
                "work_step",
                $stepId,
                "Removed actionable step",
            );
            $pdo->commit();
            json_response(["ok" => true]);

        // Notification read-state updates.
        case "mark_notifications_read":
            require_method("POST");
            require_csrf();
            $manager = require_permission("manager.dashboard");
            $in = input();
            $ids = $in["ids"] ?? [];
            if (!is_array($ids)) {
                json_response(
                    ["ok" => false, "error" => "Notification list is invalid"],
                    422,
                );
            }
            $ids = array_slice(
                array_values(
                    array_unique(
                        array_filter(
                            array_map(fn($v) => trim((string) $v), $ids),
                            fn($v) => preg_match('/^[A-Za-z0-9-]{1,80}$/', $v),
                        ),
                    ),
                ),
                0,
                100,
            );
            $pdo = db();
            $stmt = $pdo->prepare(
                "INSERT INTO notification_reads(user_id,notification_key) VALUES(?,?) ON DUPLICATE " .
                    "KEY UPDATE read_at=CURRENT_TIMESTAMP",
            );
            foreach ($ids as $id) {
                $stmt->execute([(int) $manager["id"], $id]);
            }
            audit(
                (int) $manager["id"],
                "MARK_NOTIFICATIONS_READ",
                "notification",
                null,
                "Marked " . count($ids) . " notifications as read",
            );
            json_response(["ok" => true, "count" => count($ids)]);

        // Permission lookup for the signed-in role.
        case "my_permissions":
            require_method("GET");
            $user = require_login();
            json_response([
                "ok" => true,
                "role" => $user["role"],
                "permissions" => permissions_for_role($user["role"]),
                "csrfToken" => csrf_token(),
            ]);

        default:
            json_response(["ok" => false, "error" => "Unknown action"], 404);
    }
    // Convert unexpected backend errors into safe API responses.
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("PPPM API error: " . $e->getMessage());
    json_response(
        [
            "ok" => false,
            "error" => APP_DEBUG
                ? $e->getMessage()
                : "A server error occurred. Please try again or contact the administrator.",
        ],
        500,
    );
}
