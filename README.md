# m3u editor

![logo](./public/favicon.png)

A simple `m3u` playlist editor, similar to **xteve** or **threadfin**, with `epg` management.

> [!TIP]  
> Has been tested on large playlist ([https://github.com/iptv-org/iptv](https://github.com/iptv-org/iptv)), with up to 10000+ channels. Note that larger lists will take longer to sync (it takes aproximately 1s for every 1000 channels.). If you are experiencing issues with your playlist, try the m3u playlist url provided in the link above to confirm it works to help rule out network or connectivity issues.

### Questions/issues/suggestions

Feel free to [open an issue](https://github.com/sparkison/m3u-editor/issues/new?template=bug_report.md) on this repo, you can also join our Discord server to ask questions and get help, help others, suggest new ideas, and offer suggestions for improvements! 🎉

[![](https://dcbadge.limes.pink/api/server/szPUzZT6)](https://discord.gg/rS3abJ5dz7)

## How It Works

1. Initialization and M3U Playlist(s) creation:
    - The service loads M3U playlist from specified URL, downloading and processing it.
    - Each playlist will have a unique URL to output enabled channels and any customizations
    - Only enabled channels will be returned.
2. Initialization and XML EPG(s) creation:
    - The service loads EPG from specified URL, or from the uploaded file, downloading and processing it. File should conform to the [XMLTV standard](https://github.com/XMLTV/xmltv/blob/master/xmltv.dtd).
    - Each playlist will have a unique URL to output enabled channels EPG data
    - Only enabled channels, that have been mapped to an EPG, will be returned.
3. Automatic playlist(s) and epg(s) syncing:
    - Playlist and EPG syncs happens upon creation, and every 24hr after. The schedule can be adjusted after playlist is created. Auto sync can also be disabled to prevent this (an initial sync will still happen upon creation).
4. (Optionally) custom and merged playlists can be created:
    - Merged playlists will allow you to create a new playlist from existing playslist, allowing you to combine multiple playlists into one.
    - Custom playlists allow you to select specific channels from existing playslist to compose a new playlist.
5. HTTP endpoints:
    - The app can be accessed here: [http://localhost:36400](http://localhost:36400)
      - **LOGIN INFO**: user = admin, password = admin (this can be changed at any time in user profile)
    - Each playlist will have a unique URL, in the format: `http://localhost:36400/9dfbc010-a809-4a31-801d-ca2a34030966/playlist.m3u`
      - **NOTE**: Only enabled channels be included.
    - Each playlist will also have a unique EPG URL, in a similar format: `http://localhost:36400/9dfbc010-a809-4a31-801d-ca2a34030966/epg.xml`
      - **NOTE**: Only enabled channels that have been mapped to an EPG will be included.
6. Customization:
    - Enable/disable auto syncing of Playlists and EPGs.
    - Modify M3U channel numbers, logos, and offset. Channels are opt-in, so **all channels will be disabled by default** and need to be enabled based on your preference. This is to prevent channel additions automatically populating your playlist. The behavior can be changed to enalbe channels by default when creating/editing a playlist.

## Prerequisites

- [Docker](https://www.docker.com/) installed on your system.
- M3U URLs/files containing an M3U playlist of video streams.
- (Optionally) EPG URLs/files containing valid XMLTV data.

## Screenshots

[View screenshots](./screenshots/)

## 🐳 Docker compose

Use the following compose example to get up and running.

```yaml
version: "3.8"
services:
  m3u-editor:
    image: sparkison/m3u-editor:latest
    container_name: m3u-editor
    environment:
      - PUID=1000
      - PGID=1000
      - TZ=Etc/UTC
    volumes:
      # This will allow you to reuse the data across container recreates.
      # Format is: <host_directory_path>:<container_path>
      # More information: https://docs.docker.com/reference/compose-file/volumes/
      - ./data:/var/www/config
    restart: unless-stopped
    ports:
      - 36400:36400 # app
      - 36800:36800 # websockets/broadcasting
networks: {}

```

Access via: [http://localhost:36400](http://localhost:36400) (user = admin, password = admin)

To ensure the data is saved across builds, link an empty volume to: `/var/www/config` within the container. This is where the `env` file will be stored, along with the sqlite database and the application log files.

> [!NOTE]  
> Once built, head to the `env` file with your linked config directory and make sure the `REVERB_HOST` is properly set to the machine IP (if not `localhost`) for websockets and broadcasting to work correctly. This is not required for the app to function, but you will not receive in-app notifications without it. You will need to restart the container after changing this.

### 📡 (Optionally) creating a playlist proxy

Using the [MediaFlow Proxy](https://github.com/mhdzumair/mediaflow-proxy) as an example.

```yaml
version: "3.3"
services:
  mediaflow-proxy:
    image: mhdzumair/mediaflow-proxy
    environment:
      - API_PASSWORD=YOUR_PROXY_API_PASSWORD
    ports:
      - 8888:8888
networks: {}

```

Your proxied m3u playlist can then be access via: [http://localhost:8888/proxy/hls/manifest.m3u8?d=http://localhost:36400/YOUR_M3U_EDITOR_PLAYLIST_UID/playlist.m3u&api_password=YOUR_PROXY_API_PASSWORD](http://localhost:8888/proxy/hls/manifest.m3u8?d=http://localhost:36400/YOUR_M3U_EDITOR_PLAYLIST_UID/playlist.m3u&api_password=YOUR_PROXY_API_PASSWORD)

More setup information can be found on the [MediaFlow Proxy](https://github.com/mhdzumair/mediaflow-proxy) page.

## Tips and tricks

- 🔒 Use the app over HTTPS:
  - You will need to update the `env` file within the config directory and update: `OCTANE_HTTPS=true` and then restart your container. You can also issue the following cli command within the container to restart the app: `php artisan octane:reload`. This will reload the app with HTTPS support and remove any mixed contents warnings/errors.
- 🌄 Using local images for playlist or EPG icons:
  - Map a local directory to a directory in the public direcory of m3u editor, e.g.:
  ```yaml
  volumes:
      - ...
      - ./images/logos:/var/www/html/public/logos
  ```
  - Your logos can then be accesses via [http://localhost:36400/logos/filename.jpg](http://localhost:36400/logos/filename.jpg) and updated in your channel or EPG channel within m3u editor.

## Known issues

- 💻 Apple Silicon (M-series) and other non-x86_64 platforms will need to add the `platform` (`platform: linux/x86_64`) parameter to the compose file to support x86_64 architecture - for example:

```yaml
services:
  m3u-editor:
    platform: linux/x86_64
    image: sparkison/m3u-editor:latest
    ...
```
