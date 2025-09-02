# Ubuntu
FROM ubuntu:24.04

# Git build arguments
ARG GIT_BRANCH
ARG GIT_COMMIT
ARG GIT_TAG

# Set environment variables for git information
ENV GIT_BRANCH=${GIT_BRANCH}
ENV GIT_COMMIT=${GIT_COMMIT}
ENV GIT_TAG=${GIT_TAG}

# Set the working directory
WORKDIR /var/www/html

ENV WWWGROUP="m3ue"
ENV WWWUSER="m3ue"

# Create m3ue user and group if not already present (safe, auto UID)
RUN getent group m3ue || groupadd m3ue && \
    id -u m3ue || useradd -g m3ue -m m3ue

# Install basic packages
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
    software-properties-common \
    ca-certificates \
    curl \
    gettext-base \
    wget && \
    add-apt-repository ppa:ondrej/php && \
    apt-get update && \
    apt-get install -y --no-install-recommends \
    git \
    supervisor \
    sqlite3 \
    nano \
    nodejs \
    npm \
    redis-server \
    bash \
    tzdata \
    cron \
    gosu \
    # HW accelerated video encoding
    libva2 \
    libva-drm2 \
    libva-x11-2 \
    vainfo \
    # FFmpeg
    ffmpeg \
    # nginx + php-fpm
    nginx \
    php8.4-cli \
    php8.4-fpm \
    php8.4-dev

# Add architecture-specific packages conditionally
RUN if [ "$(uname -m)" = "x86_64" ]; then \
        dpkg --add-architecture i386 && \
        apt-get update && \
        echo "Installing Intel VAAPI/QSV drivers for x86_64..." && \
        apt-get install -y --no-install-recommends \
            intel-media-va-driver-non-free \
            libmfx1 \
            libmfx-tools \
            libvpl2 \
            libigc1 \
            libigdfcl1 \
            libva-dev \
            libdrm-dev; \
    elif [ "$(uname -m)" = "aarch64" ]; then \
        apt-get update && \
        echo "Installing ARM-compatible VAAPI drivers for aarch64..." && \
        apt-get install -y --no-install-recommends \
            libva-dev \
            libdrm-dev; \
    else \
        apt-get update && \
        echo "Installing basic VAAPI support for $(uname -m) architecture..." && \
        apt-get install -y --no-install-recommends \
            libva-dev \
            libdrm-dev; \
    fi

# Install PostgreSQL 17 server & client from official PostgreSQL repository
RUN apt-get update && \
    apt-get install -y wget gnupg && \
    wget -qO - https://www.postgresql.org/media/keys/ACCC4CF8.asc | apt-key add - && \
    echo "deb http://apt.postgresql.org/pub/repos/apt/ noble-pgdg main" > /etc/apt/sources.list.d/pgdg.list && \
    apt-get update && \
    apt-get install -y --no-install-recommends \
        postgresql-17 \
        postgresql-client-17 \
        postgresql-contrib-17

# Install Jellyfin FFmpeg
RUN apt-get update && \
    apt-get install -y wget gnupg && \
    wget -O - https://repo.jellyfin.org/ubuntu/jellyfin_team.gpg.key | gpg --dearmor > /usr/share/keyrings/jellyfin.gpg && \
    echo "deb [signed-by=/usr/share/keyrings/jellyfin.gpg] https://repo.jellyfin.org/ubuntu noble main" > /etc/apt/sources.list.d/jellyfin.list
RUN apt-get update && \
    apt-get install -y --no-install-recommends jellyfin-ffmpeg7

# Install CRON
RUN echo '* * * * * root cd /var/www/html && /usr/bin/php artisan schedule:run >> /dev/null 2>&1' > /etc/cron.d/laravel-scheduler && \
    chmod 0644 /etc/cron.d/laravel-scheduler

# Install Redis config
COPY ./docker/8.4/redis.conf /etc/redis/redis.tmpl
RUN chmod 0644 /etc/redis/redis.tmpl

# Install and configure PHP extensions (adjust as needed)
RUN apt-get install -y --no-install-recommends \
    php8.4-sqlite3 php8.4-gd php8.4-curl \
    php8.4-intl php8.4-imap php8.4-mbstring \
    php8.4-xml php8.4-zip php8.4-bcmath php8.4-soap \
    php8.4-ldap \
    php8.4-msgpack \
    php8.4-opcache \
    php8.4-pgsql \
    php8.4-igbinary \
    php8.4-pcov php8.4-imagick \
    php8.4-redis \
    php8.4-posix

COPY ./docker/8.4/php.ini /etc/php/8.4/mods-available/99-m3ue.ini
RUN ln -s /etc/php/8.4/mods-available/99-m3ue.ini /etc/php/8.4/cli/conf.d/99-m3ue.ini && \
    ln -s /etc/php/8.4/mods-available/99-m3ue.ini /etc/php/8.4/fpm/conf.d/99-m3ue.ini

# Configure supervisord
RUN mkdir -p /var/log/supervisor

COPY ./docker/8.4/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Install composer
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PATH=$PATH:/root/.composer/vendor/bin
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy or create an nginx.conf if needed
COPY ./docker/8.4/nginx/nginx.conf /etc/nginx/nginx.tmpl
COPY ./docker/8.4/nginx/laravel.conf /etc/nginx/conf.d/laravel.tmpl

# Configure PHP-FPM
COPY ./docker/8.4/www.conf /etc/php/8.4/fpm/pool.d/www.tmpl

# Configure container startup script
COPY start-container /usr/local/bin/start-container
RUN chmod +x /usr/local/bin/start-container

# Copy application code
COPY . /var/www/html

# Create git info file
RUN echo "GIT_BRANCH=${GIT_BRANCH}" > /var/www/html/.git-info && \
    echo "GIT_COMMIT=${GIT_COMMIT}" >> /var/www/html/.git-info && \
    echo "GIT_TAG=${GIT_TAG}" >> /var/www/html/.git-info && \
    echo "BUILD_DATE=$(date -u +%Y-%m-%dT%H:%M:%SZ)" >> /var/www/html/.git-info

# Install composer dependencies
RUN composer install --no-dev --no-interaction --no-progress -o

# Setup user, group and permissions
RUN addgroup $WWWGROUP \
    && adduser -h /var/www/html -s /bin/bash -G $WWWGROUP -D $WWWUSER

# Symlink jellyfin-ffmpeg to usr/bin
RUN ln -s /usr/lib/jellyfin-ffmpeg/ffmpeg /usr/bin/jellyfin-ffmpeg
RUN ln -s /usr/lib/jellyfin-ffmpeg/ffprobe /usr/bin/jellyfin-ffprobe

RUN chown -R $WWWUSER:$WWWGROUP /var/www/html
RUN chown -R $WWWUSER:$WWWGROUP /var/lib/nginx
RUN mkdir -p /var/lib/postgresql && chown -R $WWWUSER:$WWWGROUP /var/lib/postgresql
RUN mkdir -p /run/postgresql && chown -R $WWWUSER:$WWWGROUP /run/postgresql

# Final entrypoint
ENTRYPOINT ["start-container"]
