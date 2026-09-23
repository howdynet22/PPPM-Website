<?php
declare(strict_types=1);
require_once __DIR__.'/notifications.php';

/** Shared, server-authoritative review-cycle policy. */
function review_exception_active(int $participantId, string $type): bool
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM review_participant_exceptions WHERE participant_id=? AND exception_type=? AND revoked_at IS NULL");
    $stmt->execute([$participantId, $type]);
    return (bool) $stmt->fetchColumn();
}

function review_participant_inactive(int $participantId): bool
{
    return review_exception_active($participantId, 'excluded')
        || review_exception_active($participantId, 'withdrawn');
}

function review_cycle_period(string $start, string $end): ?string
{
    $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
    $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $end);
    if (!$startDate || !$endDate || $startDate->format('Y-m-d') !== $start || $endDate->format('Y-m-d') !== $end) {
        return null;
    }
    $year = $startDate->format('Y');
    $windows = [
        "{$year}-01-01|{$year}-04-30" => "Cycle 1",
        "{$year}-05-01|{$year}-08-31" => "Cycle 2",
        "{$year}-09-01|{$year}-12-31" => "Cycle 3",
    ];
    return $windows[$start . '|' . $end] ?? null;
}

function cycle_competencies(int $cycleId): array
{
    $stmt = db()->prepare("SELECT competency_id id,name,description FROM review_cycle_competencies WHERE cycle_id=? ORDER BY display_order");
    $stmt->execute([$cycleId]);
    return $stmt->fetchAll();
}

function feedback_request_submit_capability(array $request): array
{
    if ($request['status'] !== 'pending') {
        return ['allowed'=>false, 'late'=>false, 'reason'=>'This request is no longer pending.'];
    }
    if (review_participant_inactive((int)$request['participant_id'])) {
        return ['allowed'=>false, 'late'=>false, 'reason'=>'This participant is no longer active in the cycle.'];
    }
    $participantClosed = in_array($request['participant_status'], ['manager_submitted','released'], true);
    if ($participantClosed) {
        return ['allowed'=>false, 'late'=>false, 'reason'=>'The participant review is already finalized.'];
    }
    // Manager approval creates the peer request, so the assigned reviewer can
    // act immediately. Keeping it open through manager_review prevents an
    // organization-wide stage change from stranding an approved reviewer.
    $allowedStages = $request['type'] === 'self' ? ['open'] : ['open','peer_review','manager_review'];
    if (!in_array($request['cycle_status'], $allowedStages, true)) {
        return ['allowed'=>false, 'late'=>false, 'reason'=>'The cycle is not accepting this feedback type.'];
    }
    $due = $request['response_deadline'] ?: $request['due'];
    return [
        'allowed'=>true,
        'late'=>(bool)$due && $due < date('Y-m-d'),
        'reason'=>null,
    ];
}

function cycle_readiness(int $cycleId): array
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id,status,min_peers FROM review_cycles WHERE id=?");
    $stmt->execute([$cycleId]);
    $cycle = $stmt->fetch();
    if (!$cycle) throw new InvalidArgumentException('Review cycle not found.');

    $scalar = static function(string $sql) use ($pdo,$cycleId): int {
        $s=$pdo->prepare($sql); $s->execute([$cycleId]); return (int)$s->fetchColumn();
    };
    $active = "NOT EXISTS (SELECT 1 FROM review_participant_exceptions x WHERE x.participant_id=rp.id AND x.revoked_at IS NULL AND x.exception_type IN ('excluded','withdrawn'))";
    return [
        'participants'=>$scalar("SELECT COUNT(*) FROM review_participants rp WHERE rp.cycle_id=? AND $active"),
        'missingSelf'=>$scalar("SELECT COUNT(*) FROM review_participants rp LEFT JOIN feedback_requests fr ON fr.participant_id=rp.id AND fr.type='self' WHERE rp.cycle_id=? AND $active AND COALESCE(fr.status,'pending')<>'submitted' AND NOT EXISTS (SELECT 1 FROM review_participant_exceptions x WHERE x.participant_id=rp.id AND x.exception_type='waive_self' AND x.revoked_at IS NULL)"),
        'peerAssignmentShortfall'=>$scalar("SELECT COUNT(*) FROM review_participants rp JOIN review_cycles rc ON rc.id=rp.cycle_id WHERE rp.cycle_id=? AND $active AND (SELECT COUNT(*) FROM feedback_requests fr WHERE fr.participant_id=rp.id AND fr.type='peer' AND fr.status IN ('pending','submitted'))<rc.min_peers AND NOT EXISTS (SELECT 1 FROM review_participant_exceptions x WHERE x.participant_id=rp.id AND x.exception_type='waive_peer' AND x.revoked_at IS NULL)"),
        'peerResponseShortfall'=>$scalar("SELECT COUNT(*) FROM review_participants rp JOIN review_cycles rc ON rc.id=rp.cycle_id WHERE rp.cycle_id=? AND $active AND (SELECT COUNT(*) FROM feedback_requests fr WHERE fr.participant_id=rp.id AND fr.type='peer' AND fr.status='submitted')<rc.min_peers AND NOT EXISTS (SELECT 1 FROM review_participant_exceptions x WHERE x.participant_id=rp.id AND x.exception_type='waive_peer' AND x.revoked_at IS NULL)"),
        'unresolvedEscalations'=>$scalar("SELECT COUNT(*) FROM peer_nomination_escalations e JOIN peer_nominations n ON n.id=e.nomination_id JOIN review_participants rp ON rp.id=n.participant_id WHERE rp.cycle_id=? AND e.status='pending_hr'"),
        'inactiveManagers'=>$scalar("SELECT COUNT(*) FROM review_participants rp JOIN users m ON m.id=rp.action_manager_id WHERE rp.cycle_id=? AND $active AND m.is_active=0"),
        'missingManagerReviews'=>$scalar("SELECT COUNT(*) FROM review_participants rp WHERE rp.cycle_id=? AND $active AND rp.status NOT IN ('manager_submitted','released')"),
        'pendingRequests'=>$scalar("SELECT COUNT(*) FROM feedback_requests fr JOIN review_participants rp ON rp.id=fr.participant_id WHERE rp.cycle_id=? AND fr.status='pending'"),
    ];
}

/** Enrol a saved account while self reviews are still open.
 * Caller owns the transaction so account, reporting line and enrolment commit together. */
function review_enrol_saved_user(int $userId, int $actorId): ?string
{
    $pdo = db();
    $cycle = $pdo->query("SELECT id,name,status,self_deadline FROM review_cycles
        WHERE status IN ('open','peer_review','manager_review') ORDER BY id DESC LIMIT 1 FOR UPDATE")->fetch();
    if (!$cycle) return null;

    $existing = $pdo->prepare("SELECT id FROM review_participants WHERE cycle_id=? AND employee_id=?");
    $existing->execute([$cycle['id'], $userId]);
    if ($existing->fetchColumn()) return null;

    if ($cycle['status'] !== 'open') return 'next_cycle';

    $person = $pdo->prepare("SELECT u.full_name,u.is_active,u.review_eligible,
        EXISTS (SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id
                WHERE rp.role_code=u.role AND p.permission_code='employee.dashboard' AND p.is_active=1) has_personal,
        apr.reports_to_employee_id manager_id
        FROM users u LEFT JOIN active_primary_relationships apr ON apr.employee_id=u.id
        LEFT JOIN users m ON m.id=apr.reports_to_employee_id AND m.is_active=1
        WHERE u.id=?");
    $person->execute([$userId]);
    $person = $person->fetch();
    if (!$person || !(int)$person['is_active'] || !(int)$person['review_eligible']) return null;
    if (!(int)$person['has_personal']) {
        throw new DomainException('Choose an access level with a Personal workspace or turn off review-cycle eligibility.');
    }
    if (!$person['manager_id']) {
        throw new DomainException('Assign an active primary manager to include this person in the open review cycle.');
    }
    $activeManager = $pdo->prepare("SELECT is_active FROM users WHERE id=?");
    $activeManager->execute([$person['manager_id']]);
    if (!(int)$activeManager->fetchColumn()) {
        throw new DomainException('Assign an active primary manager to include this person in the open review cycle.');
    }

    $pdo->prepare("INSERT INTO review_participants(cycle_id,employee_id,manager_id,action_manager_id,status)
        VALUES(?,?,?,?,'not_started')")->execute([
        $cycle['id'],$userId,$person['manager_id'],$person['manager_id']
    ]);
    $participantId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO feedback_requests(participant_id,respondent_id,type,status,response_deadline)
        VALUES(?,?,'self','pending',?)")->execute([$participantId,$userId,$cycle['self_deadline']]);
    create_notification($userId,'review_cycle_open','New Performance Review Cycle',
        'Your “'.$cycle['name'].'” performance review is now available.',
        'review_cycle',(int)$cycle['id'],'employee-dashboard.html#feedback',
        'cycle-open:'.$cycle['id']);
    audit($actorId,'ENROL_REVIEW_PARTICIPANT','review_participant',$participantId,
        'Added '.$person['full_name'].' to '.$cycle['name']);
    return 'enrolled';
}

function review_publish_cycle(int $cycleId, int $actorId): array
{
    $pdo=db(); $pdo->beginTransaction();
    try {
        $stmt=$pdo->prepare("SELECT * FROM review_cycles WHERE id=? FOR UPDATE");
        $stmt->execute([$cycleId]); $cycle=$stmt->fetch();
        if(!$cycle) throw new InvalidArgumentException('Review cycle not found.');
        if($cycle['status']!=='draft') throw new DomainException('Only a draft cycle can be published.');
        $other=$pdo->prepare("SELECT name FROM review_cycles WHERE id<>? AND status IN ('open','peer_review','manager_review') LIMIT 1 FOR UPDATE");
        $other->execute([$cycleId]);
        if($name=$other->fetchColumn()) throw new DomainException('Close or release the active cycle "'.$name.'" before publishing another.');
        $competencies=$pdo->query("SELECT id,name,description FROM competencies WHERE is_active=1 ORDER BY id")->fetchAll();
        if(!$competencies) throw new DomainException('Add at least one active competency before publishing a review cycle.');
        $invalid=$pdo->query("SELECT u.full_name FROM users u WHERE u.is_active=1 AND u.review_eligible=1 AND EXISTS (SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_code=u.role AND p.permission_code='employee.dashboard' AND p.is_active=1) AND NOT EXISTS (SELECT 1 FROM active_primary_relationships apr JOIN users m ON m.id=apr.reports_to_employee_id AND m.is_active=1 WHERE apr.employee_id=u.id) ORDER BY u.full_name LIMIT 6")->fetchAll(PDO::FETCH_COLUMN);
        if($invalid) throw new DomainException('Assign an active manager before publishing: '.implode(', ',$invalid).'.');
        $people=$pdo->query("SELECT u.id,apr.reports_to_employee_id manager_id FROM users u JOIN active_primary_relationships apr ON apr.employee_id=u.id JOIN users m ON m.id=apr.reports_to_employee_id AND m.is_active=1 WHERE u.is_active=1 AND u.review_eligible=1 AND EXISTS (SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_code=u.role AND p.permission_code='employee.dashboard' AND p.is_active=1) ORDER BY u.id")->fetchAll();
        if(!$people) throw new DomainException('No review-eligible people have an active manager and Personal workspace.');
        $ins=$pdo->prepare("INSERT INTO review_participants(cycle_id,employee_id,manager_id,action_manager_id,status) VALUES(?,?,?,?,'not_started')");
        $self=$pdo->prepare("INSERT INTO feedback_requests(participant_id,respondent_id,type,status,response_deadline) VALUES(?,?,'self','pending',?)");
        foreach($people as $person){
            $ins->execute([$cycleId,$person['id'],$person['manager_id'],$person['manager_id']]);
            $self->execute([(int)$pdo->lastInsertId(),$person['id'],$cycle['self_deadline']]);
            create_notification((int)$person['id'],'review_cycle_open','New Performance Review Cycle',
                'Your “'.$cycle['name'].'” performance review is now available.','review_cycle',$cycleId,
                'employee-dashboard.html#feedback',"cycle-open:$cycleId");
        }
        $snap=$pdo->prepare("INSERT INTO review_cycle_competencies(cycle_id,competency_id,name,description,display_order) VALUES(?,?,?,?,?)");
        foreach($competencies as $i=>$c) $snap->execute([$cycleId,$c['id'],$c['name'],$c['description'],$i+1]);
        $pdo->prepare("UPDATE review_cycles SET status='open',published_at=NOW() WHERE id=?")->execute([$cycleId]);
        audit($actorId,'PUBLISH_REVIEW_CYCLE','review_cycle',$cycleId,'Published with '.count($people).' participants and '.count($competencies).' competencies');
        record_cycle_transition($cycleId,'draft','open',$actorId,'Published review cycle');
        $pdo->commit();
        return ['id'=>$cycleId,'status'=>'open','participants'=>count($people),'competencies'=>count($competencies)];
    } catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
}

function pip_transition_allowed(string $actor, string $from, string $to): bool
{
    $rules = [
        'manager'=>['draft'=>['draft'],'active'=>['active'],'extended'=>['extended']],
        'hr'=>[
            'draft'=>['draft','active','closed'],
            'active'=>['active','extended','successful','unsuccessful','closed'],
            'extended'=>['extended','successful','unsuccessful','closed'],
            'successful'=>['successful','closed'],
            'unsuccessful'=>['unsuccessful','closed'],
            'closed'=>['closed'],
        ],
    ];
    return in_array($to,$rules[$actor][$from]??[],true);
}
