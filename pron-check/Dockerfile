FROM python:3.11-slim

RUN apt-get update && apt-get install -y --no-install-recommends espeak-ng libsndfile1 && rm -rf /var/lib/apt/lists/*
WORKDIR /app

# CPU build of torch (small); the model runs on the CPU so it does not compete with Kokoro for the GPU
RUN pip install --no-cache-dir --index-url https://download.pytorch.org/whl/cpu torch==2.5.1 torchaudio==2.5.1
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt && python -m spacy download en_core_web_sm

COPY app.py measure.py respell.py ./
ENV HF_HOME=/models
EXPOSE 8890
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8890"]
