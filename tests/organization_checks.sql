-- Read-only checks for a freshly imported demo database.
USE perf_tracker;

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

-- Nimal's seeded current path: Nimal -> Sahan -> Kavindu -> Ravi -> Leena.
SELECT employee.full_name AS employee,lead.full_name AS team_lead,
       manager.full_name AS manager,head.full_name AS department_head,
       ceo.full_name AS leadership
FROM users employee
JOIN active_primary_relationships r1 ON r1.employee_id=employee.id
JOIN users lead ON lead.id=r1.reports_to_employee_id
JOIN active_primary_relationships r2 ON r2.employee_id=lead.id
JOIN users manager ON manager.id=r2.reports_to_employee_id
JOIN active_primary_relationships r3 ON r3.employee_id=manager.id
JOIN users head ON head.id=r3.reports_to_employee_id
JOIN active_primary_relationships r4 ON r4.employee_id=head.id
JOIN users ceo ON ceo.id=r4.reports_to_employee_id
WHERE employee.id=4;

-- Historical relationship and record ownership remain present.
SELECT employee_id,reports_to_employee_id,effective_from,effective_to
FROM reporting_relationships
WHERE employee_id=4 AND relationship_type='primary'
ORDER BY effective_from;

SELECT id,cycle_id,employee_id,manager_id
FROM review_participants
WHERE employee_id=4
ORDER BY cycle_id;

-- Job title does not grant descendant permission to an employee role.
SELECT u.id,u.job_title,u.role,MAX(p.permission_code) AS descendant_permission
FROM users u
LEFT JOIN role_permissions rp ON rp.role_code=u.role
LEFT JOIN permissions p ON p.id=rp.permission_id AND p.permission_code='org.descendants.view'
WHERE u.id=4
GROUP BY u.id,u.job_title,u.role;
