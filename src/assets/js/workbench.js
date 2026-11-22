(() => {
  'use strict';
  const book = document.querySelector('[data-book]');
  if (!book) return;
  const wb = document.querySelector('[data-workbench]');
  const field = name => (wb ? wb.querySelector(name) : null);
  const text = field('[data-wb-text]');
  const written = field('[data-wb-written]');
  const spoken = field('[data-wb-spoken]');
  const audio = field('[data-wb-audio]');
  const status = field('[data-wb-status]');
  const hear = field('[data-wb-hear]');
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

  /** The sentence of the original paragraph that contains the words. */
  function sentenceOf(source, needle) {
    const sentences = source.split(/(?<=[.!?…]["')\]]*)\s+/);
    const hit = sentences.find(s => needle && s.toLowerCase().includes(needle.toLowerCase()));
    return (hit || source).slice(0, 600);
  }

  /** Puts words into the workbench. */
  function load({source, para, shownSpoken, layerValue, label, autoHear}) {
    if (!wb) return;
    written.value = source;
    spoken.value = shownSpoken;
    text.value = sentenceOf(para.dataset.source, source);
    from.textContent = label ? `Now: ${label}.` : '';
    if (layerValue) [...layer.options].forEach(o => { if (o.value === layerValue) layer.value = layerValue; });
    status.textContent = '';
    if (autoHear) hear.click();
  }

  // ----- click a marked word: load it and play it -----
  book.addEventListener('click', event => {
    if (editing) return;
    const mark = event.target.closest('mark.chg');
    if (!mark || !wb) return;
    const selection = window.getSelection();
    if (selection && !selection.isCollapsed) return;       // a drag-selection is handled on mouseup
    const para = mark.closest('.para');
    load({source: mark.dataset.src, para, shownSpoken: mark.dataset.out, layerValue: mark.dataset.layer || `project|${wb.dataset.project}`, label: `${mark.dataset.label}`, autoHear: true});
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
    load({source, para, shownSpoken: shown.replace(/^[“"]+|[”".,;:!?]+$/g, ''), layerValue: `project|${wb.dataset.project}`, label: source === shown ? 'unchanged text' : 'changed by rules', autoHear: false});
    status.textContent = 'Type how it should sound in "Spoken", then press Hear it.';
  });

  if (wb) {
    hear.addEventListener('click', async () => {
      if (!text.value.trim()) { status.textContent = 'Click a word in the text first, or type a sentence.'; return; }
      hear.disabled = true;
      status.textContent = 'Generating…';
      try {
        const temp = written.value.trim() ? [{pattern: written.value.trim(), replacement: spoken.value}] : [];
        const response = await post(wb.dataset.preview, wb.dataset.token, {
          text: text.value, voice: wb.dataset.voice, speed: Number(wb.dataset.speed),
          apply_pronunciation: true, project_id: wb.dataset.project, temp_rules: temp
        });
        const blob = await response.blob();
        if (audio.dataset.url) URL.revokeObjectURL(audio.dataset.url);
        audio.dataset.url = URL.createObjectURL(blob);
        audio.src = audio.dataset.url;
        audio.hidden = false;
        const said = decodeURIComponent(response.headers.get('X-Spoken-Text') || '');
        status.textContent = said ? `Spoken as: ${said.slice(0, 240)}${said.length > 240 ? '…' : ''}` : 'Done.';
        audio.play().catch(() => {});
      } catch (error) {
        status.textContent = error.message;
      } finally {
        hear.disabled = false;
      }
    });

    save.addEventListener('click', async () => {
      if (!written.value.trim()) { status.textContent = 'Fill in "Written" first.'; return; }
      if (spoken.value === '' && !confirm('The spoken form is empty. That removes this text from the narration. Save it anyway?')) return;
      const [scope, ref] = layer.value.split('|');
      save.disabled = true;
      try {
        await post(wb.dataset.rule, wb.dataset.token, {layer: scope, ref, pattern: written.value.trim(), replacement: spoken.value, whole_word: whole.checked});
        status.textContent = 'Saved. Reloading: the chapter now shows the new text and needs approving again.';
        setTimeout(() => location.reload(), 900);
      } catch (error) {
        status.textContent = error.message;
        save.disabled = false;
      }
    });
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
