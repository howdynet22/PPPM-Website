-- Read-only checks for the actionable work-step model.

USE perf_tracker;

-- Expected: zero. Every step must belong to exactly one supported work item.
SELECT COUNT(*) AS invalid_parent_count
FROM work_steps
WHERE
  (goal_id IS NOT NULL) +
  (pdp_action_id IS NOT NULL) +
  (pip_objective_id IS NOT NULL) <> 1;

-- Expected: zero rows. Every goal, PDP action and PIP objective needs a step.
SELECT 'goal' AS work_type, g.id
FROM goals g
LEFT JOIN work_steps ws ON ws.goal_id = g.id
GROUP BY g.id
HAVING COUNT(ws.id) = 0
UNION ALL
SELECT 'pdp_action', pa.id
FROM pdp_actions pa
LEFT JOIN work_steps ws ON ws.pdp_action_id = pa.id
GROUP BY pa.id
HAVING COUNT(ws.id) = 0
UNION ALL
SELECT 'pip_objective', po.id
FROM pip_objectives po
LEFT JOIN work_steps ws ON ws.pip_objective_id = po.id
GROUP BY po.id
HAVING COUNT(ws.id) = 0;

-- Expected: zero rows. Completed work must have every step checked off.
SELECT 'goal' AS work_type, g.id
FROM goals g
JOIN work_steps ws ON ws.goal_id = g.id
WHERE g.status = 'completed'
GROUP BY g.id
HAVING SUM(ws.is_completed) <> COUNT(ws.id)
UNION ALL
SELECT 'pdp_action', pa.id
FROM pdp_actions pa
JOIN work_steps ws ON ws.pdp_action_id = pa.id
WHERE pa.status = 'completed'
GROUP BY pa.id
HAVING SUM(ws.is_completed) <> COUNT(ws.id)
UNION ALL
SELECT 'pip_objective', po.id
FROM pip_objectives po
JOIN work_steps ws ON ws.pip_objective_id = po.id
WHERE po.status = 'met'
GROUP BY po.id
HAVING SUM(ws.is_completed) <> COUNT(ws.id);

-- Inspect step authorship. The UI and API use created_by to decide whether
-- the signed-in person may rewrite the step definition.
SELECT
  ws.id,
  COALESCE(g.employee_id, pdp.employee_id, pip.employee_id) AS assignee_id,
  ws.created_by,
  creator.full_name AS step_author,
  ws.title,
  ws.is_completed
FROM work_steps ws
JOIN users creator ON creator.id = ws.created_by
LEFT JOIN goals g ON g.id = ws.goal_id
LEFT JOIN pdp_actions pa ON pa.id = ws.pdp_action_id
LEFT JOIN pdps pdp ON pdp.id = pa.pdp_id
LEFT JOIN pip_objectives po ON po.id = ws.pip_objective_id
LEFT JOIN pips pip ON pip.id = po.pip_id
ORDER BY ws.id;
