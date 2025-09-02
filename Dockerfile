# syntax=docker/dockerfile:1
FROM ubuntu:24.04

# Git build arguments
ARG GIT_BRANCH
ARG GIT_COMMIT
ARG GIT_TAG

# Set environment variables
ENV GIT_BRANCH=${GIT_BRANCH}
ENV GIT_COMMIT=${GIT_COMMIT}
ENV GIT_TAG=${GIT_TAG}
ENV DEBIAN_FRONTEND=noninteractive
ENV WWWGROUP="m3ue"
ENV WWWUSER="m3ue"

WORKDIR /var/www/html

# Create user and group + install all packages in one layer with aggressive cleanup
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    getent group m3ue || groupadd m3ue && \
    id -u m3ue || useradd -g m3ue -m m3ue && \
    apt-get update && \
    apt-get install -y --no-install-recommends \
        software-properties-common ca-certificates curl gettext-base wget && \
    add-apt-repository ppa:ondrej/php && \
    apt-get update && \
    apt-get install -y --no-install-recommends \
        git supervisor sqlite3 nano nodejs npm redis-server bash tzdata cron gosu \
        libva2 libva-drm2 libva-x11-2 vainfo ffmpeg nginx \
        php8.4-cli php8.4-fpm php8.4-dev php8.4-sqlite3 php8.4-gd php8.4-curl \
        php8.4-intl php8.4-imap php8.4-mbstring php8.4-xml php8.4-zip php8.4-bcmath \
        php8.4-soap php8.4-ldap php8.4-msgpack php8.4-opcache php8.4-pgsql \
        php8.4-igbinary php8.4-imagick php8.4-redis php8.4-posix && \
    if [ "$(uname -m)" = "x86_64" ]; then \
        dpkg --add-architecture i386 && apt-get update && \
        apt-get install -y --no-install-recommends \
            intel-media-va-driver-non-free libmfx1 libmfx-tools libvpl2 \
            libigc1 libigdfcl1 libva-dev libdrm-dev; \
    else \
        apt-get install -y --no-install-recommends libva-dev libdrm-dev; \
    fi && \
    apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /var/cache/debconf/*

# Install PostgreSQL in separate layer (only when needed)
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update && \
    apt-get install -y --no-install-recommends wget gnupg && \
    wget -qO - https://www.postgresql.org/media/keys/ACCC4CF8.asc | gpg --dearmor -o /usr/share/keyrings/postgresql.gpg && \
    echo "deb [signed-by=/usr/share/keyrings/postgresql.gpg] http://apt.postgresql.org/pub/repos/apt/ noble-pgdg main" > /etc/apt/sources.list.d/pgdg.list && \
    apt-get update && \
    apt-get install -y --no-install-recommends \
        postgresql-17 postgresql-client-17 postgresql-contrib-17 && \
    apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Install Jellyfin FFmpeg in separate layer
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    wget -O - https://repo.jellyfin.org/ubuntu/jellyfin_team.gpg.key | gpg --dearmor -o /usr/share/keyrings/jellyfin.gpg && \
    echo "deb [signed-by=/usr/share/keyrings/jellyfin.gpg] https://repo.jellyfin.org/ubuntu noble main" > /etc/apt/sources.list.d/jellyfin.list && \
    apt-get update && \
    apt-get install -y --no-install-recommends jellyfin-ffmpeg7 && \
    ln -s /usr/lib/jellyfin-ffmpeg/ffmpeg /usr/bin/jellyfin-ffmpeg && \
    ln -s /usr/lib/jellyfin-ffmpeg/ffprobe /usr/bin/jellyfin-ffprobe && \
    apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Copy configuration files and setup
COPY ./docker/8.4/redis.conf /etc/redis/redis.tmpl
COPY ./docker/8.4/php.ini /etc/php/8.4/mods-available/99-m3ue.ini
COPY ./docker/8.4/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY ./docker/8.4/nginx/nginx.conf /etc/nginx/nginx.tmpl
COPY ./docker/8.4/nginx/laravel.conf /etc/nginx/conf.d/laravel.tmpl
COPY ./docker/8.4/www.conf /etc/php/8.4/fpm/pool.d/www.tmpl
COPY start-container /usr/local/bin/start-container

# Setup configs and permissions
RUN chmod 0644 /etc/redis/redis.tmpl && \
    ln -s /etc/php/8.4/mods-available/99-m3ue.ini /etc/php/8.4/cli/conf.d/99-m3ue.ini && \
    ln -s /etc/php/8.4/mods-available/99-m3ue.ini /etc/php/8.4/fpm/conf.d/99-m3ue.ini && \
    mkdir -p /var/log/supervisor && \
    chmod +x /usr/local/bin/start-container && \
    echo '* * * * * root cd /var/www/html && /usr/bin/php artisan schedule:run >> /dev/null 2>&1' > /etc/cron.d/laravel-scheduler && \
    chmod 0644 /etc/cron.d/laravel-scheduler

# Install composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PATH=$PATH:/root/.composer/vendor/bin

# Copy application and build
COPY . /var/www/html

# Build application and cleanup in single layer
RUN echo "GIT_BRANCH=${GIT_BRANCH}" > /var/www/html/.git-info && \
    echo "GIT_COMMIT=${GIT_COMMIT}" >> /var/www/html/.git-info && \
    echo "GIT_TAG=${GIT_TAG}" >> /var/www/html/.git-info && \
    echo "BUILD_DATE=$(date -u +%Y-%m-%dT%H:%M:%SZ)" >> /var/www/html/.git-info && \
    composer install --no-dev --no-interaction --no-progress -o --no-cache && \
    mkdir -p /var/lib/postgresql /run/postgresql && \
    chown -R $WWWUSER:$WWWGROUP /var/www/html /var/lib/nginx /var/lib/postgresql /run/postgresql && \
    rm -rf /root/.composer/cache /tmp/* /var/tmp/*

ENTRYPOINT ["start-container"]