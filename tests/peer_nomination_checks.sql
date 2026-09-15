-- Every query should return zero rows. Run after migration 004.

-- Nomination evidence and ownership are required.
SELECT pn.id, 'missing nomination evidence or owner' AS problem
FROM peer_nominations pn
WHERE pn.nominated_by IS NULL
   OR pn.direct_knowledge_confirmed <> 1
   OR CHAR_LENGTH(TRIM(pn.shared_work)) < 5
   OR CHAR_LENGTH(TRIM(pn.collaboration_details)) < 30
   OR CHAR_LENGTH(TRIM(pn.reviewer_justification)) < 30;

-- The nominator must be the employee whose review is being nominated for.
SELECT pn.id, 'nominator is not the review participant' AS problem
FROM peer_nominations pn
JOIN review_participants rp ON rp.id = pn.participant_id
WHERE pn.nominated_by <> rp.employee_id;

-- A participant cannot nominate themself or their assigned manager.
SELECT pn.id, 'self or assigned manager nominated as peer' AS problem
FROM peer_nominations pn
JOIN review_participants rp ON rp.id = pn.participant_id
WHERE pn.peer_id IN (rp.employee_id, rp.manager_id);

-- Decisions must identify a manager and time; rejections also require a reason.
SELECT pn.id, 'incomplete manager decision' AS problem
FROM peer_nominations pn
WHERE pn.status IN ('approved', 'rejected')
  AND (pn.decided_by IS NULL OR pn.decided_at IS NULL
       OR (pn.status = 'rejected' AND CHAR_LENGTH(TRIM(pn.decision_reason)) < 15));

-- Every approved nomination must create the peer's actual feedback request.
SELECT pn.id, 'approved nomination has no peer feedback request' AS problem
FROM peer_nominations pn
LEFT JOIN feedback_requests fr
  ON fr.participant_id = pn.participant_id
 AND fr.respondent_id = pn.peer_id
 AND fr.type = 'peer'
WHERE pn.status = 'approved' AND fr.id IS NULL;

-- Escalations belong only to the affected employee and only follow a rejection.
SELECT pne.id, 'invalid nomination escalation' AS problem
FROM peer_nomination_escalations pne
JOIN peer_nominations pn ON pn.id = pne.nomination_id
JOIN review_participants rp ON rp.id = pn.participant_id
WHERE pn.status <> 'rejected'
   OR pne.employee_id <> rp.employee_id
   OR CHAR_LENGTH(TRIM(pne.escalation_reason)) < 30;
