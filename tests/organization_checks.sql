-- Read-only checks for a freshly imported demo database.
-- Run in the selected database.

-- Every employee has at most one current primary manager.
SELECT employee_id, COUNT(*) AS active_primary_count
FROM active_primary_relationships
GROUP BY employee_id
HAVING COUNT(*) > 1;

-- All organization foreign keys resolve and team membership matches department.
SELECT u.id AS invalid_user_id
FROM users u
LEFT JOIN departments d ON d.id=u.department_id
LEFT JOIN teams t ON t.id=u.team_id
WHERE d.id IS NULL
   OR (u.team_id IS NOT NULL AND (t.id IS NULL OR t.department_id<>u.department_id));

SELECT rr.id AS invalid_relationship_id
FROM reporting_relationships rr
LEFT JOIN users employee ON employee.id=rr.employee_id
LEFT JOIN users manager ON manager.id=rr.reports_to_employee_id
LEFT JOIN users creator ON creator.id=rr.created_by
WHERE employee.id IS NULL OR manager.id IS NULL OR creator.id IS NULL;

-- No seeded demo account may have a missing manager except leadership.
SELECT u.id FROM demo_users du JOIN users u ON u.id=du.user_id
LEFT JOIN active_primary_relationships ar ON ar.employee_id=u.id
WHERE u.role<>'leadership' AND ar.employee_id IS NULL;
