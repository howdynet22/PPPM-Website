<?php
declare(strict_types=1);

function available_workspaces(array $user): array
{
    $permissions = $user['permissions'] ?? permissions_for_role($user['role']);
    $spaces = [];
    foreach ([
        'employee' => ['employee.dashboard', 'Employee', 'employee-dashboard.html'],
        'manager' => ['manager.dashboard', 'Manager', 'manager-dashboard.html'],
        'hr' => ['hr.dashboard', 'HR', 'hr-dashboard.html'],
        'executive' => ['admin.dashboard', 'Executive', 'admin-dashboard.html'],
    ] as $key => [$permission, $label, $path]) {
        if (in_array($permission, $permissions, true)) $spaces[] = compact('key', 'label', 'path');
    }
    return $spaces;
}

function workspace_rows(string $sql, array $args = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetchAll();
}

function workspace_item(string $type, array $row, int $viewer): array
{
    $row['id'] = (int) $row['id'];
    $row['type'] = $type;
    $row['steps'] = work_steps_for(db(), $type, $row['id'], $viewer);
    $row['progress'] = work_progress($row['steps'], $row['due'] ?? null);
    $context = work_item_context(db(), $type, $row['id']);
    $row['locked'] = ($row['plan_status'] ?? '') === 'cancelled' || ($row['status'] ?? '') === 'cancelled'
        || ($type === 'pip_objective' && in_array($context['plan_status'], ['successful','unsuccessful','closed'], true));
    $row['canUpdate'] = !$row['locked'] && can_access_work_item($context, $viewer);
    $row['canEdit'] = $row['canUpdate'] && ($row['steps']
        ? count(array_filter($row['steps'], fn($s) => !$s['canEdit'])) === 0
        : (int) $context['manager_id'] === $viewer);
    return $row;
}

function workspace_pips(int $viewer, string $scope): array
{
    $ownerColumn = $scope === 'hr' ? 'hr_owner_id' : 'employee_id';
    $plans = workspace_rows("SELECT p.id,p.reason,p.status,p.start_date,p.end_date,p.outcome_note,
        e.full_name employee,m.full_name manager,h.full_name hr_owner
        FROM pips p JOIN users e ON e.id=p.employee_id JOIN users m ON m.id=p.manager_id
        JOIN users h ON h.id=p.hr_owner_id WHERE p.$ownerColumn=? ORDER BY p.end_date", [$viewer]);
    foreach ($plans as &$plan) {
        $plan['objectives'] = array_map(fn($r) => workspace_item('pip_objective', $r, $viewer),
            workspace_rows("SELECT id,objective title,success_criteria description,due_date due,status FROM pip_objectives WHERE pip_id=? ORDER BY id", [$plan['id']]));
        $plan['checkins'] = workspace_rows('SELECT pc.checkin_date,pc.notes,u.full_name author FROM pip_checkins pc JOIN users u ON u.id=pc.author_id WHERE pip_id=? ORDER BY pc.checkin_date DESC,pc.id DESC', [$plan['id']]);
    }
    return $plans;
}

function workspace_personal(int $viewer): array
{
    $goals = array_map(fn($r) => workspace_item('goal', $r, $viewer), workspace_rows(
        'SELECT g.id,g.title,g.description,g.due_date due,g.status,u.full_name owner FROM goals g JOIN users u ON u.id=g.manager_id WHERE g.employee_id=? ORDER BY g.due_date,g.id', [$viewer]));
    $plans = workspace_rows('SELECT p.id,p.summary,p.status,u.full_name owner FROM pdps p JOIN users u ON u.id=p.manager_id WHERE p.employee_id=? ORDER BY p.id DESC', [$viewer]);
    foreach ($plans as &$plan) {
        $plan['actions'] = array_map(fn($r) => workspace_item('pdp_action', $r, $viewer), workspace_rows(
            'SELECT pa.id,pa.title,pa.description,pa.due_date due,pa.status,p.status plan_status FROM pdp_actions pa JOIN pdps p ON p.id=pa.pdp_id WHERE pa.pdp_id=? ORDER BY pa.due_date,pa.id', [$plan['id']]));
        $active = array_values(array_filter($plan['actions'], fn($a) => $a['status'] !== 'cancelled'));
        $steps = array_merge([], ...array_column($active, 'steps'));
        $plan['progress'] = work_progress($steps);
        // An action without steps still needs definition, so cannot finish a plan.
        $plan['progress']['needsSteps'] = count(array_filter($active, fn($a) => !$a['steps'])) > 0;
        if ($plan['progress']['needsSteps'] && $plan['progress']['status'] === 'completed') $plan['progress']['status'] = 'in_progress';
        $plan['progress']['overdue'] = count(array_filter($active, fn($a) => $a['progress']['overdue'])) > 0;
    }
    unset($plan);
    $reviews = workspace_rows('SELECT rp.id,rc.name cycle,rc.status cycle_status,rp.status,rc.min_peers,rc.self_deadline,rc.peer_deadline,rc.manager_deadline,
        CASE WHEN rp.status=\'released\' THEN rp.final_rating ELSE NULL END final_rating,
        CASE WHEN rp.status=\'released\' THEN rp.manager_summary ELSE NULL END manager_summary
        FROM review_participants rp JOIN review_cycles rc ON rc.id=rp.cycle_id WHERE rp.employee_id=? ORDER BY rc.period_end DESC,rp.id DESC', [$viewer]);
    foreach ($reviews as &$review) {
        $review['feedback'] = [];
        if ($review['status'] !== 'released') continue;
        $count = workspace_rows("SELECT COUNT(*) n FROM feedback_requests WHERE participant_id=? AND type='peer' AND status='submitted'", [$review['id']])[0]['n'];
        if ((int) $count >= (int) $review['min_peers']) {
            $review['feedback'] = workspace_rows("SELECT competency,avg_score,responses FROM v_360_summary WHERE participant_id=? AND type='peer' ORDER BY competency", [$review['id']]);
        }
    }
    unset($review);
    // Only the current user's assigned forms; no respondent identities or raw peer responses.
    $requests = workspace_rows("SELECT fr.id,fr.type,fr.status,rp.status participant_status,rc.name cycle,rc.status cycle_status,
        e.full_name employee,IF(fr.type='self',rc.self_deadline,rc.peer_deadline) due
        FROM feedback_requests fr JOIN review_participants rp ON rp.id=fr.participant_id
        JOIN review_cycles rc ON rc.id=rp.cycle_id JOIN users e ON e.id=rp.employee_id
        WHERE fr.respondent_id=? AND fr.type IN ('self','peer') ORDER BY due,fr.id", [$viewer]);
    foreach ($requests as &$request) $request['canSubmit'] = personal_feedback_open($request);
    return ['goals'=>$goals, 'plans'=>$plans, 'pips'=>workspace_pips($viewer,'employee'), 'reviews'=>$reviews, 'requests'=>$requests, 'nominations'=>personal_peer_nominations($viewer)];
}

function personal_feedback_open(array $request): bool
{
    return $request['status'] === 'pending'
        && in_array($request['cycle_status'], $request['type'] === 'self' ? ['open'] : ['open','peer_review'], true)
        && !in_array($request['participant_status'], ['manager_submitted','released'], true)
        && (!$request['due'] || $request['due'] >= date('Y-m-d'));
}

function workspace_api(string $action): never
{
    $user = require_login();
    $viewer = (int) $user['id'];
    $pdo = db();
    if ($action === 'workspace') {
        require_method('GET');
        $scope = (string) ($_GET['scope'] ?? 'employee');
        $user['permissions'] = permissions_for_role($user['role']);
        $user['workspaces'] = available_workspaces($user);
        if (!in_array($scope, array_column($user['workspaces'], 'key'), true)) json_response(['ok'=>false,'error'=>'This workspace is not available to your account'],403);
        $pdo->beginTransaction();
        $data = [];
        if ($scope === 'employee') $data = workspace_personal($viewer);
        if ($scope === 'hr') $data = ['pips'=>has_permission($viewer,'hr.pips') ? workspace_pips($viewer,'hr') : [], 'reportsTo'=>get_reporting_path($viewer)];
        if ($scope === 'executive') $data = ['counts'=>[
            'people'=>(int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_active=1')->fetchColumn(),
            'departments'=>(int) $pdo->query('SELECT COUNT(*) FROM departments WHERE is_active=1')->fetchColumn(),
            'teams'=>(int) $pdo->query('SELECT COUNT(*) FROM teams WHERE is_active=1')->fetchColumn(),
        ]];
        $pdo->commit();
        json_response(['ok'=>true,'user'=>$user,'data'=>$data,'csrfToken'=>csrf_token()]);
    }
    if ($action === 'work_item') {
        require_method('GET');
        $type = (string) ($_GET['type'] ?? '');
        $id = (int) ($_GET['id'] ?? 0);
        $context = work_item_context($pdo,$type,$id);
        if (!$context || !can_access_work_item($context,$viewer)) json_response(['ok'=>false,'error'=>'Work item not found'],404);
        $sql = match ($type) {
            'goal' => 'SELECT g.id,g.title,g.description,g.due_date due,g.status,u.full_name owner,e.full_name employee FROM goals g JOIN users u ON u.id=g.manager_id JOIN users e ON e.id=g.employee_id WHERE g.id=?',
            'pdp_action' => 'SELECT a.id,a.title,a.description,a.due_date due,a.status,p.status plan_status,u.full_name owner,e.full_name employee FROM pdp_actions a JOIN pdps p ON p.id=a.pdp_id JOIN users u ON u.id=p.manager_id JOIN users e ON e.id=p.employee_id WHERE a.id=?',
            'pip_objective' => 'SELECT a.id,a.objective title,a.success_criteria description,a.due_date due,a.status,p.status plan_status,u.full_name owner,e.full_name employee FROM pip_objectives a JOIN pips p ON p.id=a.pip_id JOIN users u ON u.id=p.manager_id JOIN users e ON e.id=p.employee_id WHERE a.id=?',
        };
        $item = workspace_item($type,workspace_rows($sql,[$id])[0],$viewer);
        $item['updates'] = $type === 'pdp_action' ? workspace_rows('SELECT au.note,au.created_at,u.full_name author FROM action_updates au JOIN users u ON u.id=au.author_id WHERE action_id=? ORDER BY au.id DESC LIMIT 20',[$id]) : [];
        json_response(['ok'=>true,'item'=>$item]);
    }
    if ($action === 'set_step_status') {
        require_method('POST'); require_csrf();
        $in = input();
        $id = (int) ($in['id'] ?? 0);
        $status = $in['status'] ?? (filter_var($in['completed'] ?? false,FILTER_VALIDATE_BOOLEAN) ? 'completed' : 'not_started');
        $note = trim((string) ($in['note'] ?? ''));
        if (!in_array($status,['not_started','in_progress','blocked','completed'],true) || strlen($note)>5000) json_response(['ok'=>false,'error'=>'Choose a valid status and a note under 5,000 characters'],422);
        $context = work_step_context($pdo,$id);
        if (!$context || !can_access_work_item($context,$viewer)) json_response(['ok'=>false,'error'=>'Step not found'],404);
        assert_work_item_open($context);
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();
        // Serialize sibling updates before calculating the shared parent progress.
        $table = match ($context['work_type']) {'goal'=>'goals','pdp_action'=>'pdp_actions','pip_objective'=>'pip_objectives'};
        if ($context['work_type'] === 'pdp_action') {
            $planId=workspace_rows('SELECT pdp_id FROM pdp_actions WHERE id=?',[$context['work_id']])[0]['pdp_id'];
            workspace_rows('SELECT id FROM pdps WHERE id=? FOR UPDATE',[$planId]);
        }
        if ($context['work_type'] === 'pip_objective') {
            $planId=workspace_rows('SELECT pip_id FROM pip_objectives WHERE id=?',[$context['work_id']])[0]['pip_id'];
            workspace_rows('SELECT id FROM pips WHERE id=? FOR UPDATE',[$planId]);
        }
        workspace_rows("SELECT id FROM $table WHERE id=? FOR UPDATE",[$context['work_id']]);
        $context = work_step_context($pdo,$id);
        assert_work_item_open($context);
        $step = workspace_rows('SELECT * FROM work_steps WHERE id=? FOR UPDATE',[$id])[0] ?? null;
        if (!$step || (isset($in['version']) && (int)$in['version'] !== (int)$step['version'])) {
            $pdo->rollBack();
            json_response(['ok'=>false,'error'=>'This step changed since you opened it. Reload the goal and try again.'],409);
        }
        $note = array_key_exists('note',$in) ? $note : $step['progress_note'];
        $complete = $status === 'completed';
        $pdo->prepare('UPDATE work_steps SET status=?,is_completed=?,progress_note=?,completed_by=?,completed_at=?,version=version+1 WHERE id=?')->execute([
            $status,$complete ? 1 : 0,$note,
            $complete ? ($step['completed_by'] ?? $viewer) : null,
            $complete ? ($step['completed_at'] ?? date('Y-m-d H:i:s')) : null,$id,
        ]);
        sync_work_item_status($pdo,$context);
        audit($viewer,'UPDATE_STEP_STATUS','work_step',$id,$step['status'].' -> '.$status);
        $pdo->commit();
        json_response(['ok'=>true]);
    }
    if (in_array($action,['feedback_form','submit_personal_feedback'],true)) {
        require_permission('employee.dashboard');
        $write = $action === 'submit_personal_feedback';
        require_method($write ? 'POST' : 'GET');
        if ($write) require_csrf();
        $in = $write ? input() : $_GET;
        $id = (int) ($in['id'] ?? 0);
        if ($write) $pdo->beginTransaction();
        $request = workspace_rows("SELECT fr.id,fr.participant_id,fr.type,fr.status,rc.status cycle_status,rp.status participant_status,rc.name cycle,e.full_name employee,
            IF(fr.type='self',rc.self_deadline,rc.peer_deadline) due
            FROM feedback_requests fr JOIN review_participants rp ON rp.id=fr.participant_id
            JOIN review_cycles rc ON rc.id=rp.cycle_id JOIN users e ON e.id=rp.employee_id
            WHERE fr.id=? AND fr.respondent_id=? AND fr.type IN ('self','peer')".($write?' FOR UPDATE':''),[$id,$viewer])[0] ?? null;
        if (!$request) json_response(['ok'=>false,'error'=>'Feedback request not found'],404);
        $competencies = workspace_rows('SELECT id,name FROM competencies WHERE is_active=1 ORDER BY id');
        if (!$write) {
            $request['canSubmit'] = personal_feedback_open($request);
            json_response(['ok'=>true,'request'=>$request,'competencies'=>$competencies,'ratings'=>workspace_rows('SELECT competency_id,score,comment FROM feedback_ratings WHERE request_id=?',[$id])]);
        }
        if (!personal_feedback_open($request)) { $pdo->rollBack(); json_response(['ok'=>false,'error'=>'This feedback request is submitted or outside its submission window'],409); }
        $ratings = $in['ratings'] ?? [];
        if (!is_array($ratings) || !$competencies || count($ratings)!==count($competencies)) json_response(['ok'=>false,'error'=>'Rate every competency'],422);
        $byId = [];
        foreach ($ratings as $rating) {
            if (!is_array($rating)) json_response(['ok'=>false,'error'=>'Invalid rating'],422);
            $score = filter_var($rating['score'] ?? null,FILTER_VALIDATE_INT);
            $cid = (int)($rating['competencyId'] ?? 0);
            $comment = trim((string)($rating['comment'] ?? ''));
            if (!$score || $score<1 || $score>5 || strlen($comment)>2000 || isset($byId[$cid])) json_response(['ok'=>false,'error'=>'Use one rating from 1 to 5 per competency and comments under 2,000 characters'],422);
            $byId[$cid] = [$score,$comment];
        }
        foreach ($competencies as $c) if (!isset($byId[(int)$c['id']])) json_response(['ok'=>false,'error'=>'Rate every competency'],422);
        $pdo->prepare('DELETE FROM feedback_ratings WHERE request_id=?')->execute([$id]);
        $insert=$pdo->prepare('INSERT INTO feedback_ratings(request_id,competency_id,score,comment) VALUES(?,?,?,?)');
        foreach ($byId as $cid=>[$score,$comment]) $insert->execute([$id,$cid,$score,$comment]);
        $pdo->prepare("UPDATE feedback_requests SET status='submitted',submitted_at=NOW() WHERE id=?")->execute([$id]);
        if ($request['type']==='self') $pdo->prepare("UPDATE review_participants SET status='self_submitted' WHERE id=? AND status='not_started'")->execute([$request['participant_id']]);
        $pdo->prepare("UPDATE review_participants rp JOIN review_cycles rc ON rc.id=rp.cycle_id SET rp.status='peers_complete'
            WHERE rp.id=? AND rp.status='self_submitted' AND (SELECT COUNT(*) FROM feedback_requests fr WHERE fr.participant_id=rp.id AND fr.type='peer' AND fr.status='submitted')>=rc.min_peers")->execute([$request['participant_id']]);
        audit($viewer,'SUBMIT_PERSONAL_FEEDBACK','feedback_request',$id,'Submitted '.$request['type'].' feedback');
        $pdo->commit();
        json_response(['ok'=>true]);
    }
    json_response(['ok'=>false,'error'=>'Unknown action'],404);
}
