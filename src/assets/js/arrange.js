// Arrange: every change is sent at once and can be undone. Renames and read-aloud switches update in place;
// changes to the shape of the book (combine, split, suggestions, undo) reload the page at the same scroll position.
(function () {
  const page = document.getElementById('arrange');
  if (!page) return;
  const endpoint = page.dataset.endpoint;
  const token = page.dataset.token;
  const project = page.dataset.project;
  const textUrl = page.dataset.text;
  const status = page.querySelector('[data-status]');
  const undoBtn = page.querySelector('[data-undo]');
  const bulk = page.querySelector('[data-bulk]');
  const rows = () => [...page.querySelectorAll('[data-row]')];
  const say = text => { status.textContent = text; };

  try {
    const y = sessionStorage.getItem('arrangeScroll');
    if (y !== null) { sessionStorage.removeItem('arrangeScroll'); window.scrollTo(0, Number(y)); }
  } catch (e) { /* storage unavailable */ }
  const reload = focusId => {
    try { sessionStorage.setItem('arrangeScroll', String(window.scrollY)); } catch (e) { /* storage unavailable */ }
    const u = new URL(location.href);
    if (focusId) u.searchParams.set('section', focusId);
    location.href = u.toString();
  };

  async function send(action, data = {}) {
    say('Saving…');
    try {
      const r = await fetch(endpoint, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(Object.assign({_token: token, project, action}, data))});
      const x = await r.json();
      if (!r.ok || !x.ok) throw new Error(x.message || 'That did not work.');
      say(x.message === 'Saved.' ? 'Saved.' : x.message);
      if (undoBtn) { undoBtn.disabled = !x.undo; undoBtn.textContent = x.undo ? 'Undo: ' + x.undo.replace(/\.$/, '') : 'Nothing to undo'; }
      return x;
    } catch (error) {
      say(error.message);
      return null;
    }
  }

  // ---- names: saved when you leave the field or press Enter
  page.addEventListener('keydown', e => { if (e.target.matches('[data-name]') && e.key === 'Enter') { e.preventDefault(); e.target.blur(); } });
  page.addEventListener('change', async e => {
    const input = e.target.closest('[data-name]');
    if (!input) return;
    const title = input.value.trim();
    if (title === '' ) { input.value = input.dataset.saved; say('A name cannot be empty.'); return; }
    if (title === input.dataset.saved) return;
    input.setAttribute('data-unsaved', '');
    const row = input.closest('[data-row]');
    if (await send('rename', {id: row.dataset.row, title})) { input.dataset.saved = title; if (row.classList.contains('is-current')) readerTitle.textContent = title; }
    else input.value = input.dataset.saved;
    input.removeAttribute('data-unsaved');
  });

  // ---- read aloud switches
  page.addEventListener('click', async e => {
    const sw = e.target.closest('[data-keep]');
    if (!sw) return;
    const row = sw.closest('[data-row]');
    const on = sw.getAttribute('aria-checked') !== 'true';
    sw.setAttribute('aria-checked', String(on));
    row.classList.toggle('is-off', !on);
    if (!(await send('keep', {ids: [row.dataset.row], value: on}))) { sw.setAttribute('aria-checked', String(!on)); row.classList.toggle('is-off', on); }
    if (row.classList.contains('is-current')) readerMeta.dataset.on = String(on), updateMeta();
  });

  // ---- order
  page.addEventListener('click', async e => {
    const b = e.target.closest('[data-move]');
    if (!b) return;
    const row = b.closest('[data-row]');
    if (await send('move', {id: row.dataset.row, delta: Number(b.dataset.move)})) reload(row.dataset.row);
  });

  // ---- selection and the bulk bar
  const selected = () => rows().filter(r => r.querySelector('[data-select]').checked).map(r => r.dataset.row);
  const refreshBulk = () => {
    const n = selected().length;
    bulk.hidden = n === 0;
    page.querySelector('[data-bulk-count]').textContent = n + (n === 1 ? ' section selected' : ' sections selected');
  };
  page.addEventListener('change', e => {
    if (e.target.matches('[data-select-all]')) rows().forEach(r => { r.querySelector('[data-select]').checked = e.target.checked; });
    if (e.target.matches('[data-select], [data-select-all]')) refreshBulk();
  });

  page.addEventListener('click', async e => {
    const b = e.target.closest('[data-act]');
    if (!b) return;
    const ids = selected();
    const act = b.dataset.act;
    if (act === 'clear') { rows().forEach(r => { r.querySelector('[data-select]').checked = false; }); page.querySelector('[data-select-all]').checked = false; refreshBulk(); return; }
    if (act === 'keep_on' || act === 'keep_off') { if (await send('keep', {ids, value: act === 'keep_on'})) reload(); return; }
    if (act === 'combine') {
      if (ids.length < 2) { say('Select two or more sections next to each other.'); return; }
      if (!confirm('Combine the ' + ids.length + ' selected sections into one chapter? Hand edits and skipped paragraphs are kept; the joined chapter needs a new approval and new audio. You can undo this.')) return;
      if (await send('combine', {ids})) reload(ids[0]);
      return;
    }
    if (act === 'name_chapters') {
      if (!ids.length) { say('Select the sections that are chapters first.'); return; }
      if (await send('name_chapters', {ids, start: Number(page.querySelector('[data-start]').value) || 1, headings: page.querySelector('[data-headings]').checked})) reload();
      return;
    }
    if (act === 'suggest_keep' || act === 'suggest_names' || act === 'undo') { if (await send(act, {ids})) reload(); }
  });

  // ---- the reading panel
  const reader = page.querySelector('[data-reader]');
  const readerTitle = reader.querySelector('[data-reader-title]');
  const readerMeta = reader.querySelector('[data-reader-meta]');
  const readerText = reader.querySelector('[data-reader-text]');
  let current = null;
  const updateMeta = () => {
    const row = rows().find(r => r.dataset.row === current);
    if (!row) return;
    const on = row.querySelector('[data-keep]').getAttribute('aria-checked') === 'true';
    readerMeta.textContent = row.children[3].textContent.trim() + ' words · ' + (on ? 'read aloud' : 'left out');
  };
  async function open(id, scroll) {
    const row = rows().find(r => r.dataset.row === id);
    if (!row) return;
    current = id;
    rows().forEach(r => r.classList.toggle('is-current', r === row));
    if (scroll) row.scrollIntoView({block: 'nearest'});
    readerTitle.textContent = row.querySelector('[data-name]').value;
    updateMeta();
    readerText.textContent = '';
    try {
      const x = await (await fetch(textUrl + '&section=' + encodeURIComponent(id), {credentials: 'same-origin'})).json();
      if (!x.ok || current !== id) return;
      if (!x.blocks.length) { const p = document.createElement('p'); p.className = 'quiet'; p.textContent = 'No text in this section.'; readerText.append(p); return; }
      x.blocks.forEach((b, i) => {
        if (i > 0) {
          const split = document.createElement('button');
          split.type = 'button';
          split.className = 'split-here';
          split.textContent = 'Split here';
          split.setAttribute('aria-label', 'Split the section before: ' + b.text.slice(0, 60));
          split.addEventListener('click', async () => {
            const title = prompt('Name of the new section that starts here:', readerTitle.textContent + ' Part 2');
            if (title === null) return;
            if (await send('split', {id, block: b.id, title})) reload(id);
          });
          readerText.append(split);
        }
        const p = document.createElement('p');
        if (b.heading) p.className = 'h';
        p.textContent = b.text;
        readerText.append(p);
      });
      if (x.more > 0) { const p = document.createElement('p'); p.className = 'quiet'; p.textContent = '…and ' + x.more + ' more paragraphs.'; readerText.append(p); }
    } catch (error) { readerText.textContent = 'The text could not be loaded.'; }
  }
  page.addEventListener('click', e => {
    const row = e.target.closest('[data-row]');
    if (!row || e.target.closest('button, input[type=checkbox]')) return;
    if (row.dataset.row !== current) open(row.dataset.row, false);
  });
  page.addEventListener('focusin', e => { const row = e.target.closest('[data-row]'); if (row && e.target.matches('[data-name]') && row.dataset.row !== current) open(row.dataset.row, false); });
  reader.querySelectorAll('[data-step]').forEach(b => b.addEventListener('click', () => {
    const list = rows();
    const at = list.findIndex(r => r.dataset.row === current);
    const next = list[Math.min(list.length - 1, Math.max(0, at + Number(b.dataset.step)))];
    if (next) open(next.dataset.row, true);
  }));
  if (page.dataset.focus) open(page.dataset.focus, true);
})();
