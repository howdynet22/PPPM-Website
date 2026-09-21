-- Backfill missing employee/manager participants in active review cycles.
INSERT IGNORE INTO role_permissions (role_code, permission_id)
SELECT 'manager', p.id FROM permissions p WHERE p.permission_code = 'employee.dashboard';

INSERT IGNORE INTO review_participants (cycle_id, employee_id, manager_id, status)
SELECT rc.id, u.id, apr.reports_to_employee_id, 'not_started'
FROM review_cycles rc
JOIN users u ON u.is_active = 1 AND u.role IN ('employee', 'manager')
JOIN active_primary_relationships apr ON apr.employee_id = u.id
WHERE rc.status IN ('open', 'peer_review');

INSERT IGNORE INTO feedback_requests (participant_id, respondent_id, type, status)
SELECT rp.id, rp.employee_id, 'self', 'pending'
FROM review_participants rp
JOIN users u ON u.id = rp.employee_id
JOIN review_cycles rc ON rc.id = rp.cycle_id
WHERE u.is_active = 1
  AND u.role IN ('employee', 'manager')
  AND rc.status IN ('open', 'peer_review');
