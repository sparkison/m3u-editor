########################################
# Multistage Dockerfile
#
# Stages:
#  * node_builder  - builds JS/CSS assets (uses official Node image)
#  * composer      - installs PHP dependencies (uses official composer image)
#  * runtime       - runtime image with PHP-FPM and application files (Alpine)
#
# Default final image is the `runtime` stage which contains PHP-FPM and the
# application (built assets + vendor).
########################################

ARG GIT_BRANCH
ARG GIT_COMMIT
ARG GIT_TAG

########################################
# Node builder — builds frontend assets
########################################
FROM node:18-alpine AS node_builder
WORKDIR /app

# Copy only the files required for npm install to leverage Docker cache
COPY package.json package-lock.json ./
RUN npm ci --silent

# Copy the rest of the app and run the frontend build
COPY . ./
RUN npm run build

########################################
# Composer builder — installs PHP dependencies
########################################
FROM composer:2 AS composer
WORKDIR /app

# Copy composer metadata first for better caching
COPY composer.json composer.lock ./
# Copy everything else to ensure autoload generation is correct
COPY . /app

# Install production dependencies only (no-dev) and optimize
RUN composer install --no-dev --no-interaction --no-progress -o --prefer-dist

########################################
# Redis — minimal Alpine image with PHP-FPM
########################################
FROM redis:7-alpine as redis

# Add envsubst (gettext) so we can template the redis config at container start
RUN apk add --no-cache gettext

COPY docker/8.4/redis/ /docker-entrypoint-redis/
COPY docker/8.4/redis/redis.conf /etc/redis/redis.tmpl
COPY docker/8.4/redis/docker-entrypoint.sh /usr/local/bin/docker-entrypoint-redis
RUN chmod +x /usr/local/bin/docker-entrypoint-redis

ENTRYPOINT ["/usr/local/bin/docker-entrypoint-redis"]
CMD ["/usr/local/bin/docker-entrypoint-redis"]

########################################
# Runtime — minimal Alpine image with PHP-FPM
########################################
FROM alpine:3.21.3 AS runtime

ENV WWWGROUP="m3ue"
ENV WWWUSER="m3ue"
WORKDIR /var/www/html

# Install runtime packages and PHP extensions
RUN apk update && apk --no-cache add \
    coreutils \
    openssl \
    supervisor \
    envsubst \
    su-exec \
    nano \
    wget \
    curl \
    sqlite \
    ca-certificates \
    php84-cli \
    php84-fpm \
    php84-posix \
    php84-openssl \
    php84-dev \
    php84-sqlite3 php84-gd php84-curl \
    php84-intl php84-imap php84-mbstring \
    php84-xml php84-zip php84-bcmath php84-soap \
    php84-xmlreader php84-xmlwriter \
    php84-iconv php84-ldap php84-tokenizer \
    php84-msgpack php84-opcache php84-pdo_sqlite \
    php84-pdo_pgsql php84-phar php84-fileinfo \
    php84-pecl-igbinary php84-pecl-pcov php84-pecl-imagick \
    php84-pecl-redis php84-pcntl \
    bash tzdata \
    && ln -s /usr/bin/php84 /usr/bin/php

# PHP & supervisor configuration copied from the repo
COPY ./docker/8.4/php.ini /etc/php84/conf.d/99-m3ue.ini
COPY ./docker/8.4/www.conf /etc/php84/php-fpm.d/www.tmpl
COPY ./docker/8.4/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copy the application files (from composer stage to bring vendor)
COPY --from=composer /app /var/www/html

# Copy built frontend assets from node builder
COPY --from=node_builder /app/public/build /var/www/html/public/build

# Copy start script and make executable
COPY start-container /usr/local/bin/start-container
RUN chmod +x /usr/local/bin/start-container || true

# Create git info file (preserve ARG values)
RUN echo "GIT_BRANCH=${GIT_BRANCH:-local}" > /var/www/html/.git-info || true \
    && echo "GIT_COMMIT=${GIT_COMMIT:-local}" >> /var/www/html/.git-info || true \
    && echo "GIT_TAG=${GIT_TAG:-local}" >> /var/www/html/.git-info || true \
    && echo "BUILD_DATE=$(date -u +%Y-%m-%dT%H:%M:%SZ)" >> /var/www/html/.git-info || true

# Create user and set permissions
RUN addgroup $WWWGROUP || true \
    && adduser -h /var/www/html -s /bin/bash -G $WWWGROUP -D $WWWUSER || true \
    && chown -R $WWWUSER:$WWWGROUP /var/www/html

# Small helper alias used in the original image
RUN echo -e '#!/bin/bash\nphp artisan "$@"' > /usr/bin/m3ue && chmod +x /usr/bin/m3ue || true

# Default entrypoint preserved from original Dockerfile
ENTRYPOINT ["start-container"]


########################################
# Nginx-only image (serves static assets, proxies to php-fpm)
########################################
FROM nginx:1.26-alpine AS nginx

# Copy built frontend assets into the nginx image
WORKDIR /var/www/html
COPY --from=node_builder /app/public /var/www/html/public

# Copy nginx templates so we can envsubst at container start
COPY ./docker/8.4/nginx/nginx.conf /etc/nginx/nginx.tmpl
COPY ./docker/8.4/nginx/laravel.tmpl /etc/nginx/conf.d/laravel.tmpl

# Add a small entrypoint that templates the config and runs nginx in foreground
COPY docker/8.4/nginx/docker-entrypoint.sh /usr/local/bin/docker-entrypoint-nginx
RUN chmod +x /usr/local/bin/docker-entrypoint-nginx

ENTRYPOINT ["/usr/local/bin/docker-entrypoint-nginx"]

########################################
# All-in-one image (backwards compatible master image)
# Contains php-fpm, nginx, redis, postgres init and embedded m3u-proxy
########################################
FROM runtime AS allinone

# ...

# Keep the same entrypoint so users can run the single-image the same way
ENTRYPOINT ["start-container"]