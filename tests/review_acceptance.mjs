import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';

const base = process.env.PPPM_TEST_URL;
assert(base, 'PPPM_TEST_URL is required');
const sql = query => execFileSync('php', ['-r', 'require "config.php"; $q=db()->query($argv[1]); echo json_encode($q ? $q->fetchAll() : []);', query], {encoding:'utf8'}).trim();
const rows = query => JSON.parse(sql(query) || '[]');
const quote = value => "'" + String(value).replaceAll("'", "''") + "'";
class Client {
  cookie = ''; token = ''; user = null;
  async call(action, body, expected=200) {
    const r = await fetch(base+'/api.php?action='+action, {
      method: body === undefined ? 'GET' : 'POST',
      headers: {Cookie:this.cookie,'Content-Type':'application/json','X-CSRF-Token':this.token},
      ...(body === undefined ? {} : {body:JSON.stringify(body)})
    });
    if (r.headers.get('set-cookie')) this.cookie=r.headers.get('set-cookie').split(';')[0];
    const data=await r.json();
    if(data.csrfToken) this.token=data.csrfToken;
    assert.equal(r.status,expected,action+': '+JSON.stringify(data));
    return data;
  }
  async login(name) { this.user=(await this.call('login',{email:name+'@demo.pppm.test',password:'password123'})).user; return this; }
  async personal() {return (await this.call('workspace&scope=employee')).data;}
}
const accounts=Object.fromEntries(await Promise.all(['alex','casey','jordan','morgan','riley','taylor','sam','devon','avery','blair','quinn'].map(async name=>[name,await new Client().login(name)])));
const {alex,casey,jordan,morgan,riley,taylor,sam,devon,avery,blair,quinn}=accounts;
const log=(id)=>console.log('PASS '+id);

// T-14: workspace permission is checked server-side for every access tier.
for(const client of [alex,casey,jordan,riley,taylor,sam,devon]) {
  assert(client.user.workspaces.some(w=>w.key==='employee'));
  assert((await client.personal()).reviews.length>0);
}
await avery.call('workspace&scope=employee',undefined,403);
log('T-14');

// T-15: both endpoints of the 1-20 inclusive step-count rule.
const goal={employeeId:alex.user.id,title:'Acceptance goal',description:'Exercise the action step bounds',due:'2099-12-31'};
await casey.call('create_pdp',{...goal,steps:[]},422);
await casey.call('create_pdp',{...goal,steps:Array.from({length:21},(_,i)=>'Step '+i)},422);
const one=await casey.call('create_pdp',{...goal,steps:['Only step']});
const twenty=await casey.call('create_pdp',{...goal,title:'Acceptance twenty',steps:Array.from({length:20},(_,i)=>'Step '+i)});
assert(one.id>0&&twenty.id>0);
log('T-15');

// T-16: agreement identity/timestamp survive completion and reopening.
let personal=await alex.personal();
const plan=personal.plans.find(p=>p.actions.some(a=>Number(a.id)===Number(one.id)));
assert(plan);
sql('UPDATE pdps SET status=\'draft\',agreed_at=NULL,agreed_by=NULL WHERE id='+Number(plan.id));
await alex.call('agree_pdp',{id:Number(plan.id)});
personal=await alex.personal();
const agreed=personal.plans.find(p=>Number(p.id)===Number(plan.id));
assert.equal(agreed.status,'agreed');
assert(agreed.agreed_at);
const agreedAt=agreed.agreed_at;
let step=(await alex.call('work_item&type=pdp_action&id='+one.id)).item.steps[0];
await alex.call('set_step_status',{id:step.id,version:step.version,status:'completed'});
step=(await alex.call('work_item&type=pdp_action&id='+one.id)).item.steps[0];
await alex.call('set_step_status',{id:step.id,version:step.version,status:'in_progress'});
personal=await alex.personal();
const reopened=personal.plans.find(p=>Number(p.id)===Number(plan.id));
assert.equal(reopened.status,'agreed');
assert.equal(reopened.agreed_at,agreedAt);
assert.equal(Number(rows('SELECT agreed_by FROM pdps WHERE id='+Number(plan.id))[0].agreed_by),Number(alex.user.id));
log('T-16');

// T-17-19: rejection boundary, one escalation, and independent HR resolutions.
const option=(await alex.personal()).nominationOptions.find(c=>c.peers.some(p=>Number(p.id)===Number(blair.user.id)));
assert(option,'Active nomination cycle required');
const nomination=async peer=>alex.call('create_peer_nomination',{
  participantId:Number(option.participantId),peerId:Number(peer.user.id),
  sharedWork:'Acceptance test delivery',
  collaborationDetails:'We worked together directly on the release and verified each acceptance outcome together.',
  reviewerJustification:'This peer observed my planning, communication, delivery decisions and final release work.',
  directKnowledgeConfirmed:true
});
const n1=await nomination(blair);
await casey.call('decide_peer',{id:n1.id,status:'rejected',reason:''},422);
await casey.call('decide_peer',{id:n1.id,status:'rejected',reason:'Short'},422);
await casey.call('decide_peer',{id:n1.id,status:'rejected',reason:'A'.repeat(1001)},422);
await casey.call('decide_peer',{id:n1.id,status:'rejected',reason:'Insufficient direct observation.'});
log('T-17');
await alex.call('escalate_peer_nomination',{id:n1.id,reason:'I disagree with this decision because we worked together directly.'});
await alex.call('escalate_peer_nomination',{id:n1.id,reason:'A second attempt must fail for the same rejected nomination.'},409);
log('T-18');
const n2=await nomination(quinn);
await casey.call('decide_peer',{id:n2.id,status:'rejected',reason:'Insufficient direct observation.'});
await alex.call('escalate_peer_nomination',{id:n2.id,reason:'The reviewer directly observed my work on the shared release.'});
const escalations=rows('SELECT id,nomination_id FROM peer_nomination_escalations WHERE nomination_id IN ('+Number(n1.id)+','+Number(n2.id)+')');
assert.equal(escalations.length,2);
const e1=escalations.find(e=>Number(e.nomination_id)===Number(n1.id));
const e2=escalations.find(e=>Number(e.nomination_id)===Number(n2.id));
await riley.call('hr_case_resolve',{id:Number(e1.id),outcome:'resolved_upheld',note:'The rejection is upheld after reviewing the shared evidence.'});
await riley.call('hr_case_resolve',{id:Number(e2.id),outcome:'resolved_overturned',note:'The shared evidence supports this peer feedback request.'});
assert.equal(rows('SELECT status FROM peer_nominations WHERE id='+Number(n1.id))[0].status,'rejected');
assert.equal(rows('SELECT status FROM peer_nominations WHERE id='+Number(n2.id))[0].status,'rejected');
assert.equal(rows('SELECT status FROM peer_nomination_escalations WHERE id='+Number(e1.id))[0].status,'resolved_upheld');
assert.equal(rows('SELECT status FROM peer_nomination_escalations WHERE id='+Number(e2.id))[0].status,'resolved_overturned');
assert.equal(Number(rows("SELECT COUNT(*) n FROM feedback_requests WHERE participant_id="+Number(option.participantId)+" AND respondent_id="+Number(quinn.user.id)+" AND type='peer'")[0].n),1);
log('T-19');

// T-20: API rejects early manager review; manager stage still needs peer responses or HR waiver.
const participant=Number(option.participantId);
const cycle=rows('SELECT cycle_id FROM review_participants WHERE id='+participant)[0].cycle_id;
await casey.call('submit_review',{participantId:participant},409);
const before=rows('SELECT status FROM review_cycles WHERE id='+Number(cycle))[0].status;
sql("UPDATE review_cycles SET status='manager_review' WHERE id="+Number(cycle));
const self=rows("SELECT id FROM feedback_requests WHERE participant_id="+participant+" AND type='self'")[0];
assert(self);
const form=await alex.call('feedback_form&id='+Number(self.id));
await alex.call('submit_personal_feedback',{id:Number(self.id),ratings:form.competencies.map(c=>({competencyId:Number(c.id),score:4,comment:'Acceptance self assessment.'}))});
const blocked=await casey.call('submit_review',{participantId:participant},409);
assert.match(blocked.error,/peer responses|waiver/i);
sql("UPDATE review_cycles SET status="+quote(before)+" WHERE id="+Number(cycle));
log('T-20');

// T-21: released aggregate hides below threshold and reveals without respondent identity at threshold.
const released=(await morgan.personal()).reviews.find(r=>r.status==='released');
assert(released&&released.feedback.length>0,'Released fixture with aggregate feedback required');
const releasedCycle=rows('SELECT cycle_id FROM review_participants WHERE id='+Number(released.id))[0].cycle_id;
const originalMin=Number(released.min_peers);
sql('UPDATE review_cycles SET min_peers='+Number(released.peerResponses+1)+' WHERE id='+Number(releasedCycle));
assert.equal((await morgan.personal()).reviews.find(r=>Number(r.id)===Number(released.id)).feedback.length,0);
sql('UPDATE review_cycles SET min_peers='+Number(released.peerResponses)+' WHERE id='+Number(releasedCycle));
const visible=(await morgan.personal()).reviews.find(r=>Number(r.id)===Number(released.id));
assert(visible.feedback.length>0);
assert(!JSON.stringify(visible.feedback).includes('respondent'));
sql('UPDATE review_cycles SET min_peers='+originalMin+' WHERE id='+Number(releasedCycle));
log('T-21');

// T-22: rejects self-report and a loop; changing the primary manager closes the former relation.
await devon.call('org_change_manager',{employeeId:alex.user.id,managerId:alex.user.id},422);
await devon.call('org_change_manager',{employeeId:casey.user.id,managerId:alex.user.id},422);
const oldManager=rows("SELECT reports_to_employee_id FROM reporting_relationships WHERE employee_id="+Number(alex.user.id)+" AND relationship_type='primary' AND effective_to IS NULL")[0].reports_to_employee_id;
await devon.call('org_change_manager',{employeeId:alex.user.id,managerId:jordan.user.id,effectiveFrom:new Date().toISOString().slice(0,10),reason:'Acceptance test manager change'});
const relation=rows("SELECT reports_to_employee_id FROM reporting_relationships WHERE employee_id="+Number(alex.user.id)+" AND relationship_type='primary' AND effective_to IS NULL");
assert.equal(relation.length,1);
assert.equal(Number(relation[0].reports_to_employee_id),Number(jordan.user.id));
assert.equal(Number(rows("SELECT COUNT(*) n FROM reporting_relationships WHERE employee_id="+Number(alex.user.id)+" AND reports_to_employee_id="+Number(oldManager)+" AND relationship_type='primary' AND effective_to IS NULL")[0].n),0);
log('T-22');
