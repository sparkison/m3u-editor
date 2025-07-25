// Global stream player functionality
function streamPlayer() {
    return {
        player: null,
        hls: null,
        mpegts: null,

        initPlayer(url, format, playerId) {
            console.log('initPlayer called with:', { url, format, playerId });

            const video = document.getElementById(playerId);
            const loadingEl = document.getElementById(playerId + '-loading');
            const errorEl = document.getElementById(playerId + '-error');
            const statusEl = document.getElementById(playerId + '-status');

            console.log('DOM elements found:', {
                video: !!video,
                loadingEl: !!loadingEl,
                errorEl: !!errorEl,
                statusEl: !!statusEl
            });

            if (!video) {
                console.error('Video element not found:', playerId);
                return;
            }

            if (!url) {
                console.error('No stream URL provided');
                this.showError(playerId, 'No stream URL provided');
                return;
            }

            console.log('Starting player initialization...');

            // Clean up any existing players
            this.cleanup();

            // Update status
            statusEl.textContent = 'Connecting...';
            loadingEl.style.display = 'flex';
            errorEl.style.display = 'none';

            try {
                if (format === 'hls' || url.includes('.m3u8')) {
                    console.log('Initializing HLS player');
                    this.initHlsPlayer(video, url, playerId);
                } else if (format === 'ts' || format === 'mpegts') {
                    console.log('Initializing MPEG-TS player');
                    this.initMpegTsPlayer(video, url, playerId);
                } else {
                    console.log('Initializing native player');
                    this.initNativePlayer(video, url, playerId);
                }
            } catch (error) {
                console.error('Error initializing player:', error);
                this.showError(playerId, error.message);
            }
        },

        initHlsPlayer(video, url, playerId) {
            if (typeof Hls !== 'undefined' && Hls.isSupported()) {
                this.hls = new Hls({
                    enableWorker: true,
                    lowLatencyMode: true,
                    backBufferLength: 90
                });

                this.hls.loadSource(url);
                this.hls.attachMedia(video);

                this.hls.on(Hls.Events.MANIFEST_PARSED, () => {
                    this.hideLoading(playerId);
                    this.updateStatus(playerId, 'Connected');
                });

                this.hls.on(Hls.Events.ERROR, (event, data) => {
                    console.error('HLS Error:', data);
                    this.showError(playerId, `HLS Error: ${data.details || 'Unknown error'}`);
                });

            } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                // Safari native HLS support
                video.src = url;
                this.setupNativeEvents(video, playerId);
            } else {
                throw new Error('HLS is not supported in this browser');
            }
        },

        initMpegTsPlayer(video, url, playerId) {
            if (typeof mpegts !== 'undefined' && mpegts.getFeatureList().mseLivePlayback) {
                this.mpegts = mpegts.createPlayer({
                    type: 'mpegts',
                    url: url,
                    isLive: true
                });

                this.mpegts.attachMediaElement(video);
                this.mpegts.load();

                this.mpegts.on(mpegts.Events.METADATA_ARRIVED, () => {
                    this.hideLoading(playerId);
                    this.updateStatus(playerId, 'Connected');
                });

                this.mpegts.on(mpegts.Events.ERROR, (type, details, info) => {
                    console.error('MPEGTS Error:', type, details, info);
                    this.showError(playerId, `MPEGTS Error: ${details || 'Unknown error'}`);
                });

            } else {
                // Fallback to native
                this.initNativePlayer(video, url, playerId);
            }
        },

        initNativePlayer(video, url, playerId) {
            video.src = url;
            this.setupNativeEvents(video, playerId);
        },

        setupNativeEvents(video, playerId) {
            video.addEventListener('loadstart', () => {
                this.updateStatus(playerId, 'Loading...');
            });

            video.addEventListener('canplay', () => {
                this.hideLoading(playerId);
                this.updateStatus(playerId, 'Ready');
            });

            video.addEventListener('playing', () => {
                this.updateStatus(playerId, 'Playing');
            });

            video.addEventListener('error', (e) => {
                console.error('Video Error:', e);
                this.showError(playerId, 'Failed to load video stream');
            });
        },

        hideLoading(playerId) {
            const loadingEl = document.getElementById(playerId + '-loading');
            if (loadingEl) loadingEl.style.display = 'none';
        },

        showError(playerId, message) {
            const loadingEl = document.getElementById(playerId + '-loading');
            const errorEl = document.getElementById(playerId + '-error');
            const errorMessageEl = document.getElementById(playerId + '-error-message');

            if (loadingEl) loadingEl.style.display = 'none';
            if (errorEl) errorEl.style.display = 'flex';
            if (errorMessageEl) errorMessageEl.textContent = message;

            this.updateStatus(playerId, 'Error');
        },

        updateStatus(playerId, status) {
            const statusEl = document.getElementById(playerId + '-status');
            if (statusEl) statusEl.textContent = status;
        },

        cleanup() {
            if (this.hls) {
                this.hls.destroy();
                this.hls = null;
            }
            if (this.mpegts) {
                this.mpegts.destroy();
                this.mpegts = null;
            }
        }
    };
}

// Global retry function
function retryStream(playerId) {
    const component = document.querySelector(`#${playerId}`).closest('[wire\\:id]');
    if (component) {
        // Re-trigger the player initialization
        const alpineData = Alpine.$data(document.getElementById(playerId));
        if (alpineData && typeof alpineData.initPlayer === 'function') {
            const url = document.getElementById(playerId).getAttribute('data-url');
            const format = document.getElementById(playerId).getAttribute('data-format');
            alpineData.initPlayer(url, format, playerId);
        }
    }
}

// Make streamPlayer function globally accessible
window.streamPlayer = streamPlayer;

// Make retryStream function globally accessible
window.retryStream = retryStream;
