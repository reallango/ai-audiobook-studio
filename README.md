# AI Audiobook Studio

A single-container web app that turns EPUB books into audiobooks using a Kokoro TTS server. Books are projects: import, build chapters, set voice and pronunciation, review and approve the narration text, generate audio, and regenerate only what changed.

Status: 0.0.1. Import, chapter building, layered pronunciation, narration review as a book with in-place edits, background generation, AAC mono chapters, chaptered M4B and ZIP export. See `PLAN.md`.

## How it works
- **Source stays untouched.** The EPUB is stored and hashed. Chapter text is computed from it in layers: cleaned, pronunciation rules applied, then your per-paragraph edits.
- **Pronunciation layers**, highest wins: project > linked series set > voice > default. Series sets are shared between books (edit a set and every linked book changes). An empty spoken form removes the text (watermarks).
- **Approval** is tied to the exact narration text. Change a rule or a paragraph afterwards and the chapter shows "changed since approval".
- **Generation** splits a chapter into segments (paragraph, then sentence, then clause), sends each to Kokoro, and joins them into one WAV with pauses. Scene breaks (`* * *`) become longer pauses. Unchanged segments are reused, so a fix redoes only the affected audio.
- **A background worker** (supervised in the container) runs queued chapters and picks up unfinished ones after a restart.

## Run

```bash
cp .env.example .env     # set KOKORO_URL and KOKORO_API_KEY
docker compose up -d --build
```

Open `http://<host>:8085/`. Everything (database, EPUBs, audio) lives in the `audiobook-storage` volume: back that up.

### As a Bosun stack
Create a stack from this repository (`docker-compose.yml`, branch `main`) with the environment `KOKORO_URL` and `KOKORO_API_KEY`, and an override such as `svc.audiobook-studio.network=ainet` to join an existing network. Later updates use Bosun's **Update from source and redeploy**, which keeps the stored environment and override.

## Settings
| Environment | Meaning |
|---|---|
| `KOKORO_URL` | Kokoro server, for example `http://192.168.1.130:8880` |
| `KOKORO_API_KEY` | its API key |
| `AI_AUDIOBOOK_BASE_PATH` | optional sub-folder to serve from (default: the web root) |

Voice and speed are saved per project in the app, never in environment variables.

## Tests
```bash
php tests/pronunciation_test.php
php tests/narration_test.php
php tests/segmenter_wav_test.php
php tests/generation_test.php
php tests/jobs_test.php
```
They use a throwaway database and a fake Kokoro. CI runs them and builds the image on every push.

Do not commit `.env`, EPUBs or generated audio.
