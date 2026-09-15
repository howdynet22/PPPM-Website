(function () {
  const {request,esc,label,progress,openWorkItem}=window.PPPM;
  const content=document.getElementById('employeeContent');
  const message=document.getElementById('employeeMessage');
  let user,data,busy=false,dialog,last='';
  const empty=text=>`<p class="empty">${text}</p>`;
  function itemCard(item) {
    return `<article class="personal-item"><div class="section-head"><div><h3>${esc(item.title)}</h3><p class="muted">Due ${esc(item.due || 'Not set')}${item.owner?` · Set by ${esc(item.owner)}`:''}</p></div>
      <button class="btn small" data-work-type="${item.type}" data-work-id="${item.id}">Open goal</button></div>${progress(item.progress)}</article>`;
  }
  function render() {
    const activeActions=data.plans.filter(p=>p.status!=='cancelled').flatMap(p=>p.actions.filter(a=>a.status!=='cancelled'));
    const allSteps=activeActions.flatMap(a=>a.steps);
    const complete=allSteps.filter(s=>s.completed).length;
    const activeGoals=data.goals.filter(g=>g.progress.status!=='completed').length;
    const pending=data.requests.filter(r=>r.canSubmit).length;
    content.innerHTML=`<div class="grid kpis employee-kpis">
      <div class="card kpi"><span class="label">Active goals</span><strong class="value">${activeGoals}</strong></div>
      <div class="card kpi"><span class="label">Development steps</span><strong class="value">${complete}/${allSteps.length}</strong><span class="muted">Completed steps</span></div>
      <div class="card kpi"><span class="label">Feedback to submit</span><strong class="value">${pending}</strong><a class="btn small" href="#feedback">View requests</a></div></div>
      <section id="development" class="workspace-section"><div class="section-head"><div><h2>My development plans</h2><p class="muted">Open a goal to record progress, blockers or completion.</p></div><button class="btn primary" data-create="pdp">+ Development goal</button></div>
      ${data.plans.map(p=>`<article class="card plan-card"><div class="section-head"><div><h3>${esc(p.summary || 'Development plan')}</h3><p class="muted">Set by ${esc(p.owner)}</p></div>${p.status==='cancelled'?'<span class="status">Cancelled</span>':''}</div><div class="personal-items">${p.actions.map(itemCard).join('') || empty('This plan has no goals yet.')}</div></article>`).join('') || empty('No development plan yet. Add a goal to start your plan.')}
      <div class="section-head"><h2>My goals</h2><button class="btn" data-create="goal">+ Goal</button></div><div class="card">${data.goals.map(itemCard).join('') || empty('No personal goals yet.')}</div></section>
      <section id="improvement" class="workspace-section"><h2>My improvement plans</h2>${data.pips.map(p=>`<article class="card"><div class="section-head"><h3>${esc(p.reason)}</h3><span class="status">${label(p.status)}</span></div><p class="muted">${esc(p.start_date)} to ${esc(p.end_date)} · Manager: ${esc(p.manager)} · HR owner: ${esc(p.hr_owner)}</p>
      ${p.objectives.map(itemCard).join('')}${p.checkins.length?`<details><summary>Check-ins (${p.checkins.length})</summary>${p.checkins.map(c=>`<p>${esc(c.notes)}<br><small class="muted">${esc(c.author)} · ${esc(c.checkin_date)}</small></p>`).join('')}</details>`:''}${p.outcome_note?`<p>${esc(p.outcome_note)}</p>`:''}</article>`).join('') || empty('No improvement plans assigned.')}</section>
      <section id="feedback" class="workspace-section"><h2>Reviews & 360° feedback</h2><div class="card"><h3>My feedback requests</h3>
      ${data.requests.map(r=>`<div class="personal-item section-head"><div><strong>${r.type==='self'?'Self review':`Feedback for ${esc(r.employee)}`}</strong><p class="muted">${esc(r.cycle)} · ${label(r.status)}${r.due?` · Due ${esc(r.due)}`:''}</p>${!r.canSubmit && r.status==='pending'?'<p class="muted">The submission window is closed or has not opened.</p>':''}</div><button class="btn small ${r.canSubmit?'primary':''}" data-feedback="${r.id}">${r.canSubmit?'Open form':'View form'}</button></div>`).join('') || empty('No feedback requests assigned.')}</div>
      ${data.reviews.map(r=>`<article class="card"><h3>${esc(r.cycle)}</h3><p><span class="status">${label(r.status)}</span></p>${r.status==='released'?`<p>Final rating: <strong>${r.final_rating==null?'Not rated':`${Number(r.final_rating).toFixed(1)} / 5`}</strong></p><p>${esc(r.manager_summary || '')}</p><h4>Anonymous peer feedback</h4>${r.feedback.length?r.feedback.map(f=>`<p>${esc(f.competency)} <strong>${Number(f.avg_score).toFixed(1)} / 5</strong></p>`).join(''):`<p class="notice">Anonymous feedback is available after at least ${Number(r.min_peers)} peer responses.</p>`}`:'<p class="notice">Your results will appear when the review is formally released.</p>'}</article>`).join('')}</section>`;
    content.querySelectorAll('[data-work-id]').forEach(b=>b.onclick=()=>openWorkItem(b.dataset.workType,Number(b.dataset.workId)));
    content.querySelectorAll('[data-create]').forEach(b=>b.onclick=()=>createForm(b.dataset.create));
    content.querySelectorAll('[data-feedback]').forEach(b=>b.onclick=()=>feedbackForm(Number(b.dataset.feedback)));
  }
  async function refresh() {
    if (busy || document.hidden) return;
    busy=true;
    try {
      const result=await request('workspace&scope=employee'); user=result.user; data=result.data;
      window.applyAuthUser?.(user);
      const snapshot=JSON.stringify(data);
      if(snapshot!==last){render();last=snapshot;}
      message.textContent=''; content.setAttribute('aria-busy','false');
    } catch(error) {message.textContent=`Unable to refresh your workspace. ${error.message}`;content.setAttribute('aria-busy','false');}
    finally {busy=false;}
  }
  function formDialog(title,body) {
    if(!dialog){dialog=document.createElement('dialog');dialog.className='work-dialog';document.body.append(dialog);}
    const opener=document.activeElement;
    dialog.onclose=()=>opener?.focus();
    dialog.setAttribute('aria-labelledby','employeeFormTitle');
    dialog.innerHTML=`<div class="section-head"><h2 id="employeeFormTitle">${esc(title)}</h2><button class="btn" data-close>Close</button></div>${body}<p role="status" id="formMessage"></p>`;
    dialog.querySelector('[data-close]').onclick=()=>dialog.close(); dialog.showModal();
    return dialog;
  }
  async function saveForm(form,action,body) {
    const controls=[...dialog.querySelectorAll('button,input,textarea,select')];controls.forEach(el=>el.disabled=true);
    dialog.querySelector('#formMessage').textContent='Saving…';
    try{await request(action,body);dialog.close();await refresh();window.dispatchEvent(new Event('pppm:data-changed'));message.textContent='Saved.';}
    catch(error){controls.forEach(el=>el.disabled=false);dialog.querySelector('#formMessage').textContent=error.message;}
  }
  function createForm(type) {
    formDialog(type==='pdp'?'New development goal':'New personal goal',`<form id="createPersonal" class="workspace-form">
      <label>Title<input name="title" required maxlength="200"></label><label>Expected outcome<textarea name="description" required maxlength="5000" rows="3"></textarea></label>
      <label>Due date<input name="due" type="date" required></label><label>Actionable steps<textarea name="steps" rows="5" required placeholder="One concrete step per line"></textarea><small>Between 1 and 20 steps.</small></label><button type="submit" class="btn primary">Create goal</button></form>`);
    dialog.querySelector('form').onsubmit=e=>{e.preventDefault();const f=e.currentTarget;
      const body={employeeId:Number(user.id),title:f.elements.title.value.trim(),due:f.elements.due.value,steps:f.elements.steps.value.split(/\r?\n/).map(s=>s.trim()).filter(Boolean)};
      body[type==='pdp'?'description':'target']=f.elements.description.value.trim();saveForm(f,type==='pdp'?'create_pdp':'create_goal',body);};
  }
  async function feedbackForm(id) {
    try {
      const result=await request(`feedback_form&id=${id}`); const r=result.request;
      formDialog(r.type==='self'?'Self review':`Feedback for ${r.employee}`,`<p>${esc(r.cycle)}${r.due?` · Due ${esc(r.due)}`:''}</p><p class="muted">${r.type==='peer'?'Feedback is presented as an anonymous aggregate when enough peers respond.':''}</p>
        <form class="workspace-form" id="feedbackForm">${result.competencies.map(c=>{const saved=result.ratings.find(v=>Number(v.competency_id)===Number(c.id));return `<fieldset data-competency="${c.id}"><legend>${esc(c.name)}</legend><label>Rating<select name="score" required ${r.canSubmit?'':'disabled'}><option value="">Choose a rating</option>${[1,2,3,4,5].map(n=>`<option value="${n}" ${Number(saved?.score)===n?'selected':''}>${n} / 5</option>`).join('')}</select></label><label>Comment (optional)<textarea name="comment" maxlength="2000" ${r.canSubmit?'':'readonly'}>${esc(saved?.comment || '')}</textarea></label></fieldset>`;}).join('')}
        ${r.canSubmit?'<button class="btn primary" type="submit">Submit feedback</button>':'<p class="notice">This form is read-only.</p>'}</form>`);
      dialog.querySelector('form').onsubmit=e=>{e.preventDefault();if(!r.canSubmit)return;const form=e.currentTarget;saveForm(form,'submit_personal_feedback',{id,ratings:[...form.querySelectorAll('fieldset')].map(f=>({competencyId:Number(f.dataset.competency),score:Number(f.querySelector('select').value),comment:f.querySelector('textarea').value}))});};
    } catch(error) {message.textContent=error.message;}
  }
  window.addEventListener('pppm:authenticated',refresh);
  if(window.currentAuthUser)refresh();
  window.addEventListener('pppm:data-changed',refresh);
  window.addEventListener('focus',refresh);
  document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
  setInterval(refresh,15000);
  function updatePersonalNavigation() {
    const current=location.hash || '#development';
    document.querySelectorAll('.sidebar .nav-btn[href^="#"]').forEach(link=>link.classList.toggle('active',link.getAttribute('href')===current));
  }
  window.addEventListener('hashchange',updatePersonalNavigation);
  updatePersonalNavigation();
})();
