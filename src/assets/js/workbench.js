(() => {
  'use strict';
  const wb = document.querySelector('[data-workbench]');
  if (!wb) return;
  const text = wb.querySelector('[data-wb-text]');
  const written = wb.querySelector('[data-wb-written]');
  const spoken = wb.querySelector('[data-wb-spoken]');
  const audio = wb.querySelector('[data-wb-audio]');
  const status = wb.querySelector('[data-wb-status]');
  const hear = wb.querySelector('[data-wb-hear]');
  const save = wb.querySelector('[data-wb-save]');
  const layer = wb.querySelector('[data-wb-layer]');
  const whole = wb.querySelector('[data-wb-whole]');

  // "try this" on a paragraph loads its text into the workbench
  document.querySelectorAll('[data-try]').forEach(button => button.addEventListener('click', () => {
    text.value = button.dataset.try;
    wb.scrollIntoView({behavior: 'smooth', block: 'start'});
    status.textContent = 'Select a word in the text below, or type it in "Written", then say how it should sound.';
    written.focus();
  }));

  // selecting a word in the original or narration text fills "Written"
  document.addEventListener('mouseup', () => {
    const selection = window.getSelection();
    const picked = selection ? selection.toString().trim() : '';
    if (!picked || picked.length > 100 || picked.includes('\n')) return;
    const anchor = selection.anchorNode && (selection.anchorNode.nodeType === 1 ? selection.anchorNode : selection.anchorNode.parentElement);
    if (!anchor || !anchor.closest('.review-source, .review-narration')) return;
    written.value = picked.replace(/[.,;:!?"“”]+$/g, '').replace(/^["“”]+/g, '');
    if (!spoken.value) spoken.value = written.value;
    const item = anchor.closest('.review-item');
    const trigger = item && item.querySelector('[data-try]');
    if (trigger && !text.value) text.value = trigger.dataset.try;
  });

  async function post(url, body) {
    const response = await fetch(url, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({_token: wb.dataset.token, ...body})});
    if (!response.ok) {
      const failure = await response.json().catch(() => ({message: `Request failed (HTTP ${response.status}).`}));
      throw new Error(failure.message);
    }
    return response;
  }

  hear.addEventListener('click', async () => {
    if (!text.value.trim()) { status.textContent = 'Choose a paragraph or type some text first.'; return; }
    hear.disabled = true;
    status.textContent = 'Generating…';
    try {
      const temp = written.value.trim() ? [{pattern: written.value.trim(), replacement: spoken.value}] : [];
      const response = await post(wb.dataset.preview, {
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
      await post(wb.dataset.rule, {layer: scope, ref, pattern: written.value.trim(), replacement: spoken.value, whole_word: whole.checked});
      status.textContent = 'Saved. Reloading: the chapter now shows the new text and needs approving again.';
      setTimeout(() => location.reload(), 900);
    } catch (error) {
      status.textContent = error.message;
      save.disabled = false;
    }
  });
})();
