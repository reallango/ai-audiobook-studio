(() => {
  'use strict';
  const panel = document.querySelector('[data-audio-panel]');
  if (!panel) return;
  const generate = panel.querySelector('[data-generate]');
  const retry = panel.querySelector('[data-retry]');
  const stop = panel.querySelector('[data-stop]');
  const bar = panel.querySelector('[data-bar]');
  const barBox = panel.querySelector('.audio-progress');
  const status = panel.querySelector('[data-audio-status]');
  let stopping = false;

  async function call(action, extra = {}) {
    const response = await fetch(panel.dataset.endpoint, {
      method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({_token: panel.dataset.token, project_id: panel.dataset.project, section: panel.dataset.section, action, ...extra})
    });
    const body = await response.json().catch(() => ({ok: false, message: `Unreadable response (HTTP ${response.status}).`}));
    if (!response.ok || !body.ok) throw new Error(body.message || `Request failed (HTTP ${response.status}).`);
    return body;
  }

  function show(counts) {
    if (!counts || !counts.total) return;
    barBox.hidden = false;
    bar.style.width = `${Math.round(counts.done / counts.total * 100)}%`;
    status.textContent = `${counts.done} of ${counts.total} segments done` + (counts.failed ? `, ${counts.failed} failed` : '') + '…';
  }

  function busy(on) {
    [generate, retry].forEach(button => { if (button) button.disabled = on; });
    panel.querySelectorAll('[data-reset]').forEach(button => { button.disabled = on; });
    stop.hidden = !on;
    stop.disabled = false;
  }

  /** Prepare if needed, generate one segment per request until none are left, then join the chapter. */
  async function run() {
    stopping = false;
    busy(true);
    try {
      if (panel.dataset.needsPrepare === '1') {
        status.textContent = 'Preparing segments…';
        const prep = await call('prepare');
        status.textContent = `${prep.total} segments, ${prep.reused} reused, ${prep.created} to generate.`;
      }
      let waits = 0;
      let counts = null;
      for (;;) {
        if (stopping) {
          status.textContent = 'Stopped. Press the button again to continue where it left off.';
          return location.reload();
        }
        const step = await call('next');
        counts = step.counts;
        show(counts);
        if (step.segment) { waits = 0; continue; }
        // another tab may be working on the last segment: wait a little, then give up
        if (step.busy && waits++ < 30) { await new Promise(resolve => setTimeout(resolve, 2000)); continue; }
        break;
      }
      if (counts && counts.failed > 0) {
        status.textContent = `${counts.failed} segment(s) failed. Open the segment list for the reason, then retry.`;
        return location.reload();
      }
      status.textContent = 'Joining the chapter audio…';
      await call('assemble');
      location.reload();
    } catch (error) {
      status.textContent = error.message;
      busy(false);
    }
  }

  async function resetThenRun(segmentId) {
    busy(true);
    try {
      await call('reset', {segment_id: segmentId});
      panel.dataset.needsPrepare = '0';
    } catch (error) {
      status.textContent = error.message;
      return busy(false);
    }
    run();
  }

  generate.addEventListener('click', run);
  if (retry) retry.addEventListener('click', () => resetThenRun(''));
  stop.addEventListener('click', () => { stopping = true; stop.disabled = true; });
  panel.querySelectorAll('[data-reset]').forEach(button => button.addEventListener('click', () => resetThenRun(button.dataset.reset)));
})();
