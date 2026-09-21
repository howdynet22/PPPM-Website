<?php
declare(strict_types=1);
require __DIR__.'/../config.php';
require __DIR__.'/../reminders.php';
if(PHP_SAPI!=='cli'||!str_contains(DB_NAME,'test'))throw new RuntimeException('Run only against an isolated test database.');
function notification_check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}

$alex=(int)db()->query("SELECT id FROM users WHERE email='alex@demo.pppm.test'")->fetchColumn();
$casey=(int)db()->query("SELECT id FROM users WHERE email='casey@demo.pppm.test'")->fetchColumn();
$id=create_notification($alex,'test_notice','Test notice','A private test notification.','user',$alex,null,'test-notice');
notification_check(count(get_notifications($alex))>0,'Recipient cannot retrieve notification');
notification_check(!in_array($id,array_column(get_notifications($casey),'id'),true),'Another user can retrieve the notification');
notification_check(mark_notifications_read($casey,[$id])===0,'Another user marked the notification read');
notification_check(mark_notifications_read($alex,[$id])===1,'Recipient could not mark notification read');

$first=send_reminders(new DateTimeImmutable('today'));
$second=send_reminders(new DateTimeImmutable('today'));
notification_check($first['pdp_overdue']>=1,'Expected overdue PDP reminder was not created');
notification_check($second['pdp_overdue']===0,'Reminder deduplication failed');
notification_check((int)db()->query("SELECT COUNT(*) FROM notifications n JOIN users u ON u.id=n.user_id WHERE u.email='alex@demo.pppm.test' AND n.notification_type='pdp_overdue'")->fetchColumn()===1,'Overdue reminder was duplicated');
echo "PASS: persistent notification ownership, read state, corrected reminder SQL and daily deduplication.\n";
