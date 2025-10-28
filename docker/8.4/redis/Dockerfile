FROM redis:7-alpine

# Add envsubst (gettext) so we can template the redis config at container start
RUN apk add --no-cache gettext

COPY docker/8.4/redis/ /docker-entrypoint-redis/
COPY docker/8.4/redis/redis.conf /etc/redis/redis.tmpl
COPY docker/8.4/redis/docker-entrypoint.sh /usr/local/bin/docker-entrypoint-redis
RUN chmod +x /usr/local/bin/docker-entrypoint-redis

ENTRYPOINT ["/usr/local/bin/docker-entrypoint-redis"]
CMD ["/usr/local/bin/docker-entrypoint-redis"]
