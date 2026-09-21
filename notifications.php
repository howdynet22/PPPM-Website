<?php
declare(strict_types=1);

/** Persistent, user-owned notifications. Callers remain responsible for
 * authorising the business action that caused a notification. */
function create_notification(
    int $userId,
    string $type,
    string $title,
    string $message,
    ?string $entityType = null,
    ?int $entityId = null,
    ?string $actionUrl = null,
    ?string $dedupeKey = null,
): int {
    $stmt = db()->prepare(
        "INSERT INTO notifications
           (user_id,notification_type,title,message,entity_type,entity_id,action_url,dedupe_key)
         VALUES(?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",
    );
    $stmt->execute([$userId,$type,$title,$message,$entityType,$entityId,$actionUrl,$dedupeKey]);
    return (int) db()->lastInsertId();
}

function create_bulk_notifications(
    array $userIds,
    string $type,
    string $title,
    string $message,
    ?string $entityType = null,
    ?int $entityId = null,
    ?string $actionUrl = null,
    ?string $dedupeKey = null,
): array {
    $ids=[];
    foreach (array_values(array_unique(array_map('intval',$userIds))) as $userId) {
        if ($userId > 0) $ids[]=create_notification($userId,$type,$title,$message,$entityType,$entityId,$actionUrl,$dedupeKey);
    }
    return $ids;
}

function get_notifications(int $userId, bool $unreadOnly=false, int $limit=50, int $offset=0): array
{
    $limit=max(1,min(100,$limit));
    $offset=max(0,$offset);
    $sql="SELECT id,notification_type,title,message,entity_type,entity_id,action_url,is_read,created_at,read_at
          FROM notifications WHERE user_id=?".($unreadOnly?' AND is_read=0':'').
         " ORDER BY created_at DESC,id DESC LIMIT $limit OFFSET $offset";
    $stmt=db()->prepare($sql);$stmt->execute([$userId]);
    return array_map(static function(array $row): array {
        $row['id']=(int)$row['id'];
        $row['entity_id']=$row['entity_id']===null?null:(int)$row['entity_id'];
        $row['is_read']=(bool)$row['is_read'];
        $row['unread']=!$row['is_read'];
        // Backwards-compatible fields used by the Manager dashboard.
        $row['text']=$row['message'];
        $row['time']=$row['created_at'];
        return $row;
    },$stmt->fetchAll());
}

function get_unread_notification_count(int $userId): int
{
    $stmt=db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0');
    $stmt->execute([$userId]);return (int)$stmt->fetchColumn();
}

function mark_notifications_read(int $userId, array $ids): int
{
    $ids=array_slice(array_values(array_unique(array_filter(array_map('intval',$ids),fn($id)=>$id>0))),0,100);
    if(!$ids)return 0;
    $marks=implode(',',array_fill(0,count($ids),'?'));
    $stmt=db()->prepare("UPDATE notifications SET is_read=1,read_at=COALESCE(read_at,NOW()) WHERE user_id=? AND id IN ($marks) AND is_read=0");
    $stmt->execute(array_merge([$userId],$ids));
    return $stmt->rowCount();
}

function mark_all_notifications_read(int $userId): int
{
    $stmt=db()->prepare('UPDATE notifications SET is_read=1,read_at=COALESCE(read_at,NOW()) WHERE user_id=? AND is_read=0');
    $stmt->execute([$userId]);return $stmt->rowCount();
}

function was_reminder_sent(int $userId,string $type,string $entityType,int $entityId,string $date): bool
{
    $stmt=db()->prepare('SELECT 1 FROM reminder_history WHERE user_id=? AND reminder_type=? AND entity_type=? AND entity_id=? AND reminder_date=?');
    $stmt->execute([$userId,$type,$entityType,$entityId,$date]);return (bool)$stmt->fetchColumn();
}

function record_reminder_sent(int $userId,string $type,string $entityType,int $entityId,string $date): bool
{
    $stmt=db()->prepare('INSERT IGNORE INTO reminder_history(user_id,reminder_type,entity_type,entity_id,reminder_date) VALUES(?,?,?,?,?)');
    $stmt->execute([$userId,$type,$entityType,$entityId,$date]);return $stmt->rowCount()===1;
}

function record_cycle_transition(int $cycleId,string $from,string $to,int $actorId,?string $notes=null): int
{
    $stmt=db()->prepare('INSERT INTO review_cycle_transitions(cycle_id,from_status,to_status,transitioned_by,notes) VALUES(?,?,?,?,?)');
    $stmt->execute([$cycleId,$from,$to,$actorId,$notes]);return (int)db()->lastInsertId();
}

function notification_recipients_for_permission(string $permission): array
{
    $stmt=db()->prepare("SELECT DISTINCT u.id FROM users u JOIN role_permissions rp ON rp.role_code=u.role JOIN permissions p ON p.id=rp.permission_id WHERE u.is_active=1 AND p.is_active=1 AND p.permission_code=?");
    $stmt->execute([$permission]);return array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));
}
