# Larabel (Alpine Edge build)
FROM alpine:edge

# Set environment variables
ENV APP_PORT=36400
ENV TZ=UTC
ENV WWWGROUP="sail"
ENV SUPERVISOR_PHP_USER="root"
# Supervisord command to run the app
# ENV SUPERVISOR_PHP_COMMAND="/usr/bin/php -d variables_order=EGPCS /var/www/html/artisan serve --host=0.0.0.0 --port=$APP_PORT"
ENV SUPERVISOR_PHP_COMMAND="/usr/bin/php -d variables_order=EGPCS /var/www/html/artisan octane:start --workers=4 --task-workers=6 --server=swoole --host=0.0.0.0 --port=$APP_PORT"

# Set the working directory
WORKDIR /var/www/html

# Install basic packages
RUN apk update \
    && apk --no-cache add  \
        py3-setuptools \
        supervisor \
        curl-dev \
        sqlite \
        ca-certificates \
        nodejs \
        npm \
        # redis \
        git

# ...or Build Swoole from source
# RUN apk add --no-cache \
#         php84-dev php84-pear php84-openssl php84-sockets \
#         gcc g++ musl-dev make \
#     && ln -s /usr/bin/pecl84 /usr/bin/pecl
# RUN pecl install \
#     --configureoptions 'enable-openssl="no" enable-sockets="yes" enable-mysqlnd="no" enable-swoole-curl="yes"' \
#     swoole
        
# https://wiki.alpinelinux.org/wiki/Setting_the_timezone
RUN apk --no-cache add tzdata \
    && cp /usr/share/zoneinfo/$TZ /etc/localtime \
    && echo $TZ > /etc/timezone \
    && echo date

# Install and configure bash
RUN apk --no-cache add bash \
    && sed -i 's/bin\/ash/bin\/bash/g' /etc/passwd

# Install CRON
COPY ./docker/8.4/crontab /etc/cron.d/crontab
RUN chmod 0644 /etc/cron.d/crontab \
    && crontab /etc/cron.d/crontab \
    && touch /var/log/cron.log

# Install Redis config
COPY ./docker/8.4/redis.conf /etc/redis/redis.conf
RUN chmod 0644 /etc/redis/redis.conf

# Install and configure PHP
RUN apk --no-cache add \
        php84-cli php84-dev \
        php84-sqlite3 php84-gd php84-curl \
        php84-intl php84-imap php84-mbstring \
        php84-xml php84-zip php84-bcmath php84-soap \
        php84-xmlreader \
        php84-ldap \
        php84-tokenizer \
        php84-msgpack \
        php84-opcache \
        php84-pdo_mysql \
        php84-phar \
        php84-fileinfo \
        php84-pecl-igbinary php84-pecl-swoole \
        php84-pecl-pcov php84-pecl-imagick \
        # php84-pecl-redis \
        php84-pcntl \
    && ln -s /usr/bin/php84 /usr/bin/php

COPY ./docker/8.4/php.ini /etc/php84/cli/conf.d/99-sail.ini

# Install PHP Swoole
RUN apk add php84-posix php84-pecl-swoole

# Configure supervisord
RUN touch /var/run/supervisord.pid \
    && mkdir -p /etc/supervisor.d/conf.d \
    && mkdir -p /var/log/supervisor \
    && touch /var/log/supervisor/supervisord.log
COPY ./docker/8.4/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Install composer
ENV COMPOSER_ALLOW_SUPERUSER 1
ENV PATH $PATH:/root/.composer/vendor/bin
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configure container startup script
COPY start-container /usr/local/bin/start-container
RUN chmod +x /usr/local/bin/start-container

RUN git clone https://github.com/sparkison/m3u-editor.git /tmp/m3u-editor \
    && mv /tmp/m3u-editor/* /var/www/html \
    && mv /tmp/m3u-editor/.git /var/www/html/.git \
    && mv /tmp/m3u-editor/.env.example /var/www/html/.env.example \
    && rm -rf /tmp/m3u-editor

# Configure user for sail
RUN addgroup $WWWGROUP \
    && adduser -h /var/www/html -s /bin/bash -G $WWWGROUP -D sail

RUN chown -R sail:$WWWGROUP /var/www/html

# Expose app port
EXPOSE $APP_PORT

ENTRYPOINT ["start-container"]