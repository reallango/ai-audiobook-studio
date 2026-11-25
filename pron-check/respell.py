"""
Plain spellings that make the voice say a set of sounds: start from the commonest spelling of each sound, then change
one sound's spelling at a time, keeping any change that brings the voice's own reading (misaki) closer to the target.
No audio is made: misaki's reading is what Kokoro will say.
"""
VOWELS = set("ɑæəɛɪiuʊɔʌɜAIOWYᵊa")

# spelling choices per sound (Kokoro's set), commonest first
SPELL = {
    "ɑ": ["ah", "a", "o"], "æ": ["a", "ah"], "ə": ["uh", "a", "e"], "ᵊ": ["uh", "a"], "ɛ": ["eh", "e"], "ɪ": ["i", "ih"],
    "i": ["ee", "i", "ea"], "u": ["oo", "u", "ou"], "ʊ": ["oo", "u"], "ɔ": ["aw", "o", "au"], "ʌ": ["u", "uh"],
    "ɜ": ["ur", "er"], "A": ["ay", "ai", "ei", "e"], "I": ["y", "eye", "i", "ai"], "O": ["oh", "o", "oa"],
    "W": ["ow", "ou"], "Y": ["oy"], "b": ["b"], "d": ["d"], "f": ["f"], "h": ["h"], "k": ["k", "c", "ck"], "l": ["l", "ll"],
    "m": ["m"], "n": ["n", "nn"], "p": ["p"], "s": ["s", "ss", "ce"], "t": ["t", "tt"], "T": ["t"], "v": ["v"], "w": ["w"],
    "z": ["z", "s"], "ɡ": ["g", "gh"], "ɹ": ["r", "rr"], "ʃ": ["sh"], "ʒ": ["zh", "s"], "ʤ": ["j", "g"], "ʧ": ["ch"],
    "θ": ["th"], "ð": ["th"], "ŋ": ["ng", "n"], "j": ["y", "i"],
}


def sounds_of(phonemes: str) -> list[str]:
    return [c for c in phonemes if c in SPELL]


def syllables(sounds: list[str]) -> list[int]:
    """Where each syllable starts: at a consonant before a vowel that follows a vowel."""
    starts, seen = [0], False
    for i, s in enumerate(sounds):
        nxt = sounds[i + 1] if i + 1 < len(sounds) else ""
        if s not in VOWELS and seen and nxt in VOWELS:
            starts.append(i)
            seen = False
        if s in VOWELS:
            seen = True
    return starts


def spell(sounds: list[str], choice: list[int], hyphens: bool) -> str:
    parts = [SPELL[s][c] for s, c in zip(sounds, choice)]
    if hyphens:
        cuts = set(syllables(sounds)[1:])
        return "".join(("-" if i in cuts else "") + p for i, p in enumerate(parts))
    return "".join(parts)


def word_variants(word: str, target: str) -> list[str]:
    """
    Small edits of the word as the book writes it, which the sound-by-sound search cannot reach one step at a time:
    a hard g or c before e, i or y written so the voice keeps it hard (Ferengi -> Ferenghi, Cesar -> Kesar), each
    alone and all together.
    """
    import itertools
    import re
    spots = []
    if "ɡ" in target:
        spots += [(m.start(), "gh") for m in re.finditer(r"g(?=[eiy])", word, re.I)]
    if "k" in target:
        spots += [(m.start(), "k") for m in re.finditer(r"c(?=[eiy])", word, re.I)]
    out = []
    for n in range(1, min(len(spots), 3) + 1):
        for combo in itertools.combinations(spots, n):
            text = word
            for pos, rep in sorted(combo, reverse=True):
                rep = rep.upper() if text[pos].isupper() else rep
                text = text[:pos] + rep + text[pos + 1:]
            out.append(text)
    return out


def target_from_respelling(text: str, g2p) -> str:
    """
    The sounds someone means by a respelling such as "fuh-RENG-ghee": each part as the voice reads it, with the stress
    on the part in capitals (the voice itself would stress every hyphenated part). Without capitals, the voice's own
    reading of what was typed.
    """
    import re
    parts = [p for p in re.split(r"[-\s]+", text.strip()) if p]
    loud = [p.isupper() and any(c.isalpha() for c in p) for p in parts]
    if not parts or not any(loud) or all(loud):
        return g2p(text)
    out = ""
    for part, stressed in zip(parts, loud):
        sounds = "".join(c for c in g2p(part.lower()) if c not in "ˈˌ ")
        if stressed:
            at = next((i for i, c in enumerate(sounds) if c in VOWELS), 0)
            sounds = sounds[:at] + "ˈ" + sounds[at:]
        else:
            sounds = sounds.replace("ʌ", "ə")                # an unstressed "uh" is the weak vowel
        out += sounds
    return out


def best(target: str, word: str, g2p, score, tries: int = 60, extra: tuple = (), alternatives: int = 0) -> dict | None:
    """The spelling whose reading is closest to target. g2p(text) -> phonemes; score(a, b) -> 0..100."""
    sounds = sounds_of(target)
    if not sounds:
        return None
    seen: dict[str, tuple[float, str]] = {}
    cap = (lambda s: s[:1].upper() + s[1:]) if word[:1].isupper() else (lambda s: s)

    def rate(text: str) -> float:
        if text not in seen:
            reads = g2p(text)
            seen[text] = (score(target, reads), reads)
        return seen[text][0]

    winner = None
    for hyphens in (False, True):
        choice = [0] * len(sounds)
        current = rate(cap(spell(sounds, choice, hyphens)))
        for _ in range(2):                                   # two passes over the sounds
            for i, s in enumerate(sounds):
                for c in range(1, len(SPELL[s])):
                    if len(seen) >= tries * (2 if hyphens else 1):     # each way of writing it gets its share
                        break
                    trial = choice[:i] + [c] + choice[i + 1:]
                    value = rate(cap(spell(sounds, trial, hyphens)))
                    if value > current:
                        choice, current = trial, value
        text = cap(spell(sounds, choice, hyphens))
        if winner is None or current > winner[0]:
            winner = (current, text)
    # the book's own spelling, lightly edited, often reads best and looks most like the name
    for text in [*word_variants(word, target), *extra]:
        value = rate(text)
        if value >= winner[0]:
            winner = (value, text)
    text = winner[1]                                         # a name keeps its capital, as the book writes it
    out = {"text": text, "reads": seen[text][1], "score": winner[0], "tried": len(seen)}
    if alternatives:
        # the next best that read differently from the winner and each other
        others, readings = [], {seen[text][1], g2p(word)}    # nothing that reads like the word as written
        for t, (value, reads) in sorted(seen.items(), key=lambda kv: -kv[1][0]):
            if reads in readings or t == word:
                continue
            readings.add(reads)
            others.append({"text": t, "reads": reads, "score": value})
            if len(others) >= alternatives:
                break
        out["others"] = others
    return out
