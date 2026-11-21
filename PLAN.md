# AI Audiobook Studio: plan of action (v3)

**Status (2026-11-21):** M0 to M7 are built, tested and deployed on AI-Laptop (version 0.0.1): import, chapter building, layered pronunciation with linked series sets, narration review and approval, segmenting, background generation that survives restarts, per-chapter audio state, and one-button generation of every approved chapter. Still open: a ZIP or M4B export (per-chapter WAV download exists), chapter-level voice and speed overrides, regenerating selected text, a regeneration history, and the chapteriser idea. Listen to real output and tune pause lengths and segment size first.
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
