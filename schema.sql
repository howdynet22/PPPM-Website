-- ============================================================
--  PERFORMANCE & DEVELOPMENT TRACKER
--  Database schema  (MySQL 8 / MariaDB — works on XAMPP)
--
--  Run this in phpMyAdmin:  Import > choose file > Go
-- ============================================================
-- Fresh installs only. This script never drops an existing database.


CREATE DATABASE IF NOT EXISTS perf_tracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;


USE perf_tracker;


-- ============================================================
-- SECTION 1: PEOPLE & ACCESS
-- ============================================================
CREATE TABLE roles (
  role_code VARCHAR(30) PRIMARY KEY,
  display_name VARCHAR(60) NOT NULL,
  dashboard_path VARCHAR(120) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
);


CREATE TABLE permissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  permission_code VARCHAR(80) NOT NULL UNIQUE,
  description VARCHAR(255),
  is_active TINYINT(1) NOT NULL DEFAULT 1
);


CREATE TABLE role_permissions (
  role_code VARCHAR(30) NOT NULL,
  permission_id INT NOT NULL,
  PRIMARY KEY (role_code, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_code) REFERENCES roles (role_code),
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions (id)
);


INSERT INTO
  roles (role_code, display_name, dashboard_path)
VALUES
  (
    'admin',
    'System Administrator',
    'admin-dashboard.html'
  ),
  ('hr', 'HR', 'hr-dashboard.html'),
  ('manager', 'Manager', 'manager-dashboard.html'),
  ('employee', 'Employee', 'employee-dashboard.html'),
  (
    'leadership',
    'Leadership',
    'admin-dashboard.html'
  );


INSERT INTO
  permissions (permission_code, description)
VALUES
  ('password.change', 'Change own password'),
  (
    'manager.dashboard',
    'View manager dashboard and team data'
  ),
  (
    'manager.reviews',
    'Manage manager reviews and peer nominations'
  ),
  ('manager.goals', 'Manage employee goals and PDPs'),
  ('manager.pips', 'Manage employee PIPs'),
  (
    'manager.reports',
    'View manager reports and insights'
  ),
  ('employee.dashboard', 'View employee dashboard'),
  ('hr.dashboard', 'View HR dashboard'),
  ('admin.dashboard', 'View administrator dashboard'),
  ('admin.users', 'Manage users and account status'),
  ('admin.roles', 'Manage roles and permissions'),
  ('hr.reports', 'View HR reports');


INSERT INTO
  role_permissions (role_code, permission_id)
SELECT
  'admin',
  id
FROM
  permissions;


INSERT INTO
  role_permissions (role_code, permission_id)
SELECT
  'hr',
  id
FROM
  permissions
WHERE
  permission_code IN ('password.change', 'hr.dashboard', 'hr.reports');


INSERT INTO
  role_permissions (role_code, permission_id)
SELECT
  'manager',
  id
FROM
  permissions
WHERE
  permission_code IN (
    'password.change',
    'employee.dashboard',
    'manager.dashboard',
    'manager.reviews',
    'manager.goals',
    'manager.pips',
    'manager.reports'
  );


INSERT INTO
  role_permissions (role_code, permission_id)
SELECT
  'employee',
  id
FROM
  permissions
WHERE
  permission_code IN ('password.change', 'employee.dashboard');


INSERT INTO
  role_permissions (role_code, permission_id)
SELECT
  'leadership',
  id
FROM
  permissions
WHERE
  permission_code IN (
    'password.change',
    'admin.dashboard',
    'hr.reports',
    'manager.dashboard',
    'manager.reviews'
  );


CREATE TABLE departments (
 id INT AUTO_INCREMENT PRIMARY KEY,
 department_code VARCHAR(30) NOT NULL UNIQUE,
 department_name VARCHAR(80) NOT NULL UNIQUE,
 head_employee_id INT NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1 CHECK (is_active IN (0,1)),
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
CREATE TABLE teams (
 id INT AUTO_INCREMENT PRIMARY KEY,
 department_id INT NOT NULL,
 team_code VARCHAR(30) NOT NULL UNIQUE,
 team_name VARCHAR(80) NOT NULL,
 team_lead_employee_id INT NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1 CHECK (is_active IN (0,1)),
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_team_department (id,department_id),
 FOREIGN KEY (department_id) REFERENCES departments(id)
);

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  emp_code VARCHAR(20) NOT NULL UNIQUE,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(120) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(30) NOT NULL DEFAULT 'employee',
  job_title VARCHAR(100),
  department_id INT NOT NULL,
  team_id INT NULL,
  date_joined DATE,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_department FOREIGN KEY (department_id) REFERENCES departments(id),
  CONSTRAINT fk_user_team_department FOREIGN KEY (team_id,department_id) REFERENCES teams(id,department_id),
  CONSTRAINT fk_user_role FOREIGN KEY (role) REFERENCES roles (role_code)
);


ALTER TABLE departments ADD CONSTRAINT fk_department_head FOREIGN KEY (head_employee_id) REFERENCES users(id);
ALTER TABLE teams ADD CONSTRAINT fk_team_lead FOREIGN KEY (team_lead_employee_id) REFERENCES users(id);
CREATE TABLE reporting_relationships (
 id INT AUTO_INCREMENT PRIMARY KEY,
 employee_id INT NOT NULL,
 reports_to_employee_id INT NOT NULL,
 relationship_type ENUM('primary','dotted_line') NOT NULL DEFAULT 'primary',
 effective_from DATE NOT NULL,
 effective_to DATE NULL,
 created_by INT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 change_reason VARCHAR(255) NULL,
 open_primary_employee_id INT GENERATED ALWAYS AS
   (CASE WHEN relationship_type='primary' AND effective_to IS NULL THEN employee_id ELSE NULL END) STORED,
 UNIQUE KEY uq_open_primary (open_primary_employee_id),
 CONSTRAINT chk_not_self CHECK (employee_id <> reports_to_employee_id),
 CONSTRAINT chk_relationship_dates CHECK (effective_to IS NULL OR effective_to > effective_from),
 FOREIGN KEY (employee_id) REFERENCES users(id),
 FOREIGN KEY (reports_to_employee_id) REFERENCES users(id),
 FOREIGN KEY (created_by) REFERENCES users(id),
 INDEX idx_rr_employee (employee_id,relationship_type,effective_from,effective_to),
 INDEX idx_rr_manager (reports_to_employee_id,relationship_type,effective_from,effective_to)
);
-- Serialize organization writes to prevent concurrent cycle/overlap races.
CREATE TABLE organization_lock (id INT PRIMARY KEY);
INSERT INTO organization_lock VALUES (1);
CREATE VIEW active_primary_relationships AS
 SELECT * FROM reporting_relationships
 WHERE relationship_type='primary' AND effective_from<=CURRENT_DATE
 AND (effective_to IS NULL OR effective_to>CURRENT_DATE);

-- Authentication throttling. Successful and failed attempts are retained so
-- repeated failures can be limited without revealing whether an account exists.
CREATE TABLE login_attempts (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(120) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_login_email_time (email, attempted_at),
  INDEX idx_login_ip_time (ip_address, attempted_at)
);


-- Computed dashboard notifications remain read when the manager refreshes.
CREATE TABLE notification_reads (
  user_id INT NOT NULL,
  notification_key VARCHAR(80) NOT NULL,
  read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, notification_key),
  CONSTRAINT fk_notification_user FOREIGN KEY (user_id) REFERENCES users (id)
);


-- Every sensitive action is written here. This is how you prove to HR/law
-- that nobody snooped. Marks are usually given for having this.
CREATE TABLE audit_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(60) NOT NULL, -- e.g. 'VIEW_REVIEW','SUBMIT_PEER','RELEASE_CYCLE'
  entity_type VARCHAR(40), -- e.g. 'review_participant'
  entity_id INT,
  detail VARCHAR(255),
  ip_address VARCHAR(45),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id)
);


-- ============================================================
-- SECTION 2: WHAT PEOPLE ARE RATED ON
-- ============================================================
CREATE TABLE competencies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  description VARCHAR(255),
  is_active TINYINT(1) DEFAULT 1
);


-- Skills are separate from competencies:
-- competency = behaviour rated in a review (Communication)
-- skill      = capability you can be missing (Laravel, Excel, Negotiation)
CREATE TABLE skills (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  category VARCHAR(60)
);


-- The level a job SHOULD have -> used to calculate skill gaps
CREATE TABLE role_skill_requirements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  job_title VARCHAR(100) NOT NULL,
  skill_id INT NOT NULL,
  required_level TINYINT NOT NULL CHECK (required_level BETWEEN 1 AND 5),
  CONSTRAINT fk_rsr_skill FOREIGN KEY (skill_id) REFERENCES skills (id),
  UNIQUE KEY uq_role_skill (job_title, skill_id)
);


-- The level a PERSON actually has
CREATE TABLE employee_skills (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  skill_id INT NOT NULL,
  current_level TINYINT NOT NULL CHECK (current_level BETWEEN 1 AND 5),
  assessed_by INT,
  assessed_at DATE,
  CONSTRAINT fk_es_emp FOREIGN KEY (employee_id) REFERENCES users (id),
  CONSTRAINT fk_es_skill FOREIGN KEY (skill_id) REFERENCES skills (id),
  CONSTRAINT fk_es_assessor FOREIGN KEY (assessed_by) REFERENCES users (id),
  UNIQUE KEY uq_emp_skill (employee_id, skill_id)
);


-- ============================================================
-- SECTION 3: THE REVIEW CYCLE
-- ============================================================
CREATE TABLE review_cycles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL, -- 'H1 2026 Review'
  period_start DATE NOT NULL, -- period being judged
  period_end DATE NOT NULL,
  self_deadline DATE,
  peer_deadline DATE,
  manager_deadline DATE,
  status ENUM(
    'draft',
    'open',
    'peer_review',
    'manager_review',
    'calibration',
    'released',
    'closed'
  ) NOT NULL DEFAULT 'draft',
  min_peers TINYINT NOT NULL DEFAULT 3 CHECK (min_peers >= 3),
  created_by INT,
  released_at DATETIME NULL,
  CONSTRAINT fk_cycle_creator FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT chk_cycle_dates CHECK (period_end >= period_start)
);


-- The status column IS the workflow. Nothing is visible to the employee
-- until status = 'released'. This single rule is the heart of the system.
-- One row per employee taking part in one cycle
CREATE TABLE review_participants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cycle_id INT NOT NULL,
  employee_id INT NOT NULL,
  manager_id INT NOT NULL, -- snapshot: manager at the time
  status ENUM(
    'not_started',
    'self_submitted',
    'peers_complete',
    'manager_submitted',
    'released'
  ) NOT NULL DEFAULT 'not_started',
  final_rating DECIMAL(3, 2) NULL CHECK (final_rating BETWEEN 1 AND 5),
  manager_summary TEXT NULL,
  released_at DATETIME NULL,
  CONSTRAINT fk_rp_cycle FOREIGN KEY (cycle_id) REFERENCES review_cycles (id),
  CONSTRAINT fk_rp_emp FOREIGN KEY (employee_id) REFERENCES users (id),
  CONSTRAINT fk_rp_mgr FOREIGN KEY (manager_id) REFERENCES users (id),
  UNIQUE KEY uq_cycle_emp (cycle_id, employee_id)
);


-- Employee suggests peers, manager approves them (stops people picking
-- only their best friends)
CREATE TABLE peer_nominations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  participant_id INT NOT NULL,
  peer_id INT NOT NULL,
  shared_work VARCHAR(255) NOT NULL,
  collaboration_details TEXT NOT NULL,
  reviewer_justification TEXT NOT NULL,
  direct_knowledge_confirmed TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
  nominated_by INT NOT NULL,
  decided_by INT,
  decision_reason TEXT NULL,
  suggested_peer_id INT NULL,
  suggestion_reason TEXT NULL,
  decided_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_pn_part FOREIGN KEY (participant_id) REFERENCES review_participants (id),
  CONSTRAINT fk_pn_peer FOREIGN KEY (peer_id) REFERENCES users (id),
  CONSTRAINT fk_pn_nominator FOREIGN KEY (nominated_by) REFERENCES users (id),
  CONSTRAINT fk_pn_decider FOREIGN KEY (decided_by) REFERENCES users (id),
  CONSTRAINT fk_pn_suggested_peer FOREIGN KEY (suggested_peer_id) REFERENCES users (id),
  UNIQUE KEY uq_part_peer (participant_id, peer_id),
  KEY idx_peer_nomination_status (status, created_at)
);


-- Employees may ask HR to review a manager's rejection. HR resolution is
-- intentionally stored here for the future HR workspace implementation.
CREATE TABLE peer_nomination_escalations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nomination_id INT NOT NULL,
  employee_id INT NOT NULL,
  escalation_reason TEXT NOT NULL,
  status ENUM('pending_hr', 'resolved_upheld', 'resolved_overturned') NOT NULL DEFAULT 'pending_hr',
  escalated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  resolved_by INT NULL,
  resolution_note TEXT NULL,
  resolved_at DATETIME NULL,
  CONSTRAINT fk_pne_nomination FOREIGN KEY (nomination_id) REFERENCES peer_nominations (id),
  CONSTRAINT fk_pne_employee FOREIGN KEY (employee_id) REFERENCES users (id),
  CONSTRAINT fk_pne_resolver FOREIGN KEY (resolved_by) REFERENCES users (id),
  UNIQUE KEY uq_peer_nomination_escalation (nomination_id),
  KEY idx_peer_escalation_status (status, escalated_at)
);


-- One "form to fill in". Type tells you which of the 360 directions it is.
CREATE TABLE feedback_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  participant_id INT NOT NULL,
  respondent_id INT NOT NULL, -- WHO is filling it in
  type ENUM('self', 'manager', 'peer') NOT NULL,
  status ENUM('pending', 'submitted') DEFAULT 'pending',
  submitted_at DATETIME NULL,
  CONSTRAINT fk_fr_part FOREIGN KEY (participant_id) REFERENCES review_participants (id),
  CONSTRAINT fk_fr_resp FOREIGN KEY (respondent_id) REFERENCES users (id),
  UNIQUE KEY uq_part_resp_type (participant_id, respondent_id, type)
);


-- ANONYMITY RULE:
-- respondent_id must exist (to prevent double submission + for HR audit)
-- but NO query that serves an employee may ever SELECT this column.
-- Always read peer feedback through the view v_peer_feedback below.
CREATE TABLE feedback_ratings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL,
  competency_id INT NOT NULL,
  score TINYINT NOT NULL CHECK (score BETWEEN 1 AND 5),
  comment TEXT,
  CONSTRAINT fk_frt_req FOREIGN KEY (request_id) REFERENCES feedback_requests (id),
  CONSTRAINT fk_frt_comp FOREIGN KEY (competency_id) REFERENCES competencies (id),
  UNIQUE KEY uq_req_comp (request_id, competency_id)
);


CREATE TABLE feedback_summary (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL UNIQUE,
  strengths TEXT,
  improvements TEXT,
  CONSTRAINT fk_fs_req FOREIGN KEY (request_id) REFERENCES feedback_requests (id)
);


-- ============================================================
-- SECTION 4: GOALS
-- ============================================================
CREATE TABLE goals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  manager_id INT NOT NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT NOT NULL,
  due_date DATE NOT NULL,
  status ENUM(
    'not_started',
    'in_progress',
    'completed',
    'missed'
  ) DEFAULT 'not_started',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_goal_emp FOREIGN KEY (employee_id) REFERENCES users (id),
  CONSTRAINT fk_goal_mgr FOREIGN KEY (manager_id) REFERENCES users (id)
);


-- ============================================================
-- SECTION 5: PDP  (the "grow" plan)
-- ============================================================
CREATE TABLE pdps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  manager_id INT NOT NULL,
  participant_id INT NULL, -- which cycle it came out of
  summary TEXT,
  status ENUM('draft', 'agreed', 'completed', 'cancelled') DEFAULT 'draft',
  agreed_at DATETIME NULL,
  CONSTRAINT fk_pdp_emp FOREIGN KEY (employee_id) REFERENCES users (id),
  CONSTRAINT fk_pdp_mgr FOREIGN KEY (manager_id) REFERENCES users (id),
  CONSTRAINT fk_pdp_part FOREIGN KEY (participant_id) REFERENCES review_participants (id)
);


CREATE TABLE pdp_actions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pdp_id INT NOT NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT,
  skill_id INT NULL, -- links the action to a skill gap
  due_date DATE NOT NULL,
  status ENUM(
    'not_started',
    'in_progress',
    'completed',
    'overdue',
    'cancelled'
  ) DEFAULT 'not_started',
  completed_at DATETIME NULL,
  CONSTRAINT fk_pa_pdp FOREIGN KEY (pdp_id) REFERENCES pdps (id),
  CONSTRAINT fk_pa_skill FOREIGN KEY (skill_id) REFERENCES skills (id)
);


-- Progress notes: this is what stops actions being "forgotten"
CREATE TABLE action_updates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  action_id INT NOT NULL,
  author_id INT NOT NULL,
  note TEXT,
  new_status VARCHAR(20),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_au_action FOREIGN KEY (action_id) REFERENCES pdp_actions (id),
  CONSTRAINT fk_au_author FOREIGN KEY (author_id) REFERENCES users (id)
);


-- ============================================================
-- SECTION 6: PIP  (the "fix it" plan — HR supervised)
-- ============================================================
CREATE TABLE pips (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  manager_id INT NOT NULL,
  hr_owner_id INT NOT NULL, -- HR must own every PIP
  reason TEXT NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  status ENUM(
    'draft',
    'active',
    'extended',
    'successful',
    'unsuccessful',
    'closed'
  ) DEFAULT 'draft',
  outcome_note TEXT,
  CONSTRAINT fk_pip_emp FOREIGN KEY (employee_id) REFERENCES users (id),
  CONSTRAINT fk_pip_mgr FOREIGN KEY (manager_id) REFERENCES users (id),
  CONSTRAINT fk_pip_hr FOREIGN KEY (hr_owner_id) REFERENCES users (id),
  CONSTRAINT chk_pip_dates CHECK (end_date >= start_date)
);


CREATE TABLE pip_objectives (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pip_id INT NOT NULL,
  objective VARCHAR(255) NOT NULL,
  success_criteria TEXT NOT NULL, -- expected evidence for the objective
  due_date DATE,
  status ENUM('not_met', 'partially_met', 'met') DEFAULT 'not_met',
  CONSTRAINT fk_po_pip FOREIGN KEY (pip_id) REFERENCES pips (id)
);


CREATE TABLE pip_checkins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pip_id INT NOT NULL,
  checkin_date DATE NOT NULL,
  author_id INT NOT NULL,
  notes TEXT NOT NULL,
  CONSTRAINT fk_pc_pip FOREIGN KEY (pip_id) REFERENCES pips (id),
  CONSTRAINT fk_pc_author FOREIGN KEY (author_id) REFERENCES users (id)
);


-- Ordered, actionable steps are the shared unit of progress for goals, PDP
-- actions and PIP objectives. The step author is the only person who may
-- rewrite or remove the step; an assignee may still mark it complete.
CREATE TABLE work_steps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  goal_id INT NULL,
  pdp_action_id INT NULL,
  pip_objective_id INT NULL,
  title VARCHAR(500) NOT NULL,
  step_order SMALLINT UNSIGNED NOT NULL,
  created_by INT NOT NULL,
  is_completed TINYINT(1) NOT NULL DEFAULT 0,
  completed_by INT NULL,
  completed_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_work_step_parent CHECK (
    (goal_id IS NOT NULL) +
    (pdp_action_id IS NOT NULL) +
    (pip_objective_id IS NOT NULL) = 1
  ),
  CONSTRAINT fk_ws_goal FOREIGN KEY (goal_id) REFERENCES goals (id) ON DELETE CASCADE,
  CONSTRAINT fk_ws_pdp_action FOREIGN KEY (pdp_action_id) REFERENCES pdp_actions (id) ON DELETE CASCADE,
  CONSTRAINT fk_ws_pip_objective FOREIGN KEY (pip_objective_id) REFERENCES pip_objectives (id) ON DELETE CASCADE,
  CONSTRAINT fk_ws_creator FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT fk_ws_completer FOREIGN KEY (completed_by) REFERENCES users (id),
  INDEX idx_ws_goal (goal_id, step_order),
  INDEX idx_ws_pdp (pdp_action_id, step_order),
  INDEX idx_ws_pip (pip_objective_id, step_order)
);


-- ============================================================
-- SECTION 7: VIEWS  (do your safe reading through these)
-- ============================================================
-- Anonymised peer feedback. respondent_id is deliberately absent.
CREATE OR REPLACE VIEW v_peer_feedback AS
SELECT
  fr.participant_id,
  fr.id AS request_id,
  c.name AS competency,
  frt.score,
  frt.comment
FROM
  feedback_requests fr
  JOIN feedback_ratings frt ON frt.request_id = fr.id
  JOIN competencies c ON c.id = frt.competency_id
WHERE
  fr.type = 'peer'
  AND fr.status = 'submitted';


-- Average score per competency, split by feedback direction.
-- This is what you draw the 360 chart from.
CREATE OR REPLACE VIEW v_360_summary AS
SELECT
  fr.participant_id,
  c.name AS competency,
  fr.type,
  ROUND(AVG(frt.score), 2) AS avg_score,
  COUNT(*) AS responses
FROM
  feedback_requests fr
  JOIN feedback_ratings frt ON frt.request_id = fr.id
  JOIN competencies c ON c.id = frt.competency_id
WHERE
  fr.status = 'submitted'
GROUP BY
  fr.participant_id,
  c.name,
  fr.type;


-- Skill gap report for management
CREATE OR REPLACE VIEW v_skill_gaps AS
SELECT
  u.id AS employee_id,
  u.full_name,
  d.department_name AS department,
  u.job_title,
  s.name AS skill,
  r.required_level,
  COALESCE(es.current_level, 0) AS current_level,
  r.required_level - COALESCE(es.current_level, 0) AS gap
FROM
  users u
  JOIN departments d ON d.id = u.department_id
  JOIN role_skill_requirements r ON r.job_title = u.job_title
  JOIN skills s ON s.id = r.skill_id
  LEFT JOIN employee_skills es ON es.employee_id = u.id
  AND es.skill_id = s.id
WHERE
  u.is_active = 1;


-- ============================================================
-- Permissions and employee-workspace migration for fresh installs.
INSERT INTO permissions(permission_code,description) VALUES
 ('org.structure.view','View own reporting path and scoped organization'),
 ('org.structure.manage','Manage organization assignments'),
 ('org.descendants.view','View descendant directory information'),
 ('org.structure.view_all','View organization-wide directory');
INSERT INTO role_permissions(role_code,permission_id)
 SELECT r.role_code,p.id FROM roles r CROSS JOIN permissions p
 WHERE p.permission_code='org.structure.view'
 OR (p.permission_code='org.descendants.view' AND r.role_code IN ('manager','hr','admin','leadership'))
 OR (p.permission_code='org.structure.manage' AND r.role_code IN ('hr','admin'))
 OR (p.permission_code='org.structure.view_all' AND r.role_code IN ('hr','admin','leadership'));

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

-- Permissions required by the HR case queue and administrator audit screens.
-- Keep this in the fresh-install schema as well as migration 005.
INSERT IGNORE INTO permissions(permission_code,description) VALUES
 ('hr.cases','Resolve escalated peer-nomination decisions'),
 ('admin.audit','Read the audit log and sign-in security records');
INSERT IGNORE INTO role_permissions(role_code,permission_id)
 SELECT r.role_code,p.id FROM roles r CROSS JOIN permissions p
 WHERE (p.permission_code='hr.cases' AND r.role_code IN ('hr','hr_partner','admin'))
    OR (p.permission_code='admin.audit' AND r.role_code='admin');

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

-- Load fictional content separately: php scripts/demo.php seed
