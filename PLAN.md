# AI Audiobook Studio: plan of action (v3)

**Status (2026-11-21):** M0 to M7 are built, tested and deployed on AI-Laptop (version 0.0.1): import, chapter building, layered pronunciation with linked series sets, narration review and approval, segmenting, background generation that survives restarts, per-chapter audio state, and one-button generation of every approved chapter. Also done since: ALL-CAPS words are said as normal words (project setting, with an audit list), a pronunciation workbench on the chapter page, a new layout (project header with tabs, compact tables, sticky workbench) and per-chapter voice and speed. Then: chapters are encoded to AAC mono m4a (32, 64, 96 or 128 kbps) after Kokoro's WAV; a chaptered m4b and a ZIP of the m4a files can be built; the chapter page shows the narration as a book with marked changes and in-place edits stored as source-anchored edits. Still open: regenerating selected text, a regeneration history, other formats (mp3, opus), cover art in the m4b, and the chapteriser idea. Listen to real output and tune pause lengths and segment size first.
Learned on the way: the backup never created the first chapter list (fixed); scene breaks such as "* * *" must be pauses, not segments; Kokoro generates about 3 segments per second, so a 44 minute chapter takes under a minute.

Goal: one Docker container, all PHP, hosting the web app at `/AI-Audiobook/`. It turns EPUBs into audiobooks through Kokoro (on AI-Laptop), one WAV per chapter. M4B and chapteriser are out of scope for now. The old Python test scripts stay out of the repo, because they contain a plain-text API key.

## What the backup (v1.0.0-phase1-complete) already does
Reviewed from the code, not yet run.
- Projects: create, archive, restore, delete. SQLite with migrations. A `jobs` table exists but the worker is an empty shell.
- EPUB upload, with the original kept and hashed. Analysis stores spine sections and **blocks** (paragraph-level text with DOM paths) plus import warnings.
- **Logical sections** (your chapters): rename, include or exclude, reorder, **combine** several EPUB sections, **split** at a block, undo (revision snapshots), and chapter title number and capitalization formatting.
- Diagnostics page, CSRF protection, a settings table.
- Not there: narration-text layer, pronunciation, Kokoro calls, voices and speed, segmenting, audio generation, any UI for audio.
- Code quality: much of it is minified one-liners from the AI, so it needs reformatting before we build on it. The bundled ffmpeg binary is replaced by the container's own.

So M0 and most of the old M3 exist. Plan below skips ahead.

## Milestones (each ends in something you can test)
| # | Milestone | You can test | Why stop here |
|---|-----------|--------------|---------------|
| M0 | Container and repo. Backup site in Docker (PHP, nginx, SQLite on a volume, ffmpeg). Reformat code. GitHub repo, `.env.example`, Actions build. | Open `/AI-Audiobook/`, import the Sisko EPUB, combine sections into chapters. | Deployable baseline. Confirms the existing chapter work. |
| M1 | Kokoro link and **Voice Lab**. URL and key from env. Voice and speed chosen in the UI and saved per project (default am_michael, 1.0). Test text, generate WAV, play, download, A/B. | Hear voices and speeds from the browser. | Useful on its own. |
| M2 | **Layered pronunciation.** Default, then per-voice, then per-project, applied before sending text. Project wins over voice, voice over default. Import your `pronunciations.json` as the Sisko starting set. "Test this word" box in the Voice Lab. | Fer-en-gi audibly right. See which layer a rule came from. | Pronunciation tuning without any book work. |
| M3 | **Narration text.** New layer between extracted blocks and audio. Original, extracted and narration text stay separate. Review view with the original beside the narration text and the rules that fired. Approve per chapter. | Review and approve a chapter. | Review before spending GPU time. |
| M4 | **Segments and one chapter end to end.** Paragraph, then sentence, then clause, with a hard split last. Per-segment status. Generate one chapter as WAV segments, join them with ffmpeg into one chapter WAV, play and download. | Generate one real chapter and listen. | First real output. |
| M5 | **Resumable queue.** PHP CLI worker, progress, skip completed segments, retry failed, survive restart. | Stop the container mid-chapter and restart. | Safe for long books. |
| M6 | **Granular regeneration.** Segment, chapter, selected text. History. Flag audio affected by a dictionary change. | Fix one word, regenerate one segment. | The core production loop. |
| M7 | **Pronunciation sets** (see below), whole-book generation, bulk download, polish. | Apply a series set to a second book. | Finished tool. |

## Pronunciation layers (needs your answer on one point)
Rules: default, per voice, per project. Precedence: project over voice over default.
Series sets: a named bundle of rules (for example "Star Trek DS9") that can be applied to projects.
- **Decided:** sets are *linked* (edit a set and every book using it updates), ranked project > series set > voice > default.
- Your `pronunciations.json` is saved first as a project-level starting list, then moved into a series set once M7 exists.

## Decisions already made
- All PHP, with a PHP CLI worker. Project settings (voice, speed) live in the database, never env. Only Kokoro URL and key are env.
- Star Trek text rules (stardates, years, ALL-CAPS) become optional presets inside series sets.
- Raw WAV is large, about 3 MB per minute. Per-segment files are kept for regeneration and deleted only on request.
- Speed default is 1.0 (slower sounded worse in your tests).

## Next changes (list from 2026-11-22)

| # | Change | State |
|---|--------|-------|
| 1 | Pronunciation becomes **Rulesets** (nav, tabs, titles, wording; `/?page=rulesets`, the old link still works). "Series set" is now "ruleset". The side panel is called "Sound test". | done |
| 2 | **Move or copy a rule** to another ruleset (default, a voice, a shared ruleset, a project). A rule with the same written form there is replaced, and the message says so. | done |
| 3 | **Regex rules and a regex helper.** Each rule gets a match type: whole word, anywhere, or regex. A helper builds the pattern from plain choices (starts with, ends with, contains, any of these words, optional letters, digits, capitalised word, possessive) with a plain-English description and a live count of matches in the book. The example finder and prev/next use the same matching. Safety: patterns are validated on save, length-limited, and run with a low backtracking limit. | done |
| 4 | **Text checks**: a project tab that scans the book for things that trip a voice and lists them with counts, examples and the same sound-test bar. A dropdown picks the kind: proper nouns, numbers (years, ordinals, digits with units), Roman numerals, initialisms, abbreviations, dates and times, odd spellings. Common words that merely look like proper nouns are filtered out with a word list (the container's `wamerican` list). Each row can become a rule. | after 3 |
| 5 | **Automatic sound test.** Spike first, then decide: (a) does the Kokoro server accept inline phonemes, which would make rules exact; (b) reference pronunciations from an online dictionary (IPA and audio); (c) how to judge "sounds alike" (speech recognition on the synthesized clip, or phoneme comparison); (d) an upload for a reference clip when none is found. Deliverable: a measured prototype on about ten words before any UI. | phase 1 built (see Automatic pronunciation check); phase 2 planned |
| 6 | **Project page layout**: a workflow stepper (Source, Structure, Rulesets, Review, Generate, Export) with a status and one clear next action per step; settings move into their own panel; the lifecycle controls fold away. | planned |

## Automatic pronunciation check (item 5)

Goal: for the words on the Suggestions tab, find how each should sound, check what the voice actually says, and
end with two lists: suggested rules (with a confidence score) and words nobody could find.

### Spike results (2026-11-24)

- **The voice server accepts exact pronunciations.** `[Habana](/həbˈɑnə/)` in the text is spoken with those sounds
  (plain, right-sounds and wrong-sounds versions all gave different audio, none read the brackets aloud). So a rule
  can carry the exact sounds; no need to guess respellings when a reference exists.
- **The server is a minimal wrapper** (hwdsl2/docker-kokoro): `/v1/audio/speech`, `/v1/voices`, `/v1/models`. It
  cannot say which sounds it will use for a piece of text, and nothing on the network can listen to audio.
- **Online references** (Wiktionary, then Wikipedia, with the book's title as search context):
  real places are well covered (Havana, Hatteras: English; Matanzas, Cienfuegos: Spanish); some invented names
  (Odo, Bajoran) have an English pronunciation; most character names (Sisko, Worf, Picard, Quark) and small places
  (Guanabacoa, Malecón) have none.
- **Rate limits:** Wikipedia refused requests after about 30 quick lookups. Look up about one word a second, in the
  background, with a cache so each word is looked up once.

### Phase 1: look up, convert, suggest (in the app, no new services)

1. **Background task** "Check pronunciations online" from the Suggestions tab (kind `pron_check`, run by the build
   worker; shows in Activity with progress). Settings per run: which kinds (names by default), minimum confidence
   for "make all".
2. **Lookup** per word, cached in a new `pronunciation_refs` table (word, language, IPA, source URL, source kind,
   looked_up_at, status found|none|error): Wiktionary page for the word (and lowercase) → Wikipedia page →
   Wikipedia search with the book title (and author) as context. Parse `{{IPA|..}}`, `{{IPAc-en|..}}`,
   `{{IPA-xx|..}}`, `{{lang-xx|..}}`. Throttle 1 request per second, back off on 429, generic User-Agent.
3. **Convert** IPA to Kokoro's sound symbols (misaki set: stress marks kept; diphthongs to its single letters,
   e.g. eɪ→A, aɪ→I, oʊ→O, aʊ→W, ɔɪ→Y; non-English sounds to the nearest English ones, e.g. ɣ→g, β→b, ɱ→m, x→h,
   r/ɾ→ɹ, ʎ→j). A table-driven converter with tests on the spike words.
4. **Confidence** from the source: High = English IPA on a page whose title is the word (or a redirect to it);
   Medium = another language's IPA, or a page found by search; Low = the word appears only as part of another title.
5. **Results page** (Suggestions tab, new "Checked online" view): Suggested rules (word, how it will sound as a
   readable respelling such as "Ha-BAH-nah", confidence, source link, Try, Make rule, Dismiss; "Make all high-
   confidence rules") and Couldn't find (Try in the sound test to set by ear). Rules made here use the exact markup
   as their spoken form; the rule list shows the readable respelling next to it.
6. **Tests**: parser on saved page samples, converter table, confidence rules, throttling/caching with a fake HTTP
   client, rule creation.

### Phase 2: listen to the voice and score it (a small new service on AI-Laptop)

**The service** (`pron-check`, Python, one container next to Kokoro, internal network only, API key like Kokoro):
- `POST /g2p {text, lang}` → the sounds Kokoro will use (misaki, the same G2P Kokoro runs). Fast, no audio.
- `POST /recognize` (WAV) → the sounds heard in the audio, from a phoneme-recognition model
  (first choice `facebook/wav2vec2-xlsr-53-espeak-cv-ft`; fallback Allosaurus on CPU).
- `POST /score {reference_ipa, candidate_ipa}` → 0–100, from a phoneme edit distance where similar sounds cost
  less (articulatory features, e.g. panphon), with stress ignored or weighted lightly.

**How a word is checked:**
1. Reference sounds: from phase 1 (online IPA) or from a clip you upload (recognized by the service).
2. Make the word with the voice inside a short carrier sentence, cut the word out by timing, recognize its sounds,
   score against the reference → the confidence rating for how the voice says it now.
3. If below the minimum (a setting, default 80): try candidates and keep the best:
   the exact markup from the reference (usually wins outright); then respellings generated from the reference
   sounds (a sound-to-spelling table, a few variants per word), each first scored cheaply with `/g2p`, the top three
   checked by audio. Stop at the first one above the minimum, at most about ten tries per word.
4. Result per word: before score, best candidate, after score, source. Above the minimum → Suggested rules; below →
   "Needs your ear", with the best attempt and its score.

**For words with no reference** the service cannot judge correctness; it can only offer the voice's current reading
and a couple of alternatives to choose from by ear. Uploading a clip (your own recording, or one found elsewhere)
gives it a reference.

**Before building phase 2, measure on about 20 words** (spike words plus common English words as a control):
recognition accuracy on Kokoro audio; whether scores separate right from wrong pronunciations (want a clear gap);
speed per word on AI-Laptop (GPU vs CPU); container size and memory. Go ahead only if right readings score clearly
higher than wrong ones.

**Deployment:** a Bosun stack built from a small repo folder (`pron-check/`, Dockerfile, requirements pinned),
`PRON_CHECK_URL` and `PRON_CHECK_API_KEY` in the app's environment; the app works without it (phase 1 only) and
Diagnostics shows whether it is reachable.

**Risks:** recognition models are trained on human speech and may mishear synthetic voices; short words score
noisily (use carrier sentences and average two takes); the model download is large (about 1.3 GB); GPU sharing with
Kokoro.

### Phase 2 measurement (2026-11-25): listening is not good enough to judge; stop that part

Service `pron-check/` deployed as its own Bosun stack on AI-Laptop (CPU). 15 names and foreign words plus 5 English
controls, voice am_michael, each said three ways (as written / with the reference sounds / with the stressed vowel
deliberately wrong), recognized by `wav2vec2-lv-60-espeak-cv-ft` and `wav2vec2-xlsr-53-espeak-cv-ft`.

- **Speed is fine:** about 0.13 s to recognize a clip on CPU, 4 s for all 60 clips from Kokoro, ~10 s to load a model.
- **English controls work:** right readings score 100 and the wrong ones 42–76.
- **Names and foreign words do not:** right averages 69.5 and wrong 60.4 (lv-60). Only 7 of 15 words show a clear gap,
  and 3 are reversed (Matanzas, Leftenant, Mamá); averaging both models gives 6 of 15. The models hear foreign sounds
  through English habits, so the right foreign reading scores about 60–75 whatever the voice does, and the voice's
  English guess often scores *higher* than the correct reading (Cienfuegos, Gracias, Mamá).
- Go/no-go was "right readings clearly higher than wrong": **no-go** for scoring by ear.
- **What does work:** comparing the reference with the voice's own reading from misaki (`/g2p`, no audio) is exact
  and instant. It separates "the voice already says it" (Merde 100, Capitán 91, Gracias 91) from "the voice gets it
  wrong" (Jesús 49, Vaya 43, Habana 66), and can rank respellings without making audio.
- Worth a listen: with the markup, both models hear Mamá and Matanzas start "my-" (maɪ), not "mah".

**Phase 2b (proposed instead):** use `/g2p` in the app. Each found word shows how close the voice already is (a
score); words above the minimum move to "the voice already says it"; for words below it, generate respellings from the
reference sounds, score each with `/g2p`, and suggest the best plain-spelling rule next to the exact-sounds rule.
