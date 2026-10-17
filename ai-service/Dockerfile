# SDC AI service - Flask + gunicorn.
#
# Deployed as its OWN Railway service, built from this subdirectory
# (see RAILWAY_DOCKERFILE_PATH in the notes at the bottom of this file).
# It is never given a public domain: only the PHP service reaches it, over
# Railway's private network.

FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1

WORKDIR /app

# Dependencies first, so a code edit does not invalidate the pip layer.
COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

COPY . ./

# Railway injects PORT; 5000 is the fallback for a bare `docker run`.
ENV PORT=5000
EXPOSE 5000

# 1 request = 1 blocking call to Agnes (up to 60s), so a single sync worker
# would serialise every chat. Threads keep a slow reply from stalling the rest,
# and the timeout sits above agnes.TIMEOUT_SECONDS so gunicorn is never the
# first thing to give up.
CMD exec gunicorn \
        --bind "0.0.0.0:${PORT}" \
        --workers 2 \
        --threads 4 \
        --timeout 90 \
        --graceful-timeout 30 \
        --access-logfile - \
        --error-logfile - \
        app:app

# Railway wiring (for reference, not applied here):
#   Service:            sdc-ai
#   Variable:           PORT=5000
#   RAILWAY_DOCKERFILE_PATH=ai-service/Dockerfile   (on the PHP service's repo build)
#   PHP service var:    AI_SERVICE_URL=http://sdc-ai.railway.internal:5000
