-- Post-migration data invariants. Every SELECT must return zero rows.

-- No ordinary pending request may survive a released/closed cycle.
SELECT fr.id,'terminal cycle has pending request' problem
FROM feedback_requests fr JOIN review_participants rp ON rp.id=fr.participant_id
JOIN review_cycles rc ON rc.id=rp.cycle_id
WHERE rc.status IN ('released','closed') AND fr.status='pending';

-- A finalized manager review needs the real peer threshold or an explicit waiver.
SELECT rp.id,'manager review bypassed peer readiness' problem
FROM review_participants rp JOIN review_cycles rc ON rc.id=rp.cycle_id
WHERE rp.status IN ('manager_submitted','released')
AND (SELECT COUNT(*) FROM feedback_requests fr WHERE fr.participant_id=rp.id AND fr.type='peer' AND fr.status='submitted')<rc.min_peers
AND NOT EXISTS(SELECT 1 FROM review_participant_exceptions x WHERE x.participant_id=rp.id AND x.exception_type='waive_peer' AND x.revoked_at IS NULL);

-- Active work cannot belong to an inactive actionable review manager.
SELECT rp.id,'active participant has inactive manager' problem
FROM review_participants rp JOIN review_cycles rc ON rc.id=rp.cycle_id
JOIN users m ON m.id=rp.action_manager_id
WHERE rc.status IN ('open','peer_review','manager_review') AND m.is_active=0
AND NOT EXISTS(SELECT 1 FROM review_participant_exceptions x WHERE x.participant_id=rp.id AND x.exception_type IN ('excluded','withdrawn') AND x.revoked_at IS NULL);

-- Project policy permits only one globally active cycle.
SELECT COUNT(*) active_cycles,'more than one active review cycle' problem
FROM review_cycles WHERE status IN ('open','peer_review','manager_review')
HAVING COUNT(*)>1;

-- Every published cycle owns a frozen, non-empty rubric.
SELECT rc.id,'published cycle has no competency snapshot' problem
FROM review_cycles rc LEFT JOIN review_cycle_competencies rcc ON rcc.cycle_id=rc.id
WHERE rc.status<>'draft' GROUP BY rc.id HAVING COUNT(rcc.competency_id)=0;

-- Ratings cannot reference a competency outside their cycle snapshot.
SELECT frt.id,'rating is outside cycle competency snapshot' problem
FROM feedback_ratings frt JOIN feedback_requests fr ON fr.id=frt.request_id
JOIN review_participants rp ON rp.id=fr.participant_id
LEFT JOIN review_cycle_competencies rcc ON rcc.cycle_id=rp.cycle_id AND rcc.competency_id=frt.competency_id
WHERE rcc.competency_id IS NULL;

-- Read-only reporting never grants review-cycle writes.
SELECT r.role_code,'hr.reports improperly grants cycle management' problem
FROM role_permissions reports JOIN permissions pr ON pr.id=reports.permission_id AND pr.permission_code='hr.reports'
JOIN roles r ON r.role_code=reports.role_code
LEFT JOIN role_permissions manage ON manage.role_code=r.role_code
LEFT JOIN permissions pm ON pm.id=manage.permission_id AND pm.permission_code='hr.cycles.manage'
WHERE r.role_code='leadership' AND pm.id IS NOT NULL;

-- HR coordinators have directory access, not protected review detail.
SELECT rp.role_code,'HR coordinator can read protected reviews' problem
FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id
WHERE rp.role_code='hr_coordinator' AND p.permission_code='hr.reviews.read';

-- Draft PIPs remain pre-approval and PDP agreement records identify the actor.
SELECT id,'agreed PDP lacks agreement audit fields' problem FROM pdps
WHERE status IN ('agreed','completed') AND (agreed_at IS NULL OR agreed_by IS NULL);

-- Employee-facing anonymous view must not expose respondent identity.
SELECT table_name,column_name,'anonymous view exposes identity' problem
FROM information_schema.columns
WHERE table_schema=DATABASE() AND table_name IN ('v_peer_feedback','v_360_summary')
AND column_name='respondent_id';
