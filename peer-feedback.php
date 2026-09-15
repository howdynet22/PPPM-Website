<?php
declare(strict_types=1);

function peer_window_open(array $review): bool
{
    return in_array($review['cycle_status'], ['open', 'peer_review'], true)
        && !in_array($review['status'], ['manager_submitted', 'released'], true)
        && (!$review['peer_deadline'] || $review['peer_deadline'] >= date('Y-m-d'));
}

function personal_peer_nominations(int $viewer): array
{
    return workspace_rows('SELECT pn.id,pn.participant_id,pn.status,u.full_name peer
        FROM peer_nominations pn JOIN review_participants rp ON rp.id=pn.participant_id
        JOIN users u ON u.id=pn.peer_id WHERE rp.employee_id=? ORDER BY pn.id', [$viewer]);
}

function peer_feedback_api(string $action): never
{
    $user = require_permission('employee.dashboard');
    $write = $action === 'nominate_peer';
    require_method($write ? 'POST' : 'GET');
    if ($write) require_csrf();
    $in = $write ? input() : $_GET;
    $pdo = db();
    if ($write) $pdo->beginTransaction();
    $review = workspace_rows('SELECT rp.id,rp.employee_id,rp.manager_id,rp.status,
        rc.status cycle_status,rc.peer_deadline FROM review_participants rp
        JOIN review_cycles rc ON rc.id=rp.cycle_id WHERE rp.id=? AND rp.employee_id=?'
        . ($write ? ' FOR UPDATE' : ''), [(int)($in['participantId'] ?? 0), (int)$user['id']])[0] ?? null;
    if (!$review) json_response(['ok'=>false,'error'=>'Review not found'], 404);
    if (!peer_window_open($review)) json_response(['ok'=>false,'error'=>'Peer nominations are closed for this review'], 409);
    // Only minimal directory details are returned; personal performance records stay private.
    $candidates = workspace_rows("SELECT u.id,u.full_name name,u.job_title FROM users u
        JOIN roles r ON r.role_code=u.role AND r.is_active=1
        WHERE u.is_active=1 AND u.id NOT IN (?,?)
        AND EXISTS (SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id
            WHERE rp.role_code=u.role AND p.permission_code='employee.dashboard' AND p.is_active=1)
        AND NOT EXISTS (SELECT 1 FROM peer_nominations pn WHERE pn.participant_id=? AND pn.peer_id=u.id)
        ORDER BY u.full_name", [$review['employee_id'], $review['manager_id'], $review['id']]);
    if (!$write) json_response(['ok'=>true,'candidates'=>$candidates]);
    $peerId = (int)($in['peerId'] ?? 0);
    if (!in_array($peerId, array_map('intval', array_column($candidates, 'id')), true)) {
        json_response(['ok'=>false,'error'=>'Choose an active eligible colleague who has not already been nominated'], 422);
    }
    $pdo->prepare('INSERT INTO peer_nominations(participant_id,peer_id,nominated_by) VALUES(?,?,?)')
        ->execute([$review['id'],$peerId,$user['id']]);
    $id = (int)$pdo->lastInsertId();
    audit((int)$user['id'], 'NOMINATE_PEER', 'peer_nomination', $id, 'Requested manager approval');
    $pdo->commit();
    json_response(['ok'=>true,'id'=>$id]);
}

function decide_peer_nomination(array $manager, array $in): never
{
    $id = (int)($in['id'] ?? 0);
    $status = $in['status'] ?? '';
    if (!in_array($status, ['approved','rejected'], true)) json_response(['ok'=>false,'error'=>'Invalid peer decision'], 422);
    $pdo = db();
    $pdo->beginTransaction();
    $nomination = workspace_rows('SELECT pn.*,rp.employee_id,rp.status review_status,
        rc.status cycle_status,rc.peer_deadline FROM peer_nominations pn
        JOIN review_participants rp ON rp.id=pn.participant_id JOIN review_cycles rc ON rc.id=rp.cycle_id
        WHERE pn.id=? AND rp.manager_id=? FOR UPDATE', [$id,$manager['id']])[0] ?? null;
    if (!$nomination) json_response(['ok'=>false,'error'=>'Nomination not found'], 404);
    if ($nomination['status'] !== 'pending') json_response(['ok'=>false,'error'=>'This nomination already has a decision'], 409);
    if (!peer_window_open(array_merge($nomination, ['status'=>$nomination['review_status']]))) {
        json_response(['ok'=>false,'error'=>'Peer nominations are closed for this review'], 409);
    }
    if ($status === 'approved') {
        if (!has_permission((int)$nomination['peer_id'], 'employee.dashboard')) {
            json_response(['ok'=>false,'error'=>'The nominated colleague cannot submit feedback'], 422);
        }
        // Approval and the assigned form commit together; repeated decisions cannot duplicate forms.
        $pdo->prepare("INSERT INTO feedback_requests(participant_id,respondent_id,type)
            VALUES(?,?,'peer') ON DUPLICATE KEY UPDATE id=id")
            ->execute([$nomination['participant_id'],$nomination['peer_id']]);
    }
    $pdo->prepare('UPDATE peer_nominations SET status=?,decided_by=? WHERE id=?')->execute([$status,$manager['id'],$id]);
    audit((int)$manager['id'], strtoupper('PEER_'.$status), 'peer_nomination', $id, 'Manager '.$status.' peer nomination');
    $pdo->commit();
    json_response(['ok'=>true]);
}
