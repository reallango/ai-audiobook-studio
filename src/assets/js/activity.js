// Activity: a button in the top bar that says what is running, and opens a list of background work
// (chapter audio, M4B builds). On a book's pages it shows that book; elsewhere, every book.
(function () {
  const root = document.getElementById('activity');
  if (!root) return;
  const url = root.dataset.url;
  const reloadWhenDone = root.dataset.reload === 'yes';
  const chapterUrl = root.dataset.chapterUrl || '';
  let open = false;
  let last = null;
  let wasActive = 0;
  let timer = 0;

  const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  };
  const plural = (n, word) => n + ' ' + word + (n === 1 ? '' : 's');
  const stateText = {QUEUED: 'Waiting', RUNNING: 'Working', DONE: 'Done', FAILED: 'Failed'};
  const since = at => {
    if (!at) return '';
    const s = Math.max(0, Math.round((Date.now() - new Date(String(at).replace(' ', 'T') + 'Z').getTime()) / 1000));
    return s < 60 ? s + 's' : Math.floor(s / 60) + 'm ' + String(s % 60).padStart(2, '0') + 's';
  };

  const button = el('button', 'activity-btn');
  button.type = 'button';
  button.setAttribute('aria-haspopup', 'true');
  button.setAttribute('aria-expanded', 'false');
  const dot = el('span', 'activity-dot');
  dot.setAttribute('aria-hidden', 'true');
  const label = el('span', '', 'Activity');
  button.append(dot, label);
  const pop = el('section', 'activity-pop');
  pop.hidden = true;
  pop.setAttribute('aria-label', 'Activity');
  root.append(button, pop);

  const setOpen = value => {
    open = value;
    pop.hidden = !open;
    button.setAttribute('aria-expanded', String(open));
    if (open && last) renderList(last);
  };
  button.addEventListener('click', () => setOpen(!open));
  document.addEventListener('click', e => { if (open && !root.contains(e.target)) setOpen(false); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && open) { setOpen(false); button.focus(); } });

  function headline(data) {
    const running = data.items.filter(i => i.status === 'RUNNING' || i.status === 'QUEUED');
    const failed = data.items.filter(i => i.status === 'FAILED');
    if (running.length) return plural(running.length, 'task') + ' running';
    if (failed.length) return plural(failed.length, 'task') + ' failed';
    return 'Nothing running';
  }

  function renderButton(data) {
    const failed = data.items.some(i => i.status === 'FAILED');
    button.classList.toggle('busy', data.active > 0);
    button.classList.toggle('failed', data.active === 0 && failed);
    label.textContent = headline(data);
  }

  function renderList(data) {
    pop.textContent = '';
    const head = el('header');
    head.append(el('h2', '', 'Activity'), el('span', 'act-sub', data.worker && data.task_worker ? 'Workers online' : 'A worker has not been seen recently'));
    pop.append(head);
    if (data.ahead > 0) pop.append(el('p', 'act-warn', 'Waiting behind ' + plural(data.ahead, 'chapter') + ' from ' + data.ahead_books.join(', ') + '. This book starts when those are done.'));
    const chaptersWaiting = data.items.some(i => i.kind === 'chapter' && (i.status === 'QUEUED' || i.status === 'RUNNING'));
    const tasksWaiting = data.items.some(i => i.kind !== 'chapter' && (i.status === 'QUEUED' || i.status === 'RUNNING'));
    if (chaptersWaiting && !data.worker) pop.append(el('p', 'act-warn', 'The audio worker has not been seen recently, so waiting chapters will not start until it runs.'));
    if (tasksWaiting && !data.task_worker) pop.append(el('p', 'act-warn', 'The build worker has not been seen recently, so the M4B build will not start until it runs.'));
    if (!data.items.length) { pop.append(el('p', 'act-empty', 'Nothing is running. Chapter audio and audiobook builds show here while they work, and for half an hour after.')); return; }
    const list = el('ul');
    const queued = data.items.filter(i => i.kind === 'chapter' && i.status === 'QUEUED');
    const shown = data.items.filter(i => !(i.kind === 'chapter' && i.status === 'QUEUED')).slice(0, 20);
    for (const i of shown) list.append(row(i, data.scope === 'all'));
    if (queued.length) {
      const li = el('li');
      const r = el('div', 'act-row');
      r.append(el('span', 'act-name', plural(queued.length, 'more chapter')), el('span', 'act-state QUEUED', 'Waiting'));
      li.append(r, el('div', 'act-sub', [...new Set(queued.map(q => q.book))].join(', ')));
      list.append(li);
    }
    pop.append(list);
  }

  function row(i, showBook) {
    const li = el('li');
    const r = el('div', 'act-row');
    const name = i.section
      ? Object.assign(document.createElement('a'), {href: chapterUrl + encodeURIComponent(i.project) + '&section=' + encodeURIComponent(i.section), className: 'act-name', textContent: i.label})
      : el('span', 'act-name', i.label);
    r.append(name, el('span', 'act-state ' + i.status, i.status === 'RUNNING' && !i.total ? since(i.started) : (stateText[i.status] || i.status)));
    li.append(r);
    const sub = [];
    if (showBook || i.kind !== 'chapter') sub.push(i.book);
    if (i.kind === 'chapter' && i.total > 0 && i.status === 'RUNNING') sub.push('part ' + i.done + ' of ' + i.total);
    if (i.message && i.status !== 'RUNNING') sub.push(i.message);
    if (sub.length) li.append(el('div', 'act-sub', sub.join(' · ')));
    if (i.kind === 'chapter' && i.total > 0 && i.status === 'RUNNING') {
      const bar = el('div', 'act-bar');
      const fill = el('span');
      fill.style.width = Math.round(100 * i.done / i.total) + '%';
      bar.append(fill);
      li.append(bar);
    }
    return li;
  }

  async function poll() {
    clearTimeout(timer);
    let delay = 20000;
    try {
      const data = await (await fetch(url, {credentials: 'same-origin'})).json();
      if (data.ok) {
        last = data;
        renderButton(data);
        if (open) renderList(data);
        // a page that shows audio state refreshes once the work is done, unless something on it is unsaved
        if (wasActive > 0 && data.active === 0 && reloadWhenDone && !document.querySelector('[data-unsaved]')) { location.reload(); return; }
        wasActive = data.active;
        delay = data.active > 0 ? 3000 : 20000;
      }
    } catch (error) { /* tried again on the next poll */ }
    timer = setTimeout(poll, delay);
  }
  document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
  window.addEventListener('activity:refresh', poll);       // pages fire this after queuing work
  poll();
})();
