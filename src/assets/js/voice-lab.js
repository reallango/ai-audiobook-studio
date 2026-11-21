(() => {
  'use strict';
  const lab = document.querySelector('.voice-lab');
  if (!lab) return;
  const text = lab.querySelector('[data-lab-text]');
  const count = lab.querySelector('[data-char-count]');
  const sample = text.value;
  const maxChars = Number(lab.dataset.maxChars) || 3000;

  function updateCount() { count.textContent = `${text.value.length} / ${maxChars} characters`; }
  text.addEventListener('input', updateCount);
  lab.querySelector('[data-use-sample]').addEventListener('click', () => { text.value = sample; updateCount(); });
  updateCount();

  async function generate(take) {
    const button = take.querySelector('[data-generate]');
    const status = take.querySelector('[data-status]');
    const audio = take.querySelector('[data-audio]');
    const download = take.querySelector('[data-download]');
    const custom = take.querySelector('[data-take-custom]').value.trim();
    const voice = custom || take.querySelector('[data-take-voice]').value;
    const speed = Number(take.querySelector('[data-take-speed]').value);
    if (!text.value.trim()) { status.textContent = 'Enter some text first.'; return; }

    button.disabled = true;
    status.textContent = 'Generating…';
    const started = performance.now();
    try {
      const response = await fetch(lab.dataset.endpoint, {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({_token: lab.dataset.token, text: text.value, voice, speed})
      });
      if (!response.ok) {
        const failure = await response.json().catch(() => ({message: `Request failed (HTTP ${response.status}).`}));
        throw new Error(failure.message || `Request failed (HTTP ${response.status}).`);
      }
      const blob = await response.blob();
      if (audio.dataset.url) URL.revokeObjectURL(audio.dataset.url);
      const url = URL.createObjectURL(blob);
      audio.dataset.url = url;
      audio.src = url;
      audio.hidden = false;
      download.href = url;
      download.download = `voice-${voice.replace(/[^a-z0-9+_-]/gi, '')}-${speed.toFixed(2)}.wav`;
      download.hidden = false;
      status.textContent = `${voice} at ${speed.toFixed(2)}x, ${(blob.size / 1024).toFixed(0)} KB in ${((performance.now() - started) / 1000).toFixed(1)}s`;
      audio.play().catch(() => {});
    } catch (error) {
      status.textContent = error.message || 'Generation failed.';
    } finally {
      button.disabled = false;
    }
  }

  const takes = [...lab.querySelectorAll('[data-take]')];
  takes.forEach(take => take.querySelector('[data-generate]').addEventListener('click', () => generate(take)));
  lab.querySelector('[data-generate-both]').addEventListener('click', async () => {
    // One after the other: the GPU handles one request at a time anyway.
    for (const take of takes) await generate(take);
  });
})();
