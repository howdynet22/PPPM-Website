-- Backfill self-review forms for review participants created by older HR-cycle code.
INSERT INTO feedback_requests (participant_id, respondent_id, type, status)
SELECT rp.id, rp.employee_id, 'self', 'pending'
FROM review_participants rp
JOIN review_cycles rc ON rc.id = rp.cycle_id
LEFT JOIN feedback_requests fr
  ON fr.participant_id = rp.id
 AND fr.respondent_id = rp.employee_id
 AND fr.type = 'self'
WHERE fr.id IS NULL
  AND rp.status = 'not_started'
  AND rc.status IN ('open', 'peer_review');
