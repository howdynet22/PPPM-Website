<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'login':
            require_method('POST');
            $in = input();
            $email = trim((string)($in['email'] ?? ''));
            $password = (string)($in['password'] ?? '');
            if ($email === '' || $password === '') json_response(['ok'=>false,'error'=>'Email and password are required'], 422);
            $stmt = db()->prepare("SELECT u.id, u.emp_code, u.full_name, u.email, u.password_hash, u.role, u.job_title, u.department, r.display_name AS role_name, r.dashboard_path FROM users u JOIN roles r ON r.role_code = u.role WHERE u.email = ? AND u.is_active = 1 AND r.is_active = 1 LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if (!$user || !password_verify($password, $user['password_hash'])) json_response(['ok'=>false,'error'=>'Invalid email or password'], 401);
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['role'] = $user['role'];
            unset($user['password_hash']);
            $user['permissions'] = permissions_for_role($user['role']);
            audit((int)$user['id'],'LOGIN','user',(int)$user['id'],'Successful login');
            json_response(['ok'=>true,'user'=>$user]);

        case 'logout':
            require_method('POST');
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            }
            session_destroy();
            json_response(['ok'=>true]);

        case 'me':
            $user = require_login();
            $user['permissions'] = permissions_for_role($user['role']);
            json_response(['ok'=>true,'user'=>$user]);

        case 'change_password':
            require_method('POST');
            $user = require_login();
            $in = input();
            $current = (string)($in['currentPassword'] ?? '');
            $new = (string)($in['newPassword'] ?? '');
            $confirm = (string)($in['confirmPassword'] ?? '');
            if ($current === '' || $new === '' || $confirm === '') json_response(['ok'=>false,'error'=>'All password fields are required'],422);
            if (strlen($new) < 8) json_response(['ok'=>false,'error'=>'New password must be at least 8 characters'],422);
            if ($new !== $confirm) json_response(['ok'=>false,'error'=>'New passwords do not match'],422);
            if ($current === $new) json_response(['ok'=>false,'error'=>'New password must be different from the current password'],422);
            $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ? AND is_active = 1');
            $stmt->execute([(int)$user['id']]);
            $row = $stmt->fetch();
            if (!$row || !password_verify($current, $row['password_hash'])) json_response(['ok'=>false,'error'=>'Current password is incorrect'],401);
            $hash = password_hash($new, PASSWORD_DEFAULT);
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash,(int)$user['id']]);
            audit((int)$user['id'],'CHANGE_PASSWORD','user',(int)$user['id'],'Password changed');
            json_response(['ok'=>true,'message'=>'Password changed successfully']);

        case 'dashboard':
            $manager = require_permission('manager.dashboard');
            $pdo = db();
            $mid = (int)$manager['id'];

            $stmt = $pdo->prepare("SELECT id, full_name AS name, job_title AS role, email, department FROM users WHERE manager_id = ? AND role = 'employee' AND is_active = 1 ORDER BY full_name");
            $stmt->execute([$mid]);
            $employees = $stmt->fetchAll();

            foreach ($employees as &$e) {
                $eid = (int)$e['id'];
                $stmt = $pdo->prepare("SELECT rp.id participant_id, rp.cycle_id, rc.name cycle_name, rp.status review_status, rp.final_rating rating, rp.manager_summary FROM review_participants rp JOIN review_cycles rc ON rc.id=rp.cycle_id WHERE rp.employee_id=? AND rp.manager_id=? ORDER BY rp.id DESC LIMIT 1");
                $stmt->execute([$eid,$mid]);
                $review = $stmt->fetch() ?: null;
                $e['participantId'] = $review['participant_id'] ?? null;
                $e['cycleId'] = $review['cycle_id'] ?? null;
                $e['cycle'] = $review['cycle_name'] ?? 'No review cycle';
                $e['review'] = match($review['review_status'] ?? 'not_started') {
                    'self_submitted' => 'Self submitted',
                    'peers_complete' => 'Peers complete',
                    'manager_submitted' => 'Manager submitted',
                    'released' => 'Released',
                    default => 'Not started'
                };
                $e['rating'] = $review['rating'] !== null ? (float)$review['rating'] : null;
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM goals WHERE employee_id=? AND manager_id=?");
                $stmt->execute([$eid,$mid]);
                $e['goals'] = (int)$stmt->fetchColumn();
                $stmt = $pdo->prepare("SELECT COALESCE(ROUND(AVG(pa.progress_pct),0),0) FROM pdp_actions pa JOIN pdps p ON p.id=pa.pdp_id WHERE p.employee_id=? AND p.manager_id=? AND pa.status <> 'cancelled'");
                $stmt->execute([$eid,$mid]);
                $e['pdp'] = (int)$stmt->fetchColumn();
                $stmt = $pdo->prepare("SELECT s.name, r.required_level, COALESCE(es.current_level,0) current_level FROM role_skill_requirements r JOIN skills s ON s.id=r.skill_id LEFT JOIN employee_skills es ON es.employee_id=? AND es.skill_id=r.skill_id WHERE r.job_title=? ORDER BY (r.required_level-COALESCE(es.current_level,0)) DESC, s.name");
                $stmt->execute([$eid,$e['role']]);
                $skills = [];
                foreach ($stmt->fetchAll() as $s) $skills[] = [$s['name'], (int)$s['required_level'], (int)$s['current_level']];
                $e['skills'] = $skills;
                $e['attention'] = (($e['rating'] !== null && $e['rating'] < 3.5) || $e['review'] !== 'Manager submitted' || $e['pdp'] < 50 || count(array_filter($skills, fn($s)=>$s[2]<$s[1])) > 0);
            }
            unset($e);

            $stmt = $pdo->prepare("SELECT pn.id, pn.status, rp.employee_id, emp.full_name employee, pn.peer_id, peer.full_name peer FROM peer_nominations pn JOIN review_participants rp ON rp.id=pn.participant_id JOIN users emp ON emp.id=rp.employee_id JOIN users peer ON peer.id=pn.peer_id WHERE rp.manager_id=? ORDER BY pn.created_at DESC");
            $stmt->execute([$mid]);
            $peerNominations = array_map(fn($r)=>[
                'id'=>(int)$r['id'], 'employeeId'=>(int)$r['employee_id'], 'employee'=>$r['employee'], 'peerId'=>(int)$r['peer_id'], 'peer'=>$r['peer'], 'status'=>$r['status']
            ], $stmt->fetchAll());

            $stmt = $pdo->prepare("SELECT g.id,g.employee_id, u.full_name employee,g.title,g.description target,g.due_date due,g.progress_pct progress,g.status FROM goals g JOIN users u ON u.id=g.employee_id WHERE g.manager_id=? ORDER BY g.due_date");
            $stmt->execute([$mid]);
            $goals = array_map(fn($r)=>[
                'id'=>(int)$r['id'],'employeeId'=>(int)$r['employee_id'],'employee'=>$r['employee'],'title'=>$r['title'],'target'=>$r['target'],'due'=>$r['due'],'progress'=>(int)$r['progress'],'status'=>ucwords(str_replace('_',' ',$r['status']))
            ], $stmt->fetchAll());

            $stmt = $pdo->prepare("SELECT pa.id, p.employee_id, u.full_name employee, pa.title, pa.description, pa.due_date due, pa.progress_pct progress, pa.status, p.id pdp_id FROM pdp_actions pa JOIN pdps p ON p.id=pa.pdp_id JOIN users u ON u.id=p.employee_id WHERE p.manager_id=? AND pa.status <> 'cancelled' ORDER BY pa.due_date");
            $stmt->execute([$mid]);
            $pdps = array_map(fn($r)=>[
                'id'=>(int)$r['id'],'pdpId'=>(int)$r['pdp_id'],'employeeId'=>(int)$r['employee_id'],'employee'=>$r['employee'],'title'=>$r['title'],'description'=>$r['description'],'due'=>$r['due'],'progress'=>(int)$r['progress'],'status'=>ucwords(str_replace('_',' ',$r['status']))
            ], $stmt->fetchAll());

            $stmt = $pdo->prepare("SELECT p.id,p.employee_id,u.full_name employee,p.reason,p.start_date start,p.end_date end,p.status,hr.full_name hr_owner FROM pips p JOIN users u ON u.id=p.employee_id JOIN users hr ON hr.id=p.hr_owner_id WHERE p.manager_id=? ORDER BY p.end_date DESC");
            $stmt->execute([$mid]);
            $pips = [];
            foreach ($stmt->fetchAll() as $p) {
                $pip = ['id'=>(int)$p['id'],'employeeId'=>(int)$p['employee_id'],'employee'=>$p['employee'],'reason'=>$p['reason'],'start'=>$p['start'],'end'=>$p['end'],'status'=>$p['status'],'hrOwner'=>$p['hr_owner'],'objectives'=>[],'checkins'=>[]];
                $s = $pdo->prepare("SELECT id,objective,success_criteria,due_date due,status FROM pip_objectives WHERE pip_id=? ORDER BY id"); $s->execute([$pip['id']]);
                foreach ($s->fetchAll() as $o) $pip['objectives'][]=['id'=>(int)$o['id'],'text'=>$o['objective'],'criteria'=>$o['success_criteria'],'due'=>$o['due'],'status'=>$o['status']];
                $s = $pdo->prepare("SELECT checkin_date date,notes FROM pip_checkins WHERE pip_id=? ORDER BY checkin_date DESC,id DESC"); $s->execute([$pip['id']]);
                foreach ($s->fetchAll() as $c) $pip['checkins'][]=['date'=>$c['date'],'notes'=>$c['notes']];
                $pips[]=$pip;
            }

            $stmt = $pdo->prepare("SELECT id,name FROM competencies WHERE is_active=1 ORDER BY id"); $stmt->execute(); $competencies=$stmt->fetchAll();

            $managerRatings=[];
            $feedback=[];
            foreach ($employees as $e) {
                if ($e['participantId']) {
                    $mr=$pdo->prepare("SELECT frt.competency_id, frt.score, frt.comment FROM feedback_requests fr JOIN feedback_ratings frt ON frt.request_id=fr.id WHERE fr.participant_id=? AND fr.respondent_id=? AND fr.type='manager' AND fr.status='submitted'");
                    $mr->execute([(int)$e['participantId'],$mid]);
                    $managerRatings[(string)$e['id']]=array_map(fn($r)=>['competencyId'=>(int)$r['competency_id'],'score'=>(int)$r['score'],'comment'=>$r['comment']],$mr->fetchAll());
                }
                if (!$e['participantId']) continue;
                $s=$pdo->prepare("SELECT competency,avg_score,responses FROM v_360_summary WHERE participant_id=? AND type='peer' ORDER BY competency"); $s->execute([(int)$e['participantId']]);
                $rows=$s->fetchAll();
                $s2=$pdo->prepare("SELECT COUNT(DISTINCT fr.id) FROM feedback_requests fr WHERE fr.participant_id=? AND fr.type='peer' AND fr.status='submitted'"); $s2->execute([(int)$e['participantId']]); $count=(int)$s2->fetchColumn();
                $s3=$pdo->prepare("SELECT COALESCE(min_peers,3) FROM review_cycles WHERE id=?"); $s3->execute([(int)$e['cycleId']]); $min=(int)$s3->fetchColumn();
                $feedback[(string)$e['id']] = ['available'=>$count >= $min,'responses'=>$count,'required'=>$min,'competencies'=>$count >= $min ? array_map(fn($r)=>['name'=>$r['competency'],'score'=>(float)$r['avg_score'],'responses'=>(int)$r['responses']],$rows):[]];
            }

            $s=$pdo->query("SELECT id, full_name name FROM users WHERE role='hr' AND is_active=1 ORDER BY full_name");
            $hrOwners=array_map(fn($r)=>['id'=>(int)$r['id'],'name'=>$r['name']],$s->fetchAll());

            // Manager's own personal dashboard: latest review, own goals and PDP actions.
            $s=$pdo->prepare("SELECT rp.id participant_id,rc.name cycle,rp.status,rp.final_rating,rp.manager_summary FROM review_participants rp JOIN review_cycles rc ON rc.id=rp.cycle_id WHERE rp.employee_id=? ORDER BY rp.id DESC LIMIT 1"); $s->execute([$mid]); $ownReview=$s->fetch() ?: null;
            $s=$pdo->prepare("SELECT id,title,description target,due_date due,progress_pct progress,status FROM goals WHERE employee_id=? ORDER BY due_date"); $s->execute([$mid]); $ownGoals=array_map(fn($r)=>['id'=>(int)$r['id'],'title'=>$r['title'],'target'=>$r['target'],'due'=>$r['due'],'progress'=>(int)$r['progress'],'status'=>ucwords(str_replace('_',' ',$r['status']))],$s->fetchAll());
            $s=$pdo->prepare("SELECT pa.id,pa.title,pa.due_date due,pa.progress_pct progress,pa.status FROM pdp_actions pa JOIN pdps p ON p.id=pa.pdp_id WHERE p.employee_id=? ORDER BY pa.due_date"); $s->execute([$mid]); $ownPdp=array_map(fn($r)=>['id'=>(int)$r['id'],'title'=>$r['title'],'due'=>$r['due'],'progress'=>(int)$r['progress'],'status'=>ucwords(str_replace('_',' ',$r['status']))],$s->fetchAll());
            $ownFeedback=[];
            if ($ownReview) { $s=$pdo->prepare("SELECT competency,avg_score,responses FROM v_360_summary WHERE participant_id=? ORDER BY competency,type"); $s->execute([(int)$ownReview['participant_id']]); $ownFeedback=$s->fetchAll(); }

            $notifications=[];
            foreach ($employees as $e) {
                if ($e['review'] !== 'Manager submitted') $notifications[]=['id'=>'review-'.$e['id'],'text'=>'Manager review is pending for '.$e['name'].'.','time'=>'Current review cycle','unread'=>true];
            }
            foreach ($peerNominations as $n) if ($n['status']==='pending') $notifications[]=['id'=>'peer-'.$n['id'],'text'=>'A peer nomination requires approval for '.$n['employee'].'.','time'=>'Current review cycle','unread'=>true];
            foreach ($pdps as $p) if ($p['due'] <= date('Y-m-d', strtotime('+14 days')) && $p['status'] !== 'Completed') $notifications[]=['id'=>'pdp-'.$p['id'],'text'=>'PDP action for '.$p['employee'].' is due soon.','time'=>$p['due'],'unread'=>true];

            json_response(['ok'=>true,'manager'=>$manager,'employees'=>$employees,'peerNominations'=>$peerNominations,'goals'=>$goals,'pdps'=>$pdps,'pips'=>$pips,'feedback'=>$feedback,'managerRatings'=>$managerRatings,'competencies'=>$competencies,'hrOwners'=>$hrOwners,'notifications'=>$notifications,'personal'=>['review'=>$ownReview,'goals'=>$ownGoals,'pdp'=>$ownPdp,'feedback'=>$ownFeedback]]);

        case 'submit_review':
            $manager=require_permission('manager.reviews'); $in=input(); $participantId=(int)($in['participantId']??0); $participant=participant_for_manager((int)$manager['id'],$participantId); if(!$participant) json_response(['ok'=>false,'error'=>'Review is not assigned to you'],403);
            $rating=(float)($in['rating']??0); if($rating<1||$rating>5) json_response(['ok'=>false,'error'=>'Rating must be between 1 and 5'],422);
            $summary=trim((string)($in['summary']??'')); if($summary==='') json_response(['ok'=>false,'error'=>'Manager summary is required'],422);
            $pdo=db(); $pdo->beginTransaction();
            $stmt=$pdo->prepare("UPDATE review_participants SET final_rating=?, manager_summary=?, status='manager_submitted' WHERE id=? AND manager_id=?"); $stmt->execute([$rating,$summary,$participantId,$manager['id']]);
            $stmt=$pdo->prepare("SELECT id FROM feedback_requests WHERE participant_id=? AND respondent_id=? AND type='manager' LIMIT 1"); $stmt->execute([$participantId,$manager['id']]); $req=$stmt->fetch();
            if(!$req){$stmt=$pdo->prepare("INSERT INTO feedback_requests(participant_id,respondent_id,type,status,submitted_at) VALUES(?,?, 'manager','submitted',NOW())");$stmt->execute([$participantId,$manager['id']]);$reqId=(int)$pdo->lastInsertId();} else {$reqId=(int)$req['id'];$pdo->prepare("UPDATE feedback_requests SET status='submitted',submitted_at=NOW() WHERE id=?")->execute([$reqId]);}
            foreach(($in['competencies']??[]) as $c){$cid=(int)($c['competencyId']??0);$score=(int)($c['score']??0);$comment=trim((string)($c['comment']??''));if($cid>0&&$score>=1&&$score<=5){$stmt=$pdo->prepare("INSERT INTO feedback_ratings(request_id,competency_id,score,comment) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE score=VALUES(score),comment=VALUES(comment)");$stmt->execute([$reqId,$cid,$score,$comment]);}}
            audit((int)$manager['id'],'SUBMIT_MANAGER_REVIEW','review_participant',$participantId,'Submitted manager review'); $pdo->commit(); json_response(['ok'=>true]);

        case 'decide_peer':
            $manager=require_permission('manager.reviews'); $in=input(); $id=(int)($in['id']??0); $status=$in['status']??''; if(!in_array($status,['approved','rejected'],true)) json_response(['ok'=>false,'error'=>'Invalid peer decision'],422);
            $pdo=db(); $stmt=$pdo->prepare("SELECT pn.id FROM peer_nominations pn JOIN review_participants rp ON rp.id=pn.participant_id WHERE pn.id=? AND rp.manager_id=?");$stmt->execute([$id,$manager['id']]);if(!$stmt->fetch())json_response(['ok'=>false,'error'=>'Nomination not found'],404);
            $stmt=$pdo->prepare("UPDATE peer_nominations SET status=?, decided_by=? WHERE id=?");$stmt->execute([$status,$manager['id'],$id]);audit((int)$manager['id'],strtoupper('PEER_'.$status),'peer_nomination',$id,'Manager '.$status.' peer nomination');json_response(['ok'=>true]);

        case 'create_goal':
            $manager=require_permission('manager.goals'); $in=input(); $eid=(int)($in['employeeId']??0);if($eid !== (int)$manager['id'] && !manager_employee((int)$manager['id'],$eid))json_response(['ok'=>false,'error'=>'Employee is not your direct report'],403);
            $title=trim((string)($in['title']??''));$target=trim((string)($in['target']??''));$due=(string)($in['due']??'');if($title===''||$target===''||$due==='')json_response(['ok'=>false,'error'=>'Goal title, measurable target and due date are required'],422);
            $stmt=db()->prepare("INSERT INTO goals(employee_id,manager_id,title,description,due_date,status,progress_pct) VALUES(?,?,?,?,?,'not_started',0)");$stmt->execute([$eid,$manager['id'],$title,$target,$due]);$id=(int)db()->lastInsertId();audit((int)$manager['id'],'CREATE_GOAL','goal',$id,'Created employee goal');json_response(['ok'=>true,'id'=>$id]);

        case 'update_goal':
            $manager=require_permission('manager.goals');$in=input();$id=(int)($in['id']??0);$progress=max(0,min(100,(int)($in['progress']??0)));$status=strtolower(str_replace(' ','_',trim((string)($in['status']??'not_started'))));$title=trim((string)($in['title']??''));
            $stmt=db()->prepare("SELECT id FROM goals WHERE id=? AND manager_id=?");$stmt->execute([$id,$manager['id']]);if(!$stmt->fetch())json_response(['ok'=>false,'error'=>'Goal not found'],404);
            $allowed=['not_started','in_progress','completed','missed'];if(!in_array($status,$allowed,true))json_response(['ok'=>false,'error'=>'Invalid goal status'],422);
            $pdo=db();$pdo->prepare("UPDATE goals SET title=?,progress_pct=?,status=? WHERE id=? AND manager_id=?")->execute([$title,$progress,$status,$id,$manager['id']]);audit((int)$manager['id'],'UPDATE_GOAL','goal',$id,'Updated goal progress/status');json_response(['ok'=>true]);

        case 'create_pdp':
            $manager=require_permission('manager.goals');$in=input();$eid=(int)($in['employeeId']??0);if(!manager_employee((int)$manager['id'],$eid))json_response(['ok'=>false,'error'=>'Employee is not your direct report'],403);
            $title=trim((string)($in['title']??''));$description=trim((string)($in['description']??''));$due=(string)($in['due']??'');if($title===''||$due==='')json_response(['ok'=>false,'error'=>'PDP action and due date are required'],422);
            $pdo=db();$pdo->beginTransaction();$stmt=$pdo->prepare("SELECT id FROM pdps WHERE employee_id=? AND manager_id=? AND status IN ('draft','agreed') ORDER BY id DESC LIMIT 1");$stmt->execute([$eid,$manager['id']]);$p=$stmt->fetch();if($p)$pdpId=(int)$p['id'];else{$stmt=$pdo->prepare("INSERT INTO pdps(employee_id,manager_id,summary,status) VALUES(?,?,?,'draft')");$stmt->execute([$eid,$manager['id'],'Development plan']);$pdpId=(int)$pdo->lastInsertId();}
            $stmt=$pdo->prepare("INSERT INTO pdp_actions(pdp_id,title,description,due_date,status,progress_pct) VALUES(?,?,?,?,'not_started',0)");$stmt->execute([$pdpId,$title,$description,$due]);$id=(int)$pdo->lastInsertId();audit((int)$manager['id'],'CREATE_PDP_ACTION','pdp_action',$id,'Created PDP action');$pdo->commit();json_response(['ok'=>true,'id'=>$id]);

        case 'update_pdp':
            $manager=require_permission('manager.goals');$in=input();$id=(int)($in['id']??0);$progress=max(0,min(100,(int)($in['progress']??0)));$status=strtolower(str_replace(' ','_',trim((string)($in['status']??'not_started'))));$title=trim((string)($in['title']??''));$note=trim((string)($in['note']??''));
            $allowed=['not_started','in_progress','completed','overdue','cancelled'];if(!in_array($status,$allowed,true))json_response(['ok'=>false,'error'=>'Invalid PDP status'],422);
            $pdo=db();$stmt=$pdo->prepare("SELECT pa.id FROM pdp_actions pa JOIN pdps p ON p.id=pa.pdp_id WHERE pa.id=? AND p.manager_id=?");$stmt->execute([$id,$manager['id']]);if(!$stmt->fetch())json_response(['ok'=>false,'error'=>'PDP action not found'],404);
            $completedAt=$status==='completed'?'NOW()':'NULL';$pdo->prepare("UPDATE pdp_actions SET title=?,progress_pct=?,status=?,completed_at=$completedAt WHERE id=?")->execute([$title,$progress,$status,$id]);
            if($note!=='')$pdo->prepare("INSERT INTO action_updates(action_id,author_id,note,new_status) VALUES(?,?,?,?)")->execute([$id,$manager['id'],$note,$status]);audit((int)$manager['id'],'UPDATE_PDP_ACTION','pdp_action',$id,'Updated PDP action');json_response(['ok'=>true]);

        case 'create_pip':
            $manager=require_permission('manager.pips');$in=input();$eid=(int)($in['employeeId']??0);if(!manager_employee((int)$manager['id'],$eid))json_response(['ok'=>false,'error'=>'Employee is not your direct report'],403);$hr=(int)($in['hrOwnerId']??0);$stmt=db()->prepare("SELECT id FROM users WHERE id=? AND role='hr' AND is_active=1");$stmt->execute([$hr]);if(!$stmt->fetch())json_response(['ok'=>false,'error'=>'A valid HR owner is required'],422);
            $reason=trim((string)($in['reason']??''));$start=(string)($in['start']??'');$end=(string)($in['end']??'');$objective=trim((string)($in['objective']??''));$criteria=trim((string)($in['criteria']??''));$due=(string)($in['objectiveDue']??'');if($reason===''||$start===''||$end===''||$objective===''||$criteria==='')json_response(['ok'=>false,'error'=>'Reason, dates, objective and measurable success criteria are required'],422);
            $pdo=db();$pdo->beginTransaction();$stmt=$pdo->prepare("INSERT INTO pips(employee_id,manager_id,hr_owner_id,reason,start_date,end_date,status) VALUES(?,?,?,?,?,?,'draft')");$stmt->execute([$eid,$manager['id'],$hr,$reason,$start,$end]);$id=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO pip_objectives(pip_id,objective,success_criteria,due_date) VALUES(?,?,?,?)")->execute([$id,$objective,$criteria,$due]);audit((int)$manager['id'],'CREATE_PIP','pip',$id,'Created PIP draft');$pdo->commit();json_response(['ok'=>true,'id'=>$id]);

        case 'add_pip_objective':
            $manager=require_permission('manager.pips');$in=input();$id=(int)($in['pipId']??0);$objective=trim((string)($in['objective']??''));$criteria=trim((string)($in['criteria']??''));$due=(string)($in['due']??'');$stmt=db()->prepare("SELECT id FROM pips WHERE id=? AND manager_id=?");$stmt->execute([$id,$manager['id']]);if(!$stmt->fetch())json_response(['ok'=>false,'error'=>'PIP not found'],404);if($objective===''||$criteria==='')json_response(['ok'=>false,'error'=>'Objective and measurable success criteria are required'],422);$stmt=db()->prepare("INSERT INTO pip_objectives(pip_id,objective,success_criteria,due_date) VALUES(?,?,?,?)");$stmt->execute([$id,$objective,$criteria,$due?:null]);audit((int)$manager['id'],'ADD_PIP_OBJECTIVE','pip',$id,'Added PIP objective');json_response(['ok'=>true]);

        case 'update_pip_objective':
            $manager=require_permission('manager.pips');$in=input();$id=(int)($in['id']??0);$status=(string)($in['status']??'');if(!in_array($status,['not_met','partially_met','met'],true))json_response(['ok'=>false,'error'=>'Invalid objective status'],422);$pdo=db();$stmt=$pdo->prepare("SELECT po.id FROM pip_objectives po JOIN pips p ON p.id=po.pip_id WHERE po.id=? AND p.manager_id=?");$stmt->execute([$id,$manager['id']]);if(!$stmt->fetch())json_response(['ok'=>false,'error'=>'Objective not found'],404);$pdo->prepare("UPDATE pip_objectives SET status=? WHERE id=?")->execute([$status,$id]);audit((int)$manager['id'],'UPDATE_PIP_OBJECTIVE','pip_objective',$id,'Updated PIP objective');json_response(['ok'=>true]);

        case 'add_pip_checkin':
            $manager=require_permission('manager.pips');$in=input();$pipId=(int)($in['pipId']??0);$notes=trim((string)($in['notes']??''));$date=(string)($in['date']??date('Y-m-d'));$stmt=db()->prepare("SELECT id FROM pips WHERE id=? AND manager_id=?");$stmt->execute([$pipId,$manager['id']]);if(!$stmt->fetch())json_response(['ok'=>false,'error'=>'PIP not found'],404);if($notes==='')json_response(['ok'=>false,'error'=>'Check-in notes are required'],422);$pdo=db();$pdo->prepare("INSERT INTO pip_checkins(pip_id,checkin_date,author_id,notes) VALUES(?,?,?,?)")->execute([$pipId,$date,$manager['id'],$notes]);$pdo->prepare("UPDATE pips SET status=IF(status='draft','active',status) WHERE id=?")->execute([$pipId]);audit((int)$manager['id'],'ADD_PIP_CHECKIN','pip',$pipId,'Recorded PIP check-in');json_response(['ok'=>true]);

        case 'update_pip_status':
            $manager=require_permission('manager.pips');$in=input();$id=(int)($in['id']??0);$status=(string)($in['status']??'');$note=trim((string)($in['note']??''));if(!in_array($status,['draft','active','extended','successful','unsuccessful','closed'],true))json_response(['ok'=>false,'error'=>'Invalid PIP status'],422);$pdo=db();$stmt=$pdo->prepare("SELECT id FROM pips WHERE id=? AND manager_id=?");$stmt->execute([$id,$manager['id']]);if(!$stmt->fetch())json_response(['ok'=>false,'error'=>'PIP not found'],404);$pdo->prepare("UPDATE pips SET status=?,outcome_note=? WHERE id=?")->execute([$status,$note?:null,$id]);audit((int)$manager['id'],'UPDATE_PIP_STATUS','pip',$id,'Updated PIP status');json_response(['ok'=>true]);

        case 'my_permissions':
            $user = require_login();
            json_response(['ok'=>true,'role'=>$user['role'],'permissions'=>permissions_for_role($user['role'])]);

        default: json_response(['ok'=>false,'error'=>'Unknown action'],404);
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    json_response(['ok'=>false,'error'=>$e->getMessage()],500);
}
