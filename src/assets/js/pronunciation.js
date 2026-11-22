(() => {
  'use strict';
  const form = document.querySelector('.pron-test');
  if (!form) return;
  const hear = form.querySelector('[data-hear]');
  const audio = form.querySelector('[data-audio]');
  const status = form.querySelector('[data-status]');

  hear.addEventListener('click', async () => {
    const text = form.elements.test.value.trim();
    if (!text) { status.textContent = 'Enter some text first.'; return; }
    hear.disabled = true;
    status.textContent = 'Generating…';
    try {
      const response = await fetch(form.dataset.endpoint, {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
          _token: form.dataset.token, text, voice: form.elements.test_voice.value,
          speed: 1.0, apply_pronunciation: true, project_id: form.elements.test_project.value, set_id: form.dataset.set || ''
        })
      });
      if (!response.ok) {
        const failure = await response.json().catch(() => ({message: `Request failed (HTTP ${response.status}).`}));
        throw new Error(failure.message);
      }
      const blob = await response.blob();
      if (audio.dataset.url) URL.revokeObjectURL(audio.dataset.url);
      audio.dataset.url = URL.createObjectURL(blob);
      audio.src = audio.dataset.url;
      audio.hidden = false;
      const spoken = decodeURIComponent(response.headers.get('X-Spoken-Text') || '');
      status.textContent = spoken ? `Spoken as: ${spoken}` : 'Done.';
      audio.play().catch(() => {});
    } catch (error) {
      status.textContent = error.message || 'Generation failed.';
    } finally {
      hear.disabled = false;
    }
  });
})();

// After moving a rule the page reloads; bring that rule back into view so the list does not jump to the top.
(function () {
  try {
    document.addEventListener('submit', event => {
      const form = event.target;
      const action = form.elements && form.elements.action ? form.elements.action.value : '';
      if (action === 'move_rule' && form.elements.id) sessionStorage.setItem('pronMovedRule', form.elements.id.value);
    });
    const id = sessionStorage.getItem('pronMovedRule');
    if (!id) return;
    sessionStorage.removeItem('pronMovedRule');
    const row = document.getElementById('rule-' + id);
    if (!row) return;
    row.scrollIntoView({block: 'center'});
    row.classList.add('just-moved');
    setTimeout(() => row.classList.remove('just-moved'), 2500);
  } catch (error) { /* storage unavailable: the list simply reloads at the top */ }
})();
