-- Convert goal, PDP and PIP progress from percentages/status buttons to
-- ordered, actionable steps. Import this once after 001_organization.sql.

-- Use the database selected by the caller; never switch upgrade targets.

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

UPDATE goals
SET description = 'Automate every critical-path scenario in the approved regression plan'
WHERE title = 'Automate regression suite';

UPDATE goals
SET description = 'Profile core endpoints and bring their median response time below the agreed two-second threshold'
WHERE title = 'Reduce API response time';

UPDATE pip_objectives
SET
  objective = 'Complete agreed sprint commitments',
  success_criteria = CONCAT(
    'Confirm the sprint commitment before work starts and finish every ',
    'committed item, or document and escalate the blocker within one working day'
  )
WHERE objective = 'Meet 90% of sprint commitments';

INSERT INTO work_steps
  (goal_id, title, step_order, created_by, is_completed, completed_by, completed_at)
SELECT
  id,
  'Confirm the expected outcome and evidence with the task owner',
  1,
  manager_id,
  progress_pct > 0,
  IF(progress_pct > 0, manager_id, NULL),
  IF(progress_pct > 0, NOW(), NULL)
FROM goals;

INSERT INTO work_steps
  (goal_id, title, step_order, created_by, is_completed, completed_by, completed_at)
SELECT
  id,
  description,
  2,
  manager_id,
  status = 'completed',
  IF(status = 'completed', manager_id, NULL),
  IF(status = 'completed', NOW(), NULL)
FROM goals;

INSERT INTO work_steps
  (goal_id, title, step_order, created_by, is_completed, completed_by, completed_at)
SELECT
  id,
  'Share the completed evidence with the task owner',
  3,
  manager_id,
  status = 'completed',
  IF(status = 'completed', manager_id, NULL),
  IF(status = 'completed', NOW(), NULL)
FROM goals;

INSERT INTO work_steps
  (pdp_action_id, title, step_order, created_by, is_completed, completed_by, completed_at)
SELECT
  pa.id,
  'Confirm the learning activity and evidence to provide',
  1,
  p.manager_id,
  pa.progress_pct > 0,
  IF(pa.progress_pct > 0, p.manager_id, NULL),
  IF(pa.progress_pct > 0, NOW(), NULL)
FROM pdp_actions pa
JOIN pdps p ON p.id = pa.pdp_id;

INSERT INTO work_steps
  (pdp_action_id, title, step_order, created_by, is_completed, completed_by, completed_at)
SELECT
  pa.id,
  pa.description,
  2,
  p.manager_id,
  pa.status = 'completed',
  IF(pa.status = 'completed', p.manager_id, NULL),
  IF(pa.status = 'completed', NOW(), NULL)
FROM pdp_actions pa
JOIN pdps p ON p.id = pa.pdp_id;

INSERT INTO work_steps
  (pdp_action_id, title, step_order, created_by, is_completed, completed_by, completed_at)
SELECT
  pa.id,
  'Record the learning outcome and how it will be applied',
  3,
  p.manager_id,
  pa.status = 'completed',
  IF(pa.status = 'completed', p.manager_id, NULL),
  IF(pa.status = 'completed', NOW(), NULL)
FROM pdp_actions pa
JOIN pdps p ON p.id = pa.pdp_id;

INSERT INTO work_steps
  (pip_objective_id, title, step_order, created_by, is_completed, completed_by, completed_at)
SELECT
  po.id,
  'Confirm the required evidence and due date',
  1,
  p.manager_id,
  po.status <> 'not_met',
  IF(po.status <> 'not_met', p.manager_id, NULL),
  IF(po.status <> 'not_met', NOW(), NULL)
FROM pip_objectives po
JOIN pips p ON p.id = po.pip_id;

INSERT INTO work_steps
  (pip_objective_id, title, step_order, created_by, is_completed, completed_by, completed_at)
SELECT
  po.id,
  po.success_criteria,
  2,
  p.manager_id,
  po.status = 'met',
  IF(po.status = 'met', p.manager_id, NULL),
  IF(po.status = 'met', NOW(), NULL)
FROM pip_objectives po
JOIN pips p ON p.id = po.pip_id;

INSERT INTO work_steps
  (pip_objective_id, title, step_order, created_by, is_completed, completed_by, completed_at)
SELECT
  po.id,
  'Review the evidence with the manager and HR owner',
  3,
  p.manager_id,
  po.status = 'met',
  IF(po.status = 'met', p.manager_id, NULL),
  IF(po.status = 'met', NOW(), NULL)
FROM pip_objectives po
JOIN pips p ON p.id = po.pip_id;

ALTER TABLE goals DROP COLUMN progress_pct;
ALTER TABLE pdp_actions DROP COLUMN progress_pct;
