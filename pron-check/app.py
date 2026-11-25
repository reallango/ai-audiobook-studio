"""
pron-check: listens to how the Kokoro voice says a word and scores it against a reference pronunciation.

  POST /g2p        {"text": "..."}                         -> the sounds Kokoro will use (misaki, Kokoro's own G2P)
  POST /recognize  WAV body                                -> the sounds heard in the audio (phoneme recognition)
  POST /score      {"reference": "...", "candidate": "..."} -> 0-100, articulatory-feature edit distance
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

_g2p = None


def g2p(text: str) -> str:
    global _g2p
    if _g2p is None:
        from misaki import en, espeak
        _g2p = en.G2P(trf=False, british=False, fallback=espeak.EspeakFallback(british=False))
    phonemes, _ = _g2p(text)
    return phonemes


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


@app.post("/measure", dependencies=[Depends(require_key)])
def route_measure(body: MeasureIn):
    import measure
    return measure.run(body.voice, body.models, body.words)
