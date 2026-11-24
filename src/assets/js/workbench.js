(() => {
  'use strict';
  const book = document.querySelector('[data-book]');
  const wb = document.querySelector('[data-workbench]');
  if (!book && !wb) return;
  const field = name => (wb ? wb.querySelector(name) : null);
  const text = field('[data-wb-text]');
  const written = field('[data-wb-written]');
  const spoken = field('[data-wb-spoken]');
  const audio = field('[data-wb-audio]');
  const status = field('[data-wb-status]');
  const hear = field('[data-wb-hear]');
  const hearOriginal = field('[data-wb-hear-original]');
  const findExample = field('[data-wb-example]');
  const save = field('[data-wb-save]');
  const layer = field('[data-wb-layer]');
  const whole = field('[data-wb-whole]');
  const from = field('[data-wb-from]');
  let editing = null;

  async function post(url, token, body) {
    const response = await fetch(url, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({_token: token, ...body})});
    if (!response.ok) {
      const failure = await response.json().catch(() => ({message: `Request failed (HTTP ${response.status}).`}));
      throw new Error(failure.message);
    }
    return response;
  }
  const bookPost = body => post(book.dataset.endpoint, book.dataset.token, {project_id: book.dataset.project, section: book.dataset.section, ...body});

  let caseFlag = 0;                                   // 1 when the word must match in exactly this case (ALL-CAPS words, case-sensitive rules)
  const escapeRe = s => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const wordRegex = needle => new RegExp(`(?<![\\p{L}\\p{N}_'])${escapeRe(needle)}(?:'[sS])?(?![\\p{L}\\p{N}_'])`, caseFlag ? 'u' : 'iu');

  /** The sentence of the original paragraph in which the word stands as a word (not inside another word). */
  function sentenceOf(source, needle) {
    const sentences = source.split(/(?<=[.!?…]["')\]]*)\s+/);
    let hit = null;
    try { const re = wordRegex(needle); hit = sentences.find(s => re.test(s)); } catch (error) { hit = null; }
    return (hit || sentences.find(s => needle && s.toLowerCase().includes(needle.toLowerCase())) || source).slice(0, 600);
  }

  // ----- examples from the book: sentences where the rule would really apply, with previous and next -----
  const countLabel = field('[data-wb-count]');
  const whereLink = field('[data-wb-where]');
  const prevButton = field('[data-wb-prev]');
  const nextButton = field('[data-wb-next]');
  let examples = {key: '', list: [], index: -1};

  function showCount() {
    if (!countLabel) return;
    const n = examples.list.length;
    countLabel.textContent = !examples.key ? 'no example yet' : (n === 0 ? 'not in the book' : (examples.index >= 0 ? `example ${examples.index + 1} of ${n}${n >= 60 ? '+' : ''}` : `${n}${n >= 60 ? '+' : ''} examples`));
    if (whereLink) {
      const spot = examples.index >= 0 && examples.where ? examples.where[examples.index] : null;
      whereLink.hidden = !spot;
      if (spot) { whereLink.href = spot.url; whereLink.textContent = 'Open in the book: ' + spot.label; }
    }
    if (prevButton) prevButton.disabled = n < 2;
    if (nextButton) nextButton.disabled = n < 2;
  }

  let matchMode = '';
  const matchParam = () => (matchMode === 'regex' ? '&match=regex' : '');

  async function loadExamples(word) {
    if (!wb || !word) return [];
    const key = `${word}|${whole && !whole.checked ? 0 : 1}|${caseFlag}|${matchMode}`;
    if (examples.key === key) return examples.list;
    try {
      const base = wb.dataset.example;
      const url = `${base}${base.includes('?') ? '&' : '?'}project=${encodeURIComponent(wb.dataset.project || '')}&q=${encodeURIComponent(word)}&whole=${whole && !whole.checked ? 0 : 1}&case=${caseFlag}${matchParam()}`;
      const reply = await (await fetch(url, {credentials: 'same-origin'})).json();
      examples = {key, list: Array.isArray(reply.sentences) ? reply.sentences : [], where: Array.isArray(reply.where) ? reply.where : [], index: -1};
    } catch (error) {
      examples = {key, list: [], index: -1};
    }
    return examples.list;
  }

  async function stepExample(direction) {
    const word = written.value.trim();
    if (!word) { status.textContent = 'Fill in "Written" first.'; return; }
    const list = await loadExamples(word);
    if (!list.length) { showCount(); status.textContent = `No sentence in the book contains “${word}” as a word.`; return; }
    const at = list.indexOf(text.value);
    const index = at >= 0 ? (at + direction + list.length) % list.length : (direction > 0 ? 0 : list.length - 1);
    examples.index = index;
    text.value = list[index];
    showCount();
    status.textContent = '';
    runHear(true);
  }

  /** Text to speech through the app. `rules` false sends the text exactly as given. */
  async function speak({text: spokenText, voice, speed, token, url, rules, project, temp, setId}) {
    const response = await post(url, token, {
      text: spokenText, voice, speed, apply_pronunciation: rules, project_id: project || '', set_id: setId || '', temp_rules: temp || []
    });
    return {blob: await response.blob(), said: decodeURIComponent(response.headers.get('X-Spoken-Text') || '')};
  }

  /** Puts words into the panel; with no paragraph at hand it looks for a sentence in the book. */
  async function load({source, para, shownSpoken, layerValue, label, autoHear, caseSensitive = false, wholeWord = true, match = ''}) {
    if (!wb) return;
    matchMode = match === 'regex' ? 'regex' : '';
    caseFlag = caseSensitive ? 1 : 0;
    if (whole) whole.checked = wholeWord;
    written.value = source;
    spoken.value = shownSpoken;
    from.textContent = label ? `Now: ${label}.` : '';
    if (layerValue) [...layer.options].forEach(o => { if (o.value === layerValue) layer.value = layerValue; });
    status.textContent = '';
    examples = {key: '', list: [], index: -1};
    if (para) {
      text.value = sentenceOf(para.dataset.source, source);
      showCount();
    } else {
      status.textContent = 'Looking for a sentence in the book…';
      const list = await loadExamples(source);
      if (list.length) { examples.index = 0; text.value = list[0]; status.textContent = ''; }
      else { text.value = source; status.textContent = `No sentence in the book contains “${source}” as a word, so only the word is played.`; }
      showCount();
    }
    wb.scrollIntoView({block: 'nearest'});
    if (autoHear) runHear(true);
  }

  // ----- the panel's two buttons: as the book spells it, and with the rules and my change -----
  async function runHear(withChange) {
    if (!text.value.trim()) { status.textContent = 'Pick a word first, or type a sentence.'; return; }
    const button = withChange ? hear : hearOriginal;
    button.disabled = true;
    status.textContent = 'Generating…';
    try {
      const temp = withChange && written.value.trim() ? [{pattern: written.value.trim(), replacement: spoken.value, case_sensitive: !!caseFlag, match_type: matchMode === 'regex' ? 'regex' : (whole && !whole.checked ? 'anywhere' : 'word')}] : [];
      const result = await speak({text: text.value, voice: wb.dataset.voice, speed: Number(wb.dataset.speed), token: wb.dataset.token, url: wb.dataset.preview, rules: withChange, project: wb.dataset.project, setId: wb.dataset.set, temp});
      if (audio.dataset.url) URL.revokeObjectURL(audio.dataset.url);
      audio.dataset.url = URL.createObjectURL(result.blob);
      audio.src = audio.dataset.url;
      audio.hidden = false;
      const show = result.said || text.value;
      status.textContent = `${withChange ? 'With my change' : 'As written'}: ${show.slice(0, 240)}${show.length > 240 ? '…' : ''}`;
      audio.play().catch(() => {});
    } catch (error) {
      status.textContent = error.message;
    } finally {
      button.disabled = false;
    }
  }

  if (wb) {
    hear.addEventListener('click', () => runHear(true));
    hearOriginal.addEventListener('click', () => runHear(false));
    findExample.addEventListener('click', async () => {
      const word = written.value.trim();
      if (!word) { status.textContent = 'Fill in "Written" first.'; return; }
      status.textContent = 'Looking for sentences in the book…';
      const list = await loadExamples(word);
      if (list.length) { examples.index = 0; text.value = list[0]; status.textContent = ''; }
      else status.textContent = `No sentence in the book contains “${word}” as a word.`;
      showCount();
    });
    if (prevButton) prevButton.addEventListener('click', () => stepExample(-1));
    if (nextButton) nextButton.addEventListener('click', () => stepExample(1));
    showCount();
    save.addEventListener('click', async () => {
      if (!written.value.trim()) { status.textContent = 'Fill in "Written" first.'; return; }
      if (spoken.value === '' && !confirm('The spoken form is empty. That removes this text from the narration. Save it anyway?')) return;
      const [scope, ref] = layer.value.split('|');
      save.disabled = true;
      try {
        await post(wb.dataset.rule, wb.dataset.token, {layer: scope, ref, pattern: written.value.trim(), replacement: spoken.value, whole_word: whole.checked, case_sensitive: !!caseFlag, ...(matchMode === 'regex' ? {match_type: 'regex'} : {})});
        status.textContent = 'Saved. Reloading to show the new text.';
        setTimeout(() => location.reload(), 900);
      } catch (error) {
        status.textContent = error.message;
        save.disabled = false;
      }
    });

    // ----- "try" buttons on rule rows and ALL-CAPS rows -----
    document.addEventListener('click', event => {
      const button = event.target.closest('[data-wb-load]');
      if (!button) return;
      event.preventDefault();
      load({source: button.dataset.written, shownSpoken: button.dataset.spoken, layerValue: button.dataset.layer, label: button.dataset.label, autoHear: true,
        caseSensitive: button.dataset.case === '1', wholeWord: button.dataset.whole !== '0', match: button.dataset.match || ''});
    });
  }

  if (!book) return;

  // ----- click a marked word in the chapter: load it and play it -----
  book.addEventListener('click', event => {
    if (editing) return;
    const mark = event.target.closest('mark.chg');
    if (!mark || !wb) return;
    const selection = window.getSelection();
    if (selection && !selection.isCollapsed) return;       // a drag-selection is handled on mouseup
    load({source: mark.dataset.src, para: mark.closest('.para'), shownSpoken: mark.dataset.out, layerValue: mark.dataset.layer || `project|${wb.dataset.project}`, label: mark.dataset.label, autoHear: true, caseSensitive: mark.dataset.kind === 'caps'});
  });
  book.addEventListener('keydown', event => {
    if (event.key === 'Enter' && event.target.matches && event.target.matches('mark.chg')) event.target.click();
  });

  // ----- select words: the written form is rebuilt from the book's own words behind any marked ones -----
  book.addEventListener('mouseup', () => {
    if (editing || !wb) return;
    const selection = window.getSelection();
    if (!selection || selection.isCollapsed || selection.rangeCount === 0) return;
    const shown = selection.toString().trim();
    if (!shown || shown.length > 100 || shown.includes('\n')) return;
    const range = selection.getRangeAt(0);
    const anchor = range.commonAncestorContainer.nodeType === 1 ? range.commonAncestorContainer : range.commonAncestorContainer.parentElement;
    const para = anchor && anchor.closest('.para');
    if (!para) return;
    let source = '';
    const walk = node => {
      if (node.nodeType === 3) source += node.nodeValue;
      else if (node.nodeType === 1) { if (node.matches('mark.chg') && node.dataset.src !== undefined) source += node.dataset.src; else node.childNodes.forEach(walk); }
    };
    range.cloneContents().childNodes.forEach(walk);
    source = source.trim().replace(/^[“"]+|[”".,;:!?]+$/g, '');
    if (!source) return;
    load({source, para, shownSpoken: shown.replace(/^[“"]+|[”".,;:!?]+$/g, ''), layerValue: `project|${wb.dataset.project}`, label: source === shown ? 'unchanged text' : 'changed by rules', autoHear: false})
      .then(() => { status.textContent = 'Type how it should sound in "Spoken", then compare with the two buttons.'; });
  });

  // ----- listen to one paragraph: as it will be narrated, or exactly as the book spells it -----
  let player = null;
  let playingButton = null;
  function stopPlayer() {
    if (player) { player.pause(); player = null; }
    if (playingButton) { playingButton.textContent = playingButton.dataset.label; playingButton.disabled = false; playingButton = null; }
  }
  async function listen(button, para) {
    const wasPlaying = playingButton === button;
    stopPlayer();
    if (wasPlaying) return;
    const asWritten = button.dataset.listen === 'source';
    const spokenText = (asWritten ? para.dataset.source : para.dataset.narration).trim();
    if (!spokenText) return;
    button.dataset.label ||= button.textContent;
    playingButton = button;
    button.textContent = 'Loading…';
    try {
      // the narration already has the rules and edits in it; the book's own text is sent untouched
      const result = await speak({text: spokenText, voice: book.dataset.voice, speed: Number(book.dataset.speed), token: book.dataset.token, url: book.dataset.preview, rules: false, project: ''});
      if (playingButton !== button) return;
      const url = URL.createObjectURL(result.blob);
      player = new Audio(url);
      player.addEventListener('ended', stopPlayer);
      button.textContent = '■ Stop';
      await player.play();
    } catch (error) {
      stopPlayer();
      alert(error.message);
    }
  }

  // ----- edit a paragraph in place -----
  function reloadAt(id) {
    const url = new URL(location.href);
    url.searchParams.set('b', id);
    location.href = url.toString();
  }
  function endEdit(restore) {
    if (!editing) return;
    const {para, el, html, bar} = editing;
    el.removeAttribute('contenteditable');
    para.classList.remove('editing');
    if (restore) el.innerHTML = html;
    bar.remove();
    editing = null;
  }
  function startEdit(para) {
    endEdit(true);
    stopPlayer();
    const el = para.querySelector('[data-para-text]');
    const html = el.innerHTML;
    el.textContent = para.dataset.narration;
    el.setAttribute('contenteditable', 'plaintext-only');
    if (el.contentEditable !== 'plaintext-only') el.setAttribute('contenteditable', 'true');
    para.classList.add('editing');
    const bar = document.createElement('div');
    bar.className = 'edit-bar';
    bar.innerHTML = '<button type="button" data-save-edit>Save</button> <button type="button" class="button secondary" data-cancel-edit>Cancel</button> <small>Change the words you want. Ctrl+Enter saves, Esc cancels. Saved as changes to the book\'s own words, so other rules keep applying.</small>';
    el.after(bar);
    editing = {para, el, html, bar};
    el.focus();
    const range = document.createRange();
    range.selectNodeContents(el);
    range.collapse(false);
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
    bar.querySelector('[data-save-edit]').addEventListener('click', commit);
    bar.querySelector('[data-cancel-edit]').addEventListener('click', () => endEdit(true));
    el.addEventListener('keydown', event => {
      if (event.key === 'Escape') { event.preventDefault(); endEdit(true); }
      if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) { event.preventDefault(); commit(); }
      else if (event.key === 'Enter') event.preventDefault();             // one paragraph, one line of text
    });
  }
  async function commit() {
    if (!editing) return;
    const {para, el, bar} = editing;
    const typed = el.innerText.replace(/\s+/g, ' ').trim();
    bar.querySelector('[data-save-edit]').disabled = true;
    try {
      if (typed === para.dataset.narration.trim()) { endEdit(true); return; }
      await bookPost({action: 'save_paragraph', block_id: para.dataset.block, text: typed});
      reloadAt(para.dataset.block);
    } catch (error) {
      bar.querySelector('small').textContent = error.message;
      bar.querySelector('[data-save-edit]').disabled = false;
    }
  }

  book.addEventListener('click', async event => {
    const para = event.target.closest('.para');
    if (!para) return;
    const listenButton = event.target.closest('[data-listen]');
    if (listenButton) { listen(listenButton, para); return; }
    if (event.target.closest('[data-edit]')) { startEdit(para); return; }
    const skip = event.target.closest('[data-skip]');
    const reset = event.target.closest('[data-reset-edits]');
    if (!skip && !reset) return;
    try {
      if (skip) await bookPost({action: 'skip', block_id: para.dataset.block, excluded: skip.dataset.skip === '1'});
      else await bookPost({action: 'reset_paragraph', block_id: para.dataset.block});
      reloadAt(para.dataset.block);
    } catch (error) {
      alert(error.message);
    }
  });
})();
