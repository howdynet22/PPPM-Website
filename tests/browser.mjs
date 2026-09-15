import assert from 'node:assert/strict';
import {chromium} from 'playwright';
const base=process.env.PPPM_TEST_URL;
if(!base)throw new Error('Set PPPM_TEST_URL to an isolated test server.');
const browser=await chromium.launch({headless:true});
const errors=[];
const manager=await browser.newPage({viewport:{width:1440,height:1000}});
manager.on('pageerror',e=>errors.push(e.message));
async function login(page,name){
  await page.goto(`${base}/index.html`);
  await page.locator('#email').fill(`${name}@demo.pppm.test`);
  await page.locator('#password').fill('password123');
  await page.locator('#loginForm button[type=submit]').click();
  await page.waitForURL(/dashboard\.html/);
}
try {
  await login(manager,'casey');
  await manager.waitForFunction(()=>document.querySelector('#kpiTeam')?.textContent.trim()==='3');
  for(const section of ['team','reviews','goals','pips','reports','notifications']) {
    await manager.locator(`button.nav-btn[data-page=${section}]`).click();
    assert(await manager.locator(`#page-${section}`).isVisible());
  }
  await manager.locator('button.nav-btn[data-page=team]').click();
  await manager.locator('#teamSearch').fill('Alex');
  assert((await manager.locator('#teamTable').innerText()).includes('Alex Morgan'));
  assert(!(await manager.locator('#teamTable').innerText()).includes('Jamie Vale'));
  await manager.locator('button.nav-btn[data-page=reports]').click();
  await manager.locator('#generateReportBtn').click();
  assert((await manager.locator('#reportOutput').innerText()).includes('Direct reports: 3'));
  const download=manager.waitForEvent('download');
  await manager.locator('#exportTeamBtn').click();
  assert.equal((await download).suggestedFilename(),'manager-team-performance.csv');
  await manager.getByLabel('Workspace',{exact:true}).selectOption('employee');
  await manager.waitForURL(/employee-dashboard/);
  await manager.locator('#employeeContent .plan-card').first().waitFor();
  assert((await manager.locator('[data-user-name]').innerText()).includes('Casey'));
  const employee=await browser.newPage({viewport:{width:390,height:844}});
  employee.on('pageerror',e=>errors.push(e.message));
  await login(employee,'alex');
  await employee.locator('#employeeContent .plan-card').first().waitFor();
  await employee.locator('.plan-card [data-work-id]').first().click();
  await employee.locator('.work-step').first().waitFor();
  const step=employee.locator('[data-step]').nth(1);
  await step.locator('textarea[name=note]').fill('Browser demo note');
  await step.locator('select[name=status]').selectOption('completed');
  await step.getByRole('button',{name:'Save step',exact:true}).click();
  await employee.waitForFunction(()=>document.querySelector('#workMessage')?.textContent==='Saved.');
  assert.equal(await employee.locator('[data-step]').nth(1).locator('textarea[name=note]').inputValue(),'Browser demo note');
  await employee.getByRole('button',{name:'Close goal',exact:true}).click();
  await employee.reload();
  await employee.locator('.plan-card [data-work-id]').first().click();
  await employee.locator('.work-step').first().waitFor();
  assert.equal(await employee.locator('[data-step]').nth(1).locator('select[name=status]').inputValue(),'completed');
  await employee.route('**/api.php?action=set_step_status',route=>route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({ok:false,error:'Simulated outage'})}));
  await employee.locator('[data-step]').nth(1).locator('textarea[name=note]').fill('Retain this unsaved draft');
  await employee.locator('[data-step]').nth(1).getByRole('button',{name:'Save step',exact:true}).click();
  await employee.waitForFunction(()=>document.querySelector('#workMessage')?.textContent.includes('not saved'));
  assert.equal(await employee.locator('[data-step]').nth(1).locator('textarea[name=note]').inputValue(),'Retain this unsaved draft');
  await employee.getByRole('button',{name:'Close goal',exact:true}).click();
  assert(await employee.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2));
  await employee.getByRole('button',{name:'Nominate a peer',exact:true}).click();
  await employee.getByRole('heading',{name:'Nominate a peer',exact:true}).waitFor();
  assert(await employee.locator('select[name=peer] option').count()>1);
  assert.deepEqual(errors,[]);
  console.log('PASS: manager sections/search/report/CSV, workspace switching, mobile step save and reload, failed-save draft retention, nomination form and no browser errors.');
} finally {await browser.close();}
