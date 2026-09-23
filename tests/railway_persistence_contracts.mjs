import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const read=(name)=>readFileSync(new URL(`../${name}`,import.meta.url),'utf8');
const config=read('config.php');
const admin=read('admin.php');
const organization=read('organization.php');
const auth=read('Js/auth.js');
const headers=read('.htaccess');
const hr=read('hr.php');

const cases=[
  ['application timezone defaults to Asia/Colombo',/PPPM_APP_TIMEZONE[\s\S]*Asia\/Colombo/.test(config)],
  ['MySQL session uses the application offset',/SET time_zone/.test(config)&&/DB_SESSION_TIMEZONE/.test(config)],
  ['open sessions refresh account identity from me',/async function refreshIdentity[\s\S]*request\("me"\)/.test(auth)],
  ['access changes redirect users away from unavailable workspaces',/pageAllowedFor[\s\S]*dashboard_path/.test(auth)],
  ['administrator account saves are transactional',/case "admin_user_save"[\s\S]*beginTransaction\(\)[\s\S]*commit\(\)/.test(admin)],
  ['administrator reads the committed user back before success',/could not be verified after saving/.test(admin)&&/"user" => \$saved\[0\]/.test(admin)],
  ['unchanged managers are not rewritten',/\$managerId !== \$currentManagerId/.test(admin)],
  ['same-day manager corrections replace zero-day rows',/Replacing a relationship created today[\s\S]*DELETE FROM reporting_relationships/.test(organization)],
  ['No manager ends the persisted primary relationship',/end_primary_manager/.test(admin)&&/REMOVE_PRIMARY_MANAGER/.test(organization)],
  ['missing department and team updates return not found',/Department not found/.test(organization)&&/Team not found/.test(organization)],
  ['missing competency updates return not found',/Competency not found/.test(hr)],
  ['deployed JavaScript and CSS are not served stale',/html\|php\|js\|css/.test(headers)&&/Cache-Control "no-store"/.test(headers)],
];

for(const [name,ok] of cases)assert.ok(ok,name);
console.log(`PASS: ${cases.length} Railway persistence and synchronization contracts.`);
