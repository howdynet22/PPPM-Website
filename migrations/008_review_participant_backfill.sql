-- Backfill any missing employee/manager participants in active review cycles.
-- This is safe to run after 006/007: INSERT IGNORE preserves existing rows.
-- Released/closed cycles are historical and intentionally remain unchanged.

-- Managers need Personal workspace access because they are review participants
-- as well as reviewers of their own direct reports.
INSERT IGNORE INTO role_permissions (role_code, permission_id)
SELECT 'manager', p.id
FROM permissions p
WHERE p.permission_code = 'employee.dashboard';

-- Enrol every active employee or manager who has a current primary manager.
INSERT IGNORE INTO review_participants (cycle_id, employee_id, manager_id, status)
SELECT rc.id,
       u.id,
       apr.reports_to_employee_id,
       'not_started'
FROM review_cycles rc
JOIN users u
  ON u.is_active = 1
 AND u.role IN ('employee', 'manager')
JOIN active_primary_relationships apr
  ON apr.employee_id = u.id
WHERE rc.status IN ('open', 'peer_review');

-- Every participant needs their own self-review request for the Personal
-- dashboard. Existing requests are left untouched.
INSERT IGNORE INTO feedback_requests (participant_id, respondent_id, type, status)
SELECT rp.id,
       rp.employee_id,
       'self',
       'pending'
FROM review_participants rp
JOIN users u ON u.id = rp.employee_id
JOIN review_cycles rc ON rc.id = rp.cycle_id
WHERE u.is_active = 1
  AND u.role IN ('employee', 'manager')
  AND rc.status IN ('open', 'peer_review');
