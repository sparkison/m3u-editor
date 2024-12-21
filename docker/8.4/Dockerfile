# Larabel (Alpine Edge build)
FROM alpine:edge

# Get build arguments
ARG WWWGROUP

# Set environment variables
ENV APP_PORT=36400
ENV TZ=UTC
ENV SUPERVISOR_PHP_USER="sail"
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
        dcron
        
# https://wiki.alpinelinux.org/wiki/Setting_the_timezone
RUN apk --no-cache add tzdata \
    && cp /usr/share/zoneinfo/$TZ /etc/localtime \
    && echo $TZ > /etc/timezone \
    && echo date

# Install and configure bash
RUN apk --no-cache add bash \
    && sed -i 's/bin\/ash/bin\/bash/g' /etc/passwd

# Install CRON
COPY crontab /etc/cron.d/crontab
RUN chmod 0644 /etc/cron.d/crontab \
    && crontab /etc/cron.d/crontab \
    && touch /var/log/cron.log

# Install root certificates
RUN apk add --no-cache \
    --repository http://dl-cdn.alpinelinux.org/alpine/v3.21/main \
    ca-certificates

# Install and configure Swoole
RUN apk add --no-cache \
        php84-dev php84-pear php84-openssl php84-sockets \
        gcc musl-dev make \
    && ln -s /usr/bin/pecl84 /usr/bin/pecl
RUN pecl install \
    --configureoptions 'enable-openssl="no" enable-sockets="yes" enable-mysqlnd="no" enable-swoole-curl="yes"' \
    swoole

# Install and configure PHP
RUN apk --no-cache add \
        php84-cli \
        php84-sqlite3 php84-gd php84-curl \
        php84-intl php84-imap php84-mbstring \
        php84-xml php84-zip php84-bcmath php84-soap \
        php84-xmlreader \
        php84-ldap \
        php84-msgpack \
        php84-opcache \
        php84-pecl-igbinary php84-pecl-swoole \
        php84-pecl-pcov php84-pecl-imagick \
        php84-pcntl \
    && ln -s /usr/bin/php84 /usr/bin/php

COPY php.ini /etc/php/8.4/cli/conf.d/99-sail.ini

# Configure supervisord
RUN touch /var/run/supervisord.pid \
    && mkdir -p /etc/supervisor.d/conf.d \
    && mkdir -p /var/log/supervisor \
    && touch /var/log/supervisor/supervisord.log
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Configure container startup script
COPY start-container /usr/local/bin/start-container
RUN chmod +x /usr/local/bin/start-container

# Configure user for sail
RUN addgroup $WWWGROUP \
    && adduser -h /var/www/html -s /bin/bash -G $WWWGROUP -D sail

# Expose app port
EXPOSE $APP_PORT

ENTRYPOINT ["start-container"]