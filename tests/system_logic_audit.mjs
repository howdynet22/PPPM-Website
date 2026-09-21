import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const read=(name)=>readFileSync(new URL(`../${name}`,import.meta.url),'utf8');
const api=read('api.php'),hr=read('hr.php'),workspace=read('workspaces.php');
const workflow=read('review-workflow.php'),schema=read('schema.sql'),migration=read('migrations/010_system_logic_audit.sql');
const managerHtml=read('manager-dashboard.html'),managerJs=read('Js/manager-db.js');

const cases=[
  ['late self recovery',/allowedStages = .*\['open'\]/s.test(workflow)&&/late'.*date/s.test(workflow)],
  ['peer response remains open in manager review',/\['peer_review','manager_review'\]/.test(workflow)],
  ['peer waiver gates manager submission',/waive_peer/.test(api)&&/required peer responses/.test(api)],
  ['late escalation has revised deadline route',/responseDeadline/.test(hr)&&/revised peer-response deadline/.test(hr)],
  ['inactive manager blocks release',/inactiveManagers/.test(workflow)&&/reassign inactive managers/.test(hr)],
  ['manager change reassigns active records',/active_record_reassignments/.test(read('organization.php'))],
  ['new cycles require active managers',/JOIN users m ON m.id=apr.reports_to_employee_id AND m.is_active=1/.test(workflow)],
  ['HR Coordinator review detail is permission-gated',/hr_can\(\$user,'hr\.reviews\.read'\)/.test(hr)],
  ['Leadership manager workspace is permission-based',/data-workspace="manager"/.test(managerHtml)],
  ['cycle writes use dedicated permission',/hr\.cycles\.manage/.test(hr)&&!/case "hr_cycle_create":[\s\S]{0,180}hr\.reports/.test(hr)],
  ['late peer decision blocked server-side',/peer decision window has closed/.test(api)],
  ['zero-competency publish blocked',/Add at least one active competency/.test(workflow)],
  ['competencies are frozen per cycle',/review_cycle_competencies/.test(schema)&&/cycle_competencies/.test(workspace)],
  ['one active cycle policy',/Close or release the active cycle/.test(workflow)],
  ['draft PIP hidden from employee',/p\.status<>'draft'/.test(workspace)],
  ['HR owns PIP governance transitions',/pip_transition_allowed\('hr'/.test(hr)&&/only the HR owner/.test(api)],
  ['PDP agreement is reachable',/agree_pdp/.test(workspace)&&/agreed_by/.test(schema)],
  ['terminal feedback request states exist',/expired[\s\S]*cancelled[\s\S]*waived/.test(schema)&&/SET status='expired'/.test(api)],
  ['anonymity aggregate still uses threshold',/peerResponses.*min_peers/s.test(workspace)],
  ['manager review stale writes conflict',/review changed since you opened it/.test(api)&&/reviewVersion/.test(managerJs)],
];
for(const [name,ok] of cases) assert.ok(ok,name);
assert.match(migration,/DROP TABLE IF EXISTS feedback_summary/);
assert.doesNotMatch(schema,/'calibration'/);
console.log(`PASS: ${cases.length} system-logic regression contracts.`);
