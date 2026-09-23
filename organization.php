<?php
declare(strict_types=1);

/**
 * Organization hierarchy services.
 *
 * Reporting relationships define the live organization. The manager_id fields
 * on reviews, goals, PDPs and PIPs remain immutable ownership/snapshot fields.
 */

function organization_date(?string $date = null): string
{
    $date = $date ?? date("Y-m-d");
    if (!valid_date($date)) {
        json_response(["ok" => false, "error" => "Enter a valid date"], 422);
    }
    return $date;
}

function organization_user_exists(int $userId, bool $activeOnly = true): bool
{
    $sql = "SELECT COUNT(*) FROM users WHERE id=?" . ($activeOnly ? " AND is_active=1" : "");
    $stmt = db()->prepare($sql);
    $stmt->execute([$userId]);
    return (bool) $stmt->fetchColumn();
}

function active_primary_manager(int $employeeId, ?string $onDate = null): ?int
{
    $onDate = $onDate ?: date("Y-m-d");
    $stmt = db()->prepare(
        "SELECT reports_to_employee_id FROM reporting_relationships
         WHERE employee_id=? AND relationship_type='primary'
           AND effective_from<=? AND (effective_to IS NULL OR effective_to>?)
         ORDER BY effective_from DESC,id DESC LIMIT 1",
    );
    $stmt->execute([$employeeId, $onDate, $onDate]);
    $managerId = $stmt->fetchColumn();
    return $managerId === false ? null : (int) $managerId;
}

function get_direct_reports(int $managerId, ?string $onDate = null): array
{
    $onDate = $onDate ?: date("Y-m-d");
    $stmt = db()->prepare(
        "SELECT u.id,u.emp_code,u.full_name,u.email,u.job_title,u.role,
                d.department_name,t.team_name
         FROM reporting_relationships rr
         JOIN users u ON u.id=rr.employee_id AND u.is_active=1
         JOIN departments d ON d.id=u.department_id
         LEFT JOIN teams t ON t.id=u.team_id
         WHERE rr.reports_to_employee_id=? AND rr.relationship_type='primary'
           AND rr.effective_from<=? AND (rr.effective_to IS NULL OR rr.effective_to>?)
         ORDER BY u.full_name",
    );
    $stmt->execute([$managerId, $onDate, $onDate]);
    return $stmt->fetchAll();
}

function get_descendants(int $managerId, ?string $onDate = null): array
{
    $onDate = $onDate ?: date("Y-m-d");
    $frontier = [$managerId];
    $visited = [$managerId => true];
    $descendants = [];
    $depth = 1;
    while ($frontier) {
        $placeholders = implode(",", array_fill(0, count($frontier), "?"));
        $sql = "SELECT u.id,u.emp_code,u.full_name,u.email,u.job_title,u.role,
                       d.department_name,t.team_name,rr.reports_to_employee_id
                FROM reporting_relationships rr
                JOIN users u ON u.id=rr.employee_id AND u.is_active=1
                JOIN departments d ON d.id=u.department_id
                LEFT JOIN teams t ON t.id=u.team_id
                WHERE rr.reports_to_employee_id IN ({$placeholders})
                  AND rr.relationship_type='primary' AND rr.effective_from<=?
                  AND (rr.effective_to IS NULL OR rr.effective_to>?)
                ORDER BY u.full_name";
        $stmt = db()->prepare($sql);
        $stmt->execute(array_merge($frontier, [$onDate, $onDate]));
        $next = [];
        foreach ($stmt->fetchAll() as $row) {
            $id = (int) $row["id"];
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;
            $row["depth"] = $depth;
            $descendants[] = $row;
            $next[] = $id;
        }
        $frontier = $next;
        $depth++;
    }
    return $descendants;
}

function get_descendant_ids(int $managerId, ?string $onDate = null): array
{
    return array_map(fn(array $row): int => (int) $row["id"], get_descendants($managerId, $onDate));
}

function get_reporting_path(int $employeeId, ?string $onDate = null): array
{
    $onDate = $onDate ?: date("Y-m-d");
    $sql = "SELECT u.id,u.emp_code,u.full_name,u.job_title,u.role,
                   d.department_name,t.team_name
            FROM users u JOIN departments d ON d.id=u.department_id
            LEFT JOIN teams t ON t.id=u.team_id
            WHERE u.id=? AND u.is_active=1";
    $path = [];
    $visited = [];
    $currentId = $employeeId;
    $distance = 0;
    while ($currentId !== null) {
        if (isset($visited[$currentId])) {
            break;
        }
        $visited[$currentId] = true;
        $stmt = db()->prepare($sql);
        $stmt->execute([$currentId]);
        $row = $stmt->fetch();
        if (!$row) {
            break;
        }
        $row["distance"] = $distance;
        $path[] = $row;
        $currentId = active_primary_manager($currentId, $onDate);
        $distance++;
    }
    return $path;
}

function is_authorized_ancestor(int $ancestorId, int $employeeId, ?string $onDate = null): bool
{
    return in_array($employeeId, get_descendant_ids($ancestorId, $onDate), true);
}

function would_create_reporting_cycle(int $employeeId, int $managerId, string $effectiveFrom): bool
{
    return $employeeId === $managerId || is_authorized_ancestor($employeeId, $managerId, $effectiveFrom);
}

function assign_primary_manager(
    int $employeeId,
    int $managerId,
    string $effectiveFrom,
    int $createdBy,
    ?string $reason = null,
): int {
    if (!organization_user_exists($employeeId) || !organization_user_exists($managerId)) {
        throw new InvalidArgumentException("Employee and manager must be active accounts");
    }
    if ($employeeId === $managerId) {
        throw new InvalidArgumentException("An employee cannot report to themselves");
    }
    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $pdo->query("SELECT id FROM organization_lock WHERE id=1 FOR UPDATE")->fetchColumn();
        if (would_create_reporting_cycle($employeeId, $managerId, $effectiveFrom)) {
            throw new InvalidArgumentException("This change would create a circular reporting hierarchy");
        }
        $stmt = $pdo->prepare(
            "SELECT id,reports_to_employee_id,effective_from FROM reporting_relationships
             WHERE employee_id=? AND relationship_type='primary' AND effective_to IS NULL
             ORDER BY effective_from DESC,id DESC LIMIT 1 FOR UPDATE",
        );
        $stmt->execute([$employeeId]);
        $current = $stmt->fetch();
        if ($current) {
            if ((int) $current["reports_to_employee_id"] === $managerId) {
                throw new InvalidArgumentException("That employee already has this primary manager");
            }
            if ($effectiveFrom < $current["effective_from"]) {
                throw new InvalidArgumentException("The new effective date cannot be before the current relationship start date");
            }
            if ($effectiveFrom === $current["effective_from"]) {
                // Replacing a relationship created today cannot produce a
                // valid historical interval, so replace its zero-day row.
                $pdo->prepare("DELETE FROM reporting_relationships WHERE id=?")
                    ->execute([(int) $current["id"]]);
            } else {
                $pdo->prepare("UPDATE reporting_relationships SET effective_to=? WHERE id=?")
                    ->execute([$effectiveFrom, (int) $current["id"]]);
            }
        }
        $stmt = $pdo->prepare(
            "INSERT INTO reporting_relationships
             (employee_id,reports_to_employee_id,relationship_type,effective_from,created_by,change_reason)
             VALUES (?,?,'primary',?,?,?)",
        );
        $stmt->execute([$employeeId, $managerId, $effectiveFrom, $createdBy, $reason]);
        $id = (int) $pdo->lastInsertId();
        if($current){
            $previous=(int)$current['reports_to_employee_id'];
            $recordSets=[
                ['review_participant','review_participants','action_manager_id',"employee_id={$employeeId} AND status<>'released'"],
                ['goal','goals','manager_id',"employee_id={$employeeId} AND status NOT IN ('completed','missed')"],
                ['pdp','pdps','manager_id',"employee_id={$employeeId} AND status NOT IN ('completed','cancelled')"],
                ['pip','pips','manager_id',"employee_id={$employeeId} AND status NOT IN ('successful','unsuccessful','closed')"],
            ];
            foreach($recordSets as [$recordType,$table,$column,$where]){
                $pdo->prepare("INSERT INTO active_record_reassignments(record_type,record_id,previous_owner_id,new_owner_id,reason,reassigned_by) SELECT ?,id,?,?,?,? FROM $table WHERE $column=? AND $where")
                    ->execute([$recordType,$previous,$managerId,$reason?:'Primary manager changed',$createdBy,$previous]);
                $pdo->prepare("UPDATE $table SET $column=? WHERE $column=? AND $where")->execute([$managerId,$previous]);
            }
        }
        audit($createdBy, "CHANGE_PRIMARY_MANAGER", "reporting_relationship", $id,
            "Employee {$employeeId}; manager {$managerId}; effective {$effectiveFrom}" .
            ($reason ? "; reason: " . substr($reason, 0, 150) : ""));
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $id;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** End the active primary reporting relationship without inventing a manager. */
function end_primary_manager(
    int $employeeId,
    string $effectiveTo,
    int $changedBy,
    ?string $reason = null,
): bool {
    if (!organization_user_exists($employeeId, false)) {
        throw new InvalidArgumentException("Employee account not found");
    }
    if (!valid_date($effectiveTo)) {
        throw new InvalidArgumentException("Enter a valid effective date");
    }

    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $pdo->query("SELECT id FROM organization_lock WHERE id=1 FOR UPDATE")->fetchColumn();
        $stmt = $pdo->prepare(
            "SELECT id,reports_to_employee_id,effective_from
             FROM reporting_relationships
             WHERE employee_id=? AND relationship_type='primary' AND effective_to IS NULL
             ORDER BY effective_from DESC,id DESC LIMIT 1 FOR UPDATE",
        );
        $stmt->execute([$employeeId]);
        $current = $stmt->fetch();
        if (!$current) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return false;
        }

        // A relationship created today cannot be closed today because the
        // schema requires effective_to > effective_from. Removing that
        // zero-day row represents the requested state without fake history.
        if ($effectiveTo <= $current["effective_from"]) {
            $pdo->prepare("DELETE FROM reporting_relationships WHERE id=?")
                ->execute([(int) $current["id"]]);
        } else {
            $pdo->prepare("UPDATE reporting_relationships SET effective_to=? WHERE id=?")
                ->execute([$effectiveTo, (int) $current["id"]]);
        }
        audit(
            $changedBy,
            "REMOVE_PRIMARY_MANAGER",
            "reporting_relationship",
            (int) $current["id"],
            "Employee {$employeeId}; previous manager {$current['reports_to_employee_id']}" .
                ($reason ? "; reason: " . substr($reason, 0, 150) : ""),
        );
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return true;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function assign_dotted_line_manager(
    int $employeeId,
    int $managerId,
    string $effectiveFrom,
    int $createdBy,
    ?string $reason = null,
): int {
    if (!organization_user_exists($employeeId) || !organization_user_exists($managerId)) {
        throw new InvalidArgumentException("Employee and manager must be active accounts");
    }
    if ($employeeId === $managerId) {
        throw new InvalidArgumentException("An employee cannot report to themselves");
    }
    if (would_create_reporting_cycle($employeeId, $managerId, $effectiveFrom)) {
        throw new InvalidArgumentException("This relationship would create a circular reporting hierarchy");
    }
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM reporting_relationships WHERE employee_id=?
         AND reports_to_employee_id=? AND relationship_type='dotted_line'
         AND effective_from<=? AND (effective_to IS NULL OR effective_to>?)",
    );
    $stmt->execute([$employeeId, $managerId, $effectiveFrom, $effectiveFrom]);
    if ((int) $stmt->fetchColumn() > 0) {
        throw new InvalidArgumentException("That dotted-line relationship already exists");
    }
    $stmt = db()->prepare(
        "INSERT INTO reporting_relationships
         (employee_id,reports_to_employee_id,relationship_type,effective_from,created_by,change_reason)
         VALUES (?,?,'dotted_line',?,?,?)",
    );
    $stmt->execute([$employeeId, $managerId, $effectiveFrom, $createdBy, $reason]);
    $id = (int) db()->lastInsertId();
    audit($createdBy, "ADD_DOTTED_LINE_MANAGER", "reporting_relationship", $id,
        "Employee {$employeeId}; manager {$managerId}; effective {$effectiveFrom}");
    return $id;
}

function organization_people_for(array $user): array
{
    $userId = (int) $user["id"];
    if (has_permission($userId, "org.structure.view_all")) {
        $scopeSql = "1=1";
        $params = [];
    } elseif (has_permission($userId, "org.descendants.view")) {
        $ids = array_merge([$userId], get_descendant_ids($userId));
        $scopeSql = "u.id IN (" . implode(",", array_fill(0, count($ids), "?")) . ")";
        $params = $ids;
    } else {
        $scopeSql = "u.id=?";
        $params = [$userId];
    }
    $sql = "SELECT u.id,u.emp_code,u.full_name,u.email,u.job_title,u.role,u.department_id,u.team_id,
                   d.department_name,t.team_name,rr.reports_to_employee_id,
                   manager.full_name AS manager_name,manager.job_title AS manager_job_title
            FROM users u JOIN departments d ON d.id=u.department_id
            LEFT JOIN teams t ON t.id=u.team_id
            LEFT JOIN active_primary_relationships rr ON rr.employee_id=u.id
            LEFT JOIN users manager ON manager.id=rr.reports_to_employee_id
            WHERE u.is_active=1 AND {$scopeSql} ORDER BY u.full_name";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function organization_tree(array $people): array
{
    $nodes = [];
    foreach ($people as $person) {
        $person["id"] = (int) $person["id"];
        $person["department_id"] = (int) $person["department_id"];
        $person["team_id"] = $person["team_id"] === null ? null : (int) $person["team_id"];
        $person["reports_to_employee_id"] = $person["reports_to_employee_id"] === null
            ? null : (int) $person["reports_to_employee_id"];
        $person["children"] = [];
        $nodes[$person["id"]] = $person;
    }
    $roots = [];
    foreach (array_keys($nodes) as $id) {
        $managerId = $nodes[$id]["reports_to_employee_id"];
        if ($managerId !== null && isset($nodes[$managerId])) {
            $nodes[$managerId]["children"][] =& $nodes[$id];
        } else {
            $roots[] =& $nodes[$id];
        }
    }
    return $roots;
}

function organization_can_view_employee(array $viewer, int $employeeId): bool
{
    $viewerId = (int) $viewer["id"];
    return $viewerId === $employeeId
        || has_permission($viewerId, "org.structure.view_all")
        || (has_permission($viewerId, "org.descendants.view")
            && is_authorized_ancestor($viewerId, $employeeId));
}

function organization_api(string $action): never
{
    if (in_array($action, ["org_change_manager", "org_assign_membership", "org_department_save", "org_team_save", "org_department_delete", "org_team_delete"], true)) {
        require_method("POST");
        require_csrf();
        $user = require_permission("org.structure.manage");
        $in = input();
    } else {
        require_method("GET");
        $user = require_permission("org.structure.view");
        $in = $_GET;
    }
    $actorId = (int) $user["id"];

    switch ($action) {
        case "org_tree":
            $people = organization_people_for($user);
            json_response(["ok" => true, "people" => $people, "tree" => organization_tree($people)]);

        case "org_path":
            $employeeId = (int) ($in["employeeId"] ?? $actorId);
            if ($employeeId < 1 || !organization_user_exists($employeeId)) {
                json_response(["ok" => false, "error" => "Employee not found"], 404);
            }
            if (!organization_can_view_employee($user, $employeeId)) {
                audit($actorId, "DENIED_ORG_PATH", "user", $employeeId, "Outside organization scope");
                json_response(["ok" => false, "error" => "Employee is outside your organization scope"], 403);
            }
            json_response(["ok" => true, "path" => get_reporting_path($employeeId)]);

        case "org_departments":
            $stmt = db()->query(
                "SELECT d.id,d.department_code,d.department_name,d.head_employee_id,d.is_active,
                        u.full_name AS head_name
                 FROM departments d LEFT JOIN users u ON u.id=d.head_employee_id
                 ORDER BY d.department_name",
            );
            json_response(["ok" => true, "departments" => $stmt->fetchAll()]);

        case "org_teams":
            $departmentId = (int) ($in["departmentId"] ?? 0);
            $sql = "SELECT t.id,t.department_id,t.team_code,t.team_name,t.team_lead_employee_id,t.is_active,
                           u.full_name AS team_lead_name
                    FROM teams t LEFT JOIN users u ON u.id=t.team_lead_employee_id";
            $params = [];
            if ($departmentId > 0) {
                $sql .= " WHERE t.department_id=?";
                $params[] = $departmentId;
            }
            $sql .= " ORDER BY t.team_name";
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            json_response(["ok" => true, "teams" => $stmt->fetchAll()]);

        case "org_change_manager":
            $employeeId = (int) ($in["employeeId"] ?? 0);
            $managerId = (int) ($in["managerId"] ?? 0);
            $effectiveFrom = organization_date(trim((string) ($in["effectiveFrom"] ?? "")));
            $type = (string) ($in["relationshipType"] ?? "primary");
            $reason = trim((string) ($in["reason"] ?? ""));
            if (strlen($reason) > 255 || !in_array($type, ["primary", "dotted_line"], true)) {
                json_response(["ok" => false, "error" => "Invalid relationship details"], 422);
            }
            try {
                $id = $type === "primary"
                    ? assign_primary_manager($employeeId, $managerId, $effectiveFrom, $actorId, $reason ?: null)
                    : assign_dotted_line_manager($employeeId, $managerId, $effectiveFrom, $actorId, $reason ?: null);
            } catch (InvalidArgumentException $e) {
                json_response(["ok" => false, "error" => $e->getMessage()], 422);
            }
            json_response(["ok" => true, "id" => $id]);

        case "org_assign_membership":
            $employeeId = (int) ($in["employeeId"] ?? 0);
            $departmentId = (int) ($in["departmentId"] ?? 0);
            $teamId = isset($in["teamId"]) && $in["teamId"] !== "" ? (int) $in["teamId"] : null;
            if (!organization_user_exists($employeeId, false) || $departmentId < 1) {
                json_response(["ok" => false, "error" => "Invalid employee or department"], 422);
            }
            $stmt = db()->prepare("SELECT COUNT(*) FROM departments WHERE id=? AND is_active=1");
            $stmt->execute([$departmentId]);
            if (!(bool) $stmt->fetchColumn()) {
                json_response(["ok" => false, "error" => "Department not found"], 422);
            }
            if ($teamId !== null) {
                $stmt = db()->prepare("SELECT COUNT(*) FROM teams WHERE id=? AND department_id=? AND is_active=1");
                $stmt->execute([$teamId, $departmentId]);
                if (!(bool) $stmt->fetchColumn()) {
                    json_response(["ok" => false, "error" => "Team must belong to the selected department"], 422);
                }
            }
            db()->prepare("UPDATE users SET department_id=?,team_id=? WHERE id=?")
                ->execute([$departmentId, $teamId, $employeeId]);
            audit($actorId, "ASSIGN_ORG_MEMBERSHIP", "user", $employeeId,
                "Department {$departmentId}; team " . ($teamId ?? "none"));
            json_response(["ok" => true]);

        case "org_department_save":
            $id = (int) ($in["id"] ?? 0);
            $code = strtoupper(trim((string) ($in["departmentCode"] ?? "")));
            $name = trim((string) ($in["departmentName"] ?? ""));
            $headId = isset($in["headEmployeeId"]) && $in["headEmployeeId"] !== ""
                ? (int) $in["headEmployeeId"] : null;
            $active = filter_var($in["isActive"] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            if (!preg_match('/^[A-Z0-9_-]{2,30}$/', $code) || $name === "" || strlen($name) > 80
                || ($headId !== null && !organization_user_exists($headId))) {
                json_response(["ok" => false, "error" => "Invalid department details"], 422);
            }
            if ($id > 0) {
                $exists = db()->prepare("SELECT COUNT(*) FROM departments WHERE id=?");
                $exists->execute([$id]);
                if (!(bool) $exists->fetchColumn()) {
                    json_response(["ok" => false, "error" => "Department not found"], 404);
                }
                db()->prepare("UPDATE departments SET department_code=?,department_name=?,head_employee_id=?,is_active=? WHERE id=?")
                    ->execute([$code, $name, $headId, $active, $id]);
            } else {
                db()->prepare("INSERT INTO departments(department_code,department_name,head_employee_id,is_active) VALUES(?,?,?,?)")
                    ->execute([$code, $name, $headId, $active]);
                $id = (int) db()->lastInsertId();
            }
            audit($actorId, "SAVE_DEPARTMENT", "department", $id, "Department {$code}");
            json_response(["ok" => true, "id" => $id]);

        case "org_team_save":
            $id = (int) ($in["id"] ?? 0);
            $departmentId = (int) ($in["departmentId"] ?? 0);
            $code = strtoupper(trim((string) ($in["teamCode"] ?? "")));
            $name = trim((string) ($in["teamName"] ?? ""));
            $leadId = isset($in["teamLeadEmployeeId"]) && $in["teamLeadEmployeeId"] !== ""
                ? (int) $in["teamLeadEmployeeId"] : null;
            $active = filter_var($in["isActive"] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            if ($departmentId < 1 || !preg_match('/^[A-Z0-9_-]{2,30}$/', $code)
                || $name === "" || strlen($name) > 80
                || ($leadId !== null && !organization_user_exists($leadId))) {
                json_response(["ok" => false, "error" => "Invalid team details"], 422);
            }
            $stmt = db()->prepare("SELECT COUNT(*) FROM departments WHERE id=? AND is_active=1");
            $stmt->execute([$departmentId]);
            if (!(bool) $stmt->fetchColumn()) {
                json_response(["ok" => false, "error" => "Department not found"], 422);
            }
            if ($id > 0) {
                $exists = db()->prepare("SELECT COUNT(*) FROM teams WHERE id=?");
                $exists->execute([$id]);
                if (!(bool) $exists->fetchColumn()) {
                    json_response(["ok" => false, "error" => "Team not found"], 404);
                }
                db()->prepare("UPDATE teams SET department_id=?,team_code=?,team_name=?,team_lead_employee_id=?,is_active=? WHERE id=?")
                    ->execute([$departmentId, $code, $name, $leadId, $active, $id]);
            } else {
                db()->prepare("INSERT INTO teams(department_id,team_code,team_name,team_lead_employee_id,is_active) VALUES(?,?,?,?,?)")
                    ->execute([$departmentId, $code, $name, $leadId, $active]);
                $id = (int) db()->lastInsertId();
            }
            audit($actorId, "SAVE_TEAM", "team", $id, "Team {$code}");
            json_response(["ok" => true, "id" => $id]);

        case "org_team_delete":
            $id = (int) ($in["id"] ?? 0);
            $stmt = db()->prepare("SELECT team_code FROM teams WHERE id=?");
            $stmt->execute([$id]);
            $code = $stmt->fetchColumn();
            if ($code === false) {
                json_response(["ok" => false, "error" => "Team not found"], 404);
            }
            $stmt = db()->prepare("SELECT COUNT(*) FROM users WHERE team_id=?");
            $stmt->execute([$id]);
            if ((int) $stmt->fetchColumn() > 0) {
                json_response(["ok" => false, "error" => "Move team members before deleting this team"], 409);
            }
            db()->prepare("DELETE FROM teams WHERE id=?")->execute([$id]);
            audit($actorId, "DELETE_TEAM", "team", $id, "Deleted unused team {$code}");
            json_response(["ok" => true]);

        case "org_department_delete":
            $id = (int) ($in["id"] ?? 0);
            $stmt = db()->prepare("SELECT department_code FROM departments WHERE id=?");
            $stmt->execute([$id]);
            $code = $stmt->fetchColumn();
            if ($code === false) {
                json_response(["ok" => false, "error" => "Department not found"], 404);
            }
            $stmt = db()->prepare(
                "SELECT (SELECT COUNT(*) FROM users WHERE department_id=?) +
                        (SELECT COUNT(*) FROM teams WHERE department_id=?)",
            );
            $stmt->execute([$id, $id]);
            if ((int) $stmt->fetchColumn() > 0) {
                json_response(["ok" => false, "error" => "Move employees and delete teams before deleting this department"], 409);
            }
            db()->prepare("DELETE FROM departments WHERE id=?")->execute([$id]);
            audit($actorId, "DELETE_DEPARTMENT", "department", $id, "Deleted unused department {$code}");
            json_response(["ok" => true]);
    }

    json_response(["ok" => false, "error" => "Unknown organization action"], 404);
}
