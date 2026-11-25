"""
The phase 2 go/no-go measurement: does listening to Kokoro tell a right reading from a wrong one?

For each word, Kokoro says it three ways:
  plain  - the word as written (the voice's own guess)
  right  - with the reference sounds as markup, [word](/sounds/)  -> should score high
  wrong  - the reference with its stressed vowel swapped for a distant one -> should score clearly lower
Each clip is recognized by each model and scored against the reference. Common English words are the control: the
voice reads them right, so "plain" should score high too.
"""
import os
import statistics
import time

import requests

import app

KOKORO_URL = os.environ.get("KOKORO_URL", "http://192.168.1.130:8880").rstrip("/")
KOKORO_KEY = os.environ.get("KOKORO_API_KEY", "")

# reference sounds in Kokoro's set (as phase 1 makes them); "en" words are the control group
WORDS = [
    ("Hatteras", "ˈhætəɹəs", "name"), ("Matanzas", "mɑˈtɑnsɑs", "name"), ("Habana", "ɑˈbɑnɑ", "name"),
    ("Ocracoke", "ˈOkɹəkOk", "name"), ("Cienfuegos", "sjAnˈfwAɡOs", "name"), ("Capitán", "kɑpiˈtɑn", "foreign"),
    ("Jesús", "hAˈsus", "foreign"), ("Contratación", "kOntɹɑtɑˈsjOn", "foreign"), ("Gracias", "ˈɡɹɑsjɑs", "foreign"),
    ("Santo", "ˈsɑntO", "foreign"), ("Vaya", "ˈbɑjɑ", "foreign"), ("Pourquoi", "puɹˈkwɑ", "foreign"),
    ("Merde", "mˈɛɹd", "foreign"), ("Leftenant", "lɛfˈtɛnənt", "name"), ("Mamá", "mɑˈmɑ", "foreign"),
    ("water", "wˈɔtəɹ", "en"), ("morning", "mˈɔɹnɪŋ", "en"), ("captain", "kˈæptən", "en"),
    ("harbor", "hˈɑɹbəɹ", "en"), ("island", "ˈIlənd", "en"),
]

FAR = {"ɑ": "i", "a": "i", "æ": "u", "ɛ": "u", "ə": "i", "ɪ": "ɑ", "i": "ɑ", "u": "æ", "ʊ": "æ", "ɔ": "i",
       "O": "i", "A": "u", "I": "u", "W": "i", "Y": "æ", "ʌ": "i", "ɜ": "i"}


def wrong(sounds: str) -> str:
    """The stressed vowel (or the first one) swapped for a distant vowel."""
    start = max(sounds.find("ˈ"), 0)
    for i in range(start, len(sounds)):
        if sounds[i] in FAR:
            return sounds[:i] + FAR[sounds[i]] + sounds[i + 1:]
    return sounds


def say(text: str, voice: str) -> bytes:
    r = requests.post(f"{KOKORO_URL}/v1/audio/speech", timeout=60,
                      headers={"Authorization": f"Bearer {KOKORO_KEY}", "Content-Type": "application/json"},
                      json={"model": "tts-1", "input": text, "voice": voice, "response_format": "wav", "speed": 1.0})
    r.raise_for_status()
    return r.content


def run(voice: str, models: list[str], words: list[dict] | None = None) -> dict:
    items = [(w["word"], w["sounds"], w.get("group", "name")) for w in words] if words else WORDS
    clips = {}
    t0 = time.time()
    for word, sounds, _ in items:
        clips[word] = {
            "plain": say(word + ".", voice),
            "right": say(f"[{word}](/{sounds}/).", voice),
            "wrong": say(f"[{word}](/{wrong(sounds)}/).", voice),
        }
    tts_seconds = time.time() - t0

    report = {"voice": voice, "device": app.DEVICE, "tts_seconds": round(tts_seconds, 1), "models": {}}
    for model in models:
        t_load = time.time()
        app.recognizer(model)
        load = time.time() - t_load
        rows, times = [], []
        for word, sounds, group in items:
            row = {"word": word, "group": group, "reference": sounds, "wrong_sounds": wrong(sounds),
                   "g2p": app.g2p(word), "g2p_score": app.score(sounds, app.g2p(word))["score"]}
            for kind in ("plain", "right", "wrong"):
                t = time.time()
                heard = app.recognize(clips[word][kind], model)
                times.append(time.time() - t)
                row[kind] = {"heard": heard, "score": app.score(sounds, heard)["score"]}
            rows.append(row)
        named = [r for r in rows if r["group"] != "en"]
        gaps = [r["right"]["score"] - r["wrong"]["score"] for r in named]
        report["models"][model] = {
            "load_seconds": round(load, 1),
            "seconds_per_clip": round(statistics.mean(times), 3),
            "right_mean": round(statistics.mean(r["right"]["score"] for r in named), 1),
            "wrong_mean": round(statistics.mean(r["wrong"]["score"] for r in named), 1),
            "gap_mean": round(statistics.mean(gaps), 1),
            "gap_min": round(min(gaps), 1),
            "words_with_clear_gap": sum(1 for g in gaps if g >= 10),
            "words_checked": len(named),
            "control_plain_mean": round(statistics.mean(r["plain"]["score"] for r in rows if r["group"] == "en"), 1),
            "rows": rows,
        }
    return report
