(function () {
  const {request,esc,label,progress,openWorkItem}=window.PPPM;
  const content=document.getElementById('employeeContent');
  const message=document.getElementById('employeeMessage');
  let user,data,busy=false,dialog,last='';
  const empty=text=>`<p class="empty">${text}</p>`;
  const cycleName=name=>name==='Demo previous check-in'?'Previous Performance Review':name;
  function nominationCard(item) {
    const decision=item.status==='rejected'
      ? `<div class="notice warn"><strong>Manager declined this nomination</strong><p>${esc(item.decisionReason || 'No reason was recorded.')}</p><p>This reviewer no longer counts toward your required peer nominations. Nominate a replacement if you are below the cycle minimum.</p></div>`
      : item.status==='approved'
        ? '<p class="notice">Approved. The peer feedback request is available to the reviewer.</p>'
        : '<p class="muted">Waiting for your manager to review the evidence.</p>';
    const escalation=item.escalationStatus
      ? `<div class="notice"><strong>Forwarded to HR</strong><p>${esc(item.escalationReason || '')}</p><p class="muted">Status: ${label(item.escalationStatus)}</p></div>`
      : '';
    const escalate=item.canEscalate
      ? `<button class="btn small" data-escalate-nomination="${item.id}">Forward decision to HR</button>`
      : '';
    const suggestion=item.status==='rejected' && item.suggestedPeerId
      ? `<div class="notice"><strong>Manager suggested a replacement</strong><p>${esc(item.suggestedPeer)}${item.suggestedPeerJobTitle?` · ${esc(item.suggestedPeerJobTitle)}`:''}</p><p>${esc(item.suggestionReason || 'Your manager believes this person is better placed to provide relevant feedback.')}</p><button class="btn small primary" data-use-suggested-peer="${item.suggestedPeerId}" data-participant="${item.participantId}">Nominate ${esc(item.suggestedPeer)}</button></div>`
      : '';
    return `<article class="personal-item nomination-item"><div class="section-head"><div><h4>${esc(item.peer)}</h4><p class="muted">${esc(item.peerJobTitle || 'Job title not set')} · ${esc(cycleName(item.cycle))}</p></div><span class="status">${label(item.status)}</span></div>
      <p><strong>Shared work:</strong> ${esc(item.sharedWork)}</p>
      <details><summary>View nomination evidence</summary><p><strong>Work completed together</strong><br>${esc(item.collaborationDetails)}</p><p><strong>Why this peer can review the work</strong><br>${esc(item.reviewerJustification)}</p></details>
      ${decision}${suggestion}${escalation}${escalate}</article>`;
  }
  function peerRequirementCard(review) {
    if(!['open','peer_review'].includes(review.cycle_status)) return '';
    const required=Number(review.min_peers)||3;
    const assigned=Number(review.peerAssigned)||0;
    const pending=Number(review.peerPendingNominations)||0;
    const responses=Number(review.peerResponses)||0;
    const active=Number(review.peerNominationActive)||0;
    const shortfall=Math.max(0,required-active);
    const approvalShortfall=Math.max(0,required-assigned);
    let message='';
    if(shortfall>0) message=`You must nominate ${shortfall} more peer reviewer${shortfall===1?'':'s'} for this cycle.`;
    else if(approvalShortfall>0) message=`You have nominated enough peers, but ${approvalShortfall} more approval${approvalShortfall===1?' is':'s are'} still required before HR can open peer review.`;
    else message='Your required peer reviewers are approved.';
    return `<article class="card"><div class="section-head"><div><h3>${esc(cycleName(review.cycle))} — required peer nominations</h3><p class="muted">At least ${required} approved peer reviewers are required. Rejected nominations do not count and must be replaced.</p></div><span class="status ${assigned>=required?'green':'amber'}">${assigned}/${required} approved</span></div>
      <div class="detail-grid"><div><span class="muted">Active nominations</span><strong>${active}/${required}</strong></div><div><span class="muted">Awaiting manager decision</span><strong>${pending}</strong></div><div><span class="muted">Peer responses received</span><strong>${responses}</strong></div><div><span class="muted">Anonymous results</span><strong>${responses>=required?'Available at release':`Locked until ${required} responses`}</strong></div></div>
      <p class="notice ${shortfall>0?'warn':''}">${esc(message)}</p></article>`;
  }
  function itemCard(item) {
    return `<article class="personal-item"><div class="section-head"><div><h3>${esc(item.title)}</h3><p class="muted">Due ${esc(item.due || 'Not set')}${item.owner?` · Set by ${esc(item.owner)}`:''}</p></div>
      <button class="btn small" data-work-type="${item.type}" data-work-id="${item.id}">Open goal</button></div>${progress(item.progress)}</article>`;
  }
  function render() {
    const activeActions=data.plans.filter(p=>p.status!=='cancelled').flatMap(p=>p.actions.filter(a=>a.status!=='cancelled'));
    const allSteps=activeActions.flatMap(a=>a.steps);
    const complete=allSteps.filter(s=>s.completed).length;
    const activeGoals=data.goals.filter(g=>g.progress.status!=='completed').length
      + activeActions.filter(action=>action.progress.status!=='completed').length;
    const pending=data.requests.filter(r=>r.canSubmit).length;
    const reviewAction=(data.reviews || []).find(r=>['open','peer_review'].includes(r.cycle_status) && (Number(r.peerNominationShortfall)>0 || Number(r.peerApprovalShortfall)>0));
    const reviewPrompt=reviewAction
      ? `<div class="notice warn"><div class="section-head"><div><strong>Review action required — ${esc(cycleName(reviewAction.cycle))}</strong><p>${Number(reviewAction.peerNominationShortfall)>0?`Nominate ${Number(reviewAction.peerNominationShortfall)} more peer reviewer${Number(reviewAction.peerNominationShortfall)===1?'':'s'} so you keep at least ${Number(reviewAction.min_peers)||3} active nominations.`:`You have nominated enough peers. Your manager still needs to approve ${Number(reviewAction.peerApprovalShortfall)} reviewer${Number(reviewAction.peerApprovalShortfall)===1?'':'s'} before the cycle can progress.`}</p></div><a class="btn small primary" href="#feedback">Open review actions</a></div></div>`
      : '';
    content.innerHTML=`<div class="grid kpis employee-kpis">
      <div class="card kpi"><span class="label">Active goals</span><strong class="value">${activeGoals}</strong></div>
      <div class="card kpi"><span class="label">Development steps</span><strong class="value">${complete}/${allSteps.length}</strong><span class="muted">Completed steps</span></div>
      <div class="card kpi"><span class="label">Feedback to submit</span><strong class="value">${pending}</strong><a class="btn small" href="#feedback">View requests</a></div></div>${reviewPrompt}
      <section id="development" class="workspace-section"><div class="section-head"><div><h2>My development plans</h2><p class="muted">Open a goal to record progress, blockers or completion.</p></div><button class="btn primary" data-create="pdp">+ Development goal</button></div>
      ${data.plans.map(p=>`<article class="card plan-card"><div class="section-head"><div><h3>${esc(p.summary || 'Development plan')}</h3><p class="muted">Set by ${esc(p.owner)}</p></div><span class="status">${label(p.status)}</span></div>${p.status==='draft'?`<div class="notice"><strong>Your agreement is required</strong><p>Confirm this development plan or ask the manager to revise it.</p><button class="btn small primary" data-agree-pdp="${p.id}">Agree</button> <button class="btn small" data-change-pdp="${p.id}">Request changes</button></div>`:''}<div class="personal-items">${p.actions.map(itemCard).join('') || empty('This plan has no goals yet.')}</div></article>`).join('') || empty('No development plan yet. Add a goal to start your plan.')}
      <div class="section-head"><h2>My goals</h2><button class="btn" data-create="goal">+ Goal</button></div><div class="card">${data.goals.map(itemCard).join('') || empty('No personal goals yet.')}</div></section>
      <section id="improvement" class="workspace-section"><h2>My improvement plans</h2>${data.pips.map(p=>`<article class="card"><div class="section-head"><h3>${esc(p.reason)}</h3><span class="status">${label(p.status)}</span></div><p class="muted">${esc(p.start_date)} to ${esc(p.end_date)} · Manager: ${esc(p.manager)} · HR owner: ${esc(p.hr_owner)}</p>
      ${p.objectives.map(itemCard).join('')}${p.checkins.length?`<details><summary>Check-ins (${p.checkins.length})</summary>${p.checkins.map(c=>`<p>${esc(c.notes)}<br><small class="muted">${esc(c.author)} · ${esc(c.checkin_date)}</small></p>`).join('')}</details>`:''}${p.outcome_note?`<p>${esc(p.outcome_note)}</p>`:''}</article>`).join('') || empty('No improvement plans assigned.')}</section>
      <section id="feedback" class="workspace-section"><h2>Reviews & 360° feedback</h2>
      ${(data.reviews || []).map(peerRequirementCard).join('')}
      <div class="card"><div class="section-head"><div><h3>My peer reviewer nominations</h3><p class="muted">Nominate someone who directly observed your work during an active review cycle.</p></div><button class="btn primary" data-nominate-peer>+ Nominate peer</button></div>
      ${(data.nominations || []).map(nominationCard).join('') || empty('No peer reviewers nominated yet.')}</div>
      <div class="card"><h3>My feedback requests</h3>
      ${data.requests.map(r=>`<div class="personal-item section-head"><div><strong>${r.type==='self'?'Self review':`Feedback for ${esc(r.employee)}`}</strong><p class="muted">${esc(cycleName(r.cycle))} · ${label(r.status)}${r.due?` · Due ${esc(r.due)}`:''}${r.late?' · Overdue (still open)':''}</p>${!r.canSubmit && r.status==='pending'?'<p class="muted">The submission window is closed or has not opened.</p>':''}</div><button class="btn small ${r.canSubmit?'primary':''}" data-feedback="${r.id}">${r.canSubmit?'Open form':'View form'}</button></div>`).join('') || empty('No feedback requests assigned.')}</div>
      ${data.reviews.map(r=>`<article class="card"><h3>${esc(cycleName(r.cycle))}</h3><p><span class="status">${label(r.status)}</span></p>${r.status==='released'?`<p>Final rating: <strong>${r.final_rating==null?'Not rated':`${Number(r.final_rating).toFixed(1)} / 5`}</strong></p><p>${esc(r.manager_summary || '')}</p><h4>Anonymous peer feedback</h4>${r.feedback.length?r.feedback.map(f=>`<p>${esc(f.competency)} <strong>${Number(f.avg_score).toFixed(1)} / 5</strong></p>`).join(''):`<p class="notice">Anonymous feedback is available after at least ${Number(r.min_peers)} peer responses.</p>`}`:'<p class="notice">Your results will appear when the review is formally released.</p>'}</article>`).join('')}</section>`;
    content.querySelectorAll('[data-work-id]').forEach(b=>b.onclick=()=>openWorkItem(b.dataset.workType,Number(b.dataset.workId)));
    content.querySelectorAll('[data-create]').forEach(b=>b.onclick=()=>createForm(b.dataset.create));
    content.querySelectorAll('[data-feedback]').forEach(b=>b.onclick=()=>feedbackForm(Number(b.dataset.feedback)));
    content.querySelector('[data-nominate-peer]')?.addEventListener('click',nominationForm);
    content.querySelectorAll('[data-escalate-nomination]').forEach(b=>b.onclick=()=>escalationForm(Number(b.dataset.escalateNomination)));
    content.querySelectorAll('[data-use-suggested-peer]').forEach(b=>b.onclick=()=>nominationForm({participantId:Number(b.dataset.participant),peerId:Number(b.dataset.useSuggestedPeer)}));
    content.querySelectorAll('[data-agree-pdp]').forEach(b=>b.onclick=()=>agreePdp(Number(b.dataset.agreePdp)));
    content.querySelectorAll('[data-change-pdp]').forEach(b=>b.onclick=()=>requestPdpChanges(Number(b.dataset.changePdp)));
    updatePersonalNavigation();
  }
  async function agreePdp(id){
    try{await request('agree_pdp',{id});await refresh();message.textContent='Development plan agreed.';}catch(error){message.textContent=error.message;}
  }
  function requestPdpChanges(id){
    formDialog('Request PDP changes',`<form class="workspace-form"><label>Changes needed<textarea name="note" required minlength="15" maxlength="2000" rows="5"></textarea></label><button class="btn primary" type="submit">Send request</button></form>`);
    const form=dialog.querySelector('form');form.onsubmit=e=>{e.preventDefault();saveForm(form,'request_pdp_changes',{id,note:form.elements.note.value.trim()});};
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
  function nominationForm(prefill={}) {
    const cycles=(data.nominationOptions || []).filter(option=>option.peers.length);
    if(!cycles.length){message.textContent='No active review cycle with eligible peers is available.';return;}
    const cycleOptions=cycles.map(option=>`<option value="${option.participantId}">${esc(cycleName(option.cycle))}${option.deadline?` · Nominate by ${esc(option.deadline)}`:''}</option>`).join('');
    formDialog('Nominate a peer reviewer',`<p class="muted">Choose someone who worked closely enough with you to give evidence-based feedback. Your assigned manager cannot be nominated because they complete a separate manager review.</p><form id="peerNominationForm" class="workspace-form">
      <label>Review cycle<select name="participantId" required>${cycleOptions}</select></label>
      <label>Proposed peer reviewer<select name="peerId" required></select></label>
      <label>Shared project or deliverable<input name="sharedWork" required minlength="5" maxlength="255" placeholder="Example: Customer onboarding redesign"></label>
      <label>What work did you complete together?<textarea name="collaborationDetails" required minlength="30" maxlength="2000" rows="4" placeholder="Describe the tasks, deliverables, dates or decisions you worked on together."></textarea><small>Be specific enough for your manager to verify the working relationship.</small></label>
      <label>Why can this person review your performance?<textarea name="reviewerJustification" required minlength="30" maxlength="2000" rows="4" placeholder="Explain what they directly observed, such as collaboration, communication, delivery quality or problem solving."></textarea></label>
      <label class="confirm-check"><input name="directKnowledgeConfirmed" type="checkbox" required> I confirm this person directly observed my work during this review period.</label>
      <button class="btn primary" type="submit">Send nomination to manager</button></form>`);
    const form=dialog.querySelector('form');
    const cycleSelect=form.elements.participantId;
    const peerSelect=form.elements.peerId;
    const updatePeers=()=>{const cycle=cycles.find(option=>Number(option.participantId)===Number(cycleSelect.value));peerSelect.innerHTML=(cycle?.peers || []).map(peer=>`<option value="${peer.id}">${esc(peer.name)} — ${esc(peer.jobTitle || 'Job title not set')}${peer.team?` · ${esc(peer.team)}`:''}</option>`).join('');};
    if(prefill.participantId && cycles.some(option=>Number(option.participantId)===Number(prefill.participantId))) cycleSelect.value=String(prefill.participantId);
    cycleSelect.onchange=updatePeers;updatePeers();
    if(prefill.peerId && [...peerSelect.options].some(option=>Number(option.value)===Number(prefill.peerId))) peerSelect.value=String(prefill.peerId);
    form.onsubmit=e=>{e.preventDefault();const f=e.currentTarget;saveForm(f,'create_peer_nomination',{
      participantId:Number(f.elements.participantId.value),peerId:Number(f.elements.peerId.value),sharedWork:f.elements.sharedWork.value.trim(),
      collaborationDetails:f.elements.collaborationDetails.value.trim(),reviewerJustification:f.elements.reviewerJustification.value.trim(),
      directKnowledgeConfirmed:f.elements.directKnowledgeConfirmed.checked,
    });};
  }
  function escalationForm(id) {
    const nomination=(data.nominations || []).find(item=>Number(item.id)===id);
    if(!nomination)return;
    formDialog('Forward decision to HR',`<p>Your manager declined <strong>${esc(nomination.peer)}</strong> as a reviewer.</p><div class="notice warn"><strong>Manager reason</strong><p>${esc(nomination.decisionReason || 'No reason was recorded.')}</p></div>
      <form class="workspace-form"><label>Why should HR review this decision?<textarea name="reason" required minlength="30" maxlength="2000" rows="5" placeholder="Explain why the peer has relevant first-hand knowledge or why the manager decision may be incorrect."></textarea><small>Senior HR or an HR partner will review the nomination evidence and manager decision.</small></label>
      <button class="btn primary" type="submit">Forward to HR</button></form>`);
    const form=dialog.querySelector('form');form.onsubmit=e=>{e.preventDefault();saveForm(form,'escalate_peer_nomination',{id,reason:form.elements.reason.value.trim()});};
  }
  async function feedbackForm(id) {
    try {
      const result=await request(`feedback_form&id=${id}`); const r=result.request;
      formDialog(r.type==='self'?'Self review':`Feedback for ${r.employee}`,`<p>${esc(cycleName(r.cycle))}${r.due?` · Due ${esc(r.due)}`:''}</p><p class="muted">${r.type==='peer'?'Feedback is presented as an anonymous aggregate when enough peers respond.':''}</p>
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
    const requested=location.hash.slice(1);
    const current=['development','improvement','feedback'].includes(requested)?requested:'development';
    const viewCopy={
      development:['Development','Your goals and development plans.'],
      improvement:['Improvement Plans','Your assigned performance improvement plans and progress.'],
      feedback:['Reviews & Feedback','Complete feedback requests and view released review results.']
    };
    document.querySelectorAll('.sidebar .nav-btn[href^="#"]').forEach(link=>link.classList.toggle('active',link.getAttribute('href')===`#${current}`));
    content.querySelectorAll('.workspace-section').forEach(section=>{section.hidden=section.id!==current;});
    content.querySelector('.employee-kpis')?.toggleAttribute('hidden',current!=='development');
    const title=document.querySelector('.topbar .title h1');
    const subtitle=document.querySelector('.topbar .title p');
    if(title)title.textContent=viewCopy[current][0];
    if(subtitle)subtitle.textContent=viewCopy[current][1];
  }
  window.addEventListener('hashchange',updatePersonalNavigation);
  updatePersonalNavigation();
})();
