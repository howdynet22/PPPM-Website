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
    role_code      VARCHAR(30) PRIMARY KEY,
    display_name   VARCHAR(60) NOT NULL,
    dashboard_path VARCHAR(120) NOT NULL,
    is_active      TINYINT(1) NOT NULL DEFAULT 1
);

CREATE TABLE permissions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    permission_code VARCHAR(80) NOT NULL UNIQUE,
    description     VARCHAR(255),
    is_active       TINYINT(1) NOT NULL DEFAULT 1
);

CREATE TABLE role_permissions (
    role_code     VARCHAR(30) NOT NULL,
    permission_id INT NOT NULL,
    PRIMARY KEY (role_code, permission_id),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_code) REFERENCES roles(role_code),
    CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id)
);

INSERT INTO roles (role_code, display_name, dashboard_path) VALUES
('admin','System Administrator','admin-dashboard.html'),
('hr','HR','hr-dashboard.html'),
('manager','Manager','manager-dashboard.html'),
('employee','Employee','employee-dashboard.html'),
('leadership','Leadership','admin-dashboard.html');

INSERT INTO permissions (permission_code, description) VALUES
('password.change','Change own password'),
('manager.dashboard','View manager dashboard and team data'),
('manager.reviews','Manage manager reviews and peer nominations'),
('manager.goals','Manage employee goals and PDPs'),
('manager.pips','Manage employee PIPs'),
('manager.reports','View manager reports and insights'),
('employee.dashboard','View employee dashboard'),
('hr.dashboard','View HR dashboard'),
('admin.dashboard','View administrator dashboard'),
('admin.users','Manage users and account status'),
('admin.roles','Manage roles and permissions'),
('hr.reports','View HR reports');

INSERT INTO role_permissions (role_code, permission_id)
SELECT 'admin', id FROM permissions;
INSERT INTO role_permissions (role_code, permission_id)
SELECT 'hr', id FROM permissions WHERE permission_code IN ('password.change','hr.dashboard','hr.reports');
INSERT INTO role_permissions (role_code, permission_id)
SELECT 'manager', id FROM permissions WHERE permission_code IN ('password.change','manager.dashboard','manager.reviews','manager.goals','manager.pips','manager.reports');
INSERT INTO role_permissions (role_code, permission_id)
SELECT 'employee', id FROM permissions WHERE permission_code IN ('password.change','employee.dashboard');
INSERT INTO role_permissions (role_code, permission_id)
SELECT 'leadership', id FROM permissions WHERE permission_code IN ('password.change','admin.dashboard','hr.reports');

CREATE TABLE users (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    emp_code        VARCHAR(20)  NOT NULL UNIQUE,
    full_name       VARCHAR(120) NOT NULL,
    email           VARCHAR(120) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    role            VARCHAR(30) NOT NULL DEFAULT 'employee',
    job_title       VARCHAR(100),
    department      VARCHAR(80),
    manager_id      INT NULL,
    date_joined     DATE,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_manager FOREIGN KEY (manager_id) REFERENCES users(id),
    CONSTRAINT fk_user_role FOREIGN KEY (role) REFERENCES roles(role_code)
);
-- NOTE: "manager" is both a role AND a relationship. The role controls what
-- menus you see; manager_id controls WHOSE data you can see. A manager can
-- only ever open reviews of people whose manager_id = their own id.

-- Every sensitive action is written here. This is how you prove to HR/law
-- that nobody snooped. Marks are usually given for having this.
CREATE TABLE audit_log (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NULL,
    action      VARCHAR(60)  NOT NULL,   -- e.g. 'VIEW_REVIEW','SUBMIT_PEER','RELEASE_CYCLE'
    entity_type VARCHAR(40),             -- e.g. 'review_participant'
    entity_id   INT,
    detail      VARCHAR(255),
    ip_address  VARCHAR(45),
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ============================================================
-- SECTION 2: WHAT PEOPLE ARE RATED ON
-- ============================================================

CREATE TABLE competencies (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(80) NOT NULL,
    description VARCHAR(255),
    is_active   TINYINT(1) DEFAULT 1
);

-- Skills are separate from competencies:
-- competency = behaviour rated in a review (Communication)
-- skill      = capability you can be missing (Laravel, Excel, Negotiation)
CREATE TABLE skills (
    id       INT AUTO_INCREMENT PRIMARY KEY,
    name     VARCHAR(80) NOT NULL,
    category VARCHAR(60)
);

-- The level a job SHOULD have -> used to calculate skill gaps
CREATE TABLE role_skill_requirements (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    job_title      VARCHAR(100) NOT NULL,
    skill_id       INT NOT NULL,
    required_level TINYINT NOT NULL CHECK (required_level BETWEEN 1 AND 5),
    CONSTRAINT fk_rsr_skill FOREIGN KEY (skill_id) REFERENCES skills(id),
    UNIQUE KEY uq_role_skill (job_title, skill_id)
);

-- The level a PERSON actually has
CREATE TABLE employee_skills (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    employee_id   INT NOT NULL,
    skill_id      INT NOT NULL,
    current_level TINYINT NOT NULL CHECK (current_level BETWEEN 1 AND 5),
    assessed_by   INT,
    assessed_at   DATE,
    CONSTRAINT fk_es_emp   FOREIGN KEY (employee_id) REFERENCES users(id),
    CONSTRAINT fk_es_skill FOREIGN KEY (skill_id)    REFERENCES skills(id),
    UNIQUE KEY uq_emp_skill (employee_id, skill_id)
);

-- ============================================================
-- SECTION 3: THE REVIEW CYCLE
-- ============================================================

CREATE TABLE review_cycles (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    name                 VARCHAR(100) NOT NULL,   -- 'H1 2026 Review'
    period_start         DATE NOT NULL,           -- period being judged
    period_end           DATE NOT NULL,
    self_deadline        DATE,
    peer_deadline        DATE,
    manager_deadline     DATE,
    status               ENUM('draft','open','peer_review','manager_review',
                              'calibration','released','closed')
                         NOT NULL DEFAULT 'draft',
    min_peers            TINYINT DEFAULT 3,       -- anonymity threshold
    created_by           INT,
    released_at          DATETIME NULL,
    CONSTRAINT fk_cycle_creator FOREIGN KEY (created_by) REFERENCES users(id)
);
-- The status column IS the workflow. Nothing is visible to the employee
-- until status = 'released'. This single rule is the heart of the system.

-- One row per employee taking part in one cycle
CREATE TABLE review_participants (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    cycle_id        INT NOT NULL,
    employee_id     INT NOT NULL,
    manager_id      INT NOT NULL,        -- snapshot: manager at the time
    status          ENUM('not_started','self_submitted','peers_complete',
                         'manager_submitted','released')
                    NOT NULL DEFAULT 'not_started',
    final_rating    DECIMAL(3,2) NULL,   -- e.g. 3.75
    manager_summary TEXT NULL,
    released_at     DATETIME NULL,
    CONSTRAINT fk_rp_cycle FOREIGN KEY (cycle_id)    REFERENCES review_cycles(id),
    CONSTRAINT fk_rp_emp   FOREIGN KEY (employee_id) REFERENCES users(id),
    CONSTRAINT fk_rp_mgr   FOREIGN KEY (manager_id)  REFERENCES users(id),
    UNIQUE KEY uq_cycle_emp (cycle_id, employee_id)
);

-- Employee suggests peers, manager approves them (stops people picking
-- only their best friends)
CREATE TABLE peer_nominations (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    participant_id   INT NOT NULL,
    peer_id          INT NOT NULL,
    status           ENUM('pending','approved','rejected') DEFAULT 'pending',
    nominated_by     INT,
    decided_by       INT,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pn_part FOREIGN KEY (participant_id) REFERENCES review_participants(id),
    CONSTRAINT fk_pn_peer FOREIGN KEY (peer_id)        REFERENCES users(id),
    UNIQUE KEY uq_part_peer (participant_id, peer_id)
);

-- One "form to fill in". Type tells you which of the 360 directions it is.
CREATE TABLE feedback_requests (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    participant_id INT NOT NULL,
    respondent_id  INT NOT NULL,        -- WHO is filling it in
    type           ENUM('self','manager','peer') NOT NULL,
    status         ENUM('pending','submitted') DEFAULT 'pending',
    submitted_at   DATETIME NULL,
    CONSTRAINT fk_fr_part FOREIGN KEY (participant_id) REFERENCES review_participants(id),
    CONSTRAINT fk_fr_resp FOREIGN KEY (respondent_id)  REFERENCES users(id),
    UNIQUE KEY uq_part_resp_type (participant_id, respondent_id, type)
);
-- ANONYMITY RULE:
-- respondent_id must exist (to prevent double submission + for HR audit)
-- but NO query that serves an employee may ever SELECT this column.
-- Always read peer feedback through the view v_peer_feedback below.

CREATE TABLE feedback_ratings (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    request_id    INT NOT NULL,
    competency_id INT NOT NULL,
    score         TINYINT NOT NULL CHECK (score BETWEEN 1 AND 5),
    comment       TEXT,
    CONSTRAINT fk_frt_req  FOREIGN KEY (request_id)    REFERENCES feedback_requests(id),
    CONSTRAINT fk_frt_comp FOREIGN KEY (competency_id) REFERENCES competencies(id),
    UNIQUE KEY uq_req_comp (request_id, competency_id)
);

CREATE TABLE feedback_summary (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    request_id     INT NOT NULL UNIQUE,
    strengths      TEXT,
    improvements   TEXT,
    CONSTRAINT fk_fs_req FOREIGN KEY (request_id) REFERENCES feedback_requests(id)
);

-- ============================================================
-- SECTION 4: GOALS
-- ============================================================

CREATE TABLE goals (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    employee_id  INT NOT NULL,
    manager_id   INT NOT NULL,
    title        VARCHAR(200) NOT NULL,
    description  TEXT NOT NULL,
    due_date     DATE NOT NULL,
    status       ENUM('not_started','in_progress','completed','missed') DEFAULT 'not_started',
    progress_pct TINYINT NOT NULL DEFAULT 0 CHECK (progress_pct BETWEEN 0 AND 100),
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_goal_emp FOREIGN KEY (employee_id) REFERENCES users(id),
    CONSTRAINT fk_goal_mgr FOREIGN KEY (manager_id) REFERENCES users(id)
);

-- ============================================================
-- SECTION 4: PDP  (the "grow" plan)
-- ============================================================

CREATE TABLE pdps (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    employee_id    INT NOT NULL,
    manager_id     INT NOT NULL,
    participant_id INT NULL,             -- which cycle it came out of
    summary        TEXT,
    status         ENUM('draft','agreed','completed','cancelled') DEFAULT 'draft',
    agreed_at      DATETIME NULL,
    CONSTRAINT fk_pdp_emp  FOREIGN KEY (employee_id)    REFERENCES users(id),
    CONSTRAINT fk_pdp_mgr  FOREIGN KEY (manager_id)     REFERENCES users(id),
    CONSTRAINT fk_pdp_part FOREIGN KEY (participant_id) REFERENCES review_participants(id)
);

CREATE TABLE pdp_actions (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    pdp_id       INT NOT NULL,
    title        VARCHAR(200) NOT NULL,
    description  TEXT,
    skill_id     INT NULL,               -- links the action to a skill gap
    due_date     DATE NOT NULL,
    status       ENUM('not_started','in_progress','completed','overdue','cancelled')
                 DEFAULT 'not_started',
    progress_pct TINYINT DEFAULT 0,
    completed_at DATETIME NULL,
    CONSTRAINT fk_pa_pdp   FOREIGN KEY (pdp_id)   REFERENCES pdps(id),
    CONSTRAINT fk_pa_skill FOREIGN KEY (skill_id) REFERENCES skills(id)
);

-- Progress notes: this is what stops actions being "forgotten"
CREATE TABLE action_updates (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    action_id  INT NOT NULL,
    author_id  INT NOT NULL,
    note       TEXT,
    new_status VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_au_action FOREIGN KEY (action_id) REFERENCES pdp_actions(id),
    CONSTRAINT fk_au_author FOREIGN KEY (author_id) REFERENCES users(id)
);

-- ============================================================
-- SECTION 5: PIP  (the "fix it" plan — HR supervised)
-- ============================================================

CREATE TABLE pips (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    employee_id  INT NOT NULL,
    manager_id   INT NOT NULL,
    hr_owner_id  INT NOT NULL,           -- HR must own every PIP
    reason       TEXT NOT NULL,
    start_date   DATE NOT NULL,
    end_date     DATE NOT NULL,
    status       ENUM('draft','active','extended','successful','unsuccessful','closed')
                 DEFAULT 'draft',
    outcome_note TEXT,
    CONSTRAINT fk_pip_emp FOREIGN KEY (employee_id) REFERENCES users(id),
    CONSTRAINT fk_pip_mgr FOREIGN KEY (manager_id)  REFERENCES users(id),
    CONSTRAINT fk_pip_hr  FOREIGN KEY (hr_owner_id) REFERENCES users(id)
);

CREATE TABLE pip_objectives (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    pip_id           INT NOT NULL,
    objective        VARCHAR(255) NOT NULL,
    success_criteria TEXT NOT NULL,      -- must be measurable
    due_date         DATE,
    status           ENUM('not_met','partially_met','met') DEFAULT 'not_met',
    CONSTRAINT fk_po_pip FOREIGN KEY (pip_id) REFERENCES pips(id)
);

CREATE TABLE pip_checkins (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    pip_id       INT NOT NULL,
    checkin_date DATE NOT NULL,
    author_id    INT NOT NULL,
    notes        TEXT NOT NULL,
    CONSTRAINT fk_pc_pip    FOREIGN KEY (pip_id)    REFERENCES pips(id),
    CONSTRAINT fk_pc_author FOREIGN KEY (author_id) REFERENCES users(id)
);

-- ============================================================
-- SECTION 6: VIEWS  (do your safe reading through these)
-- ============================================================

-- Anonymised peer feedback. respondent_id is deliberately absent.
CREATE OR REPLACE VIEW v_peer_feedback AS
SELECT  fr.participant_id,
        fr.id            AS request_id,
        c.name           AS competency,
        frt.score,
        frt.comment
FROM feedback_requests fr
JOIN feedback_ratings  frt ON frt.request_id = fr.id
JOIN competencies      c   ON c.id = frt.competency_id
WHERE fr.type = 'peer' AND fr.status = 'submitted';

-- Average score per competency, split by feedback direction.
-- This is what you draw the 360 chart from.
CREATE OR REPLACE VIEW v_360_summary AS
SELECT  fr.participant_id,
        c.name          AS competency,
        fr.type,
        ROUND(AVG(frt.score),2) AS avg_score,
        COUNT(*)        AS responses
FROM feedback_requests fr
JOIN feedback_ratings  frt ON frt.request_id = fr.id
JOIN competencies      c   ON c.id = frt.competency_id
WHERE fr.status = 'submitted'
GROUP BY fr.participant_id, c.name, fr.type;

-- Skill gap report for management
CREATE OR REPLACE VIEW v_skill_gaps AS
SELECT  u.id AS employee_id, u.full_name, u.department, u.job_title,
        s.name AS skill,
        r.required_level,
        COALESCE(es.current_level,0) AS current_level,
        r.required_level - COALESCE(es.current_level,0) AS gap
FROM users u
JOIN role_skill_requirements r  ON r.job_title = u.job_title
JOIN skills s                   ON s.id = r.skill_id
LEFT JOIN employee_skills es    ON es.employee_id = u.id AND es.skill_id = s.id
WHERE u.is_active = 1;

-- ============================================================
-- SECTION 7: SAMPLE DATA
--  All passwords below are the plain text 'password123' hashed
--  with PHP password_hash(). Use it to log in while testing.
-- ============================================================

INSERT INTO users (emp_code, full_name, email, password_hash, role, job_title, department, manager_id, date_joined) VALUES
('E001','Sanduni Perera','hr@demo.lk',      '$2y$12$KICJrailDGtQxqre7rNAYu0l2L6E02fi9fiZPAWJusJEmBHt0uGou','hr',        'HR Manager',        'Human Resources', NULL,'2021-01-10'),
('E002','Dilan Fernando','admin@demo.lk','$2y$12$KICJrailDGtQxqre7rNAYu0l2L6E02fi9fiZPAWJusJEmBHt0uGou','admin','Chief Executive',   'Executive',       NULL,'2019-03-01'),
('E003','Kavindu Silva','manager@demo.lk',  '$2y$12$KICJrailDGtQxqre7rNAYu0l2L6E02fi9fiZPAWJusJEmBHt0uGou','manager',   'Engineering Manager','Engineering',     2,   '2020-06-15'),
('E004','Nimal Jayasuriya','nimal@demo.lk', '$2y$12$KICJrailDGtQxqre7rNAYu0l2L6E02fi9fiZPAWJusJEmBHt0uGou','employee',  'Junior Developer',  'Engineering',     3,   '2024-02-01'),
('E005','Amaya Rathnayake','amaya@demo.lk', '$2y$12$KICJrailDGtQxqre7rNAYu0l2L6E02fi9fiZPAWJusJEmBHt0uGou','employee',  'Software Engineer', 'Engineering',     3,   '2022-08-20'),
('E006','Tharindu Bandara','tharindu@demo.lk','$2y$12$KICJrailDGtQxqre7rNAYu0l2L6E02fi9fiZPAWJusJEmBHt0uGou','employee', 'QA Engineer',       'Engineering',     3,   '2023-05-05'),
('E007','Ishara Gunasekara','ishara@demo.lk','$2y$12$KICJrailDGtQxqre7rNAYu0l2L6E02fi9fiZPAWJusJEmBHt0uGou','employee',  'Software Engineer', 'Engineering',     3,   '2023-11-11');

INSERT INTO competencies (name, description) VALUES
('Communication','Clarity of written and verbal communication'),
('Technical Skill','Depth and correctness of technical work'),
('Teamwork','Collaboration and support of colleagues'),
('Ownership','Takes responsibility and follows through'),
('Reliability','Meets deadlines and commitments');

INSERT INTO skills (name, category) VALUES
('PHP','Technical'),('MySQL','Technical'),('JavaScript','Technical'),
('Git','Technical'),('Unit Testing','Technical'),
('Presentation','Soft'),('Time Management','Soft');

INSERT INTO role_skill_requirements (job_title, skill_id, required_level) VALUES
('Junior Developer',1,3),('Junior Developer',2,3),('Junior Developer',4,3),('Junior Developer',7,3),
('Software Engineer',1,4),('Software Engineer',2,4),('Software Engineer',3,4),('Software Engineer',5,4);

INSERT INTO employee_skills (employee_id, skill_id, current_level, assessed_by, assessed_at) VALUES
(4,1,2,3,'2026-07-05'),(4,2,3,3,'2026-07-05'),(4,4,2,3,'2026-07-05'),(4,7,1,3,'2026-07-05'),
(5,1,4,3,'2026-07-05'),(5,2,3,3,'2026-07-05'),(5,3,4,3,'2026-07-05'),(5,5,2,3,'2026-07-05');

INSERT INTO review_cycles (name, period_start, period_end, self_deadline, peer_deadline, manager_deadline, status, created_by)
VALUES ('H1 2026 Review','2026-01-01','2026-06-30','2026-07-10','2026-07-17','2026-07-24','manager_review',1);

INSERT INTO review_participants (cycle_id, employee_id, manager_id, status) VALUES
(1,4,3,'peers_complete'),
(1,5,3,'self_submitted'),
(1,6,3,'not_started'),
(1,7,3,'not_started');

INSERT INTO peer_nominations (participant_id, peer_id, status, nominated_by, decided_by) VALUES
(1,5,'approved',4,3),(1,6,'approved',4,3),(1,7,'approved',4,3);

-- Nimal's self review
INSERT INTO feedback_requests (participant_id, respondent_id, type, status, submitted_at) VALUES
(1,4,'self','submitted','2026-07-08 10:00:00'),
(1,5,'peer','submitted','2026-07-15 09:00:00'),
(1,6,'peer','submitted','2026-07-16 14:00:00'),
(1,7,'peer','submitted','2026-07-16 17:30:00'),
(1,3,'manager','pending',NULL);

INSERT INTO feedback_ratings (request_id, competency_id, score, comment) VALUES
(1,1,4,'I explain my work clearly in standups'),
(1,2,3,'Still learning the framework'),
(1,3,4,'I help others when asked'),
(1,4,4,'I finish what I start'),
(1,5,3,'Missed two deadlines in April'),
(2,1,3,NULL),(2,2,3,NULL),(2,3,4,NULL),(2,4,3,NULL),(2,5,3,NULL),
(3,1,4,NULL),(3,2,2,NULL),(3,3,5,NULL),(3,4,3,NULL),(3,5,2,NULL),
(4,1,3,NULL),(4,2,3,NULL),(4,3,4,NULL),(4,4,4,NULL),(4,5,3,NULL);

INSERT INTO feedback_summary (request_id, strengths, improvements) VALUES
(2,'Always willing to help with testing','Could ask for help earlier when stuck'),
(3,'Great team spirit','Code reviews take a long time to come back'),
(4,'Positive attitude','Needs to communicate blockers sooner');

INSERT INTO goals (employee_id, manager_id, title, description, due_date, status, progress_pct) VALUES
(4,3,'Improve delivery reliability','Meet sprint commitments consistently','2026-09-30','in_progress',55),
(5,3,'Lead API refactor','Complete v2 endpoints and document the changes','2026-09-20','in_progress',80),
(6,3,'Automate regression suite','Reach 80% critical-path test coverage','2026-10-15','in_progress',35),
(7,3,'Improve code review turnaround','Complete reviews within 24 hours','2026-09-15','in_progress',60),
(3,3,'Improve manager coaching cadence','Hold structured monthly coaching sessions with direct reports','2026-12-31','in_progress',70),
(3,3,'Complete leadership workshop','Complete the leadership development workshop','2026-11-30','in_progress',35),
(3,3,'Quarterly team development review','Complete the quarterly development review','2026-09-30','completed',100);

INSERT INTO pdps (employee_id, manager_id, participant_id, summary, status) VALUES
(4,3,1,'Focus on deepening PHP and improving deadline reliability','draft');

INSERT INTO pdp_actions (pdp_id, title, description, skill_id, due_date, status, progress_pct) VALUES
(1,'Complete PHP OOP course','Finish an online OOP course and build one sample module',1,'2026-10-31','in_progress',40),
(1,'Present at team knowledge session','Deliver one 15-minute session to the team',6,'2026-09-30','not_started',0);

INSERT INTO pdps (employee_id, manager_id, participant_id, summary, status) VALUES
(3,3,NULL,'Continue strengthening coaching and leadership capability','agreed');

INSERT INTO pdp_actions (pdp_id, title, description, skill_id, due_date, status, progress_pct) VALUES
(2,'Complete leadership workshop','Complete the leadership development workshop and document three applied coaching practices',NULL,'2026-11-30','in_progress',35);


INSERT INTO pips (employee_id, manager_id, hr_owner_id, reason, start_date, end_date, status) VALUES
(4,3,1,'Repeated missed delivery commitments','2026-08-01','2026-10-31','active');

INSERT INTO pip_objectives (pip_id, objective, success_criteria, due_date, status) VALUES
(1,'Meet 90% of sprint commitments','Complete at least 90% of committed sprint work for two consecutive sprints','2026-09-15','partially_met'),
(1,'Communicate blockers within one working day','All blockers are communicated to the manager within one working day','2026-09-01','not_met');

INSERT INTO pip_checkins (pip_id, checkin_date, author_id, notes) VALUES
(1,'2026-08-08',3,'Discussed sprint planning and blocker escalation.');
