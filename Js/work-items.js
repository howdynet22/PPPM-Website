(function () {
  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const label = (s) => String(s || '').replaceAll('_',' ').replace(/\b\w/g,c=>c.toUpperCase());
  const statuses = ['not_started','in_progress','blocked','completed'];
  async function request(action, body) {
    const response = await fetch('api.php?action='+action, {
      credentials:'same-origin', cache:'no-store', method:body ? 'POST':'GET',
      headers:{'Content-Type':'application/json', ...(body ? {'X-CSRF-Token':window.currentCsrfToken || ''}: {})},
      ...(body ? {body:JSON.stringify(body)} : {}),
    });
    const result = await response.json();
    if (response.status===401) window.location.href='index.html';
    if (!result.ok) throw new Error(result.error || 'Unable to save. Please try again.');
    if (result.csrfToken) window.currentCsrfToken=result.csrfToken;
    return result;
  }
  function progress(p) {
    return `<div class="work-progress"><span>${p.completed}/${p.total} steps completed${p.total ? ` · ${p.percent}%` : ' · Add steps to get started'}</span>
      <progress max="100" value="${p.percent}" aria-label="Step completion"></progress>
      <span class="status">${label(p.status)}</span>${p.blocked?'<span class="status red">Blocked</span>':''}${p.overdue?'<span class="status amber">Overdue</span>':''}${p.needsSteps?'<span class="muted">An action still needs steps</span>':''}</div>`;
  }
  let dialog, current, returnFocus;
  function ensureDialog() {
    if (dialog) return;
    dialog=document.createElement('dialog'); dialog.className='work-dialog'; dialog.setAttribute('aria-labelledby','workTitle');
    document.body.append(dialog);
    dialog.addEventListener('close',()=>{current=null; returnFocus?.focus();});
    dialog.addEventListener('click',event=>{if(event.target===dialog && !dialog.querySelector('[aria-busy="true"]')) dialog.close();});
    dialog.addEventListener('cancel',event=>{if(dialog.querySelector('[aria-busy="true"]')) event.preventDefault();});
  }
  async function open(type,id) {
    ensureDialog(); returnFocus=document.activeElement;
    dialog.innerHTML='<h2 id="workTitle">Loading goal…</h2><p role="status">Loading steps and progress.</p><button class="btn" data-close>Close</button>';
    dialog.querySelector('[data-close]').onclick=()=>dialog.close();
    if (!dialog.open) dialog.showModal();
    try { const result=await request(`work_item&type=${encodeURIComponent(type)}&id=${Number(id)}`); current=result.item; render(); }
    catch(error) { dialog.querySelector('[role=status]').textContent=error.message; }
  }
  function render() {
    const item=current;
    dialog.innerHTML=`<div class="section-head"><div><p class="muted">${esc(item.employee)} · ${{goal:'Goal',pdp_action:'Development goal',pip_objective:'Improvement objective'}[item.type]}</p><h2 id="workTitle">${esc(item.title)}</h2></div><button class="btn" data-close aria-label="Close goal">Close</button></div>
      <p>${esc(item.description || 'No additional description.')}</p><p class="muted">Due ${esc(item.due || 'Not set')} · Set by ${esc(item.owner || 'your plan owner')}</p>
      ${progress(item.progress)}${item.locked?'<p class="notice">This plan is closed. Its steps are read-only.</p>':''}
      <p id="workMessage" role="status" aria-live="polite"></p>
      <ol class="work-step-list">${item.steps.map(s=>`<li class="work-step ${s.completed?'is-complete':''}">
        <form data-step="${s.id}"><strong>${esc(s.title)}</strong><div class="step-controls">
          <label>Status<select name="status" aria-label="Status" ${item.canUpdate?'':'disabled'}>${statuses.map(v=>`<option value="${v}" ${s.status===v?'selected':''}>${label(v)}</option>`).join('')}</select></label>
          <label class="step-note">Progress or blocker note <span class="muted">(optional)</span><textarea name="note" maxlength="5000" rows="2" ${item.canUpdate?'':'readonly'}>${esc(s.note)}</textarea></label></div>
          <div class="work-step-meta"><small>Set by ${esc(s.creator)}${s.completedAt?` · Completed ${esc(s.completedAt)}`:''}</small>
          ${item.canUpdate?`<span class="action-group"><button class="btn small" type="button" data-complete="${s.completed?'not_started':'completed'}">${s.completed?'Reopen':'Mark complete'}</button><button class="btn small primary" type="submit">Save step</button></span>`:''}</div>
          ${item.canEdit && s.canEdit?`<details class="definition-editor"><summary>Edit step definition</summary><label>Step title<input name="definition" maxlength="500" value="${esc(s.title)}"></label><div class="action-group"><button class="btn small" type="button" data-rename>Save title</button>${item.steps.length>1?'<button class="btn small" type="button" data-remove>Remove step</button>':''}</div></details>`:''}</form></li>`).join('') || '<li class="empty">No steps yet.</li>'}</ol>
      ${item.canEdit?'<form id="addStepForm" class="step-controls"><label class="step-note">Add an actionable step<input name="title" required maxlength="500" placeholder="Describe one concrete action"></label><button class="btn" type="submit">Add step</button></form>':''}
      ${item.updates?.length?`<h3>Plan notes</h3>${item.updates.map(n=>`<p>${esc(n.note)}<br><small class="muted">${esc(n.author)} · ${esc(n.created_at)}</small></p>`).join('')}`:''}`;
    dialog.querySelector('[data-close]').onclick=()=>dialog.close();
    dialog.querySelectorAll('[data-step]').forEach(form=>{
      form.onsubmit=e=>{e.preventDefault(); save(form);};
      form.querySelector('[data-complete]')?.addEventListener('click',e=>save(form,e.currentTarget.dataset.complete));
      form.querySelector('[data-rename]')?.addEventListener('click',()=>{const title=form.elements.definition.value.trim();mutate(form,()=>request('update_work_step',{id:Number(form.dataset.step),title}));});
      form.querySelector('[data-remove]')?.addEventListener('click',()=>mutate(form,()=>request('delete_work_step',{id:Number(form.dataset.step)})));
    });
    dialog.querySelector('#addStepForm')?.addEventListener('submit',async e=>{
      e.preventDefault(); const form=e.currentTarget;
      await mutate(form,()=>request('add_work_step',{type:item.type,workId:item.id,title:form.elements.title.value.trim()}));
    });
  }
  async function mutate(form,operation) {
    const inputs=[...dialog.querySelectorAll('button,input,textarea,select')];
    const original=inputs.map(el=>el.disabled); inputs.forEach(el=>el.disabled=true); form.setAttribute('aria-busy','true');
    const msg=dialog.querySelector('#workMessage'); msg.textContent='Saving…';
    let saved=false;
    try {
      await operation(); saved=true;
      window.dispatchEvent(new Event('pppm:data-changed'));
      const result=await request(`work_item&type=${current.type}&id=${current.id}`);
      current=result.item; render(); dialog.querySelector('#workMessage').textContent='Saved.';
    } catch(error) {
      msg.textContent=(saved?'Saved, but the latest view could not load. Reopen this goal. ': 'Your changes were not saved. ')+error.message;
      msg.className='notice warn';
      inputs.forEach((el,i)=>el.disabled=original[i]); form.removeAttribute('aria-busy');
    }
  }
  async function save(form,status) {
    const step=current.steps.find(s=>s.id===Number(form.dataset.step));
    const body={id:step.id,version:step.version,status:status || form.elements.status.value,note:form.elements.note.value};
    await mutate(form,()=>request('set_step_status',body));
  }
  // Refresh visible step data after another user edits, but never overwrite a draft.
  async function refreshDialog() {
    if (!current || !dialog.open || document.hidden || dialog.querySelector('[aria-busy=true]')) return;
    const dirty=[...dialog.querySelectorAll('[data-step]')].some(form=>{
      const step=current.steps.find(s=>s.id===Number(form.dataset.step));
      return step && (form.elements.status.value!==step.status || form.elements.note.value!==step.note || (form.elements.definition && form.elements.definition.value!==step.title));
    }) || Boolean(dialog.querySelector('#addStepForm input')?.value);
    if (dirty) return;
    try {
      const result=await request(`work_item&type=${current.type}&id=${current.id}`);
      if (current && JSON.stringify(result.item)!==JSON.stringify(current)) {current=result.item; render();}
    } catch (_) { /* A user's draft is retained; explicit actions show errors. */ }
  }
  setInterval(refreshDialog,15000);
  window.addEventListener('focus',refreshDialog);
  window.PPPM={request,esc,label,progress,openWorkItem:open};
})();
