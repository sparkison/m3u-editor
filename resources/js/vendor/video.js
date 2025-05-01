import videojs from 'video.js';
// VHS (HTTP-streaming) is bundled by default in v7+; no extra import needed

// VJS styles
import 'video.js/dist/video-js.css';

// expose globally so Alpine/x-inline scripts can see it:
window.videojs = videojs;