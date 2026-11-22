// Pattern builder: assemble what to find from plain parts, say what it becomes, and see the result on real sentences.
// It writes an ordinary regex rule; nothing here is needed to run the rule later.
(function () {
  const root = document.getElementById('regex-helper');
  const mount = document.getElementById('rh-mount');
  if (!root || !mount) return;

  const EDGE_BEFORE = '(?<![\\p{L}\\p{N}])';
  const EDGE_AFTER = '(?![\\p{L}\\p{N}])';
  const esc = s => s.replace(/[.*+?^${}()|[\]\\\/~#-]/g, '\\$&');

  // What each kind of part matches. `needs` says which input it takes.
  const KINDS = {
    text:   {label: 'this exact text', needs: 'text', re: v => esc(v), say: v => '“' + v + '”'},
    letter: {label: 'a letter', re: () => '\\p{L}', say: () => 'letter', plural: 'letters'},
    upper:  {label: 'a capital letter', re: () => '\\p{Lu}', say: () => 'capital letter', plural: 'capital letters'},
    lower:  {label: 'a small letter', re: () => '\\p{Ll}', say: () => 'small letter', plural: 'small letters'},
    digit:  {label: 'a digit', re: () => '\\d', say: () => 'digit', plural: 'digits'},
    alnum:  {label: 'a letter or digit', re: () => '[\\p{L}\\p{N}]', say: () => 'letter or digit', plural: 'letters or digits'},
    word:   {label: 'a whole word', re: () => "\\p{L}[\\p{L}']*", say: () => 'word', plural: 'words', noCount: true},
    space:  {label: 'a space', re: () => '\\s', say: () => 'space', plural: 'spaces'},
    oneof:  {label: 'one of these (a, b, c)', needs: 'text', re: v => '(?:' + v.split(',').map(s => s.trim()).filter(Boolean).map(esc).join('|') + ')', say: v => 'one of ' + v, noCount: true},
    any:    {label: 'any short stretch of text', re: () => '.{1,60}?', say: () => 'any short stretch of text', noCount: true},
  };
  const COUNTS = {one: 'just one', exactly: 'exactly', atleast: 'at least', optional: 'maybe (0 or 1)'};
  const TRANSFORMS = {'': 'as it is', upper: 'CAPITALS', lower: 'lowercase', title: 'Title Case', first: 'First letter capital', dash: 'one by one, joined with dashes (a-b-c)', space: 'one by one, with spaces (a b c)', digits: 'digits as words (5 → five)'};

  const PRESETS = {
    code: {name: 'A code like AR-556, read one character at a time',
      parts: [{k: 'letter', c: 'exactly', n: 2, cap: true}, {k: 'text', v: '-'}, {k: 'digit', c: 'exactly', n: 3, cap: true}],
      out: [{t: 'part', n: 1, x: 'dash'}, {t: 'text', v: '-'}, {t: 'part', n: 2, x: 'dash'}], edges: true, cs: false},
    digits: {name: 'Digits read one at a time (556 → five five six)',
      parts: [{k: 'digit', c: 'atleast', n: 2, cap: true}], out: [{t: 'part', n: 1, x: 'digits'}], edges: true, cs: false},
    acronym: {name: 'An acronym spelled out (FBI → F B I)',
      parts: [{k: 'upper', c: 'atleast', n: 2, cap: true}], out: [{t: 'part', n: 1, x: 'space'}], edges: true, cs: true},
    unit: {name: 'A number with a unit (5kg → 5 kilograms)',
      parts: [{k: 'digit', c: 'atleast', n: 1, cap: true}, {k: 'space', c: 'optional'}, {k: 'text', v: 'kg'}],
      out: [{t: 'part', n: 1}, {t: 'text', v: ' kilograms'}], edges: true, cs: false},
    heading: {name: 'Chapter headings (CHAPTER ONE → Chapter one)',
      parts: [{k: 'text', v: 'chapter'}, {k: 'space'}, {k: 'word', cap: true}],
      out: [{t: 'text', v: 'Chapter '}, {t: 'part', n: 1, x: 'lower'}], edges: true, cs: false, start: true},
    remove: {name: 'Remove something (such as [1] footnote marks)',
      parts: [{k: 'text', v: '[1]'}], out: [], edges: false, cs: false},
    after: {name: 'Change a word only after another word',
      parts: [{k: 'text', v: 'the '}, {k: 'text', v: 'lead', cap: true}], out: [{t: 'text', v: 'the '}, {t: 'text', v: 'led'}], edges: true, cs: false},
  };

  let parts = [], out = [], edges = true, cs = false, startOnly = false;
  let timer = 0, seq = 0;

  const el = (tag, attrs = {}, ...kids) => {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
      if (k === 'class') node.className = v;
      else if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
      else if (v !== undefined && v !== null) node.setAttribute(k, v);
    }
    node.append(...kids.filter(x => x !== null && x !== undefined));
    return node;
  };
  const select = (options, value, onchange, label) => {
    const s = el('select', {'aria-label': label, onchange: e => onchange(e.target.value)});
    for (const [v, text] of Object.entries(options)) {
      const o = el('option', {value: v}, text);
      if (v === value) o.selected = true;
      s.append(o);
    }
    return s;
  };

  const captureNumbers = () => { let n = 0; return parts.map(p => (p.cap ? ++n : 0)); };

  function partRegex(p) {
    const kind = KINDS[p.k];
    if (kind.needs && !(p.v || '').trim()) return null;
    let body = kind.re(p.v || '');
    const single = p.k === 'text' ? (p.v || '').length === 1 : true;
    if (!kind.noCount) {
      const n = Math.max(1, Math.min(20, parseInt(p.n, 10) || 1));
      if (p.c === 'exactly') body = (single ? body : '(?:' + body + ')') + '{' + n + '}';
      else if (p.c === 'atleast') body = (single ? body : '(?:' + body + ')') + (n <= 1 ? '+' : '{' + n + ',}');
      else if (p.c === 'optional') body = (single ? body : '(?:' + body + ')') + '?';
    } else if (p.c === 'optional') body = '(?:' + body + ')?';
    return p.cap ? '(' + body + ')' : body;
  }

  function partWords(p) {
    const kind = KINDS[p.k];
    const n = Math.max(1, parseInt(p.n, 10) || 1);
    if (p.k === 'text' || kind.noCount) return (p.c === 'optional' ? 'maybe ' : '') + (kind.say(p.v || '…').replace(/^word$/, 'a word').replace(/^any/, 'any'));
    if (p.c === 'exactly') return n + ' ' + (n === 1 ? kind.say() : kind.plural);
    if (p.c === 'atleast') return n <= 1 ? 'one or more ' + kind.plural : n + ' or more ' + kind.plural;
    if (p.c === 'optional') return 'maybe a ' + kind.say();
    return 'a ' + kind.say();
  }

  function build() {
    if (!parts.length) return null;
    const pieces = parts.map(partRegex);
    if (pieces.includes(null)) return null;
    const pattern = (startOnly ? '^' : '') + (edges ? EDGE_BEFORE : '') + pieces.join('') + (edges ? EDGE_AFTER : '');
    const caps = captureNumbers();
    const spoken = out.map(o => {
      if (o.t === 'text') return o.v || '';
      const max = Math.max(...caps, 0);
      const n = Math.min(Math.max(1, parseInt(o.n, 10) || 1), Math.max(max, 1));
      return o.x ? '${' + n + ':' + o.x + '}' : '$' + n;
    }).join('');
    const describe = parts.map((p, i) => partWords(p) + (p.cap ? ' (part ' + caps[i] + ')' : '')).join(', then ');
    const described = 'Finds: ' + describe + '.' + (edges ? ' Only as a whole word.' : '') + (startOnly ? ' Only at the start of a paragraph.' : '') + (cs ? ' Capitals must match.' : ' Capitals do not matter.');
    return {pattern, spoken, described, caps: Math.max(...caps, 0)};
  }

  // ----- drawing the builder -----
  function render() {
    mount.textContent = '';
    const caps = captureNumbers();
    const maxCap = Math.max(...caps, 0);

    const preset = el('label', {}, 'Start from an example ',
      select({'': '— choose —', ...Object.fromEntries(Object.entries(PRESETS).map(([k, v]) => [k, v.name]))}, '', v => { if (v) loadPreset(v); }, 'Example'));

    const findRows = parts.map((p, i) => {
      const kind = KINDS[p.k];
      const row = el('div', {class: 'rh-row'},
        el('span', {class: 'rh-num'}, String(i + 1)),
        select(Object.fromEntries(Object.entries(KINDS).map(([k, v]) => [k, v.label])), p.k, v => { p.k = v; render(); schedule(); }, 'Kind of part'));
      if (kind.needs) row.append(el('input', {value: p.v || '', maxlength: 60, placeholder: p.k === 'oneof' ? 'Bajor, Cardassia' : 'text', 'aria-label': 'Text', oninput: e => { p.v = e.target.value; schedule(); }}));
      if (!kind.noCount) {
        row.append(select(COUNTS, p.c || 'one', v => { p.c = v; render(); schedule(); }, 'How many'));
        if (p.c === 'exactly' || p.c === 'atleast') row.append(el('input', {type: 'number', min: 1, max: 20, value: p.n || 2, class: 'rh-n', 'aria-label': 'Number', oninput: e => { p.n = e.target.value; schedule(); }}));
      } else {
        row.append(select({one: 'once', optional: 'maybe (0 or 1)'}, p.c === 'optional' ? 'optional' : 'one', v => { p.c = v; schedule(); }, 'How many'));
      }
      row.append(
        el('label', {class: 'check rh-cap'}, el('input', {type: 'checkbox', checked: p.cap ? '' : null, onchange: e => { p.cap = e.target.checked; render(); schedule(); }}), p.cap ? 'part ' + caps[i] : 'keep as a part'),
        el('button', {type: 'button', class: 'link-button', 'aria-label': 'Move up', disabled: i === 0 ? '' : null, onclick: () => { [parts[i - 1], parts[i]] = [parts[i], parts[i - 1]]; render(); schedule(); }}, '↑'),
        el('button', {type: 'button', class: 'link-button', 'aria-label': 'Remove part', onclick: () => { parts.splice(i, 1); render(); schedule(); }}, 'remove'));
      return row;
    });

    const outRows = out.map((o, i) => {
      const row = el('div', {class: 'rh-row'}, el('span', {class: 'rh-num'}, String(i + 1)),
        select({text: 'this text', part: 'a kept part'}, o.t, v => { o.t = v; if (v === 'part') o.n = o.n || 1; render(); schedule(); }, 'Kind of item'));
      if (o.t === 'text') row.append(el('input', {value: o.v || '', maxlength: 80, placeholder: 'spoken text', 'aria-label': 'Spoken text', oninput: e => { o.v = e.target.value; schedule(); }}));
      else {
        const nums = {}; for (let n = 1; n <= Math.max(maxCap, 1); n++) nums[n] = 'part ' + n;
        row.append(select(nums, String(o.n || 1), v => { o.n = parseInt(v, 10); schedule(); }, 'Which part'),
          select(TRANSFORMS, o.x || '', v => { o.x = v; schedule(); }, 'Change it to'));
      }
      row.append(el('button', {type: 'button', class: 'link-button', 'aria-label': 'Remove item', onclick: () => { out.splice(i, 1); render(); schedule(); }}, 'remove'));
      return row;
    });

    mount.append(
      preset,
      el('h4', {}, '1. Find'),
      el('p', {class: 'rh-hint'}, 'Add the pieces in the order they appear in the text. Tick “keep as a part” on anything you want to reuse in the change.'),
      ...findRows,
      el('button', {type: 'button', onclick: () => { parts.push({k: 'text', v: ''}); render(); }}, 'Add a part'),
      el('div', {class: 'rh-opts'},
        el('label', {class: 'check'}, el('input', {type: 'checkbox', checked: edges ? '' : null, onchange: e => { edges = e.target.checked; schedule(); }}), 'only as a whole word'),
        el('label', {class: 'check'}, el('input', {type: 'checkbox', checked: cs ? '' : null, onchange: e => { cs = e.target.checked; schedule(); }}), 'capitals must match'),
        el('label', {class: 'check'}, el('input', {type: 'checkbox', checked: startOnly ? '' : null, onchange: e => { startOnly = e.target.checked; schedule(); }}), 'only at the start of a paragraph')),
      el('h4', {}, '2. Change it to'),
      el('p', {class: 'rh-hint'}, 'Leave this empty to remove the text. Mix your own text with kept parts, and change how a part is written.'),
      ...outRows,
      el('div', {}, el('button', {type: 'button', onclick: () => { out.push({t: 'text', v: ''}); render(); }}, 'Add text'),
        ' ', el('button', {type: 'button', disabled: maxCap ? null : '', onclick: () => { out.push({t: 'part', n: 1, x: ''}); render(); }}, 'Add a kept part')),
      el('h4', {}, '3. Check'),
      el('p', {class: 'rh-explain', id: 'rh-explain'}),
      el('p', {}, 'Pattern: ', el('code', {id: 'rh-pattern'}), el('br'), 'Spoken form: ', el('code', {id: 'rh-spoken'})),
      el('p', {class: 'rh-result', id: 'rh-result', 'aria-live': 'polite'}),
      el('ul', {class: 'rh-examples', id: 'rh-examples'}),
      el('button', {type: 'button', id: 'rh-use'}, 'Use this rule'));
    document.getElementById('rh-use').addEventListener('click', useRule);
    schedule();
  }

  function loadPreset(key) {
    const p = PRESETS[key];
    parts = p.parts.map(x => ({...x}));
    out = p.out.map(x => ({...x}));
    edges = p.edges; cs = p.cs; startOnly = !!p.start;
    render();
  }

  async function refresh() {
    const b = build();
    const explain = document.getElementById('rh-explain'), pattern = document.getElementById('rh-pattern'), spoken = document.getElementById('rh-spoken');
    const result = document.getElementById('rh-result'), list = document.getElementById('rh-examples'), use = document.getElementById('rh-use');
    if (!explain) return;
    list.textContent = '';
    use.disabled = !b;
    pattern.textContent = b ? b.pattern : '—';
    spoken.textContent = b ? (b.spoken === '' ? '(nothing: the text is removed)' : b.spoken) : '—';
    explain.textContent = b ? b.described : 'Add at least one part to find. A text part needs its text filled in.';
    if (!b) { result.textContent = ''; return; }
    const mine = ++seq;
    result.textContent = 'Checking the book…';
    try {
      const url = root.dataset.url + '&pattern=' + encodeURIComponent(b.pattern) + '&replacement=' + encodeURIComponent(b.spoken)
        + '&case=' + (cs ? 1 : 0) + '&project=' + encodeURIComponent(root.dataset.project || '');
      const r = await (await fetch(url, {credentials: 'same-origin'})).json();
      if (mine !== seq) return;
      if (!r.valid) { result.textContent = r.error; use.disabled = true; return; }
      result.textContent = r.count ? 'Found in ' + r.count + (r.capped ? '+' : '') + ' sentence' + (r.count === 1 ? '' : 's') + ' of the book. First few, and what they become:'
        : 'No sentence in the book matches yet.';
      for (const x of r.examples || []) {
        const li = el('li', {}, x.before, el('mark', {}, x.match), x.after);
        if (x.result !== null && x.result !== undefined) li.append(el('div', {class: 'rh-after'}, '→ ' + x.result));
        list.append(li);
      }
    } catch (error) { if (mine === seq) result.textContent = ''; }
  }

  const schedule = () => { clearTimeout(timer); timer = setTimeout(refresh, 300); };

  function useRule() {
    const b = build();
    if (!b) return;
    const form = document.getElementById('add');
    form.elements.pattern.value = b.pattern;
    form.elements.replacement.value = b.spoken;
    form.elements.match_type.value = 'regex';
    if (form.elements.case_sensitive) form.elements.case_sensitive.checked = cs;
    form.elements.note.focus();
    form.scrollIntoView({behavior: 'smooth', block: 'center'});
  }

  parts = [{k: 'text', v: ''}];
  out = [{t: 'text', v: ''}];
  render();
})();
