import assert from 'node:assert/strict';
const base=process.env.PPPM_TEST_URL;
if(!base)throw new Error('Set PPPM_TEST_URL to an isolated, seeded test server. This test changes fictional demo records.');
class Client {
  cookie=''; token=''; user=null;
  async call(action,body,status=200,csrf=true) {
    const r=await fetch(`${base}/api.php?action=${action}`,{method:body?'POST':'GET',headers:{'Content-Type':'application/json',Cookie:this.cookie,...(csrf?{'X-CSRF-Token':this.token}:{})},...(body?{body:JSON.stringify(body)}:{})});
    if(r.headers.get('set-cookie'))this.cookie=r.headers.get('set-cookie').split(';')[0];
    const result=await r.json();assert.equal(r.status,status,`${action}: ${JSON.stringify(result)}`);
    if(result.csrfToken)this.token=result.csrfToken;
    return result;
  }
  async login(name){this.user=(await this.call('login',{email:`${name}@demo.pppm.test`,password:'password123'})).user;return this;}
}
const alex=await new Client().login('alex'),casey=await new Client().login('casey'),jordan=await new Client().login('jordan'),morgan=await new Client().login('morgan');
const blair=await new Client().login('blair'),quinn=await new Client().login('quinn');
const hr=await new Client().login('taylor'),hrLead=await new Client().login('riley'),coordinator=await new Client().login('sam'),admin=await new Client().login('devon'),ceo=await new Client().login('avery');
assert.deepEqual(casey.user.workspaces.map(w=>w.key),['employee','manager']);
assert.deepEqual(hr.user.workspaces.map(w=>w.key),['employee','hr']);
assert(admin.user.workspaces.some(w=>w.key==='employee'),'Administrators with Personal permission must receive the ordinary Personal workspace');
assert.deepEqual(ceo.user.workspaces.map(w=>w.key),['manager','executive']);
await alex.call('workspace&scope=hr',null,403);
await alex.call('dashboard',null,403);
await ceo.call('workspace&scope=employee',null,403);
for(const [label,client] of [['administrator',admin],['HR lead',hrLead],['HR partner',hr],['HR coordinator',coordinator],['manager',casey]]){
  const personal=(await client.call('workspace&scope=employee')).data;
  assert(personal.reviews.some(review=>review.cycle==='Demo development check-in'),`${label} must be enrolled in the active review cycle`);
  assert(personal.nominationOptions.some(option=>option.peers.length>0),`${label} must be able to nominate an eligible peer`);
}
let own=(await alex.call('workspace&scope=employee')).data;
assert.equal(own.plans.length,1);assert.equal(own.pips.length,0);
assert(own.nominations.some(n=>n.status==='approved'));
assert(own.nominations.some(n=>n.status==='rejected'&&n.decisionReason));
const nominationCycle=own.nominationOptions.find(option=>option.peers.some(peer=>peer.name==='Blair Hayes'));
assert(nominationCycle,'Expected an active nomination cycle with Blair as an eligible peer');
await alex.call('create_peer_nomination',{participantId:nominationCycle.participantId,peerId:blair.user.id,sharedWork:'Short',collaborationDetails:'Too short',reviewerJustification:'Too short',directKnowledgeConfirmed:true},422);
const rejectedNomination=await alex.call('create_peer_nomination',{
  participantId:nominationCycle.participantId,peerId:blair.user.id,sharedWork:'Cross-team release planning',
  collaborationDetails:'Blair and I planned the release handover, reviewed dependencies and resolved delivery risks together.',
  reviewerJustification:'Blair directly observed my communication, collaboration and follow-through across the shared release work.',
  directKnowledgeConfirmed:true,
});
await alex.call('decide_peer',{id:rejectedNomination.id,status:'rejected',reason:'No'},403);
await casey.call('decide_peer',{id:rejectedNomination.id,status:'rejected',reason:''},422);
await casey.call('decide_peer',{id:rejectedNomination.id,status:'rejected',reason:'The collaboration did not cover enough of the review period.'});
own=(await alex.call('workspace&scope=employee')).data;
const rejected=own.nominations.find(n=>Number(n.id)===Number(rejectedNomination.id));
assert.equal(rejected.status,'rejected');assert.match(rejected.decisionReason,/review period/);
await alex.call('escalate_peer_nomination',{id:rejectedNomination.id,reason:'Too short'},422);
await alex.call('escalate_peer_nomination',{id:rejectedNomination.id,reason:'Blair observed the complete release planning work and can provide direct evidence that should be considered.'});
await alex.call('escalate_peer_nomination',{id:rejectedNomination.id,reason:'This second escalation must not be accepted by the system.'},409);
own=(await alex.call('workspace&scope=employee')).data;
assert.equal(own.nominations.find(n=>Number(n.id)===Number(rejectedNomination.id)).escalationStatus,'pending_hr');
const approvalCycle=own.nominationOptions.find(option=>option.peers.some(peer=>peer.name==='Quinn River'));
const approvedNomination=await alex.call('create_peer_nomination',{
  participantId:approvalCycle.participantId,peerId:quinn.user.id,sharedWork:'Onboarding guide review',
  collaborationDetails:'Quinn reviewed the guide structure, checked the process assumptions and tested the final handover instructions.',
  reviewerJustification:'Quinn directly observed how I incorporated feedback and communicated changes during the shared review.',
  directKnowledgeConfirmed:true,
});
await casey.call('decide_peer',{id:approvedNomination.id,status:'approved',reason:'The shared work provides sufficient direct evidence.'});
const quinnRequests=(await quinn.call('workspace&scope=employee')).data.requests;
assert(quinnRequests.some(request=>request.type==='peer'&&request.employee==='Alex Morgan'));
await alex.call('create_peer_nomination',{
  participantId:approvalCycle.participantId,peerId:quinn.user.id,sharedWork:'Duplicate onboarding review',
  collaborationDetails:'This duplicate nomination repeats the same shared work and should not create another database record.',
  reviewerJustification:'The same reviewer is already assigned and the unique review relationship must be preserved.',
  directKnowledgeConfirmed:true,
},409);
const itemId=own.plans[0].actions[0].id;
const getItem=async client=>(await client.call(`work_item&type=pdp_action&id=${itemId}`)).item;
let item=await getItem(alex);
assert.equal(item.progress.completed,1);assert.equal(item.progress.blocked,true);assert.equal(item.progress.overdue,true);
await jordan.call(`work_item&type=pdp_action&id=${itemId}`,null,404);
await hrLead.call(`work_item&type=pdp_action&id=${itemId}`,null,404);
await getItem(casey);
let step=item.steps[0];
await alex.call('set_step_status',{id:step.id,status:'invalid'},422);
await alex.call('set_step_status',{id:step.id,status:'completed'},403,false);
await jordan.call('set_step_status',{id:step.id,status:'completed'},404);
await alex.call('update_work_step',{id:step.id,title:'Unauthorized redefinition'},403);
await alex.call('add_work_step',{type:'pdp_action',workId:itemId,title:'Unauthorized step'},403);
for(const status of ['not_started','in_progress','blocked','completed']) {
  step=(await getItem(alex)).steps[0];
  await alex.call('set_step_status',{id:step.id,version:step.version,status,note:`Testing ${status}`});
  const saved=(await getItem(casey)).steps[0];
  assert.equal(saved.status,status);assert.equal(saved.note,`Testing ${status}`);assert.equal(saved.completed,status==='completed');
  assert.equal(saved.completedAt!==null,status==='completed');
  await alex.call('set_step_status',{id:step.id,version:step.version,status:'not_started'},409);
}
step=(await getItem(alex)).steps[0];
await alex.call('set_step_status',{id:step.id,version:step.version,status:'completed',note:'Edited completion note'});
assert.equal((await getItem(alex)).steps[0].completedAt,step.completedAt);
for(const s of (await getItem(alex)).steps)await alex.call('set_step_status',{id:s.id,version:s.version,status:'completed'});
item=await getItem(alex);assert.equal(item.progress.percent,100);assert.equal(item.status,'completed');assert.equal(item.progress.overdue,false);
own=(await alex.call('workspace&scope=employee')).data;assert.equal(own.plans[0].status,'completed');assert.equal(own.plans[0].progress.status,'completed');
step=item.steps[0];await alex.call('set_step_status',{id:step.id,version:step.version,status:'in_progress'});
own=(await alex.call('workspace&scope=employee')).data;assert.equal(own.plans[0].status,'agreed');assert.equal(own.plans[0].progress.completed,3);
const team=(await casey.call('dashboard')).pdps.find(p=>p.id===itemId);assert.equal(team.steps.filter(s=>s.completed).length,3);
const ancestor=(await jordan.call('dashboard'));assert(!ancestor.pdps.some(p=>p.id===itemId));
const hrPlans=(await hr.call('workspace&scope=hr')).data.pips;assert.equal(hrPlans.length,1);
assert.equal((await hrLead.call('workspace&scope=hr')).data.pips.length,0);
assert.equal((await coordinator.call('workspace&scope=hr')).data.pips.length,0);
const objective=hrPlans[0].objectives[0];
await hrLead.call(`work_item&type=pip_objective&id=${objective.id}`,null,404);
await hr.call('set_step_status',{id:objective.steps[1].id,version:objective.steps[1].version,status:'blocked',note:'Review together at next check-in.'});
await casey.call('update_pip_status',{id:Number(hrPlans[0].id),status:'closed',note:'Manager recommends closing this plan after reviewing the evidence.'},409);
await hr.call('hr_pip_update',{id:Number(hrPlans[0].id),status:'closed',outcomeNote:'HR reviewed the evidence and formally closed this plan.'});
await hr.call('set_step_status',{id:objective.steps[0].id,status:'not_started'},409);
const privateReview=own.reviews[0];assert.equal(privateReview.final_rating,null);assert.equal(privateReview.manager_summary,null);assert.deepEqual(privateReview.feedback,[]);
const released=(await morgan.call('workspace&scope=employee')).data.reviews.find(review=>review.status==='released');assert(released,'Expected Morgan\'s released historical review');assert.equal(released.feedback.length,3);assert(!JSON.stringify(released.feedback).includes('respondent'));
const formRequest=own.requests.find(r=>r.type==='self');
await hr.call(`feedback_form&id=${formRequest.id}`,null,404);
await quinn.call(`feedback_form&id=${formRequest.id}`,null,404);
const form=await alex.call(`feedback_form&id=${formRequest.id}`);
await alex.call('submit_personal_feedback',{id:formRequest.id,ratings:[]},422);
await alex.call('submit_personal_feedback',{id:formRequest.id,ratings:form.competencies.map(c=>({competencyId:Number(c.id),score:4,comment:'An illustrative self review.'}))});
await alex.call('submit_personal_feedback',{id:formRequest.id,ratings:[]},409);
assert.equal((await alex.call('workspace&scope=employee')).data.reviews[0].status,'self_submitted');
const caseyDashboardAfterSelf=await casey.call('dashboard');
const alexForCasey=caseyDashboardAfterSelf.employees.find(e=>Number(e.id)===Number(alex.user.id));
assert.equal(alexForCasey.selfReview.status,'submitted');
assert.equal(alexForCasey.selfReview.ratings.length,form.competencies.length);
const jordanDashboardAfterSelf=await jordan.call('dashboard');
const alexForJordan=jordanDashboardAfterSelf.employees.find(e=>Number(e.id)===Number(alex.user.id));
assert(alexForJordan,'Alex should remain visible as a descendant to Jordan');
assert.equal(alexForJordan.participantId,null);
assert.equal(alexForJordan.selfReview,null);
const alexHrRecord=await hr.call(`hr_employee&employeeId=${alex.user.id}`);
const alexHrReview=alexHrRecord.reviews.find(r=>r.self_review?.status==='submitted');
assert(alexHrReview,'HR should see Alex self review when inspecting the employee record');
assert.equal(alexHrReview.self_review.ratings.length,form.competencies.length);
const coordinatorRecord=await coordinator.call(`hr_employee&employeeId=${alex.user.id}`);
assert.deepEqual(coordinatorRecord.reviews,[],'HR Coordinator must not receive protected review detail');
await ceo.call('dashboard');
await ceo.call('hr_cycle_create',{name:'Forbidden leadership cycle',period_start:'2099-01-01',period_end:'2099-03-31',self_deadline:'2099-04-01',peer_deadline:'2099-04-15',manager_deadline:'2099-04-30',min_peers:3},403);
const caseyPersonal=(await casey.call('workspace&scope=employee')).data;
assert(caseyPersonal.requests.some(r=>r.type==='self'&&r.employee==='Casey Brooks'),'Managers should receive self-review requests in Personal workspace');
const newGoal=await alex.call('create_pdp',{employeeId:Number(alex.user.id),title:'Practice a new skill',description:'Use a practical example',due:'2099-12-31',steps:['Prepare','Practice']});
const alexPlans=(await alex.call('workspace&scope=employee')).data.plans;
const createdPlan=alexPlans.find(plan=>plan.actions.some(action=>Number(action.id)===Number(newGoal.id)));
await alex.call('agree_pdp',{id:Number(createdPlan.id)});
assert.equal((await alex.call(`work_item&type=pdp_action&id=${newGoal.id}`)).item.canEdit,true);
let editable=(await alex.call(`work_item&type=pdp_action&id=${newGoal.id}`)).item;
await alex.call('update_work_step',{id:editable.steps[0].id,title:'Prepare an example'});
assert.equal((await alex.call(`work_item&type=pdp_action&id=${newGoal.id}`)).item.steps[0].title,'Prepare an example');
const added=await alex.call('add_work_step',{type:'pdp_action',workId:newGoal.id,title:'Reflect'});
await alex.call('delete_work_step',{id:added.id});
assert.equal((await alex.call(`work_item&type=pdp_action&id=${newGoal.id}`)).item.steps.length,2);
await alex.call('create_pdp',{employeeId:Number(casey.user.id),title:'Invalid assignment',description:'No access',due:'2099-12-31',steps:['Test']},403);
await ceo.call('create_pdp',{employeeId:Number(ceo.user.id),title:'Unavailable personal plan',description:'No employee workspace',due:'2099-12-31',steps:['Test']},403);
const draftPip=await casey.call('create_pip',{employeeId:Number(alex.user.id),hrOwnerId:Number(hr.user.id),start:'2099-01-01',end:'2099-03-31',reason:'Automated draft visibility and HR governance scenario',objective:'Demonstrate consistent follow-through',criteria:'Every agreed action has dated evidence and a recorded owner.',objectiveDue:'2099-03-01',steps:['Agree evidence','Review evidence']});
assert(!(await alex.call('workspace&scope=employee')).data.pips.some(p=>Number(p.id)===Number(draftPip.id)),'Draft PIP must not be visible to employee');
await casey.call('update_pip_status',{id:Number(draftPip.id),status:'active',note:'Manager recommends activation after HR review.'},409);
console.log('PASS: workspace boundaries, peer nomination decisions and escalation, feedback request creation, all step transitions, timestamps, notes, conflicts, CSRF, plan aggregation, cross-session reads, HR ownership, anonymous/released results, feedback submission and creation, manager Personal participation, and self-review visibility boundaries.');
