-- Apply once after 002_actionable_work_steps.sql, in the selected database.
ALTER TABLE work_steps
  ADD COLUMN status ENUM('not_started','in_progress','blocked','completed') NOT NULL DEFAULT 'not_started',
  ADD COLUMN progress_note TEXT NULL,
  ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1;
UPDATE work_steps SET status=IF(is_completed=1,'completed','not_started');
ALTER TABLE work_steps ADD CONSTRAINT chk_step_completion
  CHECK ((status='completed') = is_completed);

INSERT IGNORE INTO permissions(permission_code,description) VALUES
 ('hr.pips','Manage explicitly assigned HR improvement plans');
INSERT IGNORE INTO role_permissions(role_code,permission_id)
 SELECT r.role_code,p.id FROM roles r CROSS JOIN permissions p
 WHERE (p.permission_code='employee.dashboard' AND r.role_code IN ('manager','hr','admin'))
    OR (p.permission_code='hr.pips' AND r.role_code IN ('hr','admin'));

-- Access levels are permission sets, independent from job titles/reporting lines.
INSERT IGNORE INTO roles(role_code,display_name,dashboard_path) VALUES
 ('hr_partner','HR Partner','hr-dashboard.html'),
 ('hr_coordinator','HR Coordinator','hr-dashboard.html');
INSERT IGNORE INTO role_permissions(role_code,permission_id)
 SELECT r.role_code,p.id FROM roles r CROSS JOIN permissions p
 WHERE (r.role_code IN ('hr_partner','hr_coordinator') AND p.permission_code IN
   ('password.change','employee.dashboard','hr.dashboard','org.structure.view'))
 OR (r.role_code='hr_partner' AND p.permission_code IN ('hr.pips','hr.reports','org.structure.view_all'));

-- Only explicitly registered demo users belong to the resettable fixture.
CREATE TABLE demo_users (
 user_id INT PRIMARY KEY,
 fixture_key VARCHAR(40) NOT NULL,
 FOREIGN KEY (user_id) REFERENCES users(id)
);
CREATE TABLE demo_records (
 table_name VARCHAR(40) NOT NULL,
 record_id INT NOT NULL,
 PRIMARY KEY (table_name,record_id)
);

-- Remove legacy health states from progress; due dates remain the source of overdue health.
UPDATE goals g LEFT JOIN (SELECT goal_id,COUNT(*) total,SUM(is_completed) completed FROM work_steps WHERE goal_id IS NOT NULL GROUP BY goal_id) s ON s.goal_id=g.id
SET g.status=CASE WHEN s.total>0 AND s.total=s.completed THEN 'completed' WHEN s.completed>0 THEN 'in_progress' ELSE 'not_started' END;
UPDATE pdp_actions a LEFT JOIN (SELECT pdp_action_id,COUNT(*) total,SUM(is_completed) completed FROM work_steps WHERE pdp_action_id IS NOT NULL GROUP BY pdp_action_id) s ON s.pdp_action_id=a.id
SET a.status=CASE WHEN s.total>0 AND s.total=s.completed THEN 'completed' WHEN s.completed>0 THEN 'in_progress' ELSE 'not_started' END,
a.completed_at=CASE WHEN s.total>0 AND s.total=s.completed THEN COALESCE(a.completed_at,NOW()) ELSE NULL END WHERE a.status<>'cancelled';
