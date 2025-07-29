// Global stream player functionality
function streamPlayer() {
    return {
        player: null,
        hls: null,
        mpegts: null,
        videojsPlayer: null,
        streamMetadata: {
            codec: null,
            resolution: null,
            audioCodec: null,
            audioChannels: null,
            bitrate: null,
            framerate: null,
            profile: null,
            level: null
        },
        availableAudioTracks: [],
        selectedAudioTrack: null,
        useVideoJs: false, // Flag to determine if we should use Video.js
        
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
            
            // Store reference to this stream player instance on the video element
            video._streamPlayer = this;
            
            // Reset error counters
            this.fragmentErrorCount = 0;
            
            // Clean up any existing players
            this.cleanup();
            
            // Update status
            !!statusEl && (statusEl.textContent = 'Connecting...');
            !!loadingEl && (loadingEl.style.display = 'flex');
            !!errorEl && (errorEl.style.display = 'none');
            
            // Check if Video.js is available and if we should use it
            // Only use Video.js for specific scenarios where it adds value
            this.useVideoJs = typeof videojs !== 'undefined' && (
                // Use Video.js for HLS streams (better audio track support than hls.js)
                (format === 'hls' || url.includes('.m3u8')) ||
                // Use Video.js for MP4/MKV files with multiple audio tracks
                (url.toLowerCase().includes('.mkv') || url.toLowerCase().includes('.mp4'))
            );
            
            // Do NOT use Video.js for TS streams - mpegts.js handles these better
            if (format === 'ts' || format === 'mpegts' || url.toLowerCase().includes('.ts')) {
                this.useVideoJs = false;
                console.log('TS stream detected - using mpegts.js instead of Video.js');
            }
            
            try {
                if (this.useVideoJs) {
                    console.log('Using Video.js for enhanced audio track support');
                    this.initVideoJsPlayer(video, url, playerId);
                } else if (format === 'hls' || url.includes('.m3u8')) {
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
        
        initVideoJsPlayer(video, url, playerId) {
            console.log('Initializing Video.js player for:', playerId);
            
            // Configure Video.js options
            const options = {
                controls: true,
                responsive: true,
                fluid: false,
                preload: 'metadata',
                html5: {
                    vhs: {
                        // Enable VHS for HLS support
                        overrideNative: true
                    }
                },
                // Enable audio track selection
                audioTracks: true,
                plugins: {}
            };
            
            // Add video-js class to the video element if not present
            if (!video.classList.contains('video-js')) {
                video.classList.add('video-js', 'vjs-default-skin');
            }
            
            // Initialize Video.js player
            this.videojsPlayer = videojs(video, options);
            
            // Set up event listeners
            this.videojsPlayer.ready(() => {
                console.log('Video.js player is ready');
                
                // Set the source
                this.videojsPlayer.src({ src: url, type: this.getVideoJsMimeType(url) });
                
                this.hideLoading(playerId);
                this.updateStatus(playerId, 'Ready');
            });
            
            // Listen for loadedmetadata to get stream info
            this.videojsPlayer.on('loadedmetadata', () => {
                console.log('Video.js loadedmetadata event');
                this.collectVideoJsMetadata(playerId);
                this.updateStreamDetails(playerId);
            });
            
            // Listen for audio track changes
            this.videojsPlayer.on('audiotrackchange', () => {
                console.log('Audio track changed in Video.js');
                this.handleVideoJsAudioTrackChange(playerId);
            });
            
            // Listen for playing event
            this.videojsPlayer.on('playing', () => {
                console.log('Video.js playing event');
                this.updateStatus(playerId, 'Playing');
                // Collect metadata after playing starts
                setTimeout(() => {
                    this.collectVideoJsMetadata(playerId);
                    this.updateStreamDetails(playerId);
                }, 1000);
            });
            
            // Listen for errors
            this.videojsPlayer.on('error', (error) => {
                console.error('Video.js error:', error);
                const errorObj = this.videojsPlayer.error();
                let errorMessage = 'Playback failed';
                
                if (errorObj) {
                    switch(errorObj.code) {
                        case 1:
                            errorMessage = 'Video loading aborted';
                            break;
                        case 2:
                            errorMessage = 'Network error';
                            break;
                        case 3:
                            errorMessage = 'Video decode error';
                            break;
                        case 4:
                            errorMessage = 'Video format not supported';
                            break;
                        default:
                            errorMessage = errorObj.message || 'Unknown error';
                    }
                }
                
                // For certain errors, fall back to native player
                if (errorObj && (errorObj.code === 4 || errorObj.code === 3)) {
                    console.log('Video.js failed, falling back to native player');
                    this.cleanup();
                    this.initNativePlayer(video, url, playerId);
                } else {
                    this.showError(playerId, errorMessage);
                }
            });
        },
        
        getVideoJsMimeType(url) {
            const urlLower = url.toLowerCase();
            if (urlLower.includes('.m3u8')) return 'application/x-mpegURL';
            if (urlLower.includes('.mp4')) return 'video/mp4';
            if (urlLower.includes('.mkv')) return 'video/x-matroska';
            if (urlLower.includes('.webm')) return 'video/webm';
            if (urlLower.includes('.ts')) return 'video/mp2t';
            return 'video/mp4'; // default
        },
        
        collectVideoJsMetadata(playerId) {
            if (!this.videojsPlayer) return;
            
            console.log('Collecting Video.js metadata');
            
            // Get video dimensions
            const videoWidth = this.videojsPlayer.videoWidth();
            const videoHeight = this.videojsPlayer.videoHeight();
            
            if (videoWidth && videoHeight) {
                this.streamMetadata.resolution = `${videoWidth}x${videoHeight}`;
            }
            
            // Get audio tracks from Video.js
            const audioTracks = this.videojsPlayer.audioTracks();
            console.log('Video.js audio tracks:', audioTracks);
            
            if (audioTracks && audioTracks.length > 0) {
                this.availableAudioTracks = [];
                
                for (let i = 0; i < audioTracks.length; i++) {
                    const track = audioTracks[i];
                    console.log(`Video.js audio track ${i}:`, {
                        id: track.id,
                        label: track.label,
                        language: track.language,
                        enabled: track.enabled
                    });
                    
                    this.availableAudioTracks.push({
                        index: i,
                        id: track.id,
                        label: track.label || `Track ${i + 1}`,
                        language: track.language || 'unknown',
                        enabled: track.enabled,
                        estimated: false
                    });
                    
                    if (track.enabled) {
                        this.selectedAudioTrack = i;
                        
                        // Try to extract codec info from label
                        if (track.label) {
                            const codecMatch = track.label.match(/(aac|mp3|ac3|dts|pcm|opus|vorbis|flac)/i);
                            if (codecMatch) {
                                this.streamMetadata.audioCodec = codecMatch[1].toUpperCase();
                            }
                        }
                    }
                }
                
                console.log('Video.js available audio tracks:', this.availableAudioTracks);
                
                // Set default audio codec if we have tracks but no codec detected
                if (!this.streamMetadata.audioCodec && this.availableAudioTracks.length > 0) {
                    this.streamMetadata.audioCodec = 'AAC'; // Common default
                }
                
                // Set default channels
                if (!this.streamMetadata.audioChannels) {
                    this.streamMetadata.audioChannels = '2.0'; // Stereo default
                }
            } else {
                console.log('No audio tracks found in Video.js');
                // Try to detect from tech
                this.detectVideoJsCodecInfo(playerId);
            }
        },
        
        detectVideoJsCodecInfo(playerId) {
            if (!this.videojsPlayer) return;
            
            // Try to get codec info from the tech
            const tech = this.videojsPlayer.tech();
            
            if (tech && tech.el_) {
                const videoEl = tech.el_;
                
                // Check for native audioTracks
                if (videoEl.audioTracks && videoEl.audioTracks.length > 0) {
                    console.log('Found native audio tracks via Video.js tech:', videoEl.audioTracks.length);
                    // Use the same logic as native player for these
                    this.collectVideoMetadata(videoEl, playerId);
                    return;
                }
            }
            
            // Fallback: assume common defaults based on URL
            const url = this.videojsPlayer.currentSrc() || '';
            if (url.toLowerCase().includes('.mkv')) {
                this.streamMetadata.audioCodec = 'AAC';
                this.streamMetadata.audioChannels = '2.0';
            } else if (url.toLowerCase().includes('.mp4')) {
                this.streamMetadata.audioCodec = 'AAC';
                this.streamMetadata.audioChannels = '2.0';
            }
        },
        
        handleVideoJsAudioTrackChange(playerId) {
            if (!this.videojsPlayer) return;
            
            console.log('Handling Video.js audio track change');
            const audioTracks = this.videojsPlayer.audioTracks();
            
            if (audioTracks) {
                for (let i = 0; i < audioTracks.length; i++) {
                    const track = audioTracks[i];
                    if (track.enabled) {
                        this.selectedAudioTrack = i;
                        console.log(`Audio track ${i} is now enabled`);
                        
                        // Update our tracking
                        if (this.availableAudioTracks[i]) {
                            this.availableAudioTracks.forEach((t, index) => {
                                t.enabled = index === i;
                            });
                        }
                        break;
                    }
                }
            }
            
            this.updateStreamDetails(playerId);
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
                    
                    // Collect HLS metadata
                    if (this.hls.levels && this.hls.levels.length > 0) {
                        const level = this.hls.levels[this.hls.currentLevel] || this.hls.levels[0];
                        if (level) {
                            this.streamMetadata.resolution = `${level.width}x${level.height}`;
                            this.streamMetadata.bitrate = level.bitrate;
                            this.streamMetadata.framerate = level.frameRate;
                            
                            // Parse codec info
                            if (level.codecName) {
                                this.streamMetadata.codec = level.codecName;
                            } else if (level.videoCodec) {
                                this.streamMetadata.codec = level.videoCodec.split('.')[0];
                            }
                            
                            if (level.audioCodec) {
                                this.streamMetadata.audioCodec = level.audioCodec.split('.')[0];
                            }
                        }
                    }
                    
                    this.hideLoading(playerId);
                    this.updateStatus(playerId, 'Connected');
                    this.updateStreamDetails(playerId);
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

                this.hls.on(Hls.Events.LEVEL_SWITCHED, (event, data) => {
                    console.log('HLS Level switched to:', data.level);
                    
                    // Update metadata when level changes
                    if (this.hls.levels && this.hls.levels[data.level]) {
                        const level = this.hls.levels[data.level];
                        this.streamMetadata.resolution = `${level.width}x${level.height}`;
                        this.streamMetadata.bitrate = level.bitrate;
                        this.streamMetadata.framerate = level.frameRate;
                        this.updateStreamDetails(playerId);
                    }
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
            
            // Set some defaults for MPEG-TS streams
            this.streamMetadata.codec = 'H.264';
            this.streamMetadata.audioCodec = 'AAC';
            this.streamMetadata.audioChannels = '2.0';
            this.updateStreamDetails(playerId);
            
            if (typeof mpegts !== 'undefined' && mpegts.getFeatureList().mseLivePlayback) {
                console.log('Creating MPEG-TS player...');
                this.mpegts = mpegts.createPlayer({
                    type: 'mpegts',
                    url: url,
                    isLive: true
                });
                
                this.mpegts.attachMediaElement(video);
                this.mpegts.load();
                
                this.mpegts.on(mpegts.Events.METADATA_ARRIVED, (metadata) => {
                    // Collect MPEG-TS metadata - override defaults with actual values
                    if (metadata.videoCodec) {
                        this.streamMetadata.codec = metadata.videoCodec;
                    }
                    if (metadata.audioCodec) {
                        this.streamMetadata.audioCodec = metadata.audioCodec;
                    }
                    if (metadata.width && metadata.height) {
                        this.streamMetadata.resolution = `${metadata.width}x${metadata.height}`;
                    }
                    if (metadata.videoBitrate) {
                        this.streamMetadata.bitrate = metadata.videoBitrate;
                    }
                    if (metadata.frameRate) {
                        this.streamMetadata.framerate = metadata.frameRate;
                    }
                    if (metadata.audioChannels) {
                        this.streamMetadata.audioChannels = metadata.audioChannels;
                    }
                    
                    this.hideLoading(playerId);
                    this.updateStatus(playerId, 'Connected');
                    this.updateStreamDetails(playerId);
                });

                this.mpegts.on(mpegts.Events.MEDIA_INFO, (mediaInfo) => {
                    // Additional metadata from media info
                    if (mediaInfo.width && mediaInfo.height) {
                        this.streamMetadata.resolution = `${mediaInfo.width}x${mediaInfo.height}`;
                    }
                    if (mediaInfo.videoCodec) {
                        this.streamMetadata.codec = mediaInfo.videoCodec;
                    }
                    if (mediaInfo.audioCodec) {
                        this.streamMetadata.audioCodec = mediaInfo.audioCodec;
                    }
                    if (mediaInfo.audioChannelCount) {
                        this.streamMetadata.audioChannels = mediaInfo.audioChannelCount === 2 ? '2.0' : mediaInfo.audioChannelCount.toString();
                    }
                    if (mediaInfo.fps) {
                        this.streamMetadata.framerate = mediaInfo.fps;
                    }
                    
                    this.updateStreamDetails(playerId);
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
                this.updateStatus(playerId, 'Loading...');
            });
            
            video.addEventListener('loadedmetadata', () => {
                // Collect basic metadata
                if (video.videoWidth && video.videoHeight) {
                    this.streamMetadata.resolution = `${video.videoWidth}x${video.videoHeight}`;
                }
                
                this.collectVideoMetadata(video, playerId);
                this.hideLoading(playerId);
                this.updateStatus(playerId, 'Ready');
                this.updateStreamDetails(playerId);
            });
            
            video.addEventListener('loadeddata', () => {
                this.collectVideoMetadata(video, playerId);
            });
            
            video.addEventListener('canplay', () => {
                this.updateStatus(playerId, 'Ready');
                this.collectVideoMetadata(video, playerId);
            });
            
            video.addEventListener('playing', () => {
                this.updateStatus(playerId, 'Playing');
                
                // Try to collect additional metadata once playing
                setTimeout(() => {
                    this.collectVideoMetadata(video, playerId);
                }, 1000); // Give it a second to establish the stream
            });

            video.addEventListener('progress', () => {
                // Try to collect metadata as data loads
                if (video.buffered.length > 0 && !this.streamMetadata.codec) {
                    this.collectVideoMetadata(video, playerId);
                }
            });
            
            video.addEventListener('error', (e) => {
                console.error('Native video error:', e, video.error);
                let errorMessage = 'Playback failed';
                if (video.error) {
                    switch(video.error.code) {
                        case video.error.MEDIA_ERR_ABORTED:
                            errorMessage = 'Playback aborted';
                            break;
                        case video.error.MEDIA_ERR_NETWORK:
                            errorMessage = 'Network error';
                            break;
                        case video.error.MEDIA_ERR_DECODE:
                            errorMessage = 'Decode error';
                            break;
                        case video.error.MEDIA_ERR_SRC_NOT_SUPPORTED:
                            errorMessage = 'Format not supported';
                            break;
                    }
                }
                this.showError(playerId, errorMessage);
            });
        },
        
        hideLoading(playerId) {
            const loadingEl = document.getElementById(playerId + '-loading');
            if (loadingEl) {
                loadingEl.style.display = 'none';
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

        updateStreamDetails(playerId) {
            const detailsEl = document.getElementById(playerId + '-details');
            if (!detailsEl) return;

            let detailsHtml = '';
            
            if (this.streamMetadata.resolution) {
                detailsHtml += `<div class="flex justify-between gap-1"><span>Resolution:</span><span class="font-mono">${this.streamMetadata.resolution}</span></div>`;
            }
            
            if (this.streamMetadata.codec) {
                detailsHtml += `<div class="flex justify-between gap-1"><span>Video Codec:</span><span class="font-mono">${this.streamMetadata.codec}</span></div>`;
            }
            
            if (this.streamMetadata.audioCodec) {
                detailsHtml += `<div class="flex justify-between gap-1"><span>Audio Codec:</span><span class="font-mono">${this.streamMetadata.audioCodec}</span></div>`;
            }
            
            if (this.streamMetadata.audioChannels) {
                detailsHtml += `<div class="flex justify-between gap-1"><span>Audio Channels:</span><span class="font-mono">${this.streamMetadata.audioChannels}</span></div>`;
            }
            
            // Add audio track selector if multiple tracks are available
            if (this.availableAudioTracks && this.availableAudioTracks.length > 1) {
                detailsHtml += `<div class="border-t pt-2 mt-2">`;
                detailsHtml += `<div class="flex justify-between items-center gap-1 mb-1">`;
                detailsHtml += `<span class="text-sm">Audio Track:</span>`;
                detailsHtml += `<select onchange="selectAudioTrack('${playerId}', this.value)" class="text-xs bg-gray-700 border border-gray-600 rounded px-1 py-0.5 text-white">`;
                
                this.availableAudioTracks.forEach((track, index) => {
                    const selected = index === this.selectedAudioTrack ? 'selected' : '';
                    const estimatedTag = track.estimated ? ' (estimated)' : '';
                    const label = track.language !== 'unknown' ? `${track.label} (${track.language})` : track.label;
                    detailsHtml += `<option value="${index}" ${selected}>${label}${estimatedTag}</option>`;
                });
                
                detailsHtml += `</select></div>`;
                
                // Add note for estimated tracks
                if (this.availableAudioTracks.some(track => track.estimated)) {
                    detailsHtml += `<div class="text-xs text-yellow-400">Note: Audio track switching may not work due to browser limitations</div>`;
                }
                
                detailsHtml += `</div>`;
            }
            
            if (this.streamMetadata.bitrate) {
                const bitrateKbps = Math.round(this.streamMetadata.bitrate / 1000);
                detailsHtml += `<div class="flex justify-between gap-1"><span>Bitrate:</span><span class="font-mono">${bitrateKbps} kbps</span></div>`;
            }
            
            if (this.streamMetadata.framerate) {
                detailsHtml += `<div class="flex justify-between gap-1"><span>Frame Rate:</span><span class="font-mono">${this.streamMetadata.framerate} fps</span></div>`;
            }
            
            if (this.streamMetadata.profile) {
                detailsHtml += `<div class="flex justify-between gap-1"><span>Profile:</span><span class="font-mono">${this.streamMetadata.profile}</span></div>`;
            }
            
            if (detailsHtml) {
                detailsEl.innerHTML = detailsHtml;
                detailsEl.style.display = 'block';
            } else {
                detailsEl.innerHTML = '<div class="text-gray-500 dark:text-gray-400 text-sm">Stream details not available</div>';
                detailsEl.style.display = 'block';
            }
        },

        collectVideoMetadata(video, playerId) {
            // Get basic video properties
            if (video.videoWidth && video.videoHeight) {
                this.streamMetadata.resolution = `${video.videoWidth}x${video.videoHeight}`;
            }

            // Try to estimate framerate from video properties
            if (video.getVideoPlaybackQuality) {
                try {
                    const quality = video.getVideoPlaybackQuality();
                    if (quality.totalVideoFrames && quality.creationTime) {
                        const fps = Math.round(quality.totalVideoFrames / (quality.creationTime / 1000));
                        if (fps > 0 && fps < 120) { // Reasonable FPS range
                            this.streamMetadata.framerate = fps;
                        }
                    }
                } catch (e) {
                    // getVideoPlaybackQuality not available
                }
            }

            // Try to get video tracks info
            if (video.videoTracks && video.videoTracks.length > 0) {
                const track = video.videoTracks[0];
                if (track.label) {
                    // Parse codec info from track label if available
                    const codecMatch = track.label.match(/(\w+)/);
                    if (codecMatch) {
                        this.streamMetadata.codec = codecMatch[1];
                    }
                }
            }

            // Try to get audio tracks info
            if (video.audioTracks && video.audioTracks.length > 0) {
                const audioTrack = video.audioTracks[0];
                if (audioTrack.language) {
                    this.streamMetadata.audioCodec = audioTrack.language;
                }
                if (audioTrack.label) {
                    // Try to parse codec from label
                    const audioCodecMatch = audioTrack.label.match(/(aac|mp3|ac3|dts|pcm)/i);
                    if (audioCodecMatch) {
                        this.streamMetadata.audioCodec = audioCodecMatch[1].toUpperCase();
                    }
                }
            }

            // For TS streams, try to infer codec from URL or file extension
            const videoSrc = video.src || video.currentSrc;
            if (videoSrc && !this.streamMetadata.codec) {
                if (videoSrc.includes('.ts') || videoSrc.includes('mpegts')) {
                    this.streamMetadata.codec = 'H.264'; // Most TS streams use H.264
                    if (!this.streamMetadata.audioCodec) {
                        this.streamMetadata.audioCodec = 'AAC'; // Most TS streams use AAC audio
                    }
                }
            }

            // Try to detect audio channels from the audio context if available
            if (!this.streamMetadata.audioChannels && video.audioTracks && video.audioTracks.length > 0) {
                // Default to stereo for most streams
                this.streamMetadata.audioChannels = '2.0';
            }

            this.updateStreamDetails(playerId);
        },
        
        selectAudioTrack(playerId, trackIndex) {
            console.log('Selecting audio track:', trackIndex);
            
            if (this.videojsPlayer) {
                // Video.js audio track selection
                const audioTracks = this.videojsPlayer.audioTracks();
                if (audioTracks && audioTracks.length > trackIndex) {
                    // Disable all tracks first
                    for (let i = 0; i < audioTracks.length; i++) {
                        audioTracks[i].enabled = false;
                    }
                    
                    // Enable selected track
                    audioTracks[trackIndex].enabled = true;
                    this.selectedAudioTrack = trackIndex;
                    
                    console.log(`Enabled Video.js audio track ${trackIndex}`);
                    this.updateStreamDetails(playerId);
                    return;
                }
            }
            
            // Fallback to native video element if Video.js not available
            const video = document.getElementById(playerId);
            if (!video) return;
            
            // If we have real audio tracks, use them
            if (video.audioTracks && video.audioTracks.length > trackIndex) {
                // Disable all tracks first
                for (let i = 0; i < video.audioTracks.length; i++) {
                    video.audioTracks[i].enabled = false;
                }
                
                // Enable selected track
                video.audioTracks[trackIndex].enabled = true;
                this.selectedAudioTrack = trackIndex;
                
                console.log(`Enabled native audio track ${trackIndex}`);
            } else {
                // For estimated tracks, we can't actually change the audio
                // but we can update the UI to show the selection
                this.selectedAudioTrack = trackIndex;
                
                // Update the available tracks to reflect the selection
                if (this.availableAudioTracks) {
                    this.availableAudioTracks.forEach((track, index) => {
                        track.enabled = index === trackIndex;
                    });
                }
                
                console.log(`Selected estimated audio track ${trackIndex} (browser limitation - no actual audio change)`);
            }
            
            this.updateStreamDetails(playerId);
        },
        
        cleanup() {
            console.log('Cleaning up stream player...');
            
            // Reset stream metadata
            this.streamMetadata = {
                codec: null,
                resolution: null,
                audioCodec: null,
                audioChannels: null,
                bitrate: null,
                framerate: null,
                profile: null,
                level: null
            };
            
            // Reset audio track data
            this.availableAudioTracks = [];
            this.selectedAudioTrack = null;
            
            if (this.videojsPlayer) {
                console.log('Destroying Video.js player');
                try {
                    this.videojsPlayer.dispose();
                } catch (error) {
                    console.warn('Error destroying Video.js player:', error);
                }
                this.videojsPlayer = null;
            }
            
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

// Toggle stream details overlay
function toggleStreamDetails(playerId) {
    const overlay = document.getElementById(playerId + '-details-overlay');
    if (overlay) {
        overlay.classList.toggle('hidden');
    }
}

// Global audio track selector function
function selectAudioTrack(playerId, trackIndex) {
    // Find the stream player instance and call its selectAudioTrack method
    const videoElement = document.getElementById(playerId);
    if (videoElement && videoElement._streamPlayer) {
        videoElement._streamPlayer.selectAudioTrack(playerId, parseInt(trackIndex));
    } else {
        // Fallback: try to find the instance through Alpine.js
        const alpineData = Alpine.$data(document.getElementById(playerId));
        if (alpineData && typeof alpineData.selectAudioTrack === 'function') {
            alpineData.selectAudioTrack(playerId, parseInt(trackIndex));
        }
    }
}

// Make streamPlayer function globally accessible
window.streamPlayer = streamPlayer;

// Make retryStream function globally accessible
window.retryStream = retryStream;

// Make toggleStreamDetails function globally accessible
window.toggleStreamDetails = toggleStreamDetails;

// Make selectAudioTrack function globally accessible  
window.selectAudioTrack = selectAudioTrack;
