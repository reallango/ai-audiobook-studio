// Source and structure page: suggestions that fill in the editor. Saving is left to the page's own "Save all changes".
(function () {
  const editor = document.getElementById('logical-editor');
  if (!editor) return;
  const cards = () => [...editor.querySelectorAll('[data-section-id]')];
  const note = document.querySelector('[data-setup-note]');
  const say = text => { if (note) note.textContent = text; };
  const touch = el => el.dispatchEvent(new Event('input', {bubbles: true}));
  const selected = () => cards().filter(c => c.querySelector('[data-select]')?.checked);
  const target = () => (selected().length ? selected() : cards());

  async function suggestions() {
    const response = await fetch(editor.dataset.endpoint, {
      method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({_token: editor.dataset.token, action: 'suggest'})
    });
    const reply = await response.json();
    if (!reply.ok) throw new Error(reply.message || 'Could not get suggestions.');
    return new Map(reply.suggestions.map(s => [s.id, s]));
  }

  const setTitle = (card, text) => {
    const input = card.querySelector('[data-title]');
    if (!input || input.value === text) return false;
    input.value = text;
    touch(input);
    return true;
  };
  const setIncluded = (card, on) => {
    const box = card.querySelector('[data-included]');
    if (!box || box.checked === on) return false;
    box.checked = on;
    box.dispatchEvent(new Event('change', {bubbles: true}));
    return true;
  };

  async function run(label, work) {
    say(label + '…');
    try { say(await work()); } catch (error) { say(error.message); }
  }

  document.querySelector('[data-suggest-keep]')?.addEventListener('click', () => run('Reading the sections', async () => {
    const found = await suggestions();
    let changed = 0, kept = 0;
    for (const card of target()) {
      const s = found.get(card.dataset.sectionId);
      if (!s) continue;
      if (s.keep) kept++;
      if (setIncluded(card, s.keep)) changed++;
    }
    return changed ? `Suggested keeping ${kept}. ${changed} change${changed === 1 ? '' : 's'} are waiting: check them, then save.` : 'The boxes already match the suggestion.';
  }));

  document.querySelector('[data-suggest-names]')?.addEventListener('click', () => run('Reading the headings', async () => {
    const found = await suggestions();
    let named = 0, none = 0;
    for (const card of target()) {
      const s = found.get(card.dataset.sectionId);
      if (!s || !s.name) { none++; continue; }
      if (setTitle(card, s.name)) named++;
    }
    return `Named ${named} section${named === 1 ? '' : 's'} from their text; ${none} had no heading to use. Check the names, then save.`;
  }));

  document.querySelector('[data-number-chapters]')?.addEventListener('click', () => run('Naming the chapters', async () => {
    const chosen = selected();
    if (!chosen.length) return 'Select the sections that are chapters first (the numbered boxes on the left), or use the select links.';
    const keepTitles = document.querySelector('[data-number-keep-titles]')?.checked;
    const found = keepTitles ? await suggestions() : new Map();
    let n = Math.max(1, parseInt(document.querySelector('[data-number-start]')?.value, 10) || 1);
    for (const card of chosen) {
      const s = found.get(card.dataset.sectionId);
      // a heading found in the text that is not just "Chapter N" or "Part N" is kept as the chapter's subtitle
      const subtitle = s && s.name && !/^(chapter|part|book|section)\b/i.test(s.name) ? ': ' + s.name : (s && s.name && /:\s/.test(s.name) ? ': ' + s.name.split(/:\s/).slice(1).join(': ') : '');
      setTitle(card, 'Chapter ' + n + subtitle);
      setIncluded(card, true);
      n++;
    }
    return `Named ${chosen.length} selected section${chosen.length === 1 ? '' : 's'} Chapter ${n - chosen.length} to ${n - 1} and kept them. Check, then save.`;
  }));

  const pick = test => { for (const card of cards()) { const box = card.querySelector('[data-select]'); if (box) { box.checked = test(card); box.dispatchEvent(new Event('change', {bubbles: true})); } } };
  document.querySelector('[data-select-all]')?.addEventListener('click', () => pick(() => true));
  document.querySelector('[data-select-none]')?.addEventListener('click', () => pick(() => false));
  document.querySelector('[data-select-kept]')?.addEventListener('click', () => pick(card => card.querySelector('[data-included]')?.checked));
})();
