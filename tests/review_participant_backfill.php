<?php
declare(strict_types=1);
require __DIR__.'/../config.php';
if(PHP_SAPI!=='cli'||!str_contains(DB_NAME,'test'))throw new RuntimeException('Run only against an isolated test database.');
function backfill_check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$pdo=db();
$cycle=(int)$pdo->query("SELECT id FROM review_cycles WHERE name='Demo development check-in' AND status='open'")->fetchColumn();
$devon=(int)$pdo->query("SELECT id FROM users WHERE email='devon@demo.pppm.test'")->fetchColumn();
$stmt=$pdo->prepare('SELECT id FROM review_participants WHERE cycle_id=? AND employee_id=?');$stmt->execute([$cycle,$devon]);$participant=(int)$stmt->fetchColumn();
backfill_check($participant>0,'Devon fixture participant missing before compatibility test');
$pdo->prepare('DELETE FROM feedback_requests WHERE participant_id=?')->execute([$participant]);
$pdo->prepare('DELETE FROM review_participants WHERE id=?')->execute([$participant]);
$sql=file_get_contents(__DIR__.'/../migrations/012_review_participant_backfill.sql');
foreach(array_filter(array_map('trim',preg_split('/;\s*(?:\r?\n|$)/',$sql))) as $statement)$pdo->exec($statement);
$stmt=$pdo->prepare('SELECT id FROM review_participants WHERE cycle_id=? AND employee_id=?');$stmt->execute([$cycle,$devon]);$participant=(int)$stmt->fetchColumn();
backfill_check($participant>0,'Compatibility migration did not enrol the review-eligible administrator');
$stmt=$pdo->prepare("SELECT COUNT(*) FROM feedback_requests WHERE participant_id=? AND respondent_id=? AND type='self'");$stmt->execute([$participant,$devon]);
backfill_check((int)$stmt->fetchColumn()===1,'Compatibility migration did not create Devon self-review request');
foreach(array_filter(array_map('trim',preg_split('/;\s*(?:\r?\n|$)/',$sql))) as $statement)$pdo->exec($statement);
$stmt=$pdo->prepare('SELECT COUNT(*) FROM review_participants WHERE cycle_id=? AND employee_id=?');$stmt->execute([$cycle,$devon]);
backfill_check((int)$stmt->fetchColumn()===1,'Compatibility migration duplicated Devon participant');
echo "PASS: upgraded open cycles backfill elevated-role employees and self-review requests idempotently.\n";
