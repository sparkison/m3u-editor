## m3u editor

### Docker compose example

```yaml
version: "3.8"
services:
  m3ueditor:
    build: https://github.com/sparkison/m3u-editor.git
    image: sail-8.4/app
    container_name: m3ueditor
    network_mode: host
    extra_hosts:
      - host.docker.internal:host-gateway
    environment:
      - TZ=Etc/UTC
      - WWWUSER=sail
      - LARAVEL_SAIL=1
      - XDEBUG_MODE=off
    volumes:
      - /apps/m3ueditor/config:/var/www/config
    ports:
      - 36400:36400
networks: {}

```
