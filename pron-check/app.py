"""
pron-check: listens to how the Kokoro voice says a word and scores it against a reference pronunciation.

  POST /g2p        {"text": "..."}                         -> the sounds Kokoro will use (misaki, Kokoro's own G2P)
  POST /recognize  WAV body                                -> the sounds heard in the audio (phoneme recognition)
  POST /score      {"reference": "...", "candidate": "..."} -> 0-100, articulatory-feature edit distance
  POST /assess     {"items": [{"word", "sounds"?}], "british": false, "minimum": 85}
                   -> per word: how the voice reads it, how close that is to the sounds (stress counts), and, when below
                      the minimum, the plain spelling the voice reads closest to the sounds
  POST /measure    {"voice": "am_michael", "models": [...]} -> the phase 2 go/no-go measurement (see measure.py)
  GET  /health

Every request but /health needs "Authorization: Bearer $PRON_CHECK_API_KEY" when that variable is set.
"""
import io
import os
import time

import numpy as np
import soundfile as sf
import torch
import torchaudio
from fastapi import Depends, FastAPI, HTTPException, Request
from pydantic import BaseModel

API_KEY = os.environ.get("PRON_CHECK_API_KEY", "")
DEFAULT_MODEL = os.environ.get("PRON_CHECK_MODEL", "facebook/wav2vec2-lv-60-espeak-cv-ft")
DEVICE = "cuda" if torch.cuda.is_available() and os.environ.get("PRON_CHECK_DEVICE", "cpu") == "cuda" else "cpu"
torch.set_num_threads(int(os.environ.get("PRON_CHECK_THREADS", "4")))

app = FastAPI(title="pron-check")


def require_key(request: Request) -> None:
    if API_KEY and request.headers.get("authorization", "") != f"Bearer {API_KEY}":
        raise HTTPException(status_code=401, detail="Missing or wrong API key")


# ----- the voice's own reading (misaki) -----

_g2p: dict = {}


def g2p(text: str, british: bool = False) -> str:
    if british not in _g2p:
        from misaki import en, espeak
        _g2p[british] = en.G2P(trf=False, british=british, fallback=espeak.EspeakFallback(british=british))
    phonemes, _ = _g2p[british](text)
    return phonemes.strip()


# ----- hearing sounds in audio (wav2vec2 phoneme CTC) -----

_models: dict = {}


def recognizer(name: str):
    if name not in _models:
        from transformers import Wav2Vec2FeatureExtractor, Wav2Vec2ForCTC, Wav2Vec2PhonemeCTCTokenizer
        tok = Wav2Vec2PhonemeCTCTokenizer.from_pretrained(name, do_phonemize=False)
        fe = Wav2Vec2FeatureExtractor.from_pretrained(name)
        model = Wav2Vec2ForCTC.from_pretrained(name).to(DEVICE).eval()
        _models[name] = (tok, fe, model)
    return _models[name]


def load_wav(data: bytes) -> np.ndarray:
    audio, rate = sf.read(io.BytesIO(data), dtype="float32", always_2d=True)
    audio = audio.mean(axis=1)
    if rate != 16000:
        audio = torchaudio.functional.resample(torch.from_numpy(audio), rate, 16000).numpy()
    # trim silence at the ends so the model hears only speech
    level = np.abs(audio)
    loud = np.where(level > max(0.01, level.max() * 0.05))[0]
    if loud.size:
        audio = audio[max(0, loud[0] - 1600): loud[-1] + 1600]
    return audio


def recognize(data: bytes, model_name: str = DEFAULT_MODEL) -> str:
    tok, fe, model = recognizer(model_name)
    audio = load_wav(data)
    inputs = fe(audio, sampling_rate=16000, return_tensors="pt").input_values.to(DEVICE)
    with torch.inference_mode():
        logits = model(inputs).logits
    ids = torch.argmax(logits, dim=-1)[0]
    return tok.decode(ids).replace(" ", "")


# ----- scoring -----

# Kokoro (misaki) writes diphthongs and affricates as single letters; back to IPA before comparing
TO_IPA = {"A": "eɪ", "I": "aɪ", "O": "oʊ", "W": "aʊ", "Y": "ɔɪ", "ʤ": "dʒ", "ʧ": "tʃ", "ᵊ": "ə", "Q": "əʊ", "T": "t"}
# a sound swapped for another costs this many times the share of features that differ (capped at 1, like a missing
# sound): panphon's own distance charges a vowel change only about a tenth, so a wrong reading still scored ~95
SUB_WEIGHT = float(os.environ.get("PRON_CHECK_SUB_WEIGHT", "5"))
DROP = "ˈˌːˑ.‿͡ -'\"/[]()"

_dist = None
_ft = None


def plain(ipa: str) -> str:
    out = "".join(TO_IPA.get(c, c) for c in ipa)
    out = "".join(c for c in out if c not in DROP)
    # espeak's reduced vowels and r colouring, written the common way
    return out.replace("ᵻ", "ɪ").replace("ɚ", "əɹ").replace("ɝ", "ɜɹ").replace("r", "ɹ").replace("ɾ", "t")


def stressed_syllable(ipa: str) -> int:
    """Which syllable (counted by vowels before it) carries the main stress; -1 when none is marked."""
    at = ipa.find("ˈ")
    if at < 0:
        return -1
    return sum(1 for c in plain(ipa[:at]) if c in "aeiouæɑɐɒɔəɛɜɪʊʌ")


STRESS_PENALTY = float(os.environ.get("PRON_CHECK_STRESS_PENALTY", "12"))


def compare(reference: str, reading: str) -> float:
    """Sounds and stress: the score, less a penalty when the main stress falls on another syllable."""
    value = score(reference, reading)["score"]
    a, b = stressed_syllable(reference), stressed_syllable(reading)
    if a >= 0 and b >= 0 and a != b:
        value -= STRESS_PENALTY
    return round(max(0.0, value), 1)


def score(reference: str, candidate: str) -> dict:
    """0-100: 100 = the same sounds; a sound swapped for a similar one costs less than for a very different one."""
    global _ft
    if _ft is None:
        import panphon
        _ft = panphon.FeatureTable()
    ref, cand = plain(reference), plain(candidate)
    a = _ft.word_to_vector_list(ref, numeric=True)
    b = _ft.word_to_vector_list(cand, numeric=True)

    def sub(x, y):
        differ = sum(1 for p, q in zip(x, y) if p != q)
        return min(1.0, SUB_WEIGHT * differ / max(len(x), 1))

    # edit distance: a missing or extra sound costs 1, a swapped one by how different it is
    prev = [float(j) for j in range(len(b) + 1)]
    for i in range(1, len(a) + 1):
        cur = [float(i)] + [0.0] * len(b)
        for j in range(1, len(b) + 1):
            cur[j] = min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + sub(a[i - 1], b[j - 1]))
        prev = cur
    d = prev[-1]
    n = max(len(a), len(b), 1)
    return {"score": round(max(0.0, 100.0 * (1.0 - d / n)), 1), "reference": ref, "candidate": cand, "distance": round(d, 3)}


# ----- routes -----

class TextIn(BaseModel):
    text: str


class ScoreIn(BaseModel):
    reference: str
    candidate: str


class AssessItem(BaseModel):
    word: str
    sounds: str = ""


class AssessIn(BaseModel):
    items: list[AssessItem]
    british: bool = False
    minimum: float = 85


class FindIn(BaseModel):
    word: str
    sounds: str = ""      # the sounds wanted (Kokoro's set), or
    say: str = ""         # a respelling of them, capitals on the stressed part: "fuh-RENG-ghee"
    british: bool = False


class MeasureIn(BaseModel):
    voice: str = "am_michael"
    models: list[str] = [DEFAULT_MODEL]
    words: list[dict] | None = None


@app.get("/health")
def health():
    return {"ok": True, "device": DEVICE, "model": DEFAULT_MODEL, "loaded": list(_models)}


@app.post("/g2p", dependencies=[Depends(require_key)])
def route_g2p(body: TextIn):
    return {"phonemes": g2p(body.text)}


@app.post("/recognize", dependencies=[Depends(require_key)])
async def route_recognize(request: Request, model: str = DEFAULT_MODEL):
    data = await request.body()
    t = time.time()
    phonemes = recognize(data, model)
    return {"phonemes": phonemes, "seconds": round(time.time() - t, 3)}


@app.post("/score", dependencies=[Depends(require_key)])
def route_score(body: ScoreIn):
    return score(body.reference, body.candidate)


@app.post("/assess", dependencies=[Depends(require_key)])
def route_assess(body: AssessIn):
    import respell
    out = []
    for item in body.items[:100]:
        reads = g2p(item.word, body.british)
        row = {"word": item.word, "voice": reads}
        if item.sounds:
            row["score"] = compare(item.sounds, reads)
            if row["score"] < body.minimum:
                alt = respell.best(item.sounds, item.word, lambda s: g2p(s, body.british), compare)
                if alt and alt["score"] >= row["score"] + 5:          # only when clearly better than the word as written
                    row["spelling"] = alt
        out.append(row)
    return {"items": out}


@app.post("/find", dependencies=[Depends(require_key)])
def route_find(body: FindIn):
    """Spellings that make the voice say what someone wants: from sounds, or from a respelling of them."""
    import respell
    say = lambda s: g2p(s, body.british)
    target = body.sounds.strip() or (respell.target_from_respelling(body.say[:100], say) if body.say.strip() else "")
    if not target or not body.word.strip():
        raise HTTPException(422, "Give the word, and how it should sound.")
    word = body.word.strip()[:100]
    reads = say(word)
    found = respell.best(target, word, say, compare, tries=120, extra=tuple(t for t in {body.say.strip()} if t), alternatives=3) or {}
    spellings = [{k: found[k] for k in ("text", "reads", "score")}] + found.get("others", []) if found else []
    return {"target": target, "word_reads": reads, "word_score": compare(target, reads), "spellings": spellings}


@app.post("/measure", dependencies=[Depends(require_key)])
def route_measure(body: MeasureIn):
    import measure
    return measure.run(body.voice, body.models, body.words)
