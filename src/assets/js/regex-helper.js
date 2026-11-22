// Pattern helper: builds a safe regex from a plain-language recipe, explains it, and checks it against the book.
(function () {
  const root = document.getElementById('regex-helper');
  if (!root) return;
  const $ = id => document.getElementById(id);
  const recipe = $('rh-recipe'), text = $('rh-text'), extra = $('rh-extra'), extraWrap = $('rh-extra-wrap'), extraLabel = $('rh-extra-label');
  const cs = $('rh-case'), explain = $('rh-explain'), out = $('rh-pattern'), result = $('rh-result'), list = $('rh-examples'), use = $('rh-use');
  const esc = s => s.replace(/[.*+?^${}()|[\]\\/~#-]/g, '\$&');
  const W = '[\p{L}\p{N}\']*';
  let timer = 0, seq = 0;

  function build() {
    const t = text.value.trim(), e = extra.value.trim();
    const parts = t.split(',').map(s => s.trim()).filter(Boolean);
    switch (recipe.value) {
      case 'starts': return t ? {p: `(?<![\p{L}\p{N}'])${esc(t)}${W}`, d: `Any word that starts with “${t}”.`} : null;
      case 'ends': return t ? {p: `(?<![\p{L}\p{N}'])${W}${esc(t)}(?![\p{L}\p{N}'])`, d: `Any word that ends with “${t}”.`} : null;
      case 'contains': return t ? {p: `(?<![\p{L}\p{N}'])${W}${esc(t)}${W}`, d: `Any word with “${t}” somewhere inside it.`} : null;
      case 'either': return parts.length ? {p: `(?<![\p{L}\p{N}'])(?:${parts.map(esc).join('|')})(?![\p{L}\p{N}'])`, d: `Any one of these whole words: ${parts.join(', ')}.`} : null;
      case 'number': return t || e ? {p: `(\d+)\s?${esc(e || t)}(?![\p{L}])`, d: `A number directly followed by “${e || t}” (the number is kept as $1, so you can write “$1 kilograms”).`} : null;
      case 'after': return t && e ? {p: `(?<=${esc(e)} )${esc(t)}(?![\p{L}\p{N}'])`, d: `The word “${t}” only when it comes right after “${e}”.`} : null;
    }
    return null;
  }

  function labels() {
    const r = recipe.value;
    extraWrap.hidden = !(r === 'number' || r === 'after');
    extraLabel.textContent = r === 'number' ? 'Unit (kg, km, …)' : 'The word before it';
    text.placeholder = r === 'either' ? 'Bajor, Cardassia, Ferenginar' : r === 'number' ? '(or put the unit here)' : 'e.g. Bajor';
  }

  async function refresh() {
    labels();
    const b = build();
    use.disabled = !b;
    out.textContent = b ? b.p : '\u00a0';
    explain.textContent = b ? b.d : 'Type some text and the pattern appears here.';
    list.innerHTML = '';
    if (!b) { result.textContent = ''; return; }
    const mine = ++seq;
    result.textContent = 'Checking the book…';
    try {
      const url = `${root.dataset.url}&pattern=${encodeURIComponent(b.p)}&case=${cs.checked ? 1 : 0}&project=${encodeURIComponent(root.dataset.project || '')}`;
      const r = await (await fetch(url, {credentials: 'same-origin'})).json();
      if (mine !== seq) return;
      if (!r.valid) { result.textContent = r.error; use.disabled = true; return; }
      result.textContent = r.count ? `Found in ${r.count}${r.capped ? '+' : ''} sentence${r.count === 1 ? '' : 's'} of the book.` : 'No sentence in the book matches yet.';
      for (const x of r.examples || []) {
        const li = document.createElement('li');
        const m = document.createElement('mark');
        m.textContent = x.match;
        li.append(x.before, m, x.after);
        list.append(li);
      }
    } catch (error) { if (mine === seq) result.textContent = ''; }
  }

  const schedule = () => { clearTimeout(timer); timer = setTimeout(refresh, 250); };
  [recipe, text, extra, cs].forEach(el => el.addEventListener('input', schedule));
  use.addEventListener('click', () => {
    const b = build();
    if (!b) return;
    const form = document.getElementById('add');
    form.elements.pattern.value = b.p;
    form.elements.match_type.value = 'regex';
    if (form.elements.case_sensitive) form.elements.case_sensitive.checked = cs.checked;
    form.elements.replacement.focus();
    form.scrollIntoView({behavior: 'smooth', block: 'center'});
  });
  labels();
})();
