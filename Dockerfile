FROM webdevops/php-nginx:8.3

# ffmpeg is used later to join WAV segments into chapters.
RUN apt-get update \
 && apt-get install -y --no-install-recommends ffmpeg \
 && rm -rf /var/lib/apt/lists/*

ENV WEB_DOCUMENT_ROOT=/app \
    PHP_UPLOAD_MAX_FILESIZE=512M \
    PHP_POST_MAX_SIZE=512M \
    PHP_MAX_EXECUTION_TIME=900 \
    SERVICE_NGINX_CLIENT_MAX_BODY_SIZE=512M

COPY src/ /app/AI-Audiobook/
COPY docker/root-index.php /app/index.php

RUN mkdir -p /app/AI-Audiobook/storage/projects /app/AI-Audiobook/storage/cache \
             /app/AI-Audiobook/storage/logs /app/AI-Audiobook/storage/tmp \
             /app/AI-Audiobook/storage/backups /app/AI-Audiobook/storage/locks \
 && chown -R application:application /app

VOLUME /app/AI-Audiobook/storage
