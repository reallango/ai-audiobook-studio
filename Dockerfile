FROM webdevops/php-nginx:8.3

# ffmpeg is used later to join WAV segments into chapters.
RUN apt-get update \
 && apt-get install -y --no-install-recommends ffmpeg wamerican \
 && rm -rf /var/lib/apt/lists/*

ENV WEB_DOCUMENT_ROOT=/app \
    PHP_UPLOAD_MAX_FILESIZE=512M \
    PHP_POST_MAX_SIZE=512M \
    PHP_MAX_EXECUTION_TIME=900 \
    SERVICE_NGINX_CLIENT_MAX_BODY_SIZE=512M

COPY src/ /app/
# Background generation worker, started and restarted by the image's supervisord.
COPY docker/worker.conf /opt/docker/etc/supervisor.d/audiobook-worker.conf
# Only the app's public entry points are served; storage, code and migrations are not.
COPY docker/nginx-deny.conf /opt/docker/etc/nginx/vhost.common.d/05-deny.conf

RUN mkdir -p /app/storage/projects /app/storage/cache /app/storage/logs \
             /app/storage/tmp /app/storage/backups /app/storage/locks \
 && chmod -R a+rX /app \
 && chown -R application:application /app

VOLUME /app/storage
