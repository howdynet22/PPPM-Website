-- Apply once after 004_peer_nomination_workflow.sql, in the selected database.
-- Adds the two permissions the HR and administrator workspaces need. Existing
-- permissions are reused everywhere else, so no role loses access.

INSERT IGNORE INTO permissions(permission_code,description) VALUES
 ('hr.cases','Resolve escalated peer-nomination decisions'),
 ('admin.audit','Read the audit log and sign-in security records');

-- hr.cases follows the documented boundary: senior HR and HR partners hold it,
-- HR coordinators do not. admin.audit is administrator-only.
INSERT IGNORE INTO role_permissions(role_code,permission_id)
 SELECT r.role_code,p.id FROM roles r CROSS JOIN permissions p
 WHERE (p.permission_code='hr.cases' AND r.role_code IN ('hr','hr_partner','admin'))
    OR (p.permission_code='admin.audit' AND r.role_code='admin');
