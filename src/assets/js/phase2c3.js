(() => {
  'use strict';
  const editor = document.querySelector('#logical-editor');
  if (!editor) return;

  const list = editor.querySelector('[data-logical-list]');
  const saveButton = document.querySelector('[data-save-all]');
  const discardButton = document.querySelector('[data-discard]');
  const dirtyLabel = document.querySelector('[data-dirty-count]');
  const toastRegion = document.querySelector('[data-toast-region]');
  const endpoint = editor.dataset.endpoint;
  const token = editor.dataset.token;
  let baseline = snapshot();
  let saving = false;

  function cards() { return [...list.querySelectorAll('[data-section-id]')]; }
  function snapshot() {
    return cards().map((card, index) => ({
      id: card.dataset.sectionId,
      title: card.querySelector('[data-title]').value,
      included: card.querySelector('[data-included]').checked,
      position: index + 1
    }));
  }
  function signature(value) { return JSON.stringify(value); }
  function dirtyCards() {
    const current = snapshot();
    const initialById = new Map(baseline.map(row => [row.id, row]));
    return cards().filter((card, index) => {
      const now = current[index];
      const initial = initialById.get(now.id);
      const dirty = !initial || now.title !== initial.title || now.included !== initial.included || now.position !== initial.position;
      card.classList.toggle('is-dirty', dirty);
      return dirty;
    });
  }
  function refresh() {
    cards().forEach((card, index, all) => {
      card.querySelector('[data-position]').textContent = String(index + 1).padStart(3, '0');
      card.querySelector('[data-move="up"]').disabled = index === 0;
      card.querySelector('[data-move="down"]').disabled = index === all.length - 1;
    });
    const count = dirtyCards().length;
    saveButton.disabled = count === 0 || saving;
    discardButton.disabled = count === 0 || saving;
    dirtyLabel.textContent = count === 0 ? 'All changes saved' : `${count} unsaved change${count === 1 ? '' : 's'}`;
    window.onbeforeunload = count > 0 ? () => true : null;
  }
  function toast(message, type = 'success') {
    const item = document.createElement('div');
    item.className = `editor-toast ${type}`;
    item.textContent = message;
    toastRegion.append(item);
    requestAnimationFrame(() => item.classList.add('show'));
    setTimeout(() => { item.classList.remove('show'); setTimeout(() => item.remove(), 250); }, 3200);
  }
  function restore() {
    const byId = new Map(cards().map(card => [card.dataset.sectionId, card]));
    baseline.forEach(row => {
      const card = byId.get(row.id);
      if (!card) return;
      card.querySelector('[data-title]').value = row.title;
      card.querySelector('[data-included]').checked = row.included;
      list.append(card);
    });
    refresh();
    toast('Unsaved changes discarded.', 'info');
  }
  async function save() {
    if (saving || dirtyCards().length === 0) return;
    if (!editor.reportValidity()) return;
    saving = true;
    saveButton.disabled = true;
    saveButton.textContent = 'Saving…';
    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: JSON.stringify({_token: token, sections: snapshot()})
      });
      const result = await response.json().catch(() => ({ok: false, message: 'The server returned an unreadable response.'}));
      if (!response.ok || !result.ok) throw new Error(result.message || 'Save failed.');
      baseline = snapshot();
      toast(result.message || 'Changes saved.');
    } catch (error) {
      toast(error.message || 'Changes could not be saved.', 'error');
    } finally {
      saving = false;
      saveButton.textContent = 'Save all changes';
      refresh();
    }
  }

  editor.addEventListener('input', refresh);
  editor.addEventListener('change', refresh);
  list.addEventListener('click', event => {
    const button = event.target.closest('[data-move]');
    if (!button) return;
    const card = button.closest('[data-section-id]');
    if (button.dataset.move === 'up' && card.previousElementSibling) list.insertBefore(card, card.previousElementSibling);
    if (button.dataset.move === 'down' && card.nextElementSibling) list.insertBefore(card.nextElementSibling, card);
    refresh();
    card.scrollIntoView({block: 'nearest'});
  });
  saveButton.addEventListener('click', save);
  discardButton.addEventListener('click', restore);
  document.addEventListener('keydown', event => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
      event.preventDefault();
      save();
    }
  });
  refresh();
})();
