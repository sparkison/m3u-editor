## m3u editor

Description and screenshots to come...

### 🐳 Docker compose example

Use the following compose example to get up and running.

```yaml
version: "3.8"
services:
  m3ueditor:
    build: https://github.com/sparkison/m3u-editor.git
    image: sail-8.4/app
    container_name: m3u-editor
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
    restart: unless-stopped
    ports:
      - 36400:36400 # app
      # - 36790:36790 # for queue (redis server) - not currently used
      - 36800:36800 # websockets/broadcasting
networks: {}

```

To ensure the data is saved across builds, link an empty volume to: `/var/www/config` within the container. This is where the `env` file will be stored, along with the sqlite database and the application log files.

**NOTE**: Once built, head to the `env` file with your linked config directory and make sure the `REVERB_HOST` is properly set to the machine IP (if not `localhost`) for websockets and broadcasting to work correctly. This is not required for the app to function, but you will not receive in-app notifications without it. You will need to restart the container after changing this.

### 📡 Creating a playlist proxy

Using the [M3U Stream Merger Proxy](https://github.com/sonroyaalmerol/m3u-stream-merger-proxy) as an example.

```yaml
version: "3.8"
services:
  m3u-stream-merger-proxy:
    image: sonroyaalmerol/m3u-stream-merger-proxy:latest
    container_name: m3u-proxy
    network_mode: host
    environment:
      - PUID=816
      - PGID=816
      - TZ=Etc/UTC
      - PORT=7001
      - DEBUG=true
      - SYNC_ON_BOOT=true
      - SYNC_CRON=0 0 * * *
      - M3U_URL_1=http://192.168.0.1:36400/[PLAYLIST_UID]/playlist.m3u
      - M3U_MAX_CONCURRENCY_1=5
      #- M3U_URL_2=https://iptvprovider2.com/playlist.m3u
      #- M3U_MAX_CONCURRENCY_2=1
      #- M3U_URL_X=
    volumes:
      # [OPTIONAL] Cache persistence: This will allow you to reuse the M3U cache across container recreates.
      - /apps/m3u-proxy:/m3u-proxy/data
    restart: unless-stopped
    ports:
      - 7001:7001
networks: {}

```

Your proxied m3u playlist can then be access via: [http://192.168.0.1:7001/playlist.m3u](http://192.168.0.1:7001/playlist.m3u)

More setup information can be found on the [M3U Stream Merger Proxy](https://github.com/sonroyaalmerol/m3u-stream-merger-proxy) page.
