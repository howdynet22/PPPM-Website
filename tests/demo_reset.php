<?php
declare(strict_types=1);
require __DIR__.'/../config.php';
if(PHP_SAPI!=='cli' || !str_contains(DB_NAME,'test'))throw new RuntimeException('Run only against an isolated test database.');
function check(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
$pdo=db();
$pdo->prepare("INSERT INTO departments(department_code,department_name) VALUES('TEST-KEEP','Unrelated test department')")->execute();
$department=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users(emp_code,full_name,email,password_hash,role,department_id) VALUES('TEST-KEEP','Unrelated account','unrelated@example.test',?,'employee',?)")->execute([password_hash('NotDemo123!',PASSWORD_DEFAULT),$department]);
$user=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO goals(employee_id,manager_id,title,description,due_date) VALUES(?,?,'Preserve me','Not a demo record','2099-01-01')")->execute([$user,$user]);
$goal=(int)$pdo->lastInsertId();
$script=__DIR__.'/../scripts/demo.php';
passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' reset',$code);
check($code===0,'Reset failed');
check((int)$pdo->query('SELECT COUNT(*) FROM demo_users')->fetchColumn()===12,'Demo count');
check((int)$pdo->query("SELECT COUNT(*) FROM goals WHERE id=$goal")->fetchColumn()===1,'Unrelated goal removed');
check((int)$pdo->query("SELECT COUNT(*) FROM users WHERE id=$user")->fetchColumn()===1,'Unrelated user removed');
$before=$pdo->query('SELECT GROUP_CONCAT(user_id ORDER BY user_id) FROM demo_users')->fetchColumn();
passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' seed',$code);
check($code===0 && $before===$pdo->query('SELECT GROUP_CONCAT(user_id ORDER BY user_id) FROM demo_users')->fetchColumn(),'Seed is not idempotent');
$pdo->prepare('DELETE FROM goals WHERE id=?')->execute([$goal]);
$pdo->prepare('DELETE FROM users WHERE id=?')->execute([$user]);
$pdo->prepare('DELETE FROM departments WHERE id=?')->execute([$department]);
check((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===12,'Unexpected users after reset');
check((int)$pdo->query('SELECT COUNT(*) FROM pdps')->fetchColumn()===11,'Each non-CEO should have a personal plan');
check((int)$pdo->query("SELECT COUNT(*) FROM peer_nominations pn JOIN review_participants rp ON rp.id=pn.participant_id JOIN users u ON u.id=rp.employee_id WHERE u.email='alex@demo.pppm.test'")->fetchColumn()===2,'Expected nomination decision examples');
check((int)$pdo->query("SELECT COUNT(*) FROM peer_nomination_escalations pne JOIN peer_nominations pn ON pn.id=pne.nomination_id JOIN review_participants rp ON rp.id=pn.participant_id JOIN users u ON u.id=rp.employee_id WHERE u.email='alex@demo.pppm.test'")->fetchColumn()===0,'Demo reset should not seed an HR escalation for Alex');
echo "PASS: reset removes only demo-owned records, preserves unrelated data, restores nomination examples, and seed is idempotent.\n";
