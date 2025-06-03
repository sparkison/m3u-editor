<div>
    To use hardware transcoding you need to map <code>/dev/dri</code> to the container.
    <br>
    <br>
    <strong>Docker Compose:</strong>
    <br>
    <code><pre>
devices:
  - /dev/dri:/dev/dri
    </pre></code>
    <strong>Docker Run:</strong>
    <br>
    <code>docker run --device /dev/dri:/dev/dri</code>
    <br>
    <br>
    <strong>Confirm Access:</strong>
    <br>
    <code>
        docker exec -it m3u-editor ls -l /dev/dri
    </pre>
    <br>
    <br>
    If you see a list of devices like <strong>card0</strong>, <strong>renderD128</strong>, etc., then hardware acceleration is set up correctly.
</div>
