import { test, expect, login, api, password, navigate } from './helpers';

// Last file: this scenario advances the seeded cycle. It also works on its own.
test('review lifecycle: self, peer, manager, release, close, draft and publish', async ({ page, playwright }) => {
  test.setTimeout(90_000);
  await login(page, 'riley');
  const hr = page.request;
  const contexts: any[] = [];
  async function client(name: string) {
    const context = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:8187' });
    contexts.push(context);
    const { user } = await api(context, 'login', { email: `${name}@demo.pppm.test`, password });
    return { request: context, user };
  }
  try {
    const employee = await client('quinn');
    const manager = await client('morgan');
    const peers = [await client('devon'), await client('taylor'), await client('sam')];
    const workspace = (await api(employee.request, 'workspace&scope=employee')).data;
    const review = workspace.reviews.find((r: any) => r.cycle_status === 'open');
    expect(review).toBeTruthy();
    const cycles = (await api(hr, 'hr_dashboard')).cycles;
    const cycle = cycles.find((c: any) => c.status === 'open');
    const participants = (await api(hr, `hr_cycle_participants&id=${cycle.id}`)).participants;
    // Focus this end-to-end exercise on one participant using the real audited HR API.
    for (const participant of participants.filter((p: any) => Number(p.id) !== Number(review.id))) {
      await api(hr, 'hr_participant_exception', { participantId: participant.id, type: 'withdrawn', reason: 'This isolated automated exercise focuses on a single participant.' });
    }
    const self = workspace.requests.find((r: any) => r.type === 'self' && Number(r.participant_id) === Number(review.id));
    async function submit(request: any, id: number) {
      const form = await api(request, `feedback_form&id=${id}`);
      expect(form.request.canSubmit).toBe(true);
      await api(request, 'submit_personal_feedback', { id, ratings: form.competencies.map((c: any) => ({ competencyId: Number(c.id), score: 4, comment: 'Observed delivery, communication and collaborative problem solving.' })) });
    }
    await submit(employee.request, self.id);
    for (const peer of peers) {
      const nomination = await api(employee.request, 'create_peer_nomination', {
        participantId: review.id, peerId: peer.user.id, sharedWork: 'Automated review delivery exercise',
        collaborationDetails: 'We collaborated throughout planning, delivery, verification and handover of the shared project.',
        reviewerJustification: 'This reviewer directly observed communication, delivery quality and problem solving throughout the project.',
        directKnowledgeConfirmed: true,
      });
      await api(manager.request, 'decide_peer', { id: nomination.id, status: 'approved', reason: 'Direct observation of substantial work is supported by the evidence.' });
    }
    expect((await api(hr, 'hr_cycle_advance', { id: cycle.id })).cycle.status).toBe('peer_review');
    for (const peer of peers) {
      const requests = (await api(peer.request, 'workspace&scope=employee')).data.requests;
      const assigned = requests.find((r: any) => r.type === 'peer' && Number(r.participant_id) === Number(review.id));
      expect(assigned).toBeTruthy();
      await submit(peer.request, assigned.id);
    }
    expect((await api(hr, 'hr_cycle_advance', { id: cycle.id })).cycle.status).toBe('manager_review');
    const data = await api(manager.request, 'dashboard');
    const row = data.employees.find((e: any) => Number(e.id) === Number(employee.user.id));
    await api(manager.request, 'submit_review', {
      participantId: Number(row.participantId), version: Number(row.reviewVersion), rating: 4,
      summary: 'The employee delivered consistently and incorporated feedback throughout the review period.',
      competencies: data.competencies.map((c: any) => ({ competencyId: Number(c.id), score: 4, comment: 'Consistent delivery supported by direct evidence.' })),
    });
    expect((await api(hr, 'hr_cycle_advance', { id: cycle.id })).cycle.status).toBe('released');
    const released = (await api(employee.request, 'workspace&scope=employee')).data.reviews.find((r: any) => Number(r.id) === Number(review.id));
    expect(Number(released.final_rating)).toBe(4);
    expect(released.feedback.length).toBeGreaterThan(0);
    expect(JSON.stringify(released.feedback)).not.toContain('respondent');
    expect((await api(hr, 'hr_cycle_advance', { id: cycle.id })).cycle.status).toBe('closed');
    await api(employee.request, 'submit_personal_feedback', { id: self.id, ratings: [] }, 409);
    await page.reload();
    await navigate(page, 'cycles');
    await expect(page.locator('#cyclesTable tr').filter({ hasText: cycle.name })).toContainText('Closed');
    const draft = (await api(hr, 'hr_cycle_create', {
      name: 'Playwright next review cycle', period_start: '2098-01-01', period_end: '2098-04-30',
      self_deadline: '2098-05-05', peer_deadline: '2098-05-12', manager_deadline: '2098-05-20', min_peers: 3,
    })).cycle;
    expect(draft.status).toBe('draft');
    const published = (await api(hr, 'hr_cycle_publish', { id: draft.id })).cycle;
    expect(published.status).toBe('open');
    expect(published.participants).toBeGreaterThan(0);
    expect(published.competencies).toBeGreaterThan(0);
  } finally { for (const context of contexts) await context.dispose(); }
});
