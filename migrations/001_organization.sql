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
INSERT INTO departments(department_code,department_name)
 SELECT CONCAT('DEP',MIN(id)),COALESCE(NULLIF(TRIM(department),''),'Unassigned') FROM users
 GROUP BY COALESCE(NULLIF(TRIM(department),''),'Unassigned');
ALTER TABLE users ADD department_id INT NULL, ADD team_id INT NULL;
UPDATE users u JOIN departments d ON d.department_name=COALESCE(NULLIF(TRIM(u.department),''),'Unassigned') SET u.department_id=d.id;
ALTER TABLE users MODIFY department_id INT NOT NULL,
 ADD CONSTRAINT fk_user_department FOREIGN KEY(department_id) REFERENCES departments(id),
 ADD CONSTRAINT fk_user_team_department FOREIGN KEY(team_id,department_id) REFERENCES teams(id,department_id);
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
INSERT INTO reporting_relationships(employee_id,reports_to_employee_id,effective_from,created_by,change_reason)
 SELECT id,manager_id,COALESCE(date_joined,CURRENT_DATE),id,
        'Imported legacy manager; effective date inferred from employee join date'
 FROM users WHERE manager_id IS NOT NULL;
DROP VIEW IF EXISTS v_skill_gaps;
ALTER TABLE users DROP FOREIGN KEY fk_user_manager, DROP COLUMN manager_id, DROP COLUMN department;
CREATE VIEW v_skill_gaps AS
 SELECT u.id AS employee_id,u.full_name,d.department_name AS department,u.job_title,
        s.name AS skill,r.required_level,COALESCE(es.current_level,0) AS current_level,
        r.required_level-COALESCE(es.current_level,0) AS gap
 FROM users u
 JOIN departments d ON d.id=u.department_id
 JOIN role_skill_requirements r ON r.job_title=u.job_title
 JOIN skills s ON s.id=r.skill_id
 LEFT JOIN employee_skills es ON es.employee_id=u.id AND es.skill_id=s.id
 WHERE u.is_active=1;
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
