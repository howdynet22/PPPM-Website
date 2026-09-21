-- Managers participate in performance-review cycles through their Personal
-- workspace while also reviewing direct reports through the Manager workspace.
-- Leadership receives review-only Manager workspace access so the highest
-- manager in the reporting chain can still receive a manager review.

-- Defensive permission grants for databases upgraded from older branches.
INSERT IGNORE INTO role_permissions (role_code, permission_id)
SELECT 'manager', p.id
FROM permissions p
WHERE p.permission_code = 'employee.dashboard';

INSERT IGNORE INTO role_permissions (role_code, permission_id)
SELECT 'leadership', p.id
FROM permissions p
WHERE p.permission_code IN ('manager.dashboard', 'manager.reviews');

-- Add manager participants to review cycles that are still early enough for
-- them to complete the self/peer stages. Released/closed cycles are historical
-- and are intentionally left unchanged.
INSERT IGNORE INTO review_participants (cycle_id, employee_id, manager_id, status)
SELECT rc.id,
       u.id,
       apr.reports_to_employee_id,
       'not_started'
FROM review_cycles rc
JOIN users u
  ON u.is_active = 1
 AND u.role = 'manager'
JOIN active_primary_relationships apr
  ON apr.employee_id = u.id
WHERE rc.status IN ('open', 'peer_review');

-- Every participant needs a self-review request or their Personal workspace has
-- no form to complete. INSERT IGNORE keeps existing requests untouched.
INSERT IGNORE INTO feedback_requests (participant_id, respondent_id, type, status)
SELECT rp.id,
       rp.employee_id,
       'self',
       'pending'
FROM review_participants rp
JOIN users u ON u.id = rp.employee_id
JOIN review_cycles rc ON rc.id = rp.cycle_id
WHERE u.role = 'manager'
  AND rc.status IN ('open', 'peer_review');
