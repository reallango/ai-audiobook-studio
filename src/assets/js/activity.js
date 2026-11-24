// Activity bar: background work for the project (chapter audio, building the M4B), refreshed while anything is running.
(function () {
  const bar = document.getElementById('activity');
  if (!bar) return;
  const url = bar.dataset.url;
  const reloadOnFinish = (bar.dataset.reload || '').split(' ').includes(bar.dataset.route || '');
  let open = null;            // null: follow the state (open while busy or failed), true/false once the person chooses
  let wasActive = 0;
  let timer = 0;

  const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  };
  const plural = (n, word) => n + ' ' + word + (n === 1 ? '' : 's');
  const statusText = {QUEUED: 'Waiting', RUNNING: 'Working', DONE: 'Done', FAILED: 'Failed'};
  const elapsed = since => {
    if (!since) return '';
    const s = Math.max(0, Math.round((Date.now() - new Date(since.replace(' ', 'T') + 'Z').getTime()) / 1000));
    return s < 60 ? s + 's' : Math.floor(s / 60) + 'm ' + String(s % 60).padStart(2, '0') + 's';
  };

  function summary(data) {
    const items = data.items;
    const chapters = items.filter(i => i.kind === 'chapter');
    const running = chapters.filter(i => i.status === 'RUNNING');
    const queued = chapters.filter(i => i.status === 'QUEUED');
    const failed = items.filter(i => i.status === 'FAILED');
    const parts = [];
    const m4b = items.find(i => i.kind === 'm4b' && (i.status === 'RUNNING' || i.status === 'QUEUED'));
    if (data.ahead > 0 && !running.length) parts.push('Waiting for another book (' + plural(data.ahead, 'chapter') + ' ahead)');
    if (running.length || queued.length) {
      const total = running.length + queued.length;
      const r = running[0];
      parts.push('Generating audio: ' + plural(total, 'chapter') + ' left' + (r && r.total ? ' (' + r.label.replace('Audio for ', '') + ', ' + r.done + ' of ' + r.total + ' parts)' : ''));
    }
    if (m4b) parts.push(m4b.status === 'RUNNING' ? 'Building the M4B (' + elapsed(m4b.started) + ')' : 'M4B waiting for its turn');
    if (!parts.length && failed.length) parts.push(plural(failed.length, 'task') + ' failed');
    if (!parts.length) parts.push('Finished: ' + plural(items.length, 'recent task'));
    return parts.join(' · ');
  }

  function row(i) {
    const li = el('li', 'act-item act-' + i.status.toLowerCase());
    li.append(el('span', 'act-state', statusText[i.status] || i.status));
    const name = i.section ? Object.assign(document.createElement('a'), {href: bar.dataset.chapterUrl + encodeURIComponent(i.section), textContent: i.label}) : el('span', '', i.label);
    li.append(name);
    if (i.kind === 'chapter' && i.total > 0 && (i.status === 'RUNNING' || i.status === 'QUEUED')) {
      const progress = el('progress');
      progress.max = i.total; progress.value = i.done;
      li.append(progress, el('small', '', i.done + '/' + i.total));
    } else if (i.status === 'RUNNING' && i.started) {
      li.append(el('small', '', elapsed(i.started)));
    }
    if (i.message && i.status !== 'RUNNING') li.append(el('small', 'act-msg', i.message));
    return li;
  }

  function render(data) {
    bar.textContent = '';
    bar.hidden = false;
    if (!data.items.length) {
      bar.classList.remove('busy', 'has-failed');
      const idle = el('div', 'act-head act-idle');
      idle.append(el('span', 'act-title', 'Activity'), el('span', 'act-summary', 'Nothing running. Chapter audio and M4B builds show here while they work.'),
        el('span', 'act-toggle', data.worker && data.task_worker ? 'workers online' : 'a worker has not been seen recently'));
      bar.append(idle);
      return;
    }
    bar.classList.toggle('busy', data.active > 0);
    const failed = data.items.some(i => i.status === 'FAILED');
    bar.classList.toggle('has-failed', failed);
    const shown = open === null ? (data.active > 0 || failed) : open;
    const head = el('button', 'act-head');
    head.type = 'button';
    head.setAttribute('aria-expanded', String(shown));
    head.append(el('span', 'act-title', 'Activity'), el('span', 'act-summary', summary(data)), el('span', 'act-toggle', shown ? 'hide' : 'show'));
    head.addEventListener('click', () => { open = !shown; render(data); });
    bar.append(head);
    if (data.ahead > 0) bar.append(el('p', 'act-warn', 'Waiting behind ' + plural(data.ahead, 'chapter') + ' from another book (' + data.ahead_books.join(', ') + '). Yours start when those are done.'));
    const chaptersWaiting = data.items.some(i => i.kind === 'chapter' && (i.status === 'QUEUED' || i.status === 'RUNNING'));
    const tasksWaiting = data.items.some(i => i.kind !== 'chapter' && (i.status === 'QUEUED' || i.status === 'RUNNING'));
    if (chaptersWaiting && !data.worker) bar.append(el('p', 'act-warn', 'The audio worker has not been seen recently, so waiting chapters will not start until it is running.'));
    if (tasksWaiting && !data.task_worker) bar.append(el('p', 'act-warn', 'The build worker has not been seen recently, so the M4B build will not start until it is running.'));
    if (shown) {
      const list = el('ul', 'act-list');
      for (const i of data.items.slice(0, 25)) list.append(row(i));
      if (data.items.length > 25) list.append(el('li', 'act-more', plural(data.items.length - 25, 'more task') + ' not shown'));
      bar.append(list);
    }
  }

  async function poll() {
    clearTimeout(timer);
    let delay = 20000;
    try {
      const data = await (await fetch(url, {credentials: 'same-origin'})).json();
      if (data.ok) {
        render(data);
        if (wasActive > 0 && data.active === 0 && reloadOnFinish) { location.reload(); return; }
        wasActive = data.active;
        delay = data.active > 0 ? 3000 : 20000;
      }
    } catch (error) { /* the next poll tries again */ }
    timer = setTimeout(poll, delay);
  }
  document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
  poll();
})();
