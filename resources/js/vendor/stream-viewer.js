// Global stream player functionality
function streamPlayer() {
    return {
        player: null,
        hls: null,
        mpegts: null,
        currentTranscodeUrl: null,
        selectedServerAudioTrack: 0,
        availableServerAudioTracks: [],
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
        
        initPlayer(url, format, playerId) {
            console.log('initPlayer called with:', { url, format, playerId });
            
            // Set this instance as the global instance for audio track switching
            window.streamPlayerInstance = this;
            
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
            
            try {
                if (format === 'hls' || url.includes('.m3u8')) {
                    console.log('Initializing HLS player');
                    this.initHlsPlayer(video, url, playerId);
                } else if (format === 'ts' || format === 'mpegts') {
                    console.log('Initializing MPEG-TS player');
                    this.initMpegTsPlayer(video, url, playerId);
                } else if (this.needsServerTranscoding(url, format)) {
                    console.log('Initializing transcoded player for format:', format);
                    this.initTranscodedPlayer(video, url, playerId);
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
        
        needsServerTranscoding(url, format) {
            // Check if the format requires server-side transcoding
            const path = new URL(url, window.location.origin).pathname;
            const extension = path.split('.').pop().toLowerCase().split('?')[0];
            
            // Skip transcoding for already compatible formats
            if (format === 'hls' || format === 'ts' || format === 'mpegts') {
                return false;
            }
            
            if (url.includes('.m3u8') || url.includes('.ts')) {
                return false;
            }
            
            // File types that need transcoding for web compatibility
            const needsTranscodingExtensions = ['mkv', 'avi', 'mov', 'flv', 'wmv', 'asf', 'rm', 'rmvb'];
            
            return needsTranscodingExtensions.includes(extension);
        },
        
        async initTranscodedPlayer(video, url, playerId) {
            console.log('Initializing transcoded player for:', url);
            
            this.updateStatus(playerId, 'Preparing transcode...');
            
            try {
                // First, probe the stream to get metadata
                await this.probeStream(url, playerId);
                
                // Build the transcode URL with selected audio track
                const transcodeUrl = this.buildTranscodeUrl(url, this.selectedServerAudioTrack);
                console.log('Transcode URL:', transcodeUrl);
                
                // Store the base URL and current transcode URL for later use
                this.baseUrl = url;
                this.currentTranscodeUrl = transcodeUrl;
                
                this.updateStatus(playerId, 'Starting transcode...');
                
                // Use the transcoded stream URL with native player
                this.initNativePlayer(video, transcodeUrl, playerId);
                
                // Add transcoding indicator to stream details
                this.addTranscodingIndicator(playerId);
                
                // Override the availableAudioTracks with server tracks if available
                if (this.availableServerAudioTracks.length > 0) {
                    this.availableAudioTracks = this.availableServerAudioTracks;
                    this.selectedAudioTrack = this.selectedServerAudioTrack;
                }
                
            } catch (error) {
                console.error('Transcoding initialization failed:', error);
                console.log('Falling back to native player...');
                this.showTranscodingWarning(playerId, 'Transcoding failed, using direct stream');
                this.initNativePlayer(video, url, playerId);
            }
        },
        
        async probeStream(url, playerId) {
            try {
                const probeUrl = `/probe?url=${encodeURIComponent(url)}`;
                const response = await fetch(probeUrl);
                
                if (response.ok) {
                    const metadata = await response.json();
                    console.log('Stream metadata:', metadata);
                    
                    // Store server-side audio tracks for transcoding
                    if (metadata.audio_tracks && metadata.audio_tracks.length > 0) {
                        this.availableServerAudioTracks = metadata.audio_tracks.map((track, index) => ({
                            index: track.index,
                            id: `server-${track.index}`,
                            label: track.title || `Track ${track.index + 1}`,
                            language: track.language || 'unknown',
                            codec: track.codec,
                            channels: track.channels,
                            enabled: index === 0,
                            estimated: false,
                            serverTrack: true
                        }));
                        
                        this.selectedServerAudioTrack = 0;
                        console.log('Found server audio tracks:', this.availableServerAudioTracks);
                    }
                    
                    // Update stream metadata with probed information
                    if (metadata.streams) {
                        for (const stream of metadata.streams) {
                            if (stream.type === 'video') {
                                this.streamMetadata.codec = stream.codec;
                                if (stream.width && stream.height) {
                                    this.streamMetadata.resolution = `${stream.width}x${stream.height}`;
                                }
                                if (stream.fps) {
                                    this.streamMetadata.framerate = stream.fps;
                                }
                                if (stream.bitrate) {
                                    this.streamMetadata.bitrate = stream.bitrate;
                                }
                            } else if (stream.type === 'audio' && stream.index === 0) {
                                // Use the first audio track for general metadata
                                this.streamMetadata.audioCodec = stream.codec;
                                if (stream.channels) {
                                    this.streamMetadata.audioChannels = stream.channels === 2 ? '2.0' : stream.channels.toString();
                                }
                            }
                        }
                    }
                    
                    // Store duration for seeking support
                    if (metadata.duration) {
                        this.streamMetadata.duration = parseFloat(metadata.duration);
                    }
                    
                    this.updateStreamDetails(playerId);
                } else {
                    console.warn('Failed to probe stream:', response.status);
                }
            } catch (error) {
                console.warn('Stream probing failed:', error);
                // Non-fatal - continue with transcoding
            }
        },
        
        buildTranscodeUrl(url, audioTrack = 0, seekTo = null) {
            const params = new URLSearchParams({
                url: url,
                format: 'auto',
                audio_track: audioTrack
            });
            
            if (seekTo !== null && seekTo > 0) {
                params.set('seek', seekTo);
            }
            
            return `/transcode?${params.toString()}`;
        },
        
        addTranscodingIndicator(playerId) {
            const detailsEl = document.getElementById(playerId + '-details');
            if (detailsEl) {
                const transcodeHtml = `
                    <div class="flex items-center gap-2 mb-2 p-2 bg-blue-900 bg-opacity-20 rounded border border-blue-600">
                        <div class="text-blue-400">⚡</div>
                        <div class="text-sm">
                            <div class="font-semibold text-blue-400">Server Transcode Active</div>
                            <div class="text-xs text-blue-300 mt-1">Stream being transcoded on server for compatibility</div>
                        </div>
                    </div>
                `;
                
                const existingContent = detailsEl.innerHTML;
                detailsEl.innerHTML = transcodeHtml + existingContent;
                
                // Add audio track selector if we have server audio tracks
                this.addAudioTrackSelector(playerId);
            }
        },
        
        addAudioTrackSelector(playerId) {
            if (this.availableServerAudioTracks.length <= 1) {
                return; // No need for selector if only one track
            }
            
            const detailsEl = document.getElementById(playerId + '-details');
            if (!detailsEl) return;
            
            // Check if selector already exists to prevent duplicates
            const existingSelector = document.getElementById(`${playerId}-audio-selector`);
            if (existingSelector) {
                console.log('Audio track selector already exists, updating options instead');
                this.updateAudioTrackSelectorOptions(playerId);
                return;
            }
            
            // Create audio track selector HTML
            const trackOptions = this.availableServerAudioTracks.map((track, index) => {
                const langLabel = track.language === 'unknown' ? `Track ${index + 1}` : track.language.toUpperCase();
                const codecInfo = track.codec ? ` (${track.codec})` : '';
                const channelInfo = track.channels ? ` ${track.channels}ch` : '';
                return `<option value="${index}" ${index === this.selectedServerAudioTrack ? 'selected' : ''}>
                    ${langLabel}${codecInfo}${channelInfo}
                </option>`;
            }).join('');
            
            const selectorHtml = `
                <div id="${playerId}-audio-selector" class="mb-2 p-2 bg-gray-800 bg-opacity-40 rounded border border-gray-600">
                    <div class="flex items-center gap-3">
                        <label class="text-sm font-medium text-gray-300">Audio Track:</label>
                        <select 
                            id="${playerId}-audio-select" 
                            class="bg-gray-700 text-white text-sm rounded px-2 py-1 border border-gray-600 focus:border-blue-500"
                            onchange="window.streamPlayerInstance?.switchServerAudioTrack?.('${playerId}', parseInt(this.value))"
                        >
                            ${trackOptions}
                        </select>
                    </div>
                </div>
            `;
            
            // Insert the selector after the existing content
            const existingContent = detailsEl.innerHTML;
            detailsEl.innerHTML = existingContent + selectorHtml;
        },
        
        updateAudioTrackSelectorOptions(playerId) {
            const audioSelect = document.getElementById(`${playerId}-audio-select`);
            if (!audioSelect) return;
            
            // Update the options with current track data
            audioSelect.innerHTML = this.availableServerAudioTracks.map((track, index) => {
                const langLabel = track.language === 'unknown' ? `Track ${index + 1}` : track.language.toUpperCase();
                const codecInfo = track.codec ? ` (${track.codec})` : '';
                const channelInfo = track.channels ? ` ${track.channels}ch` : '';
                return `<option value="${index}" ${index === this.selectedServerAudioTrack ? 'selected' : ''}>
                    ${langLabel}${codecInfo}${channelInfo}
                </option>`;
            }).join('');
            
            // Ensure the correct track is selected
            audioSelect.value = this.selectedServerAudioTrack;
        },
        
        showTranscodingWarning(playerId, message) {
            const detailsEl = document.getElementById(playerId + '-details');
            if (detailsEl) {
                const warningHtml = `
                    <div class="flex items-center gap-2 mb-2 p-2 bg-yellow-900 bg-opacity-20 rounded border border-yellow-600">
                        <div class="text-yellow-400">⚠️</div>
                        <div class="text-sm">
                            <div class="font-semibold text-yellow-400">Transcoding Issue</div>
                            <div class="text-xs text-yellow-300 mt-1">${message}</div>
                        </div>
                    </div>
                `;
                
                const existingContent = detailsEl.innerHTML;
                detailsEl.innerHTML = warningHtml + existingContent;
            }
        },
        
        updateAudioTrackSelector(playerId, selectedTrackIndex) {
            const audioSelect = document.getElementById(`${playerId}-audio-select`);
            if (audioSelect) {
                // Use setTimeout to ensure DOM is ready and dropdown is properly updated
                setTimeout(() => {
                    audioSelect.value = selectedTrackIndex.toString();
                    
                    // Force a re-render if the value didn't stick
                    if (audioSelect.value !== selectedTrackIndex.toString()) {
                        this.updateAudioTrackSelectorOptions(playerId);
                    }
                }, 100);
            } else {
                console.warn(`Audio selector element not found: ${playerId}-audio-select`);
            }
        },
        
        async switchServerAudioTrack(playerId, trackIndex) {
            if (!this.baseUrl || !this.availableServerAudioTracks[trackIndex]) {
                console.warn('Cannot switch server audio track - no base URL or invalid track');
                return false;
            }
            
            console.log('Switching to server audio track:', trackIndex);
            
            const video = document.getElementById(playerId);
            if (!video) return false;
            
            // Store current playback position
            const currentTime = video.currentTime || 0;
            const wasPlaying = !video.paused;
            
            this.updateStatus(playerId, 'Switching audio track...');
            
            try {
                // Update selected track
                this.selectedServerAudioTrack = trackIndex;
                this.selectedAudioTrack = trackIndex;
                
                // Update the dropdown immediately (with delay to ensure DOM is ready)
                setTimeout(() => {
                    this.updateAudioTrackSelector(playerId, trackIndex);
                }, 10);
                
                // Update track enabled status
                this.availableServerAudioTracks.forEach((track, index) => {
                    track.enabled = index === trackIndex;
                });
                this.availableAudioTracks = this.availableServerAudioTracks;
                
                // Build new transcode URL with selected audio track and seek position
                const newTranscodeUrl = this.buildTranscodeUrl(this.baseUrl, trackIndex, currentTime);
                console.log('New transcode URL with audio track:', newTranscodeUrl);
                
                // Update video source
                video.src = newTranscodeUrl;
                this.currentTranscodeUrl = newTranscodeUrl;
                
                // Wait for the video to load and seek to the previous position
                const onLoadedData = () => {
                    video.removeEventListener('loadeddata', onLoadedData);
                    
                    if (currentTime > 0) {
                        video.currentTime = currentTime;
                    }
                    
                    if (wasPlaying) {
                        video.play().catch(e => console.warn('Autoplay failed:', e));
                    }
                    
                    this.updateStatus(playerId, wasPlaying ? 'Playing' : 'Ready');
                    
                    // Update the dropdown to reflect the selected track
                    this.updateAudioTrackSelector(playerId, trackIndex);
                };
                
                video.addEventListener('loadeddata', onLoadedData);
                video.load();
                
                this.updateStreamDetails(playerId);
                
                return true;
                
            } catch (error) {
                console.error('Failed to switch server audio track:', error);
                this.updateStatus(playerId, 'Error switching audio');
                return false;
            }
        },
        
        initNativePlayer(video, url, playerId) {
            console.log('Initializing native player for:', playerId);
            
            // Configure video element for optimal audio track detection
            video.muted = false;
            video.volume = 0.5;
            video.controls = true;
            video.preload = 'metadata';
            
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

            // Check if we have server transcoding UI that we need to preserve
            const existingTranscodeIndicator = detailsEl.querySelector('.bg-blue-900');
            const existingAudioSelector = detailsEl.querySelector(`#${playerId}-audio-selector`);
            
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
            
            // For client-side audio tracks (non-server transcoding) - only show if no server audio selector exists
            if (this.availableAudioTracks && this.availableAudioTracks.length > 1 && !existingAudioSelector && !this.availableServerAudioTracks.length) {
                detailsHtml += `<div class="border-t pt-2 mt-2">`;
                detailsHtml += `<div class="flex justify-between items-center gap-1 mb-1">`;
                detailsHtml += `<span class="text-sm">Audio Track:</span>`;
                detailsHtml += `<select onchange="selectAudioTrack('${playerId}', this.value)" class="text-xs bg-gray-700 border border-gray-600 rounded px-1 py-0.5 text-white">`;
                
                this.availableAudioTracks.forEach((track, index) => {
                    const selected = index === this.selectedAudioTrack ? 'selected' : '';
                    const estimatedTag = track.estimated ? ' (estimated)' : '';
                    const codecInfo = track.codec ? ` - ${track.codec}` : '';
                    const label = track.language !== 'unknown' ? `${track.label} (${track.language})${codecInfo}` : `${track.label}${codecInfo}`;
                    detailsHtml += `<option value="${index}" ${selected}>${label}${estimatedTag}</option>`;
                });
                
                detailsHtml += `</select></div>`;
                
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

            // If we have server transcoding UI, preserve it and maintain proper order
            if (existingTranscodeIndicator || existingAudioSelector) {
                let preservedHtml = '';
                
                // Always put transcode indicator first
                if (existingTranscodeIndicator) {
                    preservedHtml += existingTranscodeIndicator.outerHTML;
                }
                
                // Then audio selector
                if (existingAudioSelector) {
                    preservedHtml += existingAudioSelector.outerHTML;
                }
                
                if (detailsHtml) {
                    detailsEl.innerHTML = preservedHtml + detailsHtml;
                } else {
                    detailsEl.innerHTML = preservedHtml + '<div class="text-gray-500 dark:text-gray-400 text-sm">Stream details not available</div>';
                }
                detailsEl.style.display = 'block';
                
                // After preserving, make sure the audio selector shows the correct value
                if (existingAudioSelector && this.selectedServerAudioTrack !== undefined) {
                    setTimeout(() => {
                        this.updateAudioTrackSelector(playerId, this.selectedServerAudioTrack);
                    }, 50);
                }
            } else {
                if (detailsHtml) {
                    detailsEl.innerHTML = detailsHtml;
                    detailsEl.style.display = 'block';
                } else {
                    detailsEl.innerHTML = '<div class="text-gray-500 dark:text-gray-400 text-sm">Stream details not available</div>';
                    detailsEl.style.display = 'block';
                }
            }
        },

        collectVideoMetadata(video, playerId) {
            console.log('Collecting video metadata for:', playerId);
            
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

            // Enhanced audio track detection
            this.detectAudioTracks(video, playerId);

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

            // Container-based codec detection
            this.detectCodecFromContainer(video, playerId);

            this.updateStreamDetails(playerId);
        },
        
        detectAudioTracks(video, playerId) {
            console.log('Detecting audio tracks for:', playerId);
            const videoSrc = video.src || video.currentSrc;
            const isMKV = videoSrc && videoSrc.toLowerCase().includes('.mkv');
            
            // Reset audio tracks
            this.availableAudioTracks = [];
            this.selectedAudioTrack = null;
            
            // Try to get real audio tracks first
            if (video.audioTracks && video.audioTracks.length > 0) {
                console.log('Found real audio tracks:', video.audioTracks.length);
                
                for (let i = 0; i < video.audioTracks.length; i++) {
                    const track = video.audioTracks[i];
                    console.log(`Audio track ${i}:`, {
                        id: track.id,
                        kind: track.kind,
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
            } else {
                console.log('No real audio tracks found, checking for estimated tracks');
                
                // For MKV files, create estimated tracks since browsers often don't expose them
                if (isMKV && video.duration && video.duration > 30) {
                    console.log('Creating estimated audio tracks for MKV file');
                    
                    // Use different estimation strategies
                    this.estimateAudioTracksForMKV(video, playerId);
                } else {
                    // For other formats, try basic detection
                    this.detectBasicAudioInfo(video, playerId);
                }
            }
            
            // Default audio channels if we have tracks but no channels
            if (this.availableAudioTracks.length > 0 && !this.streamMetadata.audioChannels) {
                this.streamMetadata.audioChannels = '2.0'; // Stereo default
            }
        },
        
        estimateAudioTracksForMKV(video, playerId) {
            console.log('Estimating audio tracks for MKV file');
            
            // Common MKV scenarios - often have multiple language tracks
            const commonLanguages = ['eng', 'spa', 'fra', 'deu', 'jpn', 'kor'];
            
            // Create estimated tracks based on duration and common patterns
            if (video.duration > 30) { // Likely has audio
                // Create multiple estimated tracks for MKV
                this.availableAudioTracks = [
                    {
                        index: 0,
                        id: 'estimated-0',
                        label: 'Default Audio',
                        language: 'eng',
                        enabled: true,
                        estimated: true
                    },
                    {
                        index: 1,
                        id: 'estimated-1', 
                        label: 'Audio Track 2',
                        language: 'unknown',
                        enabled: false,
                        estimated: true
                    }
                ];
                
                // For longer content, assume more tracks might exist
                if (video.duration > 3600) { // > 1 hour content
                    this.availableAudioTracks.push({
                        index: 2,
                        id: 'estimated-2',
                        label: 'Audio Track 3',
                        language: 'unknown',
                        enabled: false,
                        estimated: true
                    });
                }
                
                this.selectedAudioTrack = 0;
                console.log('Created estimated audio tracks:', this.availableAudioTracks);
                
                // Set likely codec for MKV
                if (!this.streamMetadata.audioCodec) {
                    this.streamMetadata.audioCodec = 'AAC'; // Common for MKV
                }
            }
        },
        
        detectBasicAudioInfo(video, playerId) {
            console.log('Detecting basic audio info');
            
            // Check if there's likely to be audio based on video duration
            if (video.duration && video.duration > 10) {
                // Assume basic audio track exists
                this.availableAudioTracks = [{
                    index: 0,
                    id: 'default-0',
                    label: 'Default Audio',
                    language: 'unknown',
                    enabled: true,
                    estimated: false
                }];
                
                this.selectedAudioTrack = 0;
                
                // Set basic codec
                if (!this.streamMetadata.audioCodec) {
                    this.streamMetadata.audioCodec = 'AAC';
                }
            }
        },
        
        detectCodecFromContainer(video, playerId) {
            const videoSrc = video.src || video.currentSrc;
            if (!videoSrc) return;
            
            const extension = videoSrc.split('.').pop().toLowerCase().split('?')[0];
            console.log('Detecting codec from container extension:', extension);
            
            switch (extension) {
                case 'mkv':
                    if (!this.streamMetadata.codec) {
                        this.streamMetadata.codec = 'H.264'; // Most common
                    }
                    if (!this.streamMetadata.audioCodec) {
                        this.streamMetadata.audioCodec = 'AAC'; // Common fallback
                    }
                    break;
                    
                case 'mp4':
                case 'm4v':
                    if (!this.streamMetadata.codec) {
                        this.streamMetadata.codec = 'H.264';
                    }
                    if (!this.streamMetadata.audioCodec) {
                        this.streamMetadata.audioCodec = 'AAC';
                    }
                    break;
                    
                case 'webm':
                    if (!this.streamMetadata.codec) {
                        this.streamMetadata.codec = 'VP9';
                    }
                    if (!this.streamMetadata.audioCodec) {
                        this.streamMetadata.audioCodec = 'Opus';
                    }
                    break;
                    
                case 'avi':
                    if (!this.streamMetadata.codec) {
                        this.streamMetadata.codec = 'XVID';
                    }
                    if (!this.streamMetadata.audioCodec) {
                        this.streamMetadata.audioCodec = 'MP3';
                    }
                    break;
            }
        },
        
        selectAudioTrack(playerId, trackIndex) {
            console.log('Selecting audio track:', trackIndex);
            
            const video = document.getElementById(playerId);
            if (!video) return;
            
            const videoSrc = video.src || video.currentSrc;
            const trackIndexNum = parseInt(trackIndex);
            
            // Check if this is a server-transcoded stream
            if (this.currentTranscodeUrl && this.availableServerAudioTracks.length > 0) {
                console.log('Switching server-side transcoded audio track');
                this.switchServerAudioTrack(playerId, trackIndexNum);
                return;
            }
            
            // If we have real audio tracks, use them
            if (video.audioTracks && video.audioTracks.length > trackIndexNum) {
                console.log('Switching real audio track');
                
                // Disable all tracks first
                for (let i = 0; i < video.audioTracks.length; i++) {
                    video.audioTracks[i].enabled = false;
                }
                
                // Enable selected track
                video.audioTracks[trackIndexNum].enabled = true;
                this.selectedAudioTrack = trackIndexNum;
                
                // Update our tracking
                this.availableAudioTracks.forEach((track, index) => {
                    track.enabled = index === trackIndexNum;
                });
                
                console.log(`Enabled native audio track ${trackIndexNum}`);
            } else {
                console.log('Switching estimated audio track (limited functionality)');
                
                // For estimated tracks, we can't actually change the audio
                // but we can update the UI to show the selection
                this.selectedAudioTrack = trackIndexNum;
                
                // Update the available tracks to reflect the selection
                if (this.availableAudioTracks) {
                    this.availableAudioTracks.forEach((track, index) => {
                        track.enabled = index === trackIndexNum;
                    });
                }
                
                // Provide user feedback about limitations
                const isMKV = videoSrc && videoSrc.toLowerCase().includes('.mkv');
                if (isMKV) {
                    console.warn(`Selected estimated MKV audio track ${trackIndexNum} - browser API limitations prevent actual audio switching`);
                    
                    // Optional: Show user notification about limitations
                    const detailsEl = document.getElementById(playerId + '-details');
                    if (detailsEl) {
                        // Add temporary feedback message
                        const feedbackDiv = document.createElement('div');
                        feedbackDiv.className = 'text-xs text-yellow-400 mt-1';
                        feedbackDiv.textContent = 'Audio track selection simulated - browser limitations with MKV files';
                        detailsEl.appendChild(feedbackDiv);
                        
                        // Remove feedback after 3 seconds
                        setTimeout(() => {
                            if (feedbackDiv.parentNode) {
                                feedbackDiv.parentNode.removeChild(feedbackDiv);
                            }
                        }, 3000);
                    }
                } else {
                    console.log(`Selected estimated audio track ${trackIndexNum} (no actual audio change)`);
                }
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
            this.availableServerAudioTracks = [];
            this.selectedServerAudioTrack = 0;
            this.currentTranscodeUrl = null;
            this.baseUrl = null;
            
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

// Create a global instance for audio track switching
window.streamPlayerInstance = null;

// Make retryStream function globally accessible
window.retryStream = retryStream;

// Make toggleStreamDetails function globally accessible
window.toggleStreamDetails = toggleStreamDetails;

// Make selectAudioTrack function globally accessible  
window.selectAudioTrack = selectAudioTrack;
