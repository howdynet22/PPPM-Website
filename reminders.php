<?php
declare(strict_types=1);
require_once __DIR__.'/notifications.php';

/** Store the delivery guard and notification atomically. */
function send_reminder_once(int $userId,string $type,string $title,string $message,string $entityType,int $entityId,string $date,string $url): bool
{
    if ($userId < 1) return false;
    $pdo=db();$started=!$pdo->inTransaction();
    if($started)$pdo->beginTransaction();
    try {
        if(!record_reminder_sent($userId,$type,$entityType,$entityId,$date)){
            if($started)$pdo->commit();return false;
        }
        create_notification($userId,$type,$title,$message,$entityType,$entityId,$url,"reminder:$type:$entityType:$entityId:$date");
        if($started)$pdo->commit();return true;
    } catch(Throwable $e){if($started&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** Called by scripts/run-reminders.php from a trusted scheduler. */
function send_reminders(?DateTimeImmutable $clock=null): array
{
    $clock=$clock??new DateTimeImmutable('today');
    $today=$clock->format('Y-m-d');
    $three=$clock->modify('+3 days')->format('Y-m-d');
    $seven=$clock->modify('+7 days')->format('Y-m-d');
    $summary=['pdp_upcoming'=>0,'pdp_overdue'=>0,'pip_ending'=>0,'review_deadline'=>0,'review_overdue'=>0];

    // Correct ownership lives on pdps, not pdp_actions (there is no pa.employee_id).
    $stmt=db()->prepare("SELECT pa.id,pa.title,pa.due_date,p.employee_id,p.manager_id,u.full_name employee_name
      FROM pdp_actions pa JOIN pdps p ON p.id=pa.pdp_id JOIN users u ON u.id=p.employee_id
      WHERE pa.status NOT IN ('completed','cancelled') AND p.status IN ('draft','agreed') AND (pa.due_date=? OR pa.due_date<?)");
    $stmt->execute([$seven,$today]);
    foreach($stmt->fetchAll() as $a){
        $upcoming=$a['due_date']===$seven;$type=$upcoming?'pdp_due_soon':'pdp_overdue';
        $title=$upcoming?'PDP action due soon':'PDP action overdue';
        $message='Your development action “'.$a['title'].'” '.($upcoming?'is due on ':'was due on ').$a['due_date'].'.';
        if(send_reminder_once((int)$a['employee_id'],$type,$title,$message,'pdp_action',(int)$a['id'],$upcoming?$a['due_date']:$today,'employee-dashboard.html#development'))$summary[$upcoming?'pdp_upcoming':'pdp_overdue']++;
        if(!$upcoming)send_reminder_once((int)$a['manager_id'],'pdp_overdue_manager','Team PDP action overdue',$a['employee_name'].' has an overdue development action.','pdp_action',(int)$a['id'],$today,'manager-dashboard.html');
    }

    $stmt=db()->prepare("SELECT p.id,p.end_date,p.employee_id,p.manager_id,p.hr_owner_id,u.full_name employee_name
      FROM pips p JOIN users u ON u.id=p.employee_id WHERE p.status IN ('active','extended') AND p.end_date=?");
    $stmt->execute([$three]);
    foreach($stmt->fetchAll() as $p){
        if(send_reminder_once((int)$p['employee_id'],'pip_ending','Improvement plan ending soon','Your improvement plan ends on '.$p['end_date'].'.','pip',(int)$p['id'],$p['end_date'],'employee-dashboard.html#improvement'))$summary['pip_ending']++;
        send_reminder_once((int)$p['manager_id'],'pip_ending_manager','Team improvement plan ending soon',$p['employee_name'].'’s improvement plan ends on '.$p['end_date'].'.','pip',(int)$p['id'],$p['end_date'],'manager-dashboard.html');
        send_reminder_once((int)$p['hr_owner_id'],'pip_ending_hr','Improvement plan ending soon',$p['employee_name'].'’s improvement plan ends on '.$p['end_date'].'.','pip',(int)$p['id'],$p['end_date'],'hr-dashboard.html');
    }

    $stmt=db()->query("SELECT rc.id,rc.name,rc.status cycle_status,rc.self_deadline,rc.peer_deadline,rc.manager_deadline,
      rp.id participant_id,rp.employee_id,rp.action_manager_id,rp.status participant_status,u.full_name employee_name,
      (SELECT COUNT(*) FROM feedback_requests fr WHERE fr.participant_id=rp.id AND fr.type='peer' AND fr.status='pending') peer_pending
      FROM review_cycles rc JOIN review_participants rp ON rp.cycle_id=rc.id JOIN users u ON u.id=rp.employee_id
      WHERE rc.status IN ('open','peer_review','manager_review')
      AND NOT EXISTS(SELECT 1 FROM review_participant_exceptions x WHERE x.participant_id=rp.id AND x.revoked_at IS NULL AND x.exception_type IN ('excluded','withdrawn'))");
    foreach($stmt->fetchAll() as $r){
        $cycleId=(int)$r['id'];
        if($r['cycle_status']==='open'&&$r['participant_status']==='not_started'){
            if($r['self_deadline']===$three&&send_reminder_once((int)$r['employee_id'],'self_review_due_soon','Self review due soon','Your self review for “'.$r['name'].'” is due on '.$r['self_deadline'].'.','review_cycle',$cycleId,$r['self_deadline'],'employee-dashboard.html#feedback'))$summary['review_deadline']++;
            if($r['self_deadline']<$today&&send_reminder_once((int)$r['employee_id'],'self_review_overdue','Self review overdue','Your self review for “'.$r['name'].'” is overdue but remains open while the cycle is open.','review_cycle',$cycleId,$today,'employee-dashboard.html#feedback'))$summary['review_overdue']++;
        }
        if(in_array($r['cycle_status'],['peer_review','manager_review'],true)&&(int)$r['peer_pending']>0&&$r['peer_deadline']===$three){
            if(send_reminder_once((int)$r['employee_id'],'peer_feedback_pending','Peer feedback still pending','Your review is waiting for assigned peer responses.','review_participant',(int)$r['participant_id'],$r['peer_deadline'],'employee-dashboard.html#feedback'))$summary['review_deadline']++;
        }
        if($r['cycle_status']==='manager_review'&&$r['participant_status']!=='manager_submitted'&&$r['manager_deadline']===$three){
            if(send_reminder_once((int)$r['action_manager_id'],'manager_review_due_soon','Manager review due soon','Your review for '.$r['employee_name'].' is due on '.$r['manager_deadline'].'.','review_participant',(int)$r['participant_id'],$r['manager_deadline'],'manager-dashboard.html'))$summary['review_deadline']++;
        }
    }
    return $summary;
}
