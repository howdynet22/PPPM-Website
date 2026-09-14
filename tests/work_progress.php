<?php
declare(strict_types=1);
require __DIR__.'/../work-steps.php';
function expect(bool $result,string $message): void {if(!$result)throw new RuntimeException($message);}
$empty=work_progress([],'2000-01-01');
expect($empty['percent']===0 && $empty['status']==='not_started','Empty goals must not complete');
$blocked=work_progress([['status'=>'blocked'],['status'=>'completed']],'2000-01-01');
expect($blocked['percent']===50 && $blocked['status']==='in_progress' && $blocked['blocked'] && $blocked['overdue'],'Health must be separate from completion');
$done=work_progress([['status'=>'completed'],['status'=>'completed']],'2000-01-01');
expect($done['status']==='completed' && !$done['overdue'] && !$done['blocked'],'Completed past-due goals must not be overdue');
$started=work_progress([['status'=>'in_progress']]);
expect($started['status']==='in_progress' && $started['percent']===0,'Starting work does not count as completion');
echo "PASS: empty goals, blocked/overdue health, completed goals and zero-completion in-progress work.\n";
