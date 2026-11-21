(() => {
  'use strict';
  const panel = document.querySelector('[data-audio-panel]');
  if (!panel) return;
  const generate = panel.querySelector('[data-generate]');
  const retry = panel.querySelector('[data-retry]');
  const cancel = panel.querySelector('[data-stop]');
  const bar = panel.querySelector('[data-bar]');
  const barBox = panel.querySelector('.audio-progress');
  const status = panel.querySelector('[data-audio-status]');
  let polling = false;

  async function call(action, extra = {}) {
    const response = await fetch(panel.dataset.endpoint, {
      method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({_token: panel.dataset.token, project_id: panel.dataset.project, section: panel.dataset.section, action, ...extra})
    });
    const body = await response.json().catch(() => ({ok: false, message: `Unreadable response (HTTP ${response.status}).`}));
    if (!response.ok || !body.ok) throw new Error(body.message || `Request failed (HTTP ${response.status}).`);
    return body;
  }

  function busy(on) {
    [generate, retry, panel.querySelector('[data-regenerate]')].forEach(button => { if (button) button.disabled = on; });
    panel.querySelectorAll('[data-reset]').forEach(button => { button.disabled = on; });
    cancel.hidden = !on;
    cancel.disabled = false;
  }

  function show(job, counts, workerOnline) {
    if (counts && counts.total) {
      barBox.hidden = false;
      bar.style.width = `${Math.round(counts.done / counts.total * 100)}%`;
    }
    const progress = counts && counts.total ? `${counts.done} of ${counts.total} segments done` + (counts.failed ? `, ${counts.failed} failed` : '') : 'Preparing';
    if (job && job.status === 'QUEUED') {
      status.textContent = workerOnline ? `Waiting for the worker to start this chapter. ${progress}.` : 'Queued, but the background worker has not been seen. It will start when the worker is running.';
    } else {
      status.textContent = `${progress}. This keeps running if you close the page.`;
    }
  }

  /** The job runs in the background worker. This only watches it, so the page can be closed and reopened. */
  async function watch() {
    if (polling) return;
    polling = true;
    busy(true);
    try {
      for (;;) {
        const state = await call('job');
        show(state.job, state.counts, state.worker_online);
        if (!state.job || !['QUEUED', 'RUNNING'].includes(state.job.status)) break;
        await new Promise(resolve => setTimeout(resolve, 2000));
      }
    } catch (error) {
      status.textContent = error.message;
      polling = false;
      return busy(false);
    }
    location.reload();
  }

  async function start(beforeEnqueue) {
    busy(true);
    try {
      if (beforeEnqueue) await beforeEnqueue();
      await call('enqueue');
    } catch (error) {
      status.textContent = error.message;
      return busy(false);
    }
    watch();
  }

  generate.addEventListener('click', () => start());
  if (retry) retry.addEventListener('click', () => start(() => call('reset', {segment_id: ''})));
  const regenerate = panel.querySelector('[data-regenerate]');
  if (regenerate) regenerate.addEventListener('click', () => {
    if (confirm('Throw away all audio for this chapter and generate it again?')) start(() => call('reset_all'));
  });
  cancel.addEventListener('click', async () => {
    cancel.disabled = true;
    try { await call('cancel'); } catch (error) { status.textContent = error.message; }
  });
  panel.querySelectorAll('[data-reset]').forEach(button => button.addEventListener('click', () => start(() => call('reset', {segment_id: button.dataset.reset}))));

  if (panel.dataset.jobActive === '1') watch();
})();
