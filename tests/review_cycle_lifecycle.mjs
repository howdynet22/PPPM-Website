import assert from 'node:assert/strict';
import { chromium } from 'playwright-core';

const base=process.env.PPPM_TEST_URL;
if(!base) throw new Error('Set PPPM_TEST_URL to an isolated, seeded test server.');
const cycleName='Automated handover review';

class Client {
  cookie=''; token=''; user=null;
  async call(action,body,status=200) {
    const response=await fetch(`${base}/api.php?action=${action}`,{
      method:body?'POST':'GET',
      headers:{'Content-Type':'application/json',Cookie:this.cookie,...(body?{'X-CSRF-Token':this.token}:{})},
      ...(body?{body:JSON.stringify(body)}:{}),
    });
    if(response.headers.get('set-cookie')) this.cookie=response.headers.get('set-cookie').split(';')[0];
    const result=await response.json();
    assert.equal(response.status,status,`${action}: ${JSON.stringify(result)}`);
    if(result.csrfToken)this.token=result.csrfToken;
    return result;
  }
  async login(name){this.user=(await this.call('login',{email:`${name}@demo.pppm.test`,password:'password123'})).user;return this;}
}

async function loginPage(browser,name,path){
  const context=await browser.newContext();
  const page=await context.newPage();
  await page.goto(`${base}/index.html`);
  await page.fill('#email',`${name}@demo.pppm.test`);
  await page.fill('#password','password123');
  await page.click('#loginForm button[type="submit"]');
  await page.waitForURL(url=>!url.pathname.endsWith('/index.html'));
  await page.goto(`${base}/${path}`);
  await page.waitForSelector('html[data-authenticated="true"]');
  return {context,page};
}

async function waitText(page,selector,text){
  await page.waitForFunction(({selector,text})=>document.querySelector(selector)?.innerText.includes(text),{selector,text},{timeout:10000});
}

async function refreshFromDatabase(page){
  await page.evaluate(()=>window.dispatchEvent(new Event('focus')));
}

const riley=await new Client().login('riley');
const alex=await new Client().login('alex');
const casey=await new Client().login('casey');
const peers=await Promise.all(['jamie','drew','blair'].map(async name=>[name,await new Client().login(name)]));

const created=(await riley.call('hr_cycle_create',{
  name:cycleName,period_start:'2026-07-01',period_end:'2026-09-30',
  self_deadline:'2099-10-05',peer_deadline:'2099-10-12',manager_deadline:'2099-10-20',min_peers:3,
})).cycle;
assert.equal(created.status,'draft');
const published=(await riley.call('hr_cycle_publish',{id:Number(created.id)})).cycle;
assert.deepEqual({status:published.status,participants:published.participants,competencies:published.competencies},{status:'open',participants:1,competencies:3});

const browser=await chromium.launch({headless:true,executablePath:process.env.PPPM_CHROME_PATH||'/usr/bin/google-chrome'});
const employeeUi=await loginPage(browser,'alex','employee-dashboard.html#feedback');
const managerUi=await loginPage(browser,'casey','manager-dashboard.html');
const hrUi=await loginPage(browser,'riley','hr-dashboard.html');
await waitText(employeeUi.page,'#employeeContent',cycleName);
await employeeUi.page.click('a[href="#notifications"]');
await waitText(employeeUi.page,'#employeeContent','Review cycle opened');
let inbox=await alex.call('get_notifications');
assert(inbox.notifications.some(n=>n.notification_type==='review_cycle_open'&&Number(n.entity_id)===Number(created.id)));
await alex.call('mark_notifications_read',{ids:inbox.notifications.map(n=>Number(n.id))});
assert.equal((await alex.call('get_notifications&unread=1')).notifications.length,0);
await employeeUi.page.click('a[href="#feedback"]');
await hrUi.page.click('[data-page="cycles"]');
await waitText(hrUi.page,'#cyclesTable',cycleName);
await waitText(hrUi.page,'#cyclesTable','Open');

let personal=(await alex.call('workspace&scope=employee')).data;
const review=personal.reviews.find(row=>row.cycle===cycleName);
assert(review,'Published cycle must appear in Personal workspace');
const selfRequest=personal.requests.find(row=>Number(row.participant_id)===Number(review.id)&&row.type==='self');
const selfForm=await alex.call(`feedback_form&id=${selfRequest.id}`);
assert.equal(selfForm.competencies.length,3);
await alex.call('submit_personal_feedback',{id:Number(selfRequest.id),ratings:selfForm.competencies.map(c=>({competencyId:Number(c.id),score:4,comment:`Self evidence for ${c.name}`}))});

for(const [name,peer] of peers){
  await alex.call('create_peer_nomination',{
    participantId:Number(review.id),peerId:Number(peer.user.id),sharedWork:`${name} release collaboration`,
    collaborationDetails:`${name} worked with Alex throughout planning, delivery, verification and handover of the shared release.`,
    reviewerJustification:`${name} directly observed Alex's communication, collaboration, problem solving and delivery quality.`,
    directKnowledgeConfirmed:true,
  });
}

await refreshFromDatabase(employeeUi.page);
await waitText(employeeUi.page,'#employeeContent','Submitted');
await waitText(employeeUi.page,'#employeeContent','Jamie Vale');

await refreshFromDatabase(managerUi.page);
await managerUi.page.click('[data-page="reviews"]');
await waitText(managerUi.page,'#peerTable','Jamie Vale');
await waitText(managerUi.page,'#peerTable','Drew Parker');
await waitText(managerUi.page,'#peerTable','Blair Hayes');

let managerData=await casey.call('dashboard');
const nominations=managerData.peerNominations.filter(row=>Number(row.employeeId)===Number(alex.user.id)&&row.cycle===cycleName);
assert.equal(nominations.length,3);
for(const nomination of nominations) await casey.call('decide_peer',{id:Number(nomination.id),status:'approved',reason:'The nominated peer directly observed substantial work during this review period.'});
const managerInbox=await casey.call('get_notifications');
assert(managerInbox.notifications.some(n=>n.notification_type==='peer_nomination_pending'));
assert(managerInbox.notifications.some(n=>n.notification_type==='peer_nomination_pending'&&!n.unread),'Decided nomination should no longer remain unread');

await riley.call('hr_cycle_advance',{id:Number(created.id)});
await refreshFromDatabase(hrUi.page);
await waitText(hrUi.page,'#cyclesTable','Peer Review');

for(const [name,peer] of peers){
  const workspace=(await peer.call('workspace&scope=employee')).data;
  const request=workspace.requests.find(row=>Number(row.participant_id)===Number(review.id)&&row.type==='peer');
  assert(request,`${name} must receive the approved peer-feedback request`);
  const form=await peer.call(`feedback_form&id=${request.id}`);
  assert.equal(form.request.canSubmit,true);
  await peer.call('submit_personal_feedback',{id:Number(request.id),ratings:form.competencies.map(c=>({competencyId:Number(c.id),score:4,comment:`Observed evidence from ${name} for ${c.name}`}))});
}

await refreshFromDatabase(managerUi.page);
await waitText(managerUi.page,'#reviewTable','3 submitted');
managerData=await casey.call('dashboard');
const alexRow=managerData.employees.find(row=>Number(row.id)===Number(alex.user.id));
assert.equal(managerData.feedback[String(alex.user.id)].available,true);
assert.equal(managerData.feedback[String(alex.user.id)].responses,3);

await riley.call('hr_cycle_advance',{id:Number(created.id)});
await refreshFromDatabase(managerUi.page);
await managerUi.page.waitForSelector('#reviewTable button.primary:not([disabled])');
await waitText(managerUi.page,'#reviewTable','Review');
const refreshedManager=await casey.call('dashboard');
const ready=refreshedManager.employees.find(row=>Number(row.id)===Number(alex.user.id));
await casey.call('submit_review',{
  participantId:Number(ready.participantId),version:Number(ready.reviewVersion),rating:4.5,
  summary:'Alex delivered reliably, incorporated feedback and communicated clearly throughout the review period.',
  competencies:refreshedManager.competencies.map(c=>({competencyId:Number(c.id),score:4,comment:`Manager evidence for ${c.name}`})),
});
await employeeUi.page.click('a[href="#notifications"]');
await refreshFromDatabase(employeeUi.page);
await waitText(employeeUi.page,'#employeeContent','Manager review submitted');
inbox=await alex.call('get_notifications');
assert(inbox.notifications.some(n=>n.notification_type==='manager_review_submitted'));
await employeeUi.page.click('a[href="#feedback"]');
await refreshFromDatabase(managerUi.page);
await waitText(managerUi.page,'#reviewTable','Manager submitted');

await riley.call('hr_cycle_advance',{id:Number(created.id)});
let hrInbox=await riley.call('get_notifications');
assert(!hrInbox.notifications.some(n=>n.notification_type==='review_cycle_closed'&&Number(n.entity_id)===Number(created.id)),'HR close notification must not be sent at release');
await refreshFromDatabase(employeeUi.page);
await waitText(employeeUi.page,'#employeeContent','Final rating:');
await waitText(employeeUi.page,'#employeeContent','4.5 / 5');
await waitText(employeeUi.page,'#employeeContent','Anonymous peer feedback');
const released=(await alex.call('workspace&scope=employee')).data.reviews.find(row=>row.cycle===cycleName);
assert.equal(released.status,'released');
assert.equal(released.feedback.length,3);
assert(!JSON.stringify(released.feedback).includes('respondent'));

await riley.call('hr_cycle_advance',{id:Number(created.id)});
hrInbox=await riley.call('get_notifications');
assert(hrInbox.notifications.some(n=>n.notification_type==='review_cycle_closed'&&Number(n.entity_id)===Number(created.id)),'HR must be notified when the cycle closes');
inbox=await alex.call('get_notifications');
assert(inbox.notifications.some(n=>n.notification_type==='review_results_released'&&Number(n.entity_id)===Number(created.id)));
await refreshFromDatabase(hrUi.page);
await waitText(hrUi.page,'#cyclesTable','Closed');
await alex.call('submit_personal_feedback',{id:Number(selfRequest.id),ratings:selfForm.competencies.map(c=>({competencyId:Number(c.id),score:5,comment:'Too late'}))},409);

await Promise.all([employeeUi.context.close(),managerUi.context.close(),hrUi.context.close()]);
await browser.close();
console.log('PASS: complete draft-to-closed review lifecycle and live HR, Manager and Personal UI refresh checks.');
