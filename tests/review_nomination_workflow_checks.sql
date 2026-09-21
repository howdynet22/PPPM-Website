-- Expected result: every SELECT returns zero rows.

-- Active review participants should never count rejected nominations as assigned
-- peers unless HR overturned the rejection and created a real peer request.
SELECT rp.id, 'assigned peer count below cycle requirement' AS problem
FROM review_participants rp
JOIN review_cycles rc ON rc.id=rp.cycle_id
WHERE rc.status IN ('peer_review','manager_review')
  AND (SELECT COUNT(*) FROM feedback_requests fr
       WHERE fr.participant_id=rp.id AND fr.type='peer') < rc.min_peers;

-- Replacement suggestions must point to somebody other than the participant or
-- their formal manager.
SELECT pn.id, 'invalid manager replacement suggestion' AS problem
FROM peer_nominations pn
JOIN review_participants rp ON rp.id=pn.participant_id
WHERE pn.suggested_peer_id IS NOT NULL
  AND pn.suggested_peer_id IN (rp.employee_id,rp.manager_id);

-- Suggestions are meaningful only on rejected nominations.
SELECT pn.id, 'replacement suggestion attached to non-rejected nomination' AS problem
FROM peer_nominations pn
WHERE pn.suggested_peer_id IS NOT NULL AND pn.status <> 'rejected';
