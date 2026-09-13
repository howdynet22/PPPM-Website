-- ============================================================
--  PERFORMANCE & DEVELOPMENT TRACKER
--  Database schema  (MySQL 8 / MariaDB — works on XAMPP)
--
--  Run this in phpMyAdmin:  Import > choose file > Go
-- ============================================================
DROP DATABASE IF EXISTS perf_tracker;


CREATE DATABASE perf_tracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;


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
    'hr.reports'
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
  status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
  nominated_by INT,
  decided_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pn_part FOREIGN KEY (participant_id) REFERENCES review_participants (id),
  CONSTRAINT fk_pn_peer FOREIGN KEY (peer_id) REFERENCES users (id),
  CONSTRAINT fk_pn_nominator FOREIGN KEY (nominated_by) REFERENCES users (id),
  CONSTRAINT fk_pn_decider FOREIGN KEY (decided_by) REFERENCES users (id),
  UNIQUE KEY uq_part_peer (participant_id, peer_id)
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
  progress_pct TINYINT NOT NULL DEFAULT 0 CHECK (progress_pct BETWEEN 0 AND 100),
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
  progress_pct TINYINT DEFAULT 0 CHECK (progress_pct BETWEEN 0 AND 100),
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
  success_criteria TEXT NOT NULL, -- must be measurable
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
-- SECTION 8: SAMPLE DATA
--  All passwords below are the plain text 'password123' hashed
--  with PHP password_hash(). Use it to log in while testing.
-- ============================================================
INSERT INTO departments(id,department_code,department_name) VALUES (1,'EXEC','Executive'),(2,'HR','Human Resources'),(3,'ENG','IT/Engineering'),(4,'FIN','Finance');
INSERT INTO teams(id,department_id,team_code,team_name) VALUES (1,3,'PLATFORM','Platform'),(2,3,'PRODUCT','Product'),(3,4,'ACCOUNTS','Accounts'),(4,2,'PEOPLE','People Operations');
INSERT INTO users(emp_code,full_name,email,password_hash,role,job_title,department_id,team_id,date_joined) VALUES
('E001','Sanduni Perera','hr@demo.lk','$2y$12$KICJrailDGtQxqre7rNAYu0l2L6E02fi9fiZPAWJusJEmBHt0uGou','hr','HR Manager',2,4,'2021-01-10'),
('E002','Dilan Fernando','admin@demo.lk','$2y$12$KICJrailDGtQxqre7rNAYu0l2L6E02fi9fiZPAWJusJEmBHt0uGou','admin','System Administrator',1,NULL,'2019-03-01'),
('E003','Kavindu Silva','kavindu@demo.lk','$2y$12$KICJrailDGtQxqre7rNAYu0l2L6E02fi9fiZPAWJusJEmBHt0uGou','manager','Engineering Manager',3,1,'2020-06-15'),
('E004','Nimal Jayasuriya','nimal@demo.lk','$2y$12$KICJrailDGtQxqre7rNAYu0l2L6E02fi9fiZPAWJusJEmBHt0uGou','employee','Junior Developer',3,1,'2024-02-01'),
('E005','Amaya Rathnayake','amaya@demo.lk','$2y$12$KICJrailDGtQxqre7rNAYu0l2L6E02fi9fiZPAWJusJEmBHt0uGou','employee','Software Engineer',3,1,'2022-08-20'),
('E006','Tharindu Bandara','tharindu@demo.lk','$2y$12$KICJrailDGtQxqre7rNAYu0l2L6E02fi9fiZPAWJusJEmBHt0uGou','employee','QA Engineer',3,1,'2023-05-05'),
('E007','Ishara Gunasekara','ishara@demo.lk','$2y$12$KICJrailDGtQxqre7rNAYu0l2L6E02fi9fiZPAWJusJEmBHt0uGou','employee','Software Engineer',3,1,'2023-11-11'),
('E008','Sahan de Alwis','sahan@demo.lk','$2y$12$KICJrailDGtQxqre7rNAYu0l2L6E02fi9fiZPAWJusJEmBHt0uGou','manager','Team Lead',3,1,'2021-04-19'),
('E009','Malini Wijesinghe','malini@demo.lk','$2y$12$KICJrailDGtQxqre7rNAYu0l2L6E02fi9fiZPAWJusJEmBHt0uGou','employee','UI/UX Designer',3,1,'2022-10-03'),
('E010','Farah Iqbal','farah@demo.lk','$2y$12$KICJrailDGtQxqre7rNAYu0l2L6E02fi9fiZPAWJusJEmBHt0uGou','employee','DevOps Engineer',3,1,'2023-01-16'),
('E011','Janith Perera','janith@demo.lk','$2y$12$KICJrailDGtQxqre7rNAYu0l2L6E02fi9fiZPAWJusJEmBHt0uGou','employee','Data Analyst',3,1,'2024-06-10'),
('E012','Priyanka Senanayake','priyanka@demo.lk','$2y$12$KICJrailDGtQxqre7rNAYu0l2L6E02fi9fiZPAWJusJEmBHt0uGou','manager','Product Manager',3,2,'2021-09-13'),
('E013','Akeel Nazeer','akeel@demo.lk','$2y$12$KICJrailDGtQxqre7rNAYu0l2L6E02fi9fiZPAWJusJEmBHt0uGou','employee','Product Analyst',3,2,'2023-03-06'),
('E014','Hana Fairooz','hana@demo.lk','$2y$12$KICJrailDGtQxqre7rNAYu0l2L6E02fi9fiZPAWJusJEmBHt0uGou','employee','Business Analyst',3,2,'2022-11-21'),
('E015','Rishan Mohamed','rishan@demo.lk','$2y$12$KICJrailDGtQxqre7rNAYu0l2L6E02fi9fiZPAWJusJEmBHt0uGou','employee','UX Researcher',3,2,'2024-01-08'),
('E016','Leena Raman','ceo@demo.lk','$2y$12$KICJrailDGtQxqre7rNAYu0l2L6E02fi9fiZPAWJusJEmBHt0uGou','leadership','Chief Executive',1,NULL,'2020-01-01'),
('E017','Ravi Sen','engineering-head@demo.lk','$2y$12$KICJrailDGtQxqre7rNAYu0l2L6E02fi9fiZPAWJusJEmBHt0uGou','manager','Engineering Director',3,NULL,'2020-01-01'),
('E018','Maya Fernando','finance-head@demo.lk','$2y$12$KICJrailDGtQxqre7rNAYu0l2L6E02fi9fiZPAWJusJEmBHt0uGou','manager','Finance Director',4,NULL,'2020-01-01'),
('E019','Imaan Ali','hr-head@demo.lk','$2y$12$KICJrailDGtQxqre7rNAYu0l2L6E02fi9fiZPAWJusJEmBHt0uGou','hr','HR Director',2,NULL,'2020-01-01'),
('E020','Noah Peris','finance-manager@demo.lk','$2y$12$KICJrailDGtQxqre7rNAYu0l2L6E02fi9fiZPAWJusJEmBHt0uGou','manager','Finance Manager',4,3,'2020-01-01'),
('E021','Tara Dias','accountant@demo.lk','$2y$12$KICJrailDGtQxqre7rNAYu0l2L6E02fi9fiZPAWJusJEmBHt0uGou','employee','Accountant',4,3,'2020-01-01'),
('E022','Anika Sen','hr-executive@demo.lk','$2y$12$KICJrailDGtQxqre7rNAYu0l2L6E02fi9fiZPAWJusJEmBHt0uGou','employee','HR Executive',2,4,'2020-01-01');
INSERT INTO reporting_relationships(employee_id,reports_to_employee_id,effective_from,created_by,change_reason) VALUES
(1,19,'2020-01-01',2,'Fictional demo hierarchy'),
(2,16,'2020-01-01',2,'Fictional demo hierarchy'),
(3,17,'2020-01-01',2,'Fictional demo hierarchy'),
(4,8,'2026-07-01',2,'Fictional demo hierarchy'),
(5,3,'2020-01-01',2,'Fictional demo hierarchy'),
(6,3,'2020-01-01',2,'Fictional demo hierarchy'),
(7,8,'2020-01-01',2,'Fictional demo hierarchy'),
(8,3,'2020-01-01',2,'Fictional demo hierarchy'),
(9,3,'2020-01-01',2,'Fictional demo hierarchy'),
(10,3,'2020-01-01',2,'Fictional demo hierarchy'),
(11,3,'2020-01-01',2,'Fictional demo hierarchy'),
(12,17,'2020-01-01',2,'Fictional demo hierarchy'),
(13,12,'2020-01-01',2,'Fictional demo hierarchy'),
(14,12,'2020-01-01',2,'Fictional demo hierarchy'),
(15,12,'2020-01-01',2,'Fictional demo hierarchy'),
(17,16,'2020-01-01',2,'Fictional demo hierarchy'),
(18,16,'2020-01-01',2,'Fictional demo hierarchy'),
(19,16,'2020-01-01',2,'Fictional demo hierarchy'),
(20,18,'2020-01-01',2,'Fictional demo hierarchy'),
(21,20,'2020-01-01',2,'Fictional demo hierarchy'),
(22,1,'2020-01-01',2,'Fictional demo hierarchy');
INSERT INTO reporting_relationships(employee_id,reports_to_employee_id,relationship_type,effective_from,effective_to,created_by,change_reason) VALUES (4,3,'primary','2020-01-01','2026-07-01',2,'Moved to Platform team lead'),(4,12,'dotted_line','2026-07-01',NULL,2,'Cross-team collaboration');
UPDATE departments SET head_employee_id=CASE id WHEN 1 THEN 16 WHEN 2 THEN 19 WHEN 3 THEN 17 WHEN 4 THEN 18 END;
UPDATE teams SET team_lead_employee_id=CASE id WHEN 1 THEN 8 WHEN 2 THEN 12 WHEN 3 THEN 20 WHEN 4 THEN 1 END;

INSERT INTO
  competencies (name, description)
VALUES
  (
    'Communication',
    'Clarity of written and verbal communication'
  ),
  (
    'Technical Skill',
    'Depth and correctness of technical work'
  ),
  (
    'Teamwork',
    'Collaboration and support of colleagues'
  ),
  (
    'Ownership',
    'Takes responsibility and follows through'
  ),
  ('Reliability', 'Meets deadlines and commitments');


INSERT INTO
  skills (name, category)
VALUES
  ('PHP', 'Technical'),
  ('MySQL', 'Technical'),
  ('JavaScript', 'Technical'),
  ('Git', 'Technical'),
  ('Unit Testing', 'Technical'),
  ('Presentation', 'Soft'),
  ('Time Management', 'Soft'),
  ('API Design', 'Technical'),
  ('Test Automation', 'Technical'),
  ('Docker', 'Technical'),
  ('CI/CD', 'Technical'),
  ('UX Research', 'Design'),
  ('Figma', 'Design'),
  ('Data Analysis', 'Data'),
  ('SQL', 'Data'),
  ('Cloud Monitoring', 'Technical');


INSERT INTO
  role_skill_requirements (job_title, skill_id, required_level)
VALUES
  ('Junior Developer', 1, 3),
  ('Junior Developer', 2, 3),
  ('Junior Developer', 4, 3),
  ('Junior Developer', 7, 3),
  ('Software Engineer', 1, 4),
  ('Software Engineer', 2, 4),
  ('Software Engineer', 3, 4),
  ('Software Engineer', 5, 4),
  ('Software Engineer', 8, 3),
  ('QA Engineer', 4, 3),
  ('QA Engineer', 5, 4),
  ('QA Engineer', 9, 4),
  ('QA Engineer', 7, 3),
  ('Senior Software Engineer', 1, 5),
  ('Senior Software Engineer', 2, 4),
  ('Senior Software Engineer', 3, 5),
  ('Senior Software Engineer', 8, 5),
  ('Senior Software Engineer', 6, 4),
  ('UI/UX Designer', 12, 4),
  ('UI/UX Designer', 13, 4),
  ('UI/UX Designer', 6, 4),
  ('UI/UX Designer', 7, 3),
  ('DevOps Engineer', 4, 4),
  ('DevOps Engineer', 10, 4),
  ('DevOps Engineer', 11, 4),
  ('DevOps Engineer', 16, 4),
  ('Data Analyst', 14, 4),
  ('Data Analyst', 15, 4),
  ('Data Analyst', 6, 3),
  ('Data Analyst', 7, 3);


INSERT INTO
  employee_skills (
    employee_id,
    skill_id,
    current_level,
    assessed_by,
    assessed_at
  )
VALUES
  (4, 1, 2, 3, '2026-07-05'),
  (4, 2, 3, 3, '2026-07-05'),
  (4, 4, 2, 3, '2026-07-05'),
  (4, 7, 1, 3, '2026-07-05'),
  (5, 1, 4, 3, '2026-07-05'),
  (5, 2, 3, 3, '2026-07-05'),
  (5, 3, 4, 3, '2026-07-05'),
  (5, 5, 3, 3, '2026-07-05'),
  (5, 8, 4, 3, '2026-07-05'),
  (6, 4, 4, 3, '2026-07-06'),
  (6, 5, 4, 3, '2026-07-06'),
  (6, 9, 3, 3, '2026-07-06'),
  (6, 7, 3, 3, '2026-07-06'),
  (7, 1, 4, 3, '2026-07-06'),
  (7, 2, 4, 3, '2026-07-06'),
  (7, 3, 3, 3, '2026-07-06'),
  (7, 5, 3, 3, '2026-07-06'),
  (7, 8, 3, 3, '2026-07-06'),
  (8, 1, 5, 3, '2026-07-07'),
  (8, 2, 4, 3, '2026-07-07'),
  (8, 3, 5, 3, '2026-07-07'),
  (8, 8, 5, 3, '2026-07-07'),
  (8, 6, 3, 3, '2026-07-07'),
  (9, 12, 5, 3, '2026-07-08'),
  (9, 13, 5, 3, '2026-07-08'),
  (9, 6, 4, 3, '2026-07-08'),
  (9, 7, 3, 3, '2026-07-08'),
  (10, 4, 4, 3, '2026-07-09'),
  (10, 10, 4, 3, '2026-07-09'),
  (10, 11, 3, 3, '2026-07-09'),
  (10, 16, 2, 3, '2026-07-09'),
  (11, 14, 4, 3, '2026-07-10'),
  (11, 15, 5, 3, '2026-07-10'),
  (11, 6, 2, 3, '2026-07-10'),
  (11, 7, 3, 3, '2026-07-10');


INSERT INTO
  review_cycles (
    name,
    period_start,
    period_end,
    self_deadline,
    peer_deadline,
    manager_deadline,
    status,
    created_by,
    released_at
  )
VALUES
  (
    'H2 2025 Review',
    '2025-07-01',
    '2025-12-31',
    '2026-01-09',
    '2026-01-16',
    '2026-01-23',
    'closed',
    1,
    '2026-01-28 09:00:00'
  ),
  (
    'H1 2026 Review',
    '2026-01-01',
    '2026-06-30',
    '2026-07-10',
    '2026-07-17',
    '2026-08-28',
    'manager_review',
    1,
    NULL
  );


INSERT INTO
  review_participants (
    cycle_id,
    employee_id,
    manager_id,
    status,
    final_rating,
    manager_summary,
    released_at
  )
VALUES
  (
    1,
    4,
    3,
    'released',
    3.30,
    'Good teamwork and improving technical confidence. Reliability remained the main development area.',
    '2026-01-28 09:00:00'
  ),
  (
    1,
    5,
    3,
    'released',
    4.10,
    'Delivered strong API work and supported junior developers throughout the review period.',
    '2026-01-28 09:00:00'
  ),
  (
    1,
    6,
    3,
    'released',
    3.85,
    'Dependable tester with solid attention to detail and growing automation capability.',
    '2026-01-28 09:00:00'
  ),
  (
    1,
    7,
    3,
    'released',
    3.70,
    'Consistent delivery with an opportunity to communicate technical decisions more clearly.',
    '2026-01-28 09:00:00'
  ),
  (
    1,
    8,
    3,
    'released',
    4.60,
    'A strong technical leader who improved architecture quality across the team.',
    '2026-01-28 09:00:00'
  ),
  (
    1,
    9,
    3,
    'released',
    4.45,
    'Produced thoughtful user-centred designs and handled stakeholder feedback well.',
    '2026-01-28 09:00:00'
  ),
  (
    1,
    10,
    3,
    'released',
    3.40,
    'Improved deployment stability but needed more consistent incident documentation.',
    '2026-01-28 09:00:00'
  ),
  (
    1,
    11,
    3,
    'released',
    3.75,
    'Built useful reports and improved the accuracy of monthly performance data.',
    '2026-01-28 09:00:00'
  ),
  (
    1,
    3,
    2,
    'released',
    4.30,
    'Created a supportive team culture and improved the regularity of coaching conversations.',
    '2026-01-28 09:00:00'
  ),
  (2, 4, 3, 'peers_complete', NULL, NULL, NULL),
  (
    2,
    5,
    3,
    'manager_submitted',
    4.45,
    'Amaya delivered the API refactor ahead of schedule and consistently helped other team members.',
    NULL
  ),
  (2, 6, 3, 'self_submitted', NULL, NULL, NULL),
  (2, 7, 3, 'not_started', NULL, NULL, NULL),
  (
    2,
    8,
    3,
    'manager_submitted',
    4.75,
    'Sahan led complex design decisions, reduced technical risk and coached the team effectively.',
    NULL
  ),
  (2, 9, 3, 'peers_complete', NULL, NULL, NULL),
  (
    2,
    10,
    3,
    'manager_submitted',
    3.20,
    CONCAT(
      'Farah improved deployment automation, but incident follow-up and ',
      'monitoring ownership need to become more consistent.'
    ),
    NULL
  ),
  (2, 11, 3, 'self_submitted', NULL, NULL, NULL);


INSERT INTO
  peer_nominations (
    participant_id,
    peer_id,
    status,
    nominated_by,
    decided_by
  )
VALUES
  (10, 5, 'approved', 4, 3),
  (10, 6, 'approved', 4, 3),
  (10, 7, 'approved', 4, 3),
  (11, 4, 'approved', 5, 3),
  (11, 8, 'approved', 5, 3),
  (11, 9, 'approved', 5, 3),
  (12, 5, 'pending', 6, NULL),
  (12, 7, 'approved', 6, 3),
  (13, 4, 'pending', 7, NULL),
  (13, 5, 'pending', 7, NULL),
  (14, 4, 'approved', 8, 3),
  (14, 5, 'approved', 8, 3),
  (14, 10, 'approved', 8, 3),
  (15, 5, 'approved', 9, 3),
  (15, 7, 'approved', 9, 3),
  (15, 11, 'approved', 9, 3),
  (16, 5, 'approved', 10, 3),
  (16, 6, 'approved', 10, 3),
  (16, 8, 'approved', 10, 3),
  (17, 6, 'pending', 11, NULL),
  (17, 9, 'approved', 11, 3),
  (17, 10, 'rejected', 11, 3);


INSERT INTO
  feedback_requests (
    participant_id,
    respondent_id,
    type,
    status,
    submitted_at
  )
VALUES
  (10, 4, 'self', 'submitted', '2026-07-08 10:00:00'),
  (10, 5, 'peer', 'submitted', '2026-07-15 09:00:00'),
  (10, 6, 'peer', 'submitted', '2026-07-16 14:00:00'),
  (10, 7, 'peer', 'submitted', '2026-07-16 17:30:00'),
  (10, 3, 'manager', 'pending', NULL),
  (11, 5, 'self', 'submitted', '2026-07-07 11:30:00'),
  (11, 4, 'peer', 'submitted', '2026-07-13 10:15:00'),
  (11, 8, 'peer', 'submitted', '2026-07-14 15:00:00'),
  (11, 9, 'peer', 'submitted', '2026-07-16 09:20:00'),
  (
    11,
    3,
    'manager',
    'submitted',
    '2026-08-04 14:00:00'
  ),
  (12, 6, 'self', 'submitted', '2026-07-09 16:00:00'),
  (12, 5, 'peer', 'pending', NULL),
  (12, 7, 'peer', 'pending', NULL),
  (12, 3, 'manager', 'pending', NULL),
  (13, 7, 'self', 'pending', NULL),
  (13, 3, 'manager', 'pending', NULL),
  (14, 8, 'self', 'submitted', '2026-07-06 13:00:00'),
  (14, 4, 'peer', 'submitted', '2026-07-12 09:30:00'),
  (14, 5, 'peer', 'submitted', '2026-07-14 11:15:00'),
  (
    14,
    10,
    'peer',
    'submitted',
    '2026-07-16 16:45:00'
  ),
  (
    14,
    3,
    'manager',
    'submitted',
    '2026-08-03 10:30:00'
  ),
  (15, 9, 'self', 'submitted', '2026-07-09 12:30:00'),
  (15, 5, 'peer', 'submitted', '2026-07-13 14:10:00'),
  (15, 7, 'peer', 'submitted', '2026-07-15 10:40:00'),
  (
    15,
    11,
    'peer',
    'submitted',
    '2026-07-16 13:20:00'
  ),
  (15, 3, 'manager', 'pending', NULL),
  (
    16,
    10,
    'self',
    'submitted',
    '2026-07-10 09:45:00'
  ),
  (16, 5, 'peer', 'submitted', '2026-07-13 16:30:00'),
  (16, 6, 'peer', 'submitted', '2026-07-14 10:00:00'),
  (16, 8, 'peer', 'submitted', '2026-07-16 15:10:00'),
  (
    16,
    3,
    'manager',
    'submitted',
    '2026-08-05 11:20:00'
  ),
  (
    17,
    11,
    'self',
    'submitted',
    '2026-07-10 15:00:00'
  ),
  (17, 6, 'peer', 'pending', NULL),
  (17, 9, 'peer', 'pending', NULL),
  (17, 3, 'manager', 'pending', NULL),
  (9, 3, 'self', 'submitted', '2026-01-07 10:00:00'),
  (9, 4, 'peer', 'submitted', '2026-01-13 09:00:00'),
  (9, 5, 'peer', 'submitted', '2026-01-14 11:00:00'),
  (9, 8, 'peer', 'submitted', '2026-01-15 14:00:00'),
  (
    9,
    2,
    'manager',
    'submitted',
    '2026-01-22 10:00:00'
  );


INSERT INTO
  feedback_ratings (request_id, competency_id, score, comment)
VALUES
  (1, 1, 4, 'I explain my work clearly in standups'),
  (1, 2, 3, 'Still learning the framework'),
  (1, 3, 4, 'I help others when asked'),
  (1, 4, 4, 'I finish what I start'),
  (
    1,
    5,
    3,
    'I missed two deadlines during the period'
  ),
  (2, 1, 3, NULL),
  (2, 2, 3, NULL),
  (2, 3, 4, NULL),
  (2, 4, 3, NULL),
  (2, 5, 3, NULL),
  (3, 1, 4, NULL),
  (3, 2, 2, NULL),
  (3, 3, 5, NULL),
  (3, 4, 3, NULL),
  (3, 5, 2, NULL),
  (4, 1, 3, NULL),
  (4, 2, 3, NULL),
  (4, 3, 4, NULL),
  (4, 4, 4, NULL),
  (4, 5, 3, NULL),
  (6, 1, 4, NULL),
  (6, 2, 5, NULL),
  (6, 3, 4, NULL),
  (6, 4, 5, NULL),
  (6, 5, 4, NULL),
  (7, 1, 4, NULL),
  (7, 2, 4, NULL),
  (7, 3, 5, NULL),
  (7, 4, 4, NULL),
  (7, 5, 5, NULL),
  (8, 1, 5, NULL),
  (8, 2, 5, NULL),
  (8, 3, 4, NULL),
  (8, 4, 5, NULL),
  (8, 5, 4, NULL),
  (9, 1, 4, NULL),
  (9, 2, 4, NULL),
  (9, 3, 5, NULL),
  (9, 4, 4, NULL),
  (9, 5, 4, NULL),
  (10, 1, 4, 'Communicates decisions clearly'),
  (10, 2, 5, 'Strong API delivery'),
  (10, 3, 5, 'Supports junior developers'),
  (10, 4, 4, 'Takes ownership'),
  (10, 5, 4, 'Dependable delivery'),
  (11, 1, 4, NULL),
  (11, 2, 4, NULL),
  (11, 3, 4, NULL),
  (11, 4, 4, NULL),
  (11, 5, 4, NULL),
  (17, 1, 4, NULL),
  (17, 2, 5, NULL),
  (17, 3, 5, NULL),
  (17, 4, 5, NULL),
  (17, 5, 5, NULL),
  (18, 1, 5, NULL),
  (18, 2, 5, NULL),
  (18, 3, 4, NULL),
  (18, 4, 5, NULL),
  (18, 5, 5, NULL),
  (19, 1, 4, NULL),
  (19, 2, 5, NULL),
  (19, 3, 5, NULL),
  (19, 4, 5, NULL),
  (19, 5, 4, NULL),
  (20, 1, 5, NULL),
  (20, 2, 4, NULL),
  (20, 3, 5, NULL),
  (20, 4, 5, NULL),
  (20, 5, 5, NULL),
  (21, 1, 5, 'Explains technical trade-offs well'),
  (21, 2, 5, 'Excellent technical depth'),
  (21, 3, 5, 'Coaches the team'),
  (21, 4, 5, 'Owns difficult decisions'),
  (21, 5, 4, 'Consistently reliable'),
  (22, 1, 5, NULL),
  (22, 2, 4, NULL),
  (22, 3, 5, NULL),
  (22, 4, 4, NULL),
  (22, 5, 5, NULL),
  (23, 1, 5, NULL),
  (23, 2, 4, NULL),
  (23, 3, 5, NULL),
  (23, 4, 4, NULL),
  (23, 5, 4, NULL),
  (24, 1, 4, NULL),
  (24, 2, 5, NULL),
  (24, 3, 4, NULL),
  (24, 4, 5, NULL),
  (24, 5, 4, NULL),
  (25, 1, 5, NULL),
  (25, 2, 4, NULL),
  (25, 3, 5, NULL),
  (25, 4, 4, NULL),
  (25, 5, 5, NULL),
  (27, 1, 3, NULL),
  (27, 2, 4, NULL),
  (27, 3, 4, NULL),
  (27, 4, 3, NULL),
  (27, 5, 3, NULL),
  (28, 1, 3, NULL),
  (28, 2, 4, NULL),
  (28, 3, 4, NULL),
  (28, 4, 3, NULL),
  (28, 5, 3, NULL),
  (29, 1, 4, NULL),
  (29, 2, 3, NULL),
  (29, 3, 4, NULL),
  (29, 4, 3, NULL),
  (29, 5, 2, NULL),
  (30, 1, 3, NULL),
  (30, 2, 4, NULL),
  (30, 3, 3, NULL),
  (30, 4, 3, NULL),
  (30, 5, 3, NULL),
  (31, 1, 3, 'Communicates well during planned work'),
  (31, 2, 4, 'Good automation knowledge'),
  (31, 3, 3, 'Supports the team when asked'),
  (31, 4, 3, 'Incident follow-up needs improvement'),
  (31, 5, 3, 'More consistency is required'),
  (36, 1, 4, NULL),
  (36, 2, 4, NULL),
  (36, 3, 4, NULL),
  (36, 4, 5, NULL),
  (36, 5, 4, NULL),
  (37, 1, 4, NULL),
  (37, 2, 4, NULL),
  (37, 3, 5, NULL),
  (37, 4, 4, NULL),
  (37, 5, 4, NULL),
  (38, 1, 5, NULL),
  (38, 2, 4, NULL),
  (38, 3, 5, NULL),
  (38, 4, 4, NULL),
  (38, 5, 4, NULL),
  (39, 1, 4, NULL),
  (39, 2, 5, NULL),
  (39, 3, 4, NULL),
  (39, 4, 5, NULL),
  (39, 5, 4, NULL),
  (40, 1, 4, 'Clear and supportive manager'),
  (40, 2, 4, 'Understands technical priorities'),
  (40, 3, 5, 'Builds collaboration'),
  (40, 4, 4, 'Takes responsibility'),
  (40, 5, 4, 'Maintains regular coaching');


INSERT INTO
  feedback_summary (request_id, strengths, improvements)
VALUES
  (
    2,
    'Always willing to help with testing',
    'Could ask for help earlier when stuck'
  ),
  (
    3,
    'Great team spirit',
    'Code reviews take a long time to come back'
  ),
  (
    4,
    'Positive attitude',
    'Needs to communicate blockers sooner'
  ),
  (
    7,
    'Strong API knowledge and patient mentoring',
    'Could delegate smaller tasks earlier'
  ),
  (
    8,
    'Reliable technical advice',
    'Could document decisions more consistently'
  ),
  (
    9,
    'Works well across design and engineering',
    'Could protect more focus time'
  ),
  (
    18,
    'Excellent technical judgement',
    'Could make architecture notes easier for junior staff to follow'
  ),
  (
    19,
    'Calm and helpful during complex work',
    'Could involve QA earlier in design discussions'
  ),
  (
    20,
    'Strong incident support',
    'Could communicate planned changes sooner'
  ),
  (
    23,
    'Thoughtful design feedback',
    'Could shorten review turnaround during busy periods'
  ),
  (
    24,
    'Strong user focus',
    'Could share more work-in-progress designs'
  ),
  (
    25,
    'Clear visual communication',
    'Could document research findings more consistently'
  ),
  (
    28,
    'Helpful with deployment questions',
    'Could write more detailed incident notes'
  ),
  (
    29,
    'Strong automation knowledge',
    'Could improve ownership after incidents'
  ),
  (
    30,
    'Responds quickly during releases',
    'Could raise monitoring risks earlier'
  ),
  (
    37,
    'Supportive coaching style',
    'Could make priorities more explicit'
  ),
  (
    38,
    'Creates psychological safety',
    'Could delegate more operational decisions'
  ),
  (
    39,
    'Strong technical context',
    'Could communicate roadmap changes earlier'
  );


INSERT INTO
  goals (
    employee_id,
    manager_id,
    title,
    description,
    due_date,
    status,
    progress_pct
  )
VALUES
  (
    4,
    3,
    'Improve delivery reliability',
    'Meet sprint commitments consistently',
    '2026-09-30',
    'in_progress',
    55
  ),
  (
    4,
    3,
    'Build reusable PHP module',
    'Create and document one reusable validation module',
    '2026-10-20',
    'not_started',
    10
  ),
  (
    4,
    3,
    'Close inherited defect backlog',
    'Resolve the ten oldest assigned defects',
    '2026-06-30',
    'completed',
    100
  ),
  (
    5,
    3,
    'Lead API refactor',
    'Complete v2 endpoints and document the changes',
    '2026-09-20',
    'in_progress',
    85
  ),
  (
    5,
    3,
    'Mentor a junior developer',
    'Hold six pairing sessions and document learning outcomes',
    '2026-08-15',
    'completed',
    100
  ),
  (
    6,
    3,
    'Automate regression suite',
    'Reach 80% critical-path test coverage',
    '2026-10-15',
    'in_progress',
    45
  ),
  (
    6,
    3,
    'Publish mobile test plan',
    'Complete coverage for the July mobile release',
    '2026-07-20',
    'missed',
    70
  ),
  (
    7,
    3,
    'Improve code review turnaround',
    'Complete reviews within 24 hours',
    '2026-09-15',
    'in_progress',
    60
  ),
  (
    7,
    3,
    'Improve JavaScript performance',
    'Reduce dashboard load time below two seconds',
    '2026-11-15',
    'not_started',
    0
  ),
  (
    8,
    3,
    'Document architecture decisions',
    'Publish ADRs for all major H1 technical decisions',
    '2026-07-31',
    'completed',
    100
  ),
  (
    8,
    3,
    'Reduce API response time',
    'Reduce the median response time of core endpoints by 25%',
    '2026-10-31',
    'in_progress',
    75
  ),
  (
    9,
    3,
    'Complete usability study',
    'Run five moderated sessions and present findings',
    '2026-07-25',
    'completed',
    100
  ),
  (
    9,
    3,
    'Expand the design system',
    'Add accessible patterns for forms, tables and empty states',
    '2026-10-10',
    'in_progress',
    65
  ),
  (
    10,
    3,
    'Improve deployment rollback time',
    'Reduce average rollback time to under ten minutes',
    '2026-10-30',
    'in_progress',
    50
  ),
  (
    10,
    3,
    'Complete monitoring ownership map',
    'Assign an owner and runbook to all production alerts',
    '2026-07-31',
    'missed',
    40
  ),
  (
    11,
    3,
    'Automate monthly reporting',
    'Generate the monthly team metrics without manual spreadsheet work',
    '2026-09-05',
    'in_progress',
    90
  ),
  (
    11,
    3,
    'Improve data-quality checks',
    'Add validation rules to all quarterly datasets',
    '2026-11-30',
    'not_started',
    15
  ),
  (
    3,
    3,
    'Improve manager coaching cadence',
    'Hold structured monthly coaching sessions with direct reports',
    '2026-12-31',
    'in_progress',
    70
  ),
  (
    3,
    3,
    'Complete leadership workshop',
    'Complete the leadership development workshop',
    '2026-11-30',
    'in_progress',
    35
  ),
  (
    3,
    3,
    'Quarterly team development review',
    'Complete the quarterly development review',
    '2026-09-30',
    'completed',
    100
  );


INSERT INTO
  pdps (
    employee_id,
    manager_id,
    participant_id,
    summary,
    status
  )
VALUES
  (
    4,
    3,
    10,
    'Focus on deepening PHP and improving deadline reliability',
    'draft'
  ),
  (
    5,
    3,
    11,
    'Prepare for greater technical leadership and mentoring responsibility',
    'agreed'
  ),
  (
    6,
    3,
    12,
    'Strengthen automated testing and release-risk planning',
    'agreed'
  ),
  (
    8,
    3,
    14,
    'Continue developing technical leadership and presentation capability',
    'agreed'
  ),
  (
    9,
    3,
    15,
    'Build stronger research documentation and facilitation skills',
    'agreed'
  ),
  (
    10,
    3,
    16,
    'Improve monitoring ownership and incident-management consistency',
    'agreed'
  ),
  (
    11,
    3,
    17,
    'Develop presentation confidence and stakeholder storytelling',
    'draft'
  ),
  (
    3,
    3,
    NULL,
    'Continue strengthening coaching and leadership capability',
    'agreed'
  );


INSERT INTO
  pdp_actions (
    pdp_id,
    title,
    description,
    skill_id,
    due_date,
    status,
    progress_pct
  )
VALUES
  (
    1,
    'Complete PHP OOP course',
    'Finish an online OOP course and build one sample module',
    1,
    '2026-10-31',
    'in_progress',
    40
  ),
  (
    1,
    'Present at team knowledge session',
    'Deliver one 15-minute session to the team',
    6,
    '2026-09-30',
    'not_started',
    0
  ),
  (
    2,
    'Complete advanced API design course',
    'Complete the course and apply two patterns to the v2 API',
    8,
    '2026-09-25',
    'in_progress',
    70
  ),
  (
    2,
    'Run junior developer pairing sessions',
    'Complete six planned mentoring sessions',
    6,
    '2026-08-15',
    'completed',
    100
  ),
  (
    3,
    'Expand automated regression coverage',
    'Automate twenty additional critical-path scenarios',
    9,
    '2026-10-15',
    'in_progress',
    55
  ),
  (
    3,
    'Create release risk checklist',
    'Pilot the checklist across two releases',
    7,
    '2026-09-20',
    'in_progress',
    20
  ),
  (
    4,
    'Present architecture roadmap',
    'Deliver the Q4 architecture roadmap to engineering leadership',
    6,
    '2026-09-10',
    'in_progress',
    90
  ),
  (
    5,
    'Document usability research findings',
    'Create a reusable research report template',
    12,
    '2026-09-30',
    'in_progress',
    65
  ),
  (
    5,
    'Facilitate accessibility workshop',
    'Run one practical accessibility workshop for the product team',
    6,
    '2026-08-12',
    'completed',
    100
  ),
  (
    6,
    'Build monitoring runbooks',
    'Create runbooks for the ten highest-priority alerts',
    16,
    '2026-08-28',
    'in_progress',
    30
  ),
  (
    6,
    'Complete incident-management simulation',
    'Lead one tabletop production-incident exercise',
    7,
    '2026-09-18',
    'not_started',
    0
  ),
  (
    7,
    'Present monthly insights',
    'Deliver two monthly data presentations to non-technical stakeholders',
    6,
    '2026-09-05',
    'in_progress',
    80
  ),
  (
    8,
    'Complete leadership workshop',
    'Complete the leadership development workshop and document three applied coaching practices',
    NULL,
    '2026-11-30',
    'in_progress',
    35
  ),
  (
    8,
    'Apply coaching framework',
    'Use the GROW framework in four documented one-to-one sessions',
    NULL,
    '2026-07-31',
    'completed',
    100
  );


INSERT INTO
  action_updates (
    action_id,
    author_id,
    note,
    new_status,
    created_at
  )
VALUES
  (
    1,
    4,
    'Completed the inheritance and interfaces section and started the sample module.',
    'in_progress',
    '2026-08-05 10:00:00'
  ),
  (
    3,
    5,
    'Applied the first API pattern to the authentication endpoints.',
    'in_progress',
    '2026-08-07 15:30:00'
  ),
  (
    4,
    3,
    'All six mentoring sessions were completed with positive feedback.',
    'completed',
    '2026-08-15 16:00:00'
  ),
  (
    5,
    6,
    'Added eight new automated checkout and login scenarios.',
    'in_progress',
    '2026-08-09 11:20:00'
  ),
  (
    9,
    9,
    'Workshop completed with twelve attendees from design and engineering.',
    'completed',
    '2026-08-12 14:30:00'
  ),
  (
    10,
    10,
    'Drafted runbooks for database latency, disk usage and failed deployments.',
    'in_progress',
    '2026-08-14 09:15:00'
  ),
  (
    12,
    11,
    'First monthly presentation delivered to the product team.',
    'in_progress',
    '2026-08-11 13:00:00'
  ),
  (
    14,
    3,
    'Four coaching sessions have now used the agreed framework.',
    'completed',
    '2026-08-01 17:00:00'
  );


INSERT INTO
  pips (
    employee_id,
    manager_id,
    hr_owner_id,
    reason,
    start_date,
    end_date,
    status
  )
VALUES
  (
    4,
    3,
    1,
    'Repeated missed delivery commitments',
    '2026-08-01',
    '2026-10-31',
    'active'
  ),
  (
    10,
    3,
    1,
    'Inconsistent incident follow-up and incomplete production monitoring documentation',
    '2026-06-01',
    '2026-09-30',
    'extended'
  ),
  (
    6,
    3,
    1,
    'Missed regression-planning deadlines during two consecutive releases',
    '2025-10-01',
    '2025-12-15',
    'successful'
  );


UPDATE pips
SET
  outcome_note = CONCAT(
    'The plan was extended by four weeks to verify that incident ',
    'documentation and alert ownership remain consistent.'
  )
WHERE
  id = 2;


UPDATE pips
SET
  outcome_note = CONCAT(
    'All objectives were met and release-planning reliability improved ',
    'across the final two release cycles.'
  )
WHERE
  id = 3;


INSERT INTO
  pip_objectives (
    pip_id,
    objective,
    success_criteria,
    due_date,
    status
  )
VALUES
  (
    1,
    'Meet 90% of sprint commitments',
    'Complete at least 90% of committed sprint work for two consecutive sprints',
    '2026-09-15',
    'partially_met'
  ),
  (
    1,
    'Communicate blockers within one working day',
    'All blockers are communicated to the manager within one working day',
    '2026-09-01',
    'not_met'
  ),
  (
    2,
    'Complete incident reports within two working days',
    'Every production incident has a complete report within two working days for eight consecutive weeks',
    '2026-09-15',
    'partially_met'
  ),
  (
    2,
    'Assign ownership to all critical alerts',
    'Every critical alert has a named owner and linked runbook',
    '2026-08-31',
    'partially_met'
  ),
  (
    2,
    'Lead one incident simulation',
    'Plan, lead and document one cross-team incident simulation',
    '2026-09-20',
    'not_met'
  ),
  (
    3,
    'Submit release test plans on time',
    'Submit complete test plans at least five working days before three consecutive releases',
    '2025-11-30',
    'met'
  ),
  (
    3,
    'Report blockers during daily stand-up',
    'Raise every testing blocker on the day it is discovered',
    '2025-11-30',
    'met'
  );


INSERT INTO
  pip_checkins (pip_id, checkin_date, author_id, notes)
VALUES
  (
    1,
    '2026-08-08',
    3,
    'Discussed sprint planning and blocker escalation.'
  ),
  (
    1,
    '2026-08-15',
    3,
    'Delivery improved this sprint, but one blocker was still raised late.'
  ),
  (
    2,
    '2026-06-15',
    3,
    'Reviewed the first incident report and agreed on a standard template.'
  ),
  (
    2,
    '2026-07-01',
    3,
    'Five critical alerts now have named owners and draft runbooks.'
  ),
  (
    2,
    '2026-07-22',
    3,
    'A delayed incident report triggered an extension of the plan.'
  ),
  (
    2,
    '2026-08-12',
    3,
    'Documentation quality improved; the incident simulation still needs to be scheduled.'
  ),
  (
    3,
    '2025-10-15',
    3,
    'The next release test plan was submitted on time.'
  ),
  (
    3,
    '2025-11-05',
    3,
    'Blocker communication improved and no planning deadlines were missed.'
  ),
  (
    3,
    '2025-12-10',
    3,
    'All objectives were met across the final review period.'
  );


INSERT INTO
  login_attempts (email, ip_address, success, attempted_at)
VALUES
  (
    'kavindu@demo.lk',
    '127.0.0.1',
    1,
    '2026-08-10 08:55:00'
  ),
  (
    'kavindu@demo.lk',
    '127.0.0.1',
    0,
    '2026-08-11 09:01:00'
  ),
  (
    'kavindu@demo.lk',
    '127.0.0.1',
    1,
    '2026-08-11 09:02:00'
  ),
  (
    'hr@demo.lk',
    '127.0.0.1',
    1,
    '2026-08-12 10:30:00'
  );


INSERT INTO
  notification_reads (user_id, notification_key, read_at)
VALUES
  (3, 'review-7', '2026-08-12 09:00:00'),
  (3, 'review-6', '2026-08-14 15:30:00');


INSERT INTO
  audit_log (
    user_id,
    action,
    entity_type,
    entity_id,
    detail,
    ip_address,
    created_at
  )
VALUES
  (
    3,
    'VIEW_MANAGER_DASHBOARD',
    'dashboard',
    NULL,
    'Opened manager dashboard',
    '127.0.0.1',
    '2026-08-03 08:45:00'
  ),
  (
    3,
    'SUBMIT_MANAGER_REVIEW',
    'review_participant',
    14,
    'Submitted manager review for Sahan de Alwis',
    '127.0.0.1',
    '2026-08-03 10:30:00'
  ),
  (
    3,
    'SUBMIT_MANAGER_REVIEW',
    'review_participant',
    11,
    'Submitted manager review for Amaya Rathnayake',
    '127.0.0.1',
    '2026-08-04 14:00:00'
  ),
  (
    3,
    'SUBMIT_MANAGER_REVIEW',
    'review_participant',
    16,
    'Submitted manager review for Farah Iqbal',
    '127.0.0.1',
    '2026-08-05 11:20:00'
  ),
  (
    3,
    'UPDATE_GOAL',
    'goal',
    4,
    'Updated goal progress to 85%',
    '127.0.0.1',
    '2026-08-07 16:00:00'
  ),
  (
    3,
    'ADD_PIP_CHECKIN',
    'pip',
    1,
    'Recorded weekly PIP check-in',
    '127.0.0.1',
    '2026-08-08 15:00:00'
  ),
  (
    3,
    'APPROVE_PEER',
    'peer_nomination',
    11,
    'Approved peer nomination',
    '127.0.0.1',
    '2026-08-10 10:00:00'
  ),
  (
    3,
    'MARK_NOTIFICATIONS_READ',
    'notification',
    NULL,
    'Marked two notifications as read',
    '127.0.0.1',
    '2026-08-14 15:30:00'
  );

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
