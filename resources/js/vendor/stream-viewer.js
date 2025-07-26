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
            
            // Store reference to video element for cleanup
            this.player = video;
            
            // Reset error counters
            this.fragmentErrorCount = 0;
            
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
                console.log('Creating HLS player with configuration...');
                this.hls = new Hls({
                    enableWorker: true,
                    lowLatencyMode: true,
                    backBufferLength: 90,
                    maxBufferLength: 30,
                    maxMaxBufferLength: 600,
                    maxBufferSize: 60 * 1000 * 1000,
                    maxBufferHole: 0.5,
                    // Add debug logging
                    debug: false,
                    // Add retry and timeout configurations
                    manifestLoadingTimeOut: 10000,
                    manifestLoadingMaxRetry: 3,
                    manifestLoadingRetryDelay: 1000,
                    levelLoadingTimeOut: 10000,
                    levelLoadingMaxRetry: 4,
                    levelLoadingRetryDelay: 1000,
                    fragLoadingTimeOut: 20000,
                    fragLoadingMaxRetry: 6,
                    fragLoadingRetryDelay: 1000,
                    // Add CORS configuration
                    xhrSetup: function(xhr, url) {
                        console.log('HLS XHR setup for:', url);
                        // Add any necessary headers here
                        xhr.withCredentials = false;
                    }
                });
                
                this.hls.loadSource(url);
                this.hls.attachMedia(video);
                
                this.hls.on(Hls.Events.MANIFEST_PARSED, () => {
                    console.log('HLS manifest parsed successfully');
                    this.hideLoading(playerId);
                    this.updateStatus(playerId, 'Connected');
                });
                
                this.hls.on(Hls.Events.ERROR, (event, data) => {
                    console.error('HLS Error:', data);
                    
                    // Check for authentication/authorization errors (403, 401)
                    const isAuthError = data.response && (data.response.code === 403 || data.response.code === 401);
                    const isFragLoadError = data.details && data.details.includes('FRAG_LOAD_ERROR');
                    
                    // If we get auth errors on fragment loading, immediately fall back to native
                    if (isAuthError && isFragLoadError) {
                        console.log('HLS Authentication error on fragments, falling back to native player immediately');
                        this.cleanup();
                        this.initNativePlayer(video, url, playerId);
                        return;
                    }
                    
                    // Handle different types of errors
                    if (data.fatal) {
                        console.log('Fatal HLS error, attempting recovery or fallback');
                        switch(data.type) {
                            case Hls.ErrorTypes.NETWORK_ERROR:
                                console.log('HLS Network error, trying to recover...');
                                this.hls.startLoad();
                                break;
                            case Hls.ErrorTypes.MEDIA_ERROR:
                                console.log('HLS Media error, trying to recover...');
                                this.hls.recoverMediaError();
                                break;
                            default:
                                console.log('HLS Unrecoverable error, falling back to native player');
                                this.cleanup();
                                this.initNativePlayer(video, url, playerId);
                                break;
                        }
                    } else {
                        console.warn('Non-fatal HLS error:', data.details);
                        // For segment loading errors, let's show the specific error
                        if (data.details && data.details.includes('FRAG_LOAD_ERROR')) {
                            // If we've had multiple fragment errors, fall back
                            if (!this.fragmentErrorCount) this.fragmentErrorCount = 0;
                            this.fragmentErrorCount++;
                            
                            if (this.fragmentErrorCount >= 3) {
                                console.log('Multiple fragment errors, falling back to native player');
                                this.cleanup();
                                this.initNativePlayer(video, url, playerId);
                                return;
                            }
                            
                            this.showError(playerId, `Segment loading failed: ${data.response?.code || 'Network error'}`);
                        }
                    }
                });
                
                // Add more event listeners for debugging
                this.hls.on(Hls.Events.FRAG_LOAD_ERROR, (event, data) => {
                    console.error('HLS Fragment load error:', data);
                    console.error('Failed URL:', data.frag?.url);
                    console.error('Response:', data.response);
                });
                
                this.hls.on(Hls.Events.LEVEL_LOADED, (event, data) => {
                    console.log('HLS Level loaded:', data.level, 'URL:', data.details?.url);
                });
                
            } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                console.log('Using Safari native HLS support');
                video.src = url;
                this.setupNativeEvents(video, playerId);
            } else {
                throw new Error('HLS is not supported in this browser');
            }
        },
        
        initMpegTsPlayer(video, url, playerId) {
            console.log('MPEG-TS libraries available:', typeof mpegts !== 'undefined', mpegts?.getFeatureList().mseLivePlayback);
            
            if (typeof mpegts !== 'undefined' && mpegts.getFeatureList().mseLivePlayback) {
                console.log('Creating MPEG-TS player...');
                this.mpegts = mpegts.createPlayer({
                    type: 'mpegts',
                    url: url,
                    isLive: true
                });
                
                this.mpegts.attachMediaElement(video);
                this.mpegts.load();
                
                this.mpegts.on(mpegts.Events.METADATA_ARRIVED, () => {
                    console.log('MPEG-TS metadata arrived');
                    this.hideLoading(playerId);
                    this.updateStatus(playerId, 'Connected');
                });
                
                this.mpegts.on(mpegts.Events.ERROR, (type, details, info) => {
                    console.error('MPEGTS Error:', type, details, info);
                    this.showError(playerId, `MPEGTS Error: ${details || 'Unknown error'}`);
                });
                
                // Also set up native video events as backup
                this.setupNativeEvents(video, playerId);
                
            } else {
                console.log('MPEG-TS not supported, falling back to native');
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
                console.log('Video loadstart event');
                this.updateStatus(playerId, 'Loading...');
            });
            
            video.addEventListener('loadedmetadata', () => {
                console.log('Video loadedmetadata event');
                this.hideLoading(playerId);
                this.updateStatus(playerId, 'Metadata loaded');
            });
            
            video.addEventListener('canplay', () => {
                console.log('Video canplay event');
                this.hideLoading(playerId);
                this.updateStatus(playerId, 'Ready');
            });
            
            video.addEventListener('playing', () => {
                console.log('Video playing event');
                this.hideLoading(playerId);
                this.updateStatus(playerId, 'Playing');
            });
            
            video.addEventListener('error', (e) => {
                console.error('Video Error:', e);
                this.showError(playerId, 'Failed to load video stream');
            });
        },
        
        hideLoading(playerId) {
            console.log('hideLoading called for:', playerId);
            const loadingEl = document.getElementById(playerId + '-loading');
            console.log('Loading element found:', !!loadingEl);
            if (loadingEl) {
                loadingEl.style.display = 'none';
                console.log('Loading overlay hidden');
            }
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
            console.log('Cleaning up stream player...');
            
            if (this.hls) {
                console.log('Destroying HLS player');
                try {
                    this.hls.destroy();
                } catch (error) {
                    console.warn('Error destroying HLS player:', error);
                }
                this.hls = null;
            }
            
            if (this.mpegts) {
                console.log('Destroying MPEG-TS player');
                try {
                    this.mpegts.destroy();
                } catch (error) {
                    console.warn('Error destroying MPEG-TS player:', error);
                }
                this.mpegts = null;
            }
            
            // Also pause and clear any video element that might be playing
            if (this.player && this.player.tagName === 'VIDEO') {
                console.log('Stopping video playback');
                try {
                    this.player.pause();
                    this.player.src = '';
                    this.player.load(); // This will stop any ongoing loading/streaming
                } catch (error) {
                    console.warn('Error cleaning up video element:', error);
                }
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
