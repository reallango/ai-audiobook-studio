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


def best(target: str, word: str, g2p, score, tries: int = 60) -> dict | None:
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
    text = winner[1]                                         # a name keeps its capital, as the book writes it
    return {"text": text, "reads": seen[winner[1]][1], "score": winner[0], "tried": len(seen)}
