(function () {
  const API = 'api.php?action=';

  async function request(action, options = {}) {
    const response = await fetch(API + action, {
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/json', ...(options.headers || {})},
      ...options
    });
    const result = await response.json().catch(() => ({ok: false, error: 'Invalid server response'}));
    if (response.status === 401) {
      window.location.href = 'index.html';
      throw new Error('Your session has expired.');
    }
    if (!result.ok) throw new Error(result.error || 'Request failed');
    return result;
  }

  function dbStatus(s) {
    return String(s || '').toLowerCase().replaceAll(' ', '_');
  }

  function titleStatus(s) {
    return String(s || '').replaceAll('_', ' ').replace(/\b\w/g, c => c.toUpperCase());
  }

  function employeeById(id) { return data.employees.find(e => Number(e.id) === Number(id)); }

  function mapDashboard(result) {
    data = {
      employees: result.employees || [],
      peerNominations: result.peerNominations || [],
      goals: result.goals || [],
      pdps: result.pdps || [],
      pips: result.pips || [],
      notifications: result.notifications || [],
      feedback: result.feedback || {},
      managerRatings: result.managerRatings || {},
      competencies: result.competencies || [],
      hrOwners: result.hrOwners || [],
      personal: result.personal || {},
      manager: result.manager || {}
    };
  }

  async function refresh(showToast = false) {
    try {
      const result = await request('dashboard');
      mapDashboard(result);
      renderAll();
      if (showToast) toast('Data refreshed from the database.');
    } catch (err) {
      toast(err.message);
    }
  }

  function save() {}

  function renderOverview() {
    const ratings = data.employees.map(e => e.rating).filter(v => v !== null && v !== undefined);
    const avg = ratings.reduce((a,b) => a+b, 0) / (ratings.length || 1);
    $('#kpiTeam').textContent = data.employees.length;
    $('#kpiRating').textContent = avg.toFixed(1) + '/5';
    $('#kpiReviews').textContent = data.employees.filter(e => e.review !== 'Manager submitted').length;
    const p = data.pdps.length ? data.pdps.reduce((a,b) => a+b.progress, 0) / data.pdps.length : 0;
    $('#kpiPdp').textContent = Math.round(p) + '%';

    $('#overviewTeam').innerHTML = data.employees.map(e => `
      <div class="employee-row">
        <div class="person"><div class="mini-avatar">${initials(e.name)}</div><div><strong>${esc(e.name)}</strong><div class="muted">${esc(e.role)}</div></div></div>
        <div><span class="status ${statusClass(e.review)}">${esc(e.review)}</span></div>
        <div><strong>${e.rating != null ? e.rating.toFixed(1) : '—'}</strong></div>
        <div><div class="progress"><span style="width:${e.pdp}%"></span></div><div class="muted" style="margin-top:4px">PDP ${e.pdp}%</div></div>
        <div><button class="btn small" onclick="openEmployee(${e.id})">View</button></div>
      </div>`).join('') || '<div class="empty">No direct reports found.</div>';

    const selfCount = data.employees.filter(e => e.review !== 'Not started').length;
    const peerCount = data.employees.reduce((n, e) => n + Number(data.feedback[e.id]?.responses || 0), 0);
    const managerCount = data.employees.filter(e => e.review === 'Manager submitted').length;
    $('#reviewMetrics').innerHTML = [
      ['Self reviews', selfCount, data.employees.length],
      ['Peer feedback', peerCount, Math.max(data.employees.length * 3, 1)],
      ['Manager reviews', managerCount, data.employees.length]
    ].map(x => `<div class="metric"><span>${x[0]}</span><div class="progress"><span style="width:${Math.min(100, x[1]/x[2]*100)}%"></span></div><strong>${x[1]}/${x[2]}</strong></div>`).join('');

    const attention = data.employees.filter(e => e.attention).slice(0, 3);
    $('#watchlist').innerHTML = attention.map(e => `<div class="activity-item"><span class="dot"></span><div><strong>${esc(e.name)}</strong> needs development attention. <button class="btn small" onclick="openEmployee(${e.id})">Review</button></div></div>`).join('') || '<div class="empty">No employees currently on the watchlist.</div>';
    $('#activityList').innerHTML = data.notifications.slice(0,4).map(n => `<div class="activity-item"><span class="dot"></span><div>${esc(n.text)}<div class="muted" style="margin-top:3px">${esc(n.time)}</div></div></div>`).join('') || '<div class="empty">No current notifications.</div>';
  }

  function renderTeam() {
    const q = ($('#teamSearch')?.value || '').toLowerCase();
    const f = $('#teamFilter')?.value || 'all';
    let rows = data.employees.filter(e => e.name.toLowerCase().includes(q));
    if (f === 'attention') rows = rows.filter(e => e.attention);
    if (f === 'ontrack') rows = rows.filter(e => !e.attention);
    $('#teamTable').innerHTML = rows.map(e => `<tr>
      <td><div class="person"><div class="mini-avatar">${initials(e.name)}</div><div><strong>${esc(e.name)}</strong><div class="muted">${esc(e.role)}</div></div></div></td>
      <td>${esc(e.role)}</td><td><span class="status ${statusClass(e.review)}">${esc(e.review)}</span></td>
      <td class="score">${e.rating != null ? e.rating.toFixed(1) : '—'}</td><td>${e.goals}</td>
      <td><div class="progress" style="width:90px"><span style="width:${e.pdp}%"></span></div><small class="muted">${e.pdp}%</small></td>
      <td><div class="action-group"><button class="btn small" onclick="openEmployee(${e.id})">View</button><button class="btn small" onclick="newGoal(${e.id})">Goal</button><button class="btn small" onclick="newPdp(${e.id})">PDP</button></div></td>
    </tr>`).join('') || '<tr><td colspan="7" class="empty">No employees match the filter.</td></tr>';
  }

  function renderReviews() {
    const filter = $('#reviewFilter')?.value || 'all';
    let rows = data.employees;
    if (filter === 'pending') rows = rows.filter(e => e.review !== 'Manager submitted');
    if (filter === 'submitted') rows = rows.filter(e => e.review === 'Manager submitted');
    $('#reviewTable').innerHTML = rows.map(e => `<tr>
      <td>${esc(e.name)}</td><td>${esc(e.cycle)}</td><td><span class="status ${statusClass(e.review)}">${esc(e.review)}</span></td>
      <td>${data.feedback[e.id]?.available ? `${data.feedback[e.id].responses} submitted` : `${data.feedback[e.id]?.responses || 0}/${data.feedback[e.id]?.required || 3} required`}</td>
      <td><span class="status ${e.review === 'Manager submitted' ? 'green' : 'amber'}">${e.review === 'Manager submitted' ? 'Submitted' : 'Pending'}</span></td>
      <td><button class="btn small primary" onclick="openReview(${e.id})">${e.review === 'Manager submitted' ? 'View / Edit' : 'Review'}</button></td>
    </tr>`).join('') || '<tr><td colspan="6" class="empty">No review records.</td></tr>';

    $('#peerTable').innerHTML = data.peerNominations.map(n => `<tr><td>${esc(n.employee)}</td><td>${esc(n.peer)}</td><td><span class="status ${statusClass(n.status)}">${esc(n.status)}</span></td><td>
      ${n.status === 'pending' ? `<button class="btn small primary" onclick="decidePeer(${n.id},'approved')">Approve</button> <button class="btn small danger" onclick="decidePeer(${n.id},'rejected')">Reject</button>` : '<span class="muted">Decision recorded</span>'}
    </td></tr>`).join('') || '<tr><td colspan="4" class="empty">No peer nominations.</td></tr>';

    $('#feedbackCards').innerHTML = data.employees.map(e => {
      const f = data.feedback[e.id];
      if (!f || !f.available) return `<div class="card"><div class="section-head"><div><h2>${esc(e.name)}</h2><p>Anonymous 360° results</p></div><span class="status amber">${f?.responses || 0}/${f?.required || 3} responses</span></div><div class="notice warn">Feedback stays hidden until the minimum anonymity threshold is reached.</div></div>`;
      return `<div class="card"><div class="section-head"><div><h2>${esc(e.name)}</h2><p>Aggregated peer feedback · identities hidden</p></div><span class="status green">${f.responses} responses</span></div><div class="metric-list">${f.competencies.map(c => `<div class="metric"><span>${esc(c.name)}</span><div class="progress"><span style="width:${c.score/5*100}%"></span></div><strong>${c.score.toFixed(1)}</strong></div>`).join('')}</div></div>`;
    }).join('') || '<div class="empty">No 360° feedback data.</div>';
  }

  function renderGoals() {
    $('#goalsTable').innerHTML = data.goals.map(g => `<tr><td>${esc(g.employee)}</td><td><strong>${esc(g.title)}</strong><div class="muted">${esc(g.target)}</div></td><td>${g.due}</td><td><div class="progress" style="width:100px"><span style="width:${g.progress}%"></span></div><small class="muted">${g.progress}%</small></td><td><span class="status ${statusClass(g.status)}">${esc(g.status)}</span></td><td><button class="btn small" onclick="editGoal(${g.id})">Update</button></td></tr>`).join('') || '<tr><td colspan="6" class="empty">No goals.</td></tr>';
    $('#pdpTable').innerHTML = data.pdps.map(p => `<tr><td>${esc(p.employee)}</td><td><strong>${esc(p.title)}</strong><div class="muted">${esc(p.description || '')}</div></td><td>${p.due}</td><td><div class="progress" style="width:100px"><span style="width:${p.progress}%"></span></div><small class="muted">${p.progress}%</small></td><td><span class="status ${statusClass(p.status)}">${esc(p.status)}</span></td><td><button class="btn small" onclick="editPdp(${p.id})">Update</button></td></tr>`).join('') || '<tr><td colspan="6" class="empty">No PDP actions.</td></tr>';
  }

  function renderPips() {
    $('#pipTable').innerHTML = data.pips.map(p => `<tr><td>${esc(p.employee)}</td><td>${esc(p.reason)}</td><td>${p.start} → ${p.end}</td><td>${p.objectives.length}</td><td><span class="status ${statusClass(p.status)}">${esc(p.status)}</span></td><td><button class="btn small primary" onclick="openPip(${p.id})">Manage</button></td></tr>`).join('') || '<tr><td colspan="6" class="empty">No PIPs.</td></tr>';
  }

  function renderReports() {
    const ratings = data.employees.map(e => e.rating).filter(v => v != null);
    const avg = ratings.reduce((a,b)=>a+b,0)/(ratings.length||1);
    const goal = data.goals.length ? Math.round(data.goals.reduce((a,b)=>a+b.progress,0)/data.goals.length) : 0;
    const gaps = data.employees.flatMap(e => e.skills.filter(s => s[2] < s[1]).map(s => ({employee:e.name,skill:s[0],required:s[1],current:s[2],gap:s[1]-s[2]})));
    $('#reportAvg').textContent = avg.toFixed(1); $('#reportGoal').textContent = goal+'%'; $('#reportGaps').textContent = gaps.length; $('#reportPips').textContent = data.pips.filter(p => ['active','extended'].includes(p.status)).length;
    $('#performanceChart').innerHTML = data.employees.map(e => { const val=e.rating||0; return `<div class="bar"><strong style="font-size:11px">${val ? val.toFixed(1) : '—'}</strong><i style="height:${val/5*150}px"></i><span>${esc(e.name.split(' ')[0])}</span></div>`; }).join('');
    $('#skillGapList').innerHTML = gaps.sort((a,b)=>b.gap-a.gap).slice(0,8).map(g => `<div class="metric"><span>${esc(g.employee.split(' ')[0])} · ${esc(g.skill)}</span><div class="progress"><span style="width:${Math.min(100,g.current/g.required*100)}%"></span></div><strong>-${g.gap}</strong></div>`).join('') || '<div class="empty">No skill gaps found.</div>';
  }

  function renderPersonal() {
    const p = data.personal || {};
    const cards = [];
    if (p.review) cards.push(`<div class="notice"><strong>Latest review:</strong> ${esc(titleStatus(p.review.status))}${p.review.final_rating != null ? ` · ${Number(p.review.final_rating).toFixed(1)}/5` : ''}<div class="muted" style="margin-top:5px">${esc(p.review.cycle || '')}</div></div>`);
    const goals = p.goals || [];
    $('#personalGoals').innerHTML = goals.length ? goals.map(g => `<div style="padding:12px 0;border-bottom:1px solid var(--line)"><div style="display:flex;justify-content:space-between"><strong style="font-size:12px">${esc(g.title)}</strong><span class="status ${statusClass(g.status)}">${esc(g.status)}</span></div><div class="muted" style="font-size:11px;margin-top:4px">${esc(g.target || '')} · Due ${g.due}</div><div class="progress" style="margin-top:8px"><span style="width:${g.progress}%"></span></div><div class="muted" style="font-size:10px;margin-top:4px">${g.progress}% complete</div></div>`).join('') : '<div class="empty">No personal goals have been recorded.</div>';
    const pdpEl = $('#personalPdp');
    if (pdpEl) pdpEl.innerHTML = (p.pdp || []).map(a => `<div class="activity-item"><span class="dot"></span><div><strong>${esc(a.title)}</strong><div class="muted">${a.progress}% · ${esc(a.status)} · due ${a.due}</div></div></div>`).join('') || '<div class="empty">No personal PDP actions.</div>';
    const fbEl = $('#personalFeedback');
    if (fbEl) fbEl.innerHTML = (p.feedback || []).map(f => `<div class="metric"><span>${esc(f.competency)}</span><div class="progress"><span style="width:${Number(f.avg_score)/5*100}%"></span></div><strong>${Number(f.avg_score).toFixed(1)}</strong></div>`).join('') || '<div class="empty">No feedback available.</div>';
  }

  function renderNotifications() {
    $('#notificationsList').innerHTML = data.notifications.map(n => `<div class="activity-item" style="padding:11px 0;border-bottom:1px solid var(--line)"><span class="dot" style="background:#334155"></span><div style="flex:1"><strong style="font-size:12px">New · </strong>${esc(n.text)}<div class="muted" style="margin-top:4px">${esc(n.time)}</div></div></div>`).join('') || '<div class="empty">No notifications.</div>';
  }

  function renderAll() { renderOverview(); renderTeam(); renderReviews(); renderGoals(); renderPips(); renderReports(); renderPersonal(); renderNotifications(); }

  function openEmployee(id) {
    const e = employeeById(id); if (!e) return;
    const gaps = e.skills.filter(s => s[2] < s[1]);
    openModal(e.name, `<div class="profile-card" style="margin-bottom:18px"><div class="profile-avatar">${initials(e.name)}</div><div><h2 style="margin:0 0 5px">${esc(e.name)}</h2><p class="muted" style="margin:0;font-size:12px">${esc(e.role)} · Direct report</p></div></div><div class="detail-grid"><div class="detail-box"><small>Latest rating</small><strong>${e.rating != null ? e.rating.toFixed(1) : 'Not rated'}</strong></div><div class="detail-box"><small>Review status</small><strong>${esc(e.review)}</strong></div><div class="detail-box"><small>PDP progress</small><strong>${e.pdp}%</strong></div></div><div class="section-head"><div><h2>Skill gaps</h2><p>Required vs current level from the database.</p></div></div><div class="metric-list">${gaps.length ? gaps.map(s => `<div class="metric"><span>${esc(s[0])}</span><div class="progress"><span style="width:${s[1] ? s[2]/s[1]*100 : 0}%"></span></div><strong>${s[2]}/${s[1]}</strong></div>`).join('') : '<div class="notice">No current skill gaps.</div>'}</div>`, `<button class="btn" onclick="closeModal()">Close</button><button class="btn" onclick="closeModal();showPage('reviews');setTimeout(()=>openReview(${id}),100)">Review performance</button><button class="btn primary" onclick="closeModal();newPdp(${id})">Create PDP</button>`);
  }

  function openReview(id) {
    const e = employeeById(id); if (!e || !e.participantId) return toast('This employee has no review participant for the current cycle.');
    const existing = e.rating || 3;
    const savedRatings = Object.fromEntries((data.managerRatings[e.id] || []).map(x => [x.competencyId, x]));
    const peer = data.feedback[e.id];
    const compHtml = data.competencies.map(c => { const v=savedRatings[c.id]?.score || 3; return `<div class="field"><label>${esc(c.name)}</label><select id="comp_${c.id}">${[1,2,3,4,5].map(n=>`<option value="${n}" ${n===v?'selected':''}>${n}</option>`).join('')}</select></div>`; }).join('');
    openModal('Manager Review — '+e.name, `<div class="notice" style="margin-bottom:15px">Peer feedback is aggregated and anonymous. ${peer?.available ? `${peer.responses} peer responses are available.` : `Peer results are hidden until ${peer?.required || 3} responses are submitted.`}</div><div class="form-grid"><div class="field"><label>Overall rating (1–5)</label><select id="reviewRating">${[1,2,3,4,5].map(n=>`<option value="${n}" ${Math.round(existing)===n?'selected':''}>${n}</option>`).join('')}</select></div><div class="field"><label>Review status</label><input value="Manager review" disabled></div>${compHtml}<div class="field full"><label>Manager summary</label><textarea id="reviewSummary" placeholder="Summarise strengths, improvement areas and expectations..."></textarea></div></div>`, `<button class="btn" onclick="closeModal()">Cancel</button><button class="btn primary" onclick="submitReview(${id})">Submit manager review</button>`);
  }

  async function submitReview(id) {
    const e=employeeById(id); if(!e) return;
    const competencies=data.competencies.map(c=>({competencyId:c.id,score:Number($('#comp_'+c.id)?.value||0),comment:''}));
    try { await request('submit_review',{method:'POST',body:JSON.stringify({participantId:e.participantId,rating:Number($('#reviewRating').value),summary:$('#reviewSummary').value.trim(),competencies})}); closeModal(); await refresh(); toast('Manager review saved to the database.'); } catch(err){toast(err.message);}
  }

  async function decidePeer(id,status){ try { await request('decide_peer',{method:'POST',body:JSON.stringify({id,status})}); await refresh(); toast(`Peer nomination ${status}.`); } catch(err){toast(err.message);} }

  function newGoal(employeeId){
    const e=employeeById(employeeId);
    openModal('Create team goal', `<div class="form-grid"><div class="field"><label>Employee</label><select id="goalEmployee">${data.employees.map(x=>`<option value="${x.id}" ${e&&x.id===e.id?'selected':''}>${esc(x.name)}</option>`).join('')}</select></div><div class="field"><label>Due date</label><input id="goalDue" type="date"></div><div class="field full"><label>Goal title</label><input id="goalTitle" placeholder="e.g. Improve delivery reliability"></div><div class="field full"><label>Metric / target</label><textarea id="goalTarget" placeholder="Define a measurable outcome"></textarea></div></div>`, `<button class="btn" onclick="closeModal()">Cancel</button><button class="btn primary" onclick="saveGoal()">Create goal</button>`);
  }

  async function saveGoal(){try{await request('create_goal',{method:'POST',body:JSON.stringify({employeeId:Number($('#goalEmployee').value),title:$('#goalTitle').value.trim(),target:$('#goalTarget').value.trim(),due:$('#goalDue').value})});closeModal();await refresh();toast('Goal saved to the database.');}catch(err){toast(err.message);}}

  function editGoal(id){const g=data.goals.find(x=>x.id===id);if(!g)return;openModal('Update goal',`<div class="form-grid"><div class="field full"><label>Goal</label><input id="editGoalTitle" value="${esc(g.title)}"></div><div class="field"><label>Progress (%)</label><input id="editGoalProgress" type="number" min="0" max="100" value="${g.progress}"></div><div class="field"><label>Status</label><select id="editGoalStatus">${['Not Started','In Progress','Completed','Missed'].map(s=>`<option ${g.status===s?'selected':''}>${s}</option>`).join('')}</select></div></div>`,`<button class="btn" onclick="closeModal()">Cancel</button><button class="btn primary" onclick="saveGoalUpdate(${id})">Save changes</button>`)}
  async function saveGoalUpdate(id){try{await request('update_goal',{method:'POST',body:JSON.stringify({id,title:$('#editGoalTitle').value.trim(),progress:Number($('#editGoalProgress').value),status:$('#editGoalStatus').value})});closeModal();await refresh();toast('Goal updated in the database.');}catch(err){toast(err.message);}}

  function newPersonalGoal(){
    openModal('Create personal goal', `<div class="form-grid"><div class="field full"><label>Goal title</label><input id="personalGoalTitle" placeholder="e.g. Improve coaching cadence"></div><div class="field full"><label>Metric / target</label><textarea id="personalGoalTarget" placeholder="Define a measurable outcome"></textarea></div><div class="field"><label>Due date</label><input id="personalGoalDue" type="date"></div></div>`, `<button class="btn" onclick="closeModal()">Cancel</button><button class="btn primary" onclick="savePersonalGoal()">Create goal</button>`);
  }
  async function savePersonalGoal(){try{await request('create_goal',{method:'POST',body:JSON.stringify({employeeId:Number(data.manager.id),title:$('#personalGoalTitle').value.trim(),target:$('#personalGoalTarget').value.trim(),due:$('#personalGoalDue').value})});closeModal();await refresh();toast('Personal goal saved to the database.');}catch(err){toast(err.message);}}

  function newPdp(employeeId){const e=employeeById(employeeId);openModal('Create PDP action',`<div class="form-grid"><div class="field"><label>Employee</label><select id="pdpEmployee">${data.employees.map(x=>`<option value="${x.id}" ${e&&x.id===e.id?'selected':''}>${esc(x.name)}</option>`).join('')}</select></div><div class="field"><label>Due date</label><input id="pdpDue" type="date"></div><div class="field full"><label>Development action</label><input id="pdpTitle" placeholder="e.g. Complete PHP OOP course"></div><div class="field full"><label>Action description</label><textarea id="pdpDescription" placeholder="Steps, evidence and expected outcome"></textarea></div></div>`,`<button class="btn" onclick="closeModal()">Cancel</button><button class="btn primary" onclick="savePdp()">Create PDP action</button>`)}
  async function savePdp(){try{await request('create_pdp',{method:'POST',body:JSON.stringify({employeeId:Number($('#pdpEmployee').value),title:$('#pdpTitle').value.trim(),description:$('#pdpDescription').value.trim(),due:$('#pdpDue').value})});closeModal();await refresh();toast('PDP action saved to the database.');}catch(err){toast(err.message);}}

  function editPdp(id){const p=data.pdps.find(x=>x.id===id);if(!p)return;openModal('Update PDP action',`<div class="form-grid"><div class="field full"><label>Action</label><input id="editPdpTitle" value="${esc(p.title)}"></div><div class="field"><label>Progress (%)</label><input id="editPdpProgress" type="number" min="0" max="100" value="${p.progress}"></div><div class="field"><label>Status</label><select id="editPdpStatus">${['Not Started','In Progress','Completed','Overdue','Cancelled'].map(s=>`<option ${p.status===s?'selected':''}>${s}</option>`).join('')}</select></div><div class="field full"><label>Progress note</label><textarea id="editPdpNote" placeholder="Add a manager progress note"></textarea></div></div>`,`<button class="btn" onclick="closeModal()">Cancel</button><button class="btn primary" onclick="savePdpUpdate(${id})">Save progress</button>`)}
  async function savePdpUpdate(id){try{await request('update_pdp',{method:'POST',body:JSON.stringify({id,title:$('#editPdpTitle').value.trim(),progress:Number($('#editPdpProgress').value),status:$('#editPdpStatus').value,note:$('#editPdpNote').value.trim()})});closeModal();await refresh();toast('PDP progress saved to the database.');}catch(err){toast(err.message);}}

  function newPip(){openModal('Start Performance Improvement Plan',`<div class="notice warn" style="margin-bottom:14px">Every PIP must have an HR owner and measurable success criteria.</div><div class="form-grid"><div class="field"><label>Employee</label><select id="pipEmployee">${data.employees.map(e=>`<option value="${e.id}">${esc(e.name)}</option>`).join('')}</select></div><div class="field"><label>HR owner</label><select id="pipHr">${data.hrOwners.map(h=>`<option value="${h.id}">${esc(h.name)}</option>`).join('')}</select></div><div class="field"><label>Start date</label><input id="pipStart" type="date"></div><div class="field"><label>End date</label><input id="pipEnd" type="date"></div><div class="field full"><label>Reason</label><textarea id="pipReason" placeholder="Document the performance issue clearly"></textarea></div><div class="field full"><label>First measurable objective</label><input id="pipObjective" placeholder="e.g. Meet 90% of sprint commitments"></div><div class="field full"><label>Measurable success criteria</label><textarea id="pipCriteria" placeholder="How will achievement be objectively measured?"></textarea></div><div class="field"><label>Objective due date</label><input id="pipObjectiveDue" type="date"></div></div>`,`<button class="btn" onclick="closeModal()">Cancel</button><button class="btn primary" onclick="savePip()">Create PIP</button>`)}
  async function savePip(){try{await request('create_pip',{method:'POST',body:JSON.stringify({employeeId:Number($('#pipEmployee').value),hrOwnerId:Number($('#pipHr').value),start:$('#pipStart').value,end:$('#pipEnd').value,reason:$('#pipReason').value.trim(),objective:$('#pipObjective').value.trim(),criteria:$('#pipCriteria').value.trim(),objectiveDue:$('#pipObjectiveDue').value})});closeModal();await refresh();toast('PIP saved to the database.');}catch(err){toast(err.message);}}

  function openPip(id){const p=data.pips.find(x=>x.id===id);if(!p)return;openModal('Manage PIP — '+p.employee,`<div class="detail-grid"><div class="detail-box"><small>Status</small><strong>${esc(p.status)}</strong></div><div class="detail-box"><small>Period</small><strong>${p.start} → ${p.end}</strong></div><div class="detail-box"><small>HR owner</small><strong>${esc(p.hrOwner)}</strong></div><div class="detail-box"><small>Objectives</small><strong>${p.objectives.length}</strong></div></div><div class="section-head"><div><h2>Objectives</h2><p>Success criteria must be measurable.</p></div><button class="btn small" onclick="addPipObjective(${id})">+ Objective</button></div><div class="metric-list">${p.objectives.map(o=>`<div class="notice"><div style="display:flex;justify-content:space-between;gap:10px"><strong>${esc(o.text)}</strong><span class="status ${o.status==='met'?'green':o.status==='partially_met'?'amber':'red'}">${esc(o.status.replace('_',' '))}</span></div><div class="muted" style="margin-top:5px">${esc(o.criteria || '')}</div><div class="muted" style="margin-top:5px">Due ${o.due || '—'}</div><div class="toolbar" style="margin-top:8px"><button class="btn small" onclick="setObjective(${o.id},'not_met',${id})">Not met</button><button class="btn small" onclick="setObjective(${o.id},'partially_met',${id})">Partially met</button><button class="btn small" onclick="setObjective(${o.id},'met',${id})">Met</button></div></div>`).join('') || '<div class="empty">No objectives.</div>'}</div><div class="section-head"><div><h2>Check-ins</h2><p>Record weekly or biweekly manager notes.</p></div><button class="btn small" onclick="addPipCheckin(${id})">+ Check-in</button></div><div class="activity">${p.checkins.map(c=>`<div class="activity-item"><span class="dot"></span><div><strong>${esc(c.date)}</strong><div>${esc(c.notes)}</div></div></div>`).join('') || '<div class="empty">No check-ins recorded.</div>'}</div>`,`<button class="btn" onclick="closeModal()">Close</button><button class="btn primary" onclick="changePipStatus(${id})">Update outcome</button>`)}
  function addPipObjective(id){openModal('Add PIP objective',`<div class="form-grid"><div class="field full"><label>Objective</label><input id="newPipObjective"></div><div class="field full"><label>Measurable success criteria</label><textarea id="newPipCriteria"></textarea></div><div class="field"><label>Due date</label><input id="newPipDue" type="date"></div></div>`,`<button class="btn" onclick="closeModal()">Cancel</button><button class="btn primary" onclick="savePipObjective(${id})">Add objective</button>`)}
  async function savePipObjective(id){try{await request('add_pip_objective',{method:'POST',body:JSON.stringify({pipId:id,objective:$('#newPipObjective').value.trim(),criteria:$('#newPipCriteria').value.trim(),due:$('#newPipDue').value})});closeModal();await refresh();openPip(id);}catch(err){toast(err.message);}}
  async function setObjective(id,status,pipId){try{await request('update_pip_objective',{method:'POST',body:JSON.stringify({id,status})});await refresh();openPip(pipId);toast('PIP objective updated.');}catch(err){toast(err.message);}}
  function addPipCheckin(id){openModal('Record PIP check-in',`<div class="form-grid"><div class="field"><label>Check-in date</label><input id="checkinDate" type="date" value="${new Date().toISOString().slice(0,10)}"></div><div class="field full"><label>Manager notes</label><textarea id="checkinNotes" placeholder="Record progress, blockers, evidence and next steps"></textarea></div></div>`,`<button class="btn" onclick="closeModal()">Cancel</button><button class="btn primary" onclick="savePipCheckin(${id})">Record check-in</button>`)}
  async function savePipCheckin(id){try{await request('add_pip_checkin',{method:'POST',body:JSON.stringify({pipId:id,date:$('#checkinDate').value,notes:$('#checkinNotes').value.trim()})});closeModal();await refresh();openPip(id);}catch(err){toast(err.message);}}
  function changePipStatus(id){const p=data.pips.find(x=>x.id===id);openModal('Update PIP outcome',`<div class="form-grid"><div class="field"><label>Status</label><select id="pipStatus">${['draft','active','extended','successful','unsuccessful','closed'].map(s=>`<option ${p.status===s?'selected':''}>${s}</option>`).join('')}</select></div><div class="field full"><label>Outcome note</label><textarea id="pipOutcomeNote" placeholder="Document the outcome or next step"></textarea></div></div>`,`<button class="btn" onclick="closeModal()">Cancel</button><button class="btn primary" onclick="savePipStatus(${id})">Save outcome</button>`)}
  async function savePipStatus(id){try{await request('update_pip_status',{method:'POST',body:JSON.stringify({id,status:$('#pipStatus').value,note:$('#pipOutcomeNote').value.trim()})});closeModal();await refresh();toast('PIP outcome saved to the database.');}catch(err){toast(err.message);}}

  function generateReport(){const ratings=data.employees.map(e=>e.rating).filter(v=>v!=null);const avg=ratings.reduce((a,b)=>a+b,0)/(ratings.length||1);const goal=data.goals.length?Math.round(data.goals.reduce((a,b)=>a+b.progress,0)/data.goals.length):0;const active=data.pips.filter(p=>['active','extended'].includes(p.status)).length;$('#reportOutput').innerHTML=`<strong>Team performance report generated — ${new Date().toLocaleDateString()}</strong><br><br>Average available rating: <strong>${avg.toFixed(1)}/5</strong><br>Average goal progress: <strong>${goal}%</strong><br>Direct reports: <strong>${data.employees.length}</strong><br>Active/extended PIPs: <strong>${active}</strong><br>Pending manager reviews: <strong>${data.employees.filter(e=>e.review!=='Manager submitted').length}</strong>`;toast('Report generated from live database data.');}
  function exportCSV(){const header='Employee,Role,Rating,Review Status,Goals,PDP Progress\n';const body=data.employees.map(e=>[e.name,e.role,e.rating??'',e.review,e.goals,e.pdp].map(v=>'"'+String(v).replaceAll('"','""')+'"').join(',')).join('\n');const blob=new Blob([header+body],{type:'text/csv'});const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='manager-team-performance.csv';a.click();URL.revokeObjectURL(a.href);}

  async function markNotifications(){ toast('Notifications are derived from current database state; they will clear automatically when the underlying task is completed.'); }

  function wire() {
    $('#generateReportBtn').onclick=generateReport;
    $('#exportTeamBtn').onclick=exportCSV;
    $('#printReportBtn').onclick=()=>window.print();
    $('#refreshFeedback').onclick=()=>refresh(true);
    $('#markNotifications').onclick=markNotifications;
    $('#logoutBtn').onclick=async()=>{if(confirm('Sign out of the manager dashboard?')){await request('logout',{method:'POST'}).catch(()=>{});window.location.href='index.html';}};
    $('#personalGoalBtn').onclick=newPersonalGoal;
    $('#quickActionBtn').onclick=()=>openModal('Quick actions',`<div class="grid three"><button class="btn" onclick="closeModal();newGoal()">Create goal</button><button class="btn" onclick="closeModal();newPdp()">Create PDP</button><button class="btn" onclick="closeModal();newPip()">Start PIP</button><button class="btn" onclick="closeModal();showPage('reviews')">Open reviews</button><button class="btn" onclick="closeModal();showPage('reports')">Team report</button><button class="btn" onclick="closeModal();showPage('personal')">My dashboard</button></div>`,`<button class="btn" onclick="closeModal()">Close</button>`);
  }

  // Expose the new DB-backed functions for inline buttons already present in the page.
  Object.assign(window,{openEmployee,openReview,submitReview,decidePeer,newGoal,saveGoal,editGoal,saveGoalUpdate,newPdp,savePdp,editPdp,savePdpUpdate,newPip,savePip,openPip,addPipObjective,savePipObjective,setObjective,addPipCheckin,savePipCheckin,changePipStatus,savePipStatus,generateReport,exportCSV,renderAll,renderTeam,renderReviews});
  // Replace the original global function bindings too, because the first script
  // installed event listeners that resolve these names at click time.
  globalThis.renderAll = renderAll;
  globalThis.renderTeam = renderTeam;
  globalThis.renderReviews = renderReviews;
  globalThis.openEmployee = openEmployee;
  globalThis.openReview = openReview;
  globalThis.submitReview = submitReview;
  globalThis.decidePeer = decidePeer;
  globalThis.newGoal = newGoal;
  globalThis.saveGoal = saveGoal;
  globalThis.editGoal = editGoal;
  globalThis.saveGoalUpdate = saveGoalUpdate;
  globalThis.newPdp = newPdp;
  globalThis.newPersonalGoal = newPersonalGoal;
  globalThis.savePersonalGoal = savePersonalGoal;
  globalThis.savePdp = savePdp;
  globalThis.editPdp = editPdp;
  globalThis.savePdpUpdate = savePdpUpdate;
  globalThis.newPip = newPip;
  globalThis.savePip = savePip;
  globalThis.openPip = openPip;
  globalThis.addPipObjective = addPipObjective;
  globalThis.savePipObjective = savePipObjective;
  globalThis.setObjective = setObjective;
  globalThis.addPipCheckin = addPipCheckin;
  globalThis.savePipCheckin = savePipCheckin;
  globalThis.changePipStatus = changePipStatus;
  globalThis.savePipStatus = savePipStatus;

  (async function init(){
    wire();
    await refresh();
  })();
})();
