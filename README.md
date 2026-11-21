# AI Audiobook Studio

A single-container web app that turns EPUB books into audiobooks using a Kokoro TTS server. It keeps books as projects: import, review chapters, set voice and pronunciation, generate, and regenerate only what changed.

Status: 0.0.1. Done: baseline container, Voice Lab, layered pronunciation. See `PLAN.md`.

## Run

```bash
cp .env.example .env     # set KOKORO_URL and KOKORO_API_KEY
docker compose up -d --build
```

Open `http://<host>:8085/`. Data lives in the `audiobook-storage` volume.

## Notes
- Kokoro itself runs separately (GPU recommended). This app only calls its OpenAI-compatible `/v1/audio/speech` endpoint.
- Voice and speed are saved per project in the app, never in environment variables.
- Do not commit `.env`, EPUBs or generated audio.
