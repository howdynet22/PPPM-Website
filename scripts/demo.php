<?php
declare(strict_types=1);
// CLI-only fixture. No production data, fixed person IDs, or global DELETEs.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../config.php';
require __DIR__.'/../work-steps.php';

function demo_insert(string $table, array $values): int
{
    $columns=implode(',',array_keys($values));
    $placeholders=implode(',',array_fill(0,count($values),'?'));
    db()->prepare("INSERT INTO $table ($columns) VALUES ($placeholders)")->execute(array_values($values));
    $id=(int)db()->lastInsertId();
    db()->prepare('INSERT INTO demo_records(table_name,record_id) VALUES(?,?)')->execute([$table,$id]);
    return $id;
}
function demo_ids(string $table): array
{
    $s=db()->prepare('SELECT record_id FROM demo_records WHERE table_name=?'); $s->execute([$table]);
    return array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));
}
function demo_delete(string $table, string $column, array $ids): void
{
    if(!$ids)return;
    $marks=implode(',',array_fill(0,count($ids),'?'));
    db()->prepare("DELETE FROM $table WHERE $column IN ($marks)")->execute($ids);
}
function demo_child_ids(string $table, string $column, array $ids): array
{
    if(!$ids)return [];
    $marks=implode(',',array_fill(0,count($ids),'?'));
    $s=db()->prepare("SELECT id FROM $table WHERE $column IN ($marks)");$s->execute($ids);
    return array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));
}
function demo_reset(): void
{
    $users=demo_ids('users');
    if(!$users)return;
    // Personal records created while trying the demo also belong to these fixture accounts.
    $participants=demo_child_ids('review_participants','employee_id',$users);
    $requests=demo_child_ids('feedback_requests','participant_id',$participants);
    $nominations=demo_child_ids('peer_nominations','participant_id',$participants);
    $plans=demo_child_ids('pdps','employee_id',$users);
    $actions=demo_child_ids('pdp_actions','pdp_id',$plans);
    $pips=demo_child_ids('pips','employee_id',$users);
    $goals=demo_child_ids('goals','employee_id',$users);
    demo_delete('feedback_summary','request_id',$requests);
    demo_delete('feedback_ratings','request_id',$requests);
    demo_delete('feedback_requests','id',$requests);
    demo_delete('peer_nomination_escalations','nomination_id',$nominations);
    demo_delete('peer_nominations','participant_id',$participants);
    demo_delete('action_updates','action_id',$actions);
    demo_delete('pdp_actions','id',$actions);
    demo_delete('pdps','id',$plans);
    demo_delete('pip_checkins','pip_id',$pips);
    demo_delete('pip_objectives','pip_id',$pips);
    demo_delete('pips','id',$pips);
    demo_delete('goals','id',$goals);
    demo_delete('review_participants','id',$participants);
    demo_delete('employee_skills','employee_id',$users);
    demo_delete('notification_reads','user_id',$users);
    demo_delete('audit_log','user_id',$users);
    // Only fixture relationships are removed. A non-demo dependency causes a safe rollback.
    foreach(['reporting_relationships','role_skill_requirements','review_cycles','competencies','skills'] as $table) demo_delete($table,'id',demo_ids($table));
    foreach(['departments'=>'head_employee_id','teams'=>'team_lead_employee_id'] as $table=>$column) {
        $ids=demo_ids($table);if(!$ids)continue;
        $marks=implode(',',array_fill(0,count($ids),'?'));
        db()->prepare("UPDATE $table SET $column=NULL WHERE id IN ($marks)")->execute($ids);
    }
    $marks=implode(',',array_fill(0,count($users),'?'));
    db()->prepare("DELETE FROM login_attempts WHERE email IN (SELECT email FROM users WHERE id IN ($marks))")->execute($users);
    demo_delete('demo_users','user_id',$users);
    demo_delete('users','id',$users);
    demo_delete('teams','id',demo_ids('teams'));
    demo_delete('departments','id',demo_ids('departments'));
    db()->exec('DELETE FROM demo_records'); // This table contains fixture registrations only.
}
function demo_steps(string $type,int $workId,int $owner,array $titles,array $states=[]): void
{
    foreach($titles as $index=>$title){
        $state=$states[$index]??'not_started';$completed=$state==='completed';
        demo_insert('work_steps',[work_step_column($type)=>$workId,'title'=>$title,'step_order'=>$index+1,'created_by'=>$owner,'status'=>$state,'is_completed'=>$completed?1:0,'completed_by'=>$completed?$owner:null,'completed_at'=>$completed?date('Y-m-d H:i:s'):null,'progress_note'=>$state==='blocked'?'Waiting for a practice session with the facilitator.':null]);
    }
    sync_work_item_status(db(),work_item_context(db(),$type,$workId));
}
function seed_demo(): void
{
    $day=fn(string $offset)=>date('Y-m-d',strtotime($offset));
    $exec=demo_insert('departments',['department_code'=>'DEMO-EXEC','department_name'=>'Demo Executive']);
    $ops=demo_insert('departments',['department_code'=>'DEMO-OPS','department_name'=>'Demo Product & Operations']);
    $people=demo_insert('departments',['department_code'=>'DEMO-PEOPLE','department_name'=>'Demo People']);
    $team=demo_insert('teams',['department_id'=>$ops,'team_code'=>'DEMO-DELIVERY','team_name'=>'Delivery']);
    $hrteam=demo_insert('teams',['department_id'=>$people,'team_code'=>'DEMO-HR','team_name'=>'People Operations']);
    $entries=[
        ['avery','Avery Lane','leadership','Chief Executive Officer',$exec,null,null],
        ['morgan','Morgan Reed','manager','Operations Director',$ops,null,'avery'],
        ['jordan','Jordan Ellis','manager','Department Manager',$ops,null,'morgan'],
        ['casey','Casey Brooks','manager','Team Lead',$ops,$team,'jordan'],
        ['riley','Riley Hart','hr','Head of People',$people,$hrteam,'avery'],
        ['taylor','Taylor Quinn','hr_partner','HR Business Partner',$people,$hrteam,'riley'],
        ['sam','Sam Rowan','hr_coordinator','HR Coordinator',$people,$hrteam,'taylor'],
        ['alex','Alex Morgan','employee','Product Specialist',$ops,$team,'casey'],
        ['jamie','Jamie Vale','employee','Operations Analyst',$ops,$team,'casey'],
        ['drew','Drew Parker','employee','Support Specialist',$ops,$team,'casey'],
        ['blair','Blair Hayes','employee','Project Coordinator',$ops,null,'jordan'],
        ['quinn','Quinn River','employee','Business Analyst',$ops,null,'morgan'],
    ];
    $users=[]; $hash=password_hash('password123',PASSWORD_DEFAULT);
    foreach($entries as [$key,$name,$role,$job,$department,$teamId,$manager]) {
        $users[$key]=demo_insert('users',['emp_code'=>'DEMO-'.strtoupper($key),'full_name'=>$name,'email'=>$key.'@demo.pppm.test','password_hash'=>$hash,'role'=>$role,'job_title'=>$job,'department_id'=>$department,'team_id'=>$teamId,'date_joined'=>$day('-2 years')]);
        db()->prepare("INSERT INTO demo_users(user_id,fixture_key) VALUES(?,'focused-demo-v1')")->execute([$users[$key]]);
    }
    foreach($entries as [$key,$name,$role,$job,$department,$teamId,$manager]) if($manager) demo_insert('reporting_relationships',['employee_id'=>$users[$key],'reports_to_employee_id'=>$users[$manager],'effective_from'=>$day('-1 year'),'created_by'=>$users['riley'],'change_reason'=>'Fictional demo reporting relationship']);
    db()->prepare('UPDATE departments SET head_employee_id=? WHERE id=?')->execute([$users['morgan'],$ops]);
    db()->prepare('UPDATE departments SET head_employee_id=? WHERE id=?')->execute([$users['riley'],$people]);
    db()->prepare('UPDATE teams SET team_lead_employee_id=? WHERE id=?')->execute([$users['casey'],$team]);
    $competencies=[];
    foreach(['Communication','Collaboration','Delivery'] as $name) $competencies[]=demo_insert('competencies',['name'=>$name,'description'=>'Fictional demo review competency']);
    $skill=demo_insert('skills',['name'=>'Structured communication','category'=>'Demo development']);
    demo_insert('role_skill_requirements',['job_title'=>'Product Specialist','skill_id'=>$skill,'required_level'=>4]);
    demo_insert('employee_skills',['employee_id'=>$users['alex'],'skill_id'=>$skill,'current_level'=>3,'assessed_by'=>$users['casey'],'assessed_at'=>$day('-7 days')]);
    foreach($entries as [$key,$name,$role,$job,$department,$teamId,$manager]) {
        if(!$manager)continue;
        // HR staff also have personal plans; self-owned until explicitly assigned by a manager.
        $owner=in_array($key,['taylor','sam'],true)?$users[$key]:$users[$manager];
        $pdp=demo_insert('pdps',['employee_id'=>$users[$key],'manager_id'=>$owner,'summary'=>$key==='alex'?'Communicate with confidence':'A focused development goal','status'=>'agreed','agreed_at'=>date('Y-m-d H:i:s')]);
        $action=demo_insert('pdp_actions',['pdp_id'=>$pdp,'title'=>$key==='alex'?'Present a clear project update':'Practice a useful skill for my role','description'=>'Prepare a short example, practice it, and record what improved.','due_date'=>$day($key==='alex'?'-2 days':'+30 days')]);
        demo_steps('pdp_action',$action,$owner,$key==='alex'?['Choose a project update','Draft the key message','Practice with a colleague','Present and record feedback']:['Choose a practical example','Practice and reflect'],$key==='alex'?['completed','in_progress','blocked','not_started']:($key==='morgan'?['completed','in_progress']:[]));
    }
    $goal=demo_insert('goals',['employee_id'=>$users['alex'],'manager_id'=>$users['casey'],'title'=>'Publish the onboarding guide','description'=>'A short, usable guide for the next new starter.','due_date'=>$day('-5 days')]);
    demo_steps('goal',$goal,$users['casey'],['Draft the guide','Review and publish'],['completed','completed']);
    $goal=demo_insert('goals',['employee_id'=>$users['jamie'],'manager_id'=>$users['casey'],'title'=>'Improve the weekly handover','description'=>'Make ownership and next steps clear.','due_date'=>$day('+21 days')]);
    demo_steps('goal',$goal,$users['casey'],['Identify missing information','Try the revised handover'],['in_progress','not_started']);
    $pip=demo_insert('pips',['employee_id'=>$users['drew'],'manager_id'=>$users['casey'],'hr_owner_id'=>$users['taylor'],'reason'=>'Improve follow-through on agreed support actions','start_date'=>$day('-7 days'),'end_date'=>$day('+35 days'),'status'=>'active']);
    $objective=demo_insert('pip_objectives',['pip_id'=>$pip,'objective'=>'Keep support handovers current','success_criteria'=>'Each open handover has an owner and a next action.','due_date'=>$day('+28 days')]);
    demo_steps('pip_objective',$objective,$users['casey'],['Agree the handover checklist','Use the checklist for two weeks'],['completed','in_progress']);
    demo_insert('pip_checkins',['pip_id'=>$pip,'checkin_date'=>$day('-1 day'),'author_id'=>$users['casey'],'notes'=>'Checklist agreed. Review examples together next week.']);
    $cycle=demo_insert('review_cycles',['name'=>'Demo development check-in','period_start'=>$day('-30 days'),'period_end'=>$day('+30 days'),'self_deadline'=>$day('+10 days'),'peer_deadline'=>$day('+14 days'),'manager_deadline'=>$day('+21 days'),'status'=>'open','created_by'=>$users['riley']]);
    foreach(['alex','casey'] as $key){
        $manager=$key==='alex'?'casey':'jordan';
        $participant=demo_insert('review_participants',['cycle_id'=>$cycle,'employee_id'=>$users[$key],'manager_id'=>$users[$manager]]);
        demo_insert('feedback_requests',['participant_id'=>$participant,'respondent_id'=>$users[$key],'type'=>'self']);
        if($key==='alex') {
            demo_insert('peer_nominations',[
                'participant_id'=>$participant,'peer_id'=>$users['jamie'],
                'shared_work'=>'Customer onboarding guide',
                'collaboration_details'=>'Jamie reviewed the onboarding workflow, tested the handover steps and helped refine the final guide.',
                'reviewer_justification'=>'Jamie directly observed my communication, collaboration and delivery quality throughout the shared work.',
                'status'=>'approved','nominated_by'=>$users['alex'],'decided_by'=>$users['casey'],'decided_at'=>date('Y-m-d H:i:s'),
            ]);
            demo_insert('feedback_requests',['participant_id'=>$participant,'respondent_id'=>$users['jamie'],'type'=>'peer']);
            demo_insert('peer_nominations',[
                'participant_id'=>$participant,'peer_id'=>$users['drew'],
                'shared_work'=>'Support handover trial',
                'collaboration_details'=>'Drew and I compared support handovers and tested how the revised guide worked during two shared cases.',
                'reviewer_justification'=>'Drew directly observed how I explained the process and responded to feedback during the trial.',
                'status'=>'rejected','nominated_by'=>$users['alex'],'decided_by'=>$users['casey'],
                'decision_reason'=>'The shared trial was too short to provide enough evidence for the full review period.',
                'decided_at'=>date('Y-m-d H:i:s'),
            ]);
        }
    }
    $cycle=demo_insert('review_cycles',['name'=>'Previous Performance Review','period_start'=>$day('-180 days'),'period_end'=>$day('-90 days'),'status'=>'released','created_by'=>$users['riley'],'released_at'=>date('Y-m-d H:i:s')]);
    $participant=demo_insert('review_participants',['cycle_id'=>$cycle,'employee_id'=>$users['morgan'],'manager_id'=>$users['avery'],'status'=>'released','final_rating'=>4,'manager_summary'=>'Clear priorities and thoughtful follow-through.','released_at'=>date('Y-m-d H:i:s')]);
    foreach(['jordan','riley','quinn'] as $peer){
        $request=demo_insert('feedback_requests',['participant_id'=>$participant,'respondent_id'=>$users[$peer],'type'=>'peer','status'=>'submitted','submitted_at'=>date('Y-m-d H:i:s')]);
        foreach($competencies as $competency)demo_insert('feedback_ratings',['request_id'=>$request,'competency_id'=>$competency,'score'=>4]);
    }
}

try {
    $mode=$argv[1]??'seed';
    if(!in_array($mode,['seed','reset'],true))throw new RuntimeException('Usage: php scripts/demo.php seed|reset');
    $pdo=db();
    $pdo->beginTransaction();
    // Reuse the existing organization lock so simultaneous seed runs cannot duplicate fixtures.
    $pdo->query('SELECT id FROM organization_lock WHERE id=1 FOR UPDATE')->fetch();
    $count=(int)$pdo->query('SELECT COUNT(*) FROM demo_users')->fetchColumn();
    if($count && $mode==='seed'){ $pdo->rollBack(); echo "Demo already exists; no changes made. Use reset to recreate demo-owned records.\n";exit; }
    if(!$count && (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()>0)throw new RuntimeException('This database has unregistered users. Use a separate empty demo database; existing company or legacy data is preserved.');
    if($mode==='reset')demo_reset();
    seed_demo();
    $pdo->commit();echo "Created 12 fictional people: 1 CEO, 3 managers, 3 HR staff and 5 employees. Password: password123\n";
} catch(Throwable $error){
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    fwrite(STDERR,"Demo unchanged: ".$error->getMessage()."\n");exit(1);
}
