# m3u editor

![logo](./public/favicon.png)

A simple `m3u` playlist editor, similar to **xteve** or **threadfin**, with `epg` management.

Works with m3u, m3u8, m3u+ and Xtream codes api!

### Questions/issues/suggestions

Feel free to [open an issue](https://github.com/sparkison/m3u-editor/issues/new?template=bug_report.md) on this repo, you can also join our Discord server to ask questions and get help, help others, suggest new ideas, and offer suggestions for improvements! 🎉

[![](https://dcbadge.limes.pink/api/server/rS3abJ5dz7)](https://discord.gg/rS3abJ5dz7)

## Prerequisites

- [Docker](https://www.docker.com/) installed on your system.
- Xtream codes API login info or M3U URLs/files containing an M3U playlist of video streams.
- (Optionally) EPG URLs/files containing valid XMLTV data.

## 📖 Documentation

Check out the docs: [m3u editor docs](https://sparkison.github.io/m3u-editor-docs/)

## 🐳 Docker quick start

Use the following compose example to get up and running.

```yaml
services:
  m3u-editor:
    image: sparkison/m3u-editor:latest
    container_name: m3u-editor
    environment:
      - PUID=1000
      - PGID=1000
      - TZ=Etc/UTC
      # This is used for websockets and in-app notifications
      # Set to your machine/container IP where m3u editor will be accessed, if not localhost
      - REVERB_HOST=localhost
      # Uncomment and set to URL or IP if not localhost. Use full url, including http(s)
      #- APP_URL=http://localhost
      # Uncomment and set to true if using HTTPS
      #- OCTANE_HTTPS=false
    volumes:
      # This will allow you to reuse the data across container recreates
      # Format is: <host_directory_path>:<container_path>
      # More information: https://docs.docker.com/reference/compose-file/volumes/
      - ./data:/var/www/config
    restart: unless-stopped
    ports:
      - 36400:36400 # app
      - 36800:36800 # websockets/broadcasting
networks: {}
```

Or via Docker CLI:

```bash
 docker run --name m3u-editor -e PUID=1000 -e PGID=1000 -e TZ=Etc/UTC -e REVERB_HOST=localhost -v ./data:/var/www/config --restart unless-stopped -p 36400:36400 -p 36800:36800 sparkison/m3u-editor:latest 
```

Access via: [http://localhost:36400](http://localhost:36400) (user = admin, password = admin)

To ensure the data is saved across builds, link an empty volume to: `/var/www/config` within the container. This is where the `env` file will be stored, along with the sqlite database and the application log files.
