-- Repair open cycles created before review eligibility was separated from role.
-- Higher-access users remain organizational employees when they are active,
-- review eligible, have Personal workspace access, and have an active manager.
INSERT INTO review_participants
  (cycle_id,employee_id,manager_id,action_manager_id,status)
SELECT rc.id,u.id,apr.reports_to_employee_id,apr.reports_to_employee_id,'not_started'
FROM review_cycles rc
JOIN users u ON u.is_active=1 AND u.review_eligible=1
JOIN active_primary_relationships apr ON apr.employee_id=u.id
JOIN users manager ON manager.id=apr.reports_to_employee_id AND manager.is_active=1
WHERE rc.status='open'
  AND EXISTS (
    SELECT 1 FROM role_permissions rp
    JOIN permissions p ON p.id=rp.permission_id
    WHERE rp.role_code=u.role AND p.permission_code='employee.dashboard' AND p.is_active=1
  )
  AND NOT EXISTS (
    SELECT 1 FROM review_participants existing
    WHERE existing.cycle_id=rc.id AND existing.employee_id=u.id
  );

INSERT INTO feedback_requests
  (participant_id,respondent_id,type,status,response_deadline)
SELECT rp.id,rp.employee_id,'self','pending',rc.self_deadline
FROM review_participants rp
JOIN review_cycles rc ON rc.id=rp.cycle_id
WHERE rc.status='open'
  AND NOT EXISTS (
    SELECT 1 FROM feedback_requests fr
    WHERE fr.participant_id=rp.id AND fr.respondent_id=rp.employee_id AND fr.type='self'
  );
