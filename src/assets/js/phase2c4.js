(() => {
  'use strict';
  const form = document.querySelector('#project-settings');
  if (!form) return;
  const save = form.querySelector('[data-save-settings]');
  const state = form.querySelector('[data-settings-state]');
  const toastRegion = document.querySelector('[data-project-toast]');
  const fields = [...form.querySelectorAll('[data-setting]')];
  let baseline = values();
  let saving = false;

  function values() {
    return {
      provider: form.querySelector('[data-setting="provider"]').value.trim(),
      voice: form.querySelector('[data-setting="voice"]').value.trim(),
      speed: Number(form.querySelector('[data-setting="speed"]').value),
      normalize_caps: form.querySelector('[data-setting="normalize_caps"]').checked,
      bitrate: Number(form.querySelector('[data-setting="bitrate"]').value)
    };
  }
  function dirty() { return JSON.stringify(values()) !== JSON.stringify(baseline); }
  function refresh() {
    const changed = dirty();
    form.classList.toggle('is-dirty', changed);
    save.disabled = !changed || saving;
    state.textContent = changed ? 'Unsaved changes' : 'All changes saved';
    window.onbeforeunload = changed ? () => true : null;
  }
  function toast(message, type = 'success') {
    const item = document.createElement('div');
    item.className = `editor-toast ${type}`;
    item.textContent = message;
    toastRegion.append(item);
    requestAnimationFrame(() => item.classList.add('show'));
    setTimeout(() => { item.classList.remove('show'); setTimeout(() => item.remove(), 250); }, 3200);
  }
  async function persist() {
    if (!dirty() || saving || !form.reportValidity()) return;
    saving = true; save.disabled = true; save.textContent = 'Saving…';
    try {
      const current = values();
      const response = await fetch(form.dataset.endpoint, {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: JSON.stringify({_token: form.dataset.token, project_id: form.dataset.project, ...current})
      });
      const result = await response.json().catch(() => ({ok:false,message:'The server returned an unreadable response.'}));
      if (!response.ok || !result.ok) throw new Error(result.message || 'Settings could not be saved.');
      baseline = current;
      toast(result.message || 'Generation settings saved.');
    } catch (error) { toast(error.message || 'Settings could not be saved.', 'error'); }
    finally { saving = false; save.textContent = 'Save settings'; refresh(); }
  }
  fields.forEach(field => { field.addEventListener('input', refresh); field.addEventListener('change', refresh); });
  save.addEventListener('click', persist);
  form.addEventListener('submit', event => { event.preventDefault(); persist(); });
  refresh();
})();
