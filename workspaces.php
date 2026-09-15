<?php
declare(strict_types=1);

function available_workspaces(array $user): array
{
    $permissions = $user['permissions'] ?? permissions_for_role($user['role']);
    $spaces = [];
    foreach ([
        'employee' => ['employee.dashboard', 'Personal', 'employee-dashboard.html'],
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

    // Employees see only nominations for their own review participants. Manager
    // decisions and HR escalation state are visible, but no private peer response is exposed.
    $nominations = workspace_rows("SELECT pn.id,pn.participant_id participantId,rc.name cycle,peer.full_name peer,peer.job_title peerJobTitle,
        pn.shared_work sharedWork,pn.collaboration_details collaborationDetails,pn.reviewer_justification reviewerJustification,
        pn.direct_knowledge_confirmed directKnowledgeConfirmed,pn.status,pn.decision_reason decisionReason,pn.decided_at decidedAt,manager.full_name manager,
        pne.status escalationStatus,pne.escalation_reason escalationReason,pne.escalated_at escalatedAt
        FROM peer_nominations pn JOIN review_participants rp ON rp.id=pn.participant_id
        JOIN review_cycles rc ON rc.id=rp.cycle_id JOIN users peer ON peer.id=pn.peer_id
        JOIN users manager ON manager.id=rp.manager_id
        LEFT JOIN peer_nomination_escalations pne ON pne.nomination_id=pn.id
        WHERE rp.employee_id=? ORDER BY pn.created_at DESC,pn.id DESC", [$viewer]);

    // An eligible reviewer is active, has Personal access, is not the employee or
    // their assigned manager, and is not already nominated or assigned in this cycle.
    $nominationOptions = [];
    $openParticipants = workspace_rows("SELECT rp.id participant_id,rp.manager_id,rc.name cycle,rc.peer_deadline
        FROM review_participants rp JOIN review_cycles rc ON rc.id=rp.cycle_id
        WHERE rp.employee_id=? AND rp.status IN ('not_started','self_submitted')
        AND rc.status IN ('open','peer_review') AND (rc.peer_deadline IS NULL OR rc.peer_deadline>=CURDATE())
        ORDER BY rc.period_end DESC,rp.id DESC", [$viewer]);
    foreach ($openParticipants as $participant) {
        $peers = workspace_rows("SELECT DISTINCT u.id,u.full_name,u.job_title,d.department_name department,t.team_name team
            FROM users u JOIN role_permissions rperm ON rperm.role_code=u.role
            JOIN permissions perm ON perm.id=rperm.permission_id AND perm.permission_code='employee.dashboard'
            LEFT JOIN departments d ON d.id=u.department_id LEFT JOIN teams t ON t.id=u.team_id
            WHERE u.is_active=1 AND u.id NOT IN (?,?)
            AND NOT EXISTS (SELECT 1 FROM peer_nominations pn WHERE pn.participant_id=? AND pn.peer_id=u.id)
            AND NOT EXISTS (SELECT 1 FROM feedback_requests fr WHERE fr.participant_id=? AND fr.respondent_id=u.id AND fr.type='peer')
            ORDER BY u.full_name", [$viewer,(int)$participant['manager_id'],(int)$participant['participant_id'],(int)$participant['participant_id']]);
        $nominationOptions[] = [
            'participantId'=>(int)$participant['participant_id'],
            'cycle'=>$participant['cycle'],
            'deadline'=>$participant['peer_deadline'],
            'peers'=>array_map(fn($peer)=>[
                'id'=>(int)$peer['id'],'name'=>$peer['full_name'],'jobTitle'=>$peer['job_title'],
                'department'=>$peer['department'],'team'=>$peer['team'],
            ],$peers),
        ];
    }
    return ['goals'=>$goals, 'plans'=>$plans, 'pips'=>workspace_pips($viewer,'employee'), 'reviews'=>$reviews,
        'requests'=>$requests, 'nominations'=>$nominations, 'nominationOptions'=>$nominationOptions];
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
    if ($action === 'create_peer_nomination') {
        require_permission('employee.dashboard');
        require_method('POST'); require_csrf();
        $in = input();
        $participantId = (int)($in['participantId'] ?? 0);
        $peerId = (int)($in['peerId'] ?? 0);
        $sharedWork = trim((string)($in['sharedWork'] ?? ''));
        $collaboration = trim((string)($in['collaborationDetails'] ?? ''));
        $justification = trim((string)($in['reviewerJustification'] ?? ''));
        $confirmed = filter_var($in['directKnowledgeConfirmed'] ?? false,FILTER_VALIDATE_BOOLEAN);
        if (strlen($sharedWork)<5 || strlen($sharedWork)>255) json_response(['ok'=>false,'error'=>'Name the shared project or deliverable using 5 to 255 characters'],422);
        if (strlen($collaboration)<30 || strlen($collaboration)>2000) json_response(['ok'=>false,'error'=>'Describe the work you completed together using 30 to 2,000 characters'],422);
        if (strlen($justification)<30 || strlen($justification)>2000) json_response(['ok'=>false,'error'=>'Explain what this peer directly observed using 30 to 2,000 characters'],422);
        if (!$confirmed) json_response(['ok'=>false,'error'=>'Confirm that this peer directly observed your work during the review period'],422);
        $pdo->beginTransaction();
        $participant = workspace_rows("SELECT rp.id,rp.manager_id,rp.status participant_status,rc.status cycle_status,rc.peer_deadline
            FROM review_participants rp JOIN review_cycles rc ON rc.id=rp.cycle_id
            WHERE rp.id=? AND rp.employee_id=? FOR UPDATE",[$participantId,$viewer])[0] ?? null;
        if (!$participant) { $pdo->rollBack(); json_response(['ok'=>false,'error'=>'Review cycle not found'],404); }
        if (!in_array($participant['participant_status'],['not_started','self_submitted'],true)
            || !in_array($participant['cycle_status'],['open','peer_review'],true)
            || ($participant['peer_deadline'] && $participant['peer_deadline']<date('Y-m-d'))) {
            $pdo->rollBack(); json_response(['ok'=>false,'error'=>'Peer nominations are closed for this review cycle'],409);
        }
        if ($peerId===$viewer || $peerId===(int)$participant['manager_id']) {
            $pdo->rollBack(); json_response(['ok'=>false,'error'=>'Choose an eligible peer rather than yourself or your assigned manager'],422);
        }
        $eligible = workspace_rows("SELECT u.id FROM users u WHERE u.id=? AND u.is_active=1 AND EXISTS (
            SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id
            WHERE rp.role_code=u.role AND p.permission_code='employee.dashboard')",[$peerId])[0] ?? null;
        if (!$eligible) { $pdo->rollBack(); json_response(['ok'=>false,'error'=>'The selected person is not an eligible peer reviewer'],422); }
        $duplicate = workspace_rows("SELECT 1 found FROM peer_nominations WHERE participant_id=? AND peer_id=?
            UNION SELECT 1 FROM feedback_requests WHERE participant_id=? AND respondent_id=? AND type='peer' LIMIT 1",
            [$participantId,$peerId,$participantId,$peerId]);
        if ($duplicate) { $pdo->rollBack(); json_response(['ok'=>false,'error'=>'This peer is already nominated or assigned for the review'],409); }
        $stmt=$pdo->prepare("INSERT INTO peer_nominations
            (participant_id,peer_id,shared_work,collaboration_details,reviewer_justification,direct_knowledge_confirmed,nominated_by)
            VALUES(?,?,?,?,?,?,?)");
        $stmt->execute([$participantId,$peerId,$sharedWork,$collaboration,$justification,1,$viewer]);
        $id=(int)$pdo->lastInsertId();
        audit($viewer,'CREATE_PEER_NOMINATION','peer_nomination',$id,'Submitted peer reviewer nomination');
        $pdo->commit();
        json_response(['ok'=>true,'id'=>$id]);
    }
    if ($action === 'escalate_peer_nomination') {
        require_permission('employee.dashboard');
        require_method('POST'); require_csrf();
        $in = input();
        $id = (int)($in['id'] ?? 0);
        $reason = trim((string)($in['reason'] ?? ''));
        if (strlen($reason)<30 || strlen($reason)>2000) json_response(['ok'=>false,'error'=>'Explain why HR should review this decision using 30 to 2,000 characters'],422);
        $pdo->beginTransaction();
        $nomination = workspace_rows("SELECT pn.id,pn.status,rc.status cycle_status
            FROM peer_nominations pn JOIN review_participants rp ON rp.id=pn.participant_id
            JOIN review_cycles rc ON rc.id=rp.cycle_id
            WHERE pn.id=? AND rp.employee_id=? FOR UPDATE",[$id,$viewer])[0] ?? null;
        if (!$nomination) { $pdo->rollBack(); json_response(['ok'=>false,'error'=>'Nomination not found'],404); }
        if ($nomination['status']!=='rejected') { $pdo->rollBack(); json_response(['ok'=>false,'error'=>'Only a rejected nomination can be forwarded to HR'],409); }
        if (in_array($nomination['cycle_status'],['released','closed'],true)) { $pdo->rollBack(); json_response(['ok'=>false,'error'=>'This review cycle is already closed'],409); }
        if (workspace_rows('SELECT id FROM peer_nomination_escalations WHERE nomination_id=?',[$id])) {
            $pdo->rollBack(); json_response(['ok'=>false,'error'=>'This nomination has already been forwarded to HR'],409);
        }
        $stmt=$pdo->prepare('INSERT INTO peer_nomination_escalations(nomination_id,employee_id,escalation_reason) VALUES(?,?,?)');
        $stmt->execute([$id,$viewer,$reason]);
        $escalationId=(int)$pdo->lastInsertId();
        audit($viewer,'ESCALATE_PEER_NOMINATION','peer_nomination_escalation',$escalationId,'Forwarded rejected nomination to HR');
        $pdo->commit();
        json_response(['ok'=>true,'id'=>$escalationId]);
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
