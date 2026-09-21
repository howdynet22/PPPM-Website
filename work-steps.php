<?php
declare(strict_types=1);
// Work-step helpers shared by goals, PDP actions and PIP objectives.
function normalize_work_steps(mixed $value): array
{
    if (!is_array($value)) {
        json_response(
            ["ok" => false, "error" => "Add at least one actionable step"],
            422,
        );
    }
    $steps = [];
    foreach ($value as $step) {
        $title = trim((string) (is_array($step) ? ($step["title"] ?? "") : $step));
        if ($title === "" || strlen($title) > 500) {
            json_response(
                [
                    "ok" => false,
                    "error" =>
                        "Each step is required and must be under 500 characters",
                ],
                422,
            );
        }
        $steps[] = $title;
    }
    if (!$steps || count($steps) > 20) {
        json_response(
            [
                "ok" => false,
                "error" => "Add between 1 and 20 actionable steps",
            ],
            422,
        );
    }
    return $steps;
}

function work_step_column(string $type): string
{
    return match ($type) {
        "goal" => "goal_id",
        "pdp_action" => "pdp_action_id",
        "pip_objective" => "pip_objective_id",
        default => json_response(
            ["ok" => false, "error" => "Invalid work item type"],
            422,
        ),
    };
}

function insert_work_steps(
    PDO $pdo,
    string $type,
    int $workId,
    array $steps,
    int $creatorId,
): void {
    $column = work_step_column($type);
    $stmt = $pdo->prepare(
        "INSERT INTO work_steps($column,title,step_order,created_by) VALUES(?,?,?,?)",
    );
    foreach ($steps as $index => $title) {
        $stmt->execute([$workId, $title, $index + 1, $creatorId]);
    }
}

function work_steps_for(
    PDO $pdo,
    string $type,
    int $workId,
    int $viewerId,
): array {
    $column = work_step_column($type);
    $stmt = $pdo->prepare(
        "SELECT ws.id,ws.title,ws.step_order,ws.created_by,ws.is_completed," .
            "ws.completed_by,ws.completed_at,ws.status,ws.progress_note,ws.version,creator.full_name creator_name " .
            "FROM work_steps ws JOIN users creator ON creator.id=ws.created_by " .
            "WHERE ws.$column=? ORDER BY ws.step_order,ws.id",
    );
    $stmt->execute([$workId]);
    return array_map(
        fn($step) => [
            "id" => (int) $step["id"],
            "title" => $step["title"],
            "order" => (int) $step["step_order"],
            "completed" => (bool) $step["is_completed"],
            "status" => $step["status"],
            "note" => $step["progress_note"] ?? "",
            "version" => (int) $step["version"],
            "creatorId" => (int) $step["created_by"],
            "creator" => $step["creator_name"],
            "canEdit" => (int) $step["created_by"] === $viewerId,
            "completedAt" => $step["completed_at"],
        ],
        $stmt->fetchAll(),
    );
}

function work_item_context(PDO $pdo, string $type, int $workId): ?array
{
    $sql = match ($type) {
        "goal" =>
            "SELECT g.id work_id,g.employee_id,g.manager_id,NULL hr_owner_id,g.status parent_status " .
            "FROM goals g WHERE g.id=?",
        "pdp_action" =>
            "SELECT pa.id work_id,p.employee_id,p.manager_id,NULL hr_owner_id,pa.status parent_status,p.status plan_status " .
            "FROM pdp_actions pa JOIN pdps p ON p.id=pa.pdp_id WHERE pa.id=?",
        "pip_objective" =>
            "SELECT po.id work_id,p.employee_id,p.manager_id,p.hr_owner_id,p.status plan_status," .
            "po.status parent_status FROM pip_objectives po JOIN pips p ON p.id=po.pip_id WHERE po.id=?",
        default => null,
    };
    if ($sql === null) {
        return null;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$workId]);
    $context = $stmt->fetch() ?: null;
    if ($context) {
        $context["work_type"] = $type;
    }
    return $context;
}

function work_step_context(PDO $pdo, int $stepId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT ws.id,ws.created_by,ws.goal_id,ws.pdp_action_id,ws.pip_objective_id " .
            "FROM work_steps ws WHERE ws.id=?",
    );
    $stmt->execute([$stepId]);
    $step = $stmt->fetch();
    if (!$step) {
        return null;
    }
    $type = $step["goal_id"] !== null
        ? "goal"
        : ($step["pdp_action_id"] !== null
            ? "pdp_action"
            : "pip_objective");
    $workId = (int) ($step[work_step_column($type)] ?? 0);
    $context = work_item_context($pdo, $type, $workId);
    if (!$context) {
        return null;
    }
    return array_merge($context, [
        "step_id" => (int) $step["id"],
        "created_by" => (int) $step["created_by"],
    ]);
}

function can_access_work_item(array $context, int $userId): bool
{
    if ($userId === (int) $context["employee_id"]) return has_permission($userId, "employee.dashboard");
    if ($userId === (int) $context["manager_id"]) {
        return has_permission($userId, $context["work_type"] === "pip_objective" ? "manager.pips" : "manager.goals");
    }
    return $userId === (int) ($context["hr_owner_id"] ?? 0) && has_permission($userId, "hr.pips");
}

function assert_work_item_open(array $context): void
{
    if (($context['parent_status'] ?? '') === 'cancelled' || ($context['plan_status'] ?? '') === 'cancelled') {
        json_response(['ok'=>false,'error'=>'A cancelled action cannot be changed'],409);
    }
    if (
        ($context["work_type"] ?? "") === "pip_objective" &&
        in_array(
            $context["plan_status"] ?? "",
            ["successful", "unsuccessful", "closed"],
            true,
        )
    ) {
        json_response(
            ["ok" => false, "error" => "A completed PIP cannot be changed"],
            409,
        );
    }
}

function sync_work_item_status(PDO $pdo, array $context): void
{
    $type = (string) $context["work_type"];
    $workId = (int) $context["work_id"];
    $column = work_step_column($type);
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) total,COALESCE(SUM(is_completed),0) completed, COALESCE(SUM(status<>'not_started'),0) started " .
            "FROM work_steps WHERE $column=?",
    );
    $stmt->execute([$workId]);
    $counts = $stmt->fetch();
    $total = (int) ($counts["total"] ?? 0);
    $completed = (int) ($counts["completed"] ?? 0);
    $allCompleted = $total > 0 && $completed === $total;
    $started = (int) $counts["started"] > 0;

    if ($type === "goal") {
        $status = $allCompleted
            ? "completed"
            : ($started ? "in_progress" : "not_started");
        $pdo->prepare("UPDATE goals SET status=? WHERE id=?")->execute([
            $status,
            $workId,
        ]);
        return;
    }
    if ($type === "pdp_action") {
        $status = $allCompleted
            ? "completed"
            : ($started ? "in_progress" : "not_started");
        if (
            in_array(
                $context["parent_status"] ?? "",
                ["cancelled"],
                true,
            ) &&
            !$allCompleted
        ) {
            $status = $context["parent_status"];
        }
        $completedAt = $status === "completed" ? "COALESCE(completed_at,NOW())" : "NULL";
        $pdo->prepare(
            "UPDATE pdp_actions SET status=?,completed_at=$completedAt WHERE id=?",
        )->execute([$status, $workId]);
        $parent = $pdo->prepare("SELECT pdp_id FROM pdp_actions WHERE id=?");
        $parent->execute([$workId]);
        sync_pdp_status($pdo, (int) $parent->fetchColumn());
        return;
    }
    $status = $allCompleted
        ? "met"
        : ($started ? "partially_met" : "not_met");
    $pdo->prepare("UPDATE pip_objectives SET status=? WHERE id=?")->execute([
        $status,
        $workId,
    ]);
}

function sync_pdp_status(PDO $pdo, int $planId): void
{
    $stmt = $pdo->prepare("SELECT COUNT(*) total,SUM(status='completed') completed FROM pdp_actions WHERE pdp_id=? AND status<>'cancelled'");
    $stmt->execute([$planId]);
    $counts = $stmt->fetch();
    $complete = (int) $counts['total'] > 0 && (int) $counts['total'] === (int) $counts['completed'];
    $pdo->prepare("UPDATE pdps SET status=CASE WHEN ?=1 AND status IN ('agreed','completed') THEN 'completed' WHEN status='completed' THEN 'agreed' ELSE status END WHERE id=? AND status<>'cancelled'")->execute([$complete ? 1 : 0, $planId]);
}

// All step-definition mutations use the same plan -> item lock order as progress writes.
function lock_work_item(PDO $pdo, array $context): array
{
    $type=$context['work_type'];
    $table=match($type){'goal'=>'goals','pdp_action'=>'pdp_actions','pip_objective'=>'pip_objectives'};
    if($type!=='goal') {
        $column=$type==='pdp_action'?'pdp_id':'pip_id';
        $parentTable=$type==='pdp_action'?'pdps':'pips';
        $s=$pdo->prepare("SELECT $column FROM $table WHERE id=?");$s->execute([$context['work_id']]);$parent=$s->fetchColumn();
        $s=$pdo->prepare("SELECT id FROM $parentTable WHERE id=? FOR UPDATE");$s->execute([$parent]);$s->fetch();
    }
    $s=$pdo->prepare("SELECT id FROM $table WHERE id=? FOR UPDATE");$s->execute([$context['work_id']]);
    if(!$s->fetch())json_response(['ok'=>false,'error'=>'Work item no longer exists'],404);
    $context=work_item_context($pdo,$type,(int)$context['work_id']);
    assert_work_item_open($context);
    return $context;
}

// Progress is step-weighted; empty items remain at zero and are never complete.
function work_progress(array $steps, ?string $due = null): array
{
    $total = count($steps);
    $completed = count(array_filter($steps, fn($s) => $s['status'] === 'completed'));
    $blocked = count(array_filter($steps, fn($s) => $s['status'] === 'blocked'));
    $started = count(array_filter($steps, fn($s) => $s['status'] !== 'not_started'));
    return [
        'total' => $total, 'completed' => $completed,
        'percent' => $total ? (int) round(100 * $completed / $total) : 0,
        'status' => $total && $completed === $total ? 'completed' : ($started ? 'in_progress' : 'not_started'),
        'blocked' => $blocked > 0,
        'overdue' => $due && $due < date('Y-m-d') && !($total && $completed === $total),
    ];
}
