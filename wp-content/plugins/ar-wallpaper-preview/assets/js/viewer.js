(function () {
    let controller = null;

    class ViewerController {
        constructor(options) {
            this.options = options;
            this.elements = options.elements;
            this.settings = options.settings || {};
            this.callbacks = {
                status: options.onStatus || function () {},
                modeChange: options.onModeChange || function () {},
                snapshotToggle: options.onSnapshotToggle || function () {},
                fallbackMessage: options.onFallbackMessage || function () {},
            };

            this.wallpaperImage = null;
            this.stream = null;
            this.xrSession = null;
            this.renderer = null;
            this.scene = null;
            this.camera = null;
            this.reticle = null;
            this.hitTestSource = null;
            this.viewerSpace = null;
            this.referenceSpace = null;
            this.xrAnimationLoop = null;
            this.exiting = false;

            this.fallbackState = {
                scale: Math.max(0.1, (this.settings.defaultScalePercent || 100) / 100),
                rotation: 0,
                translateX: 0,
                translateY: 0,
            };

            this.bound = {};
        }

        async start() {
            try {
                await this.loadWallpaperImage();
                this.applyOverlayDefaults();
                this.setupFallbackControls();
                this.prepareSnapshot();

                const webxrAvailable = await this.checkWebXRSupport();

                if (webxrAvailable) {
                    this.callbacks.modeChange('webxr');
                    this.callbacks.snapshotToggle(false);
                    this.callbacks.status(this.settings.strings ? this.settings.strings.tapToPlace : '');
                    this.prepareWebXR();
                } else {
                    this.callbacks.modeChange('fallback');
                    this.callbacks.fallbackMessage(this.settings.strings ? this.settings.strings.webxrUnsupported : '');
                    await this.startFallback();
                }
            } catch (error) {
                console.error('[AR Wallpaper Preview]', error);
                this.callbacks.modeChange('fallback');
                this.callbacks.status('');
                this.callbacks.fallbackMessage(error.message);
                await this.startFallback();
            }
        }

        async loadWallpaperImage() {
            if (this.wallpaperImage) {
                return;
            }

            const image = new Image();
            image.crossOrigin = 'anonymous';

            this.wallpaperImage = image;

            const loadPromise = new Promise((resolve, reject) => {
                image.onload = () => resolve();
                image.onerror = () => reject(new Error('Unable to load wallpaper image.'));
            });

            image.src = this.options.imageUrl;
            if (this.elements.overlay) {
                this.elements.overlay.src = this.options.imageUrl;
            }

            await loadPromise;
        }

        applyOverlayDefaults() {
            if (!this.elements.overlay) {
                return;
            }
            const overlay = this.elements.overlay;
            overlay.style.opacity = this.settings.overlayOpacity || 0.9;
            overlay.style.left = '50%';
            overlay.style.top = '50%';
            overlay.style.width = '70%';
            overlay.style.height = 'auto';
            overlay.style.maxWidth = 'none';
            overlay.style.transformOrigin = 'center center';
            overlay.style.touchAction = 'none';
            overlay.draggable = false;
            this.updateOverlayTransform();
        }

        setupFallbackControls() {
            if (!this.elements.overlay) {
                return;
            }

            const overlay = this.elements.overlay;

            this.bound.pointerDown = this.handlePointerDown.bind(this);
            this.bound.pointerMove = this.handlePointerMove.bind(this);
            this.bound.pointerUp = this.handlePointerUp.bind(this);
            overlay.addEventListener('pointerdown', this.bound.pointerDown);
            overlay.addEventListener('pointermove', this.bound.pointerMove);
            overlay.addEventListener('pointerup', this.bound.pointerUp);
            overlay.addEventListener('pointercancel', this.bound.pointerUp);
            overlay.addEventListener('lostpointercapture', this.bound.pointerUp);

            if (this.elements.scale) {
                this.elements.scale.value = this.settings.defaultScalePercent || 100;
                this.bound.scaleChange = this.handleScaleChange.bind(this);
                this.elements.scale.addEventListener('input', this.bound.scaleChange);
            }

            if (this.elements.rotateButtons && this.elements.rotateButtons.length) {
                this.bound.rotateClick = (event) => {
                    event.preventDefault();
                    const button = event.currentTarget;
                    const delta = parseFloat(button.dataset.rotate || '0');
                    this.fallbackState.rotation = (this.fallbackState.rotation + delta) % 360;
                    this.updateOverlayTransform();
                };
                this.elements.rotateButtons.forEach((button) => {
                    button.addEventListener('click', this.bound.rotateClick);
                });
            }
        }

        prepareSnapshot() {
            if (!this.elements.snapshot || !this.settings.enableSnapshot) {
                this.callbacks.snapshotToggle(false);
                return;
            }

            this.bound.snapshot = (event) => {
                event.preventDefault();
                this.takeSnapshot();
            };

            this.elements.snapshot.addEventListener('click', this.bound.snapshot);
            this.callbacks.snapshotToggle(true);
        }

        updateOverlayTransform() {
            if (!this.elements.overlay) {
                return;
            }
            const { scale, rotation, translateX, translateY } = this.fallbackState;
            const transform = `translate(calc(-50% + ${translateX}px), calc(-50% + ${translateY}px)) rotate(${rotation}deg) scale(${scale})`;
            this.elements.overlay.style.transform = transform;
        }

        handleScaleChange(event) {
            const value = parseFloat(event.target.value || '100') / 100;
            this.fallbackState.scale = Math.max(0.1, value);
            this.updateOverlayTransform();
        }

        handlePointerDown(event) {
            if (event.button !== undefined && event.button !== 0) {
                return;
            }
            event.preventDefault();
            this.dragPointerId = event.pointerId;
            this.dragStart = {
                x: event.clientX,
                y: event.clientY,
                translateX: this.fallbackState.translateX,
                translateY: this.fallbackState.translateY,
            };
            event.currentTarget.setPointerCapture(event.pointerId);
        }

        handlePointerMove(event) {
            if (this.dragPointerId !== event.pointerId || !this.dragStart) {
                return;
            }
            event.preventDefault();
            const deltaX = event.clientX - this.dragStart.x;
            const deltaY = event.clientY - this.dragStart.y;
            this.fallbackState.translateX = this.dragStart.translateX + deltaX;
            this.fallbackState.translateY = this.dragStart.translateY + deltaY;
            this.updateOverlayTransform();
        }

        handlePointerUp(event) {
            if (this.dragPointerId === event.pointerId) {
                this.dragPointerId = null;
                this.dragStart = null;
                event.currentTarget.releasePointerCapture(event.pointerId);
            }
        }

        async checkWebXRSupport() {
            if (!navigator.xr || !navigator.xr.isSessionSupported) {
                return false;
            }

            try {
                return await navigator.xr.isSessionSupported('immersive-ar');
            } catch (error) {
                return false;
            }
        }

        prepareWebXR() {
            if (!this.elements.webxrPanel) {
                return;
            }

            const startButton = this.elements.webxrPanel.querySelector('.arwp-webxr__start');
            if (!startButton) {
                return;
            }

            startButton.disabled = false;
            startButton.addEventListener('click', this.bound.startXR = this.beginWebXRSession.bind(this));
            this.elements.webxrPanel.hidden = false;
            this.callbacks.fallbackMessage('');
            try {
                startButton.focus({ preventScroll: true });
            } catch (error) {
                startButton.focus();
            }
        }

        async beginWebXRSession() {
            if (this.xrSession) {
                return;
            }

            try {
                const init = {
                    requiredFeatures: ['hit-test'],
                    optionalFeatures: ['dom-overlay'],
                };

                if (this.elements.modal) {
                    init.domOverlay = { root: this.elements.modal };
                }

                const session = await navigator.xr.requestSession('immersive-ar', init);
                await this.setupWebXRSession(session);
            } catch (error) {
                console.error('WebXR session failed to start', error);
                this.callbacks.fallbackMessage(this.settings.strings ? this.settings.strings.webxrUnsupported : '');
                this.callbacks.modeChange('fallback');
                await this.startFallback();
            }
        }

        async setupWebXRSession(session) {
            let THREE;
            try {
                THREE = await this.loadThree();
            } catch (error) {
                console.error('Failed to load Three.js for WebXR', error);
                this.callbacks.fallbackMessage(this.settings.strings ? this.settings.strings.webxrUnsupported : '');
                this.callbacks.modeChange('fallback');
                await this.startFallback();
                return;
            }

            this.xrSession = session;
            this.exiting = false;

            this.renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true, preserveDrawingBuffer: true });
            this.renderer.xr.enabled = true;
            this.renderer.xr.setReferenceSpaceType('local');
            this.renderer.domElement.style.position = 'fixed';
            this.renderer.domElement.style.top = '0';
            this.renderer.domElement.style.left = '0';
            this.renderer.domElement.style.width = '100%';
            this.renderer.domElement.style.height = '100%';
            this.renderer.domElement.style.zIndex = '10000';
            document.body.appendChild(this.renderer.domElement);

            this.scene = new THREE.Scene();
            this.camera = new THREE.PerspectiveCamera();
            const light = new THREE.HemisphereLight(0xffffff, 0xbbbbff, 1);
            this.scene.add(light);

            const textureLoader = new THREE.TextureLoader();
            if (textureLoader.setCrossOrigin) {
                textureLoader.setCrossOrigin('anonymous');
            }
            const texture = textureLoader.load(this.options.imageUrl);
            const planeGeometry = new THREE.PlaneGeometry(
                Math.max(0.1, this.settings.defaultWidthMeters || 2.5),
                Math.max(0.1, this.settings.defaultHeightMeters || 2)
            );
            const material = new THREE.MeshBasicMaterial({ map: texture, transparent: true, side: THREE.DoubleSide });
            this.wallpaperMesh = new THREE.Mesh(planeGeometry, material);
            this.wallpaperMesh.visible = false;
            this.scene.add(this.wallpaperMesh);

            this.reticle = new THREE.Mesh(
                new THREE.RingGeometry(0.08, 0.1, 32).rotateX(-Math.PI / 2),
                new THREE.MeshBasicMaterial({ color: 0x0ea5e9 })
            );
            this.reticle.matrixAutoUpdate = false;
            this.reticle.visible = false;
            this.scene.add(this.reticle);

            this.bound.xrSelect = this.handleXRSelect.bind(this);
            session.addEventListener('select', this.bound.xrSelect);

            this.bound.xrEnd = this.handleXRSessionEnd.bind(this);
            session.addEventListener('end', this.bound.xrEnd);

            this.renderer.xr.setSession(session);

            this.referenceSpace = await session.requestReferenceSpace('local');
            this.viewerSpace = await session.requestReferenceSpace('viewer');
            try {
                this.hitTestSource = await session.requestHitTestSource({ space: this.viewerSpace });
            } catch (error) {
                console.warn('Hit test source unavailable, falling back to basic preview.', error);
                try {
                    await session.end();
                } catch (e) {
                    // Ignore errors when closing the session early.
                }
                this.callbacks.fallbackMessage(this.settings.strings ? this.settings.strings.webxrUnsupported : '');
                this.callbacks.modeChange('fallback');
                await this.startFallback();
                return;
            }

            this.xrAnimationLoop = this.onXRFrame.bind(this);
            this.renderer.setAnimationLoop(this.xrAnimationLoop);

            this.callbacks.status(this.settings.strings ? this.settings.strings.tapToPlace : '');

            if (this.elements.webxrPanel) {
                this.elements.webxrPanel.hidden = true;
            }
        }

        onXRFrame(time, frame) {
            if (!frame) {
                return;
            }

            const referenceSpace = this.referenceSpace;
            if (!referenceSpace) {
                return;
            }

            const session = frame.session;
            const pose = frame.getViewerPose(referenceSpace);
            if (!pose) {
                return;
            }

            if (this.hitTestSource) {
                const hitTestResults = frame.getHitTestResults(this.hitTestSource);
                if (hitTestResults.length > 0) {
                    const hit = hitTestResults[0];
                    const hitPose = hit.getPose(referenceSpace);
                    if (hitPose) {
                        this.reticle.visible = true;
                        this.reticle.matrix.fromArray(hitPose.transform.matrix);
                    }
                } else {
                    this.reticle.visible = false;
                }
            }

            this.renderer.render(this.scene, this.camera);
        }

        handleXRSelect() {
            if (!this.reticle || !this.reticle.visible || !this.wallpaperMesh) {
                return;
            }

            this.wallpaperMesh.visible = true;
            this.wallpaperMesh.position.setFromMatrixPosition(this.reticle.matrix);
            this.wallpaperMesh.quaternion.setFromRotationMatrix(this.reticle.matrix);
            this.callbacks.status(this.settings.strings ? this.settings.strings.placed : '');
        }

        handleXRSessionEnd() {
            if (this.renderer) {
                this.renderer.setAnimationLoop(null);
                const canvas = this.renderer.domElement;
                if (canvas && canvas.parentNode) {
                    canvas.parentNode.removeChild(canvas);
                }
                this.renderer.dispose();
            }

            this.renderer = null;
            this.scene = null;
            this.camera = null;
            this.reticle = null;
            this.wallpaperMesh = null;
            this.hitTestSource = null;
            this.viewerSpace = null;
            this.referenceSpace = null;
            this.xrSession = null;

            if (!this.exiting) {
                this.callbacks.modeChange('fallback');
                this.startFallback();
            }
        }

        async loadThree() {
            if (this.THREE) {
                return this.THREE;
            }

            const module = await import('https://cdn.jsdelivr.net/npm/three@0.156/build/three.module.js');
            this.THREE = module;
            return this.THREE;
        }

        async startFallback() {
            this.callbacks.modeChange('fallback');

            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                this.callbacks.fallbackMessage(this.settings.strings ? this.settings.strings.webxrUnsupported : '');
                this.callbacks.snapshotToggle(false);
                return;
            }

            try {
                this.stream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: { ideal: 'environment' },
                    },
                    audio: false,
                });

                if (this.elements.video) {
                    this.elements.video.srcObject = this.stream;
                    await this.elements.video.play().catch(() => {});
                }

                this.callbacks.fallbackMessage(this.settings.strings ? this.settings.strings.fallbackReady : '');
                this.callbacks.status('');
                this.callbacks.snapshotToggle(!!this.settings.enableSnapshot);
            } catch (error) {
                if (error.name === 'NotAllowedError') {
                    this.callbacks.status(this.settings.strings ? this.settings.strings.permissionDenied : '');
                }
                this.callbacks.fallbackMessage(error.message || this.settings.strings.permissionDenied);
                this.callbacks.snapshotToggle(false);
            }
        }

        async takeSnapshot() {
            if (!this.elements.canvas || !this.elements.video) {
                return;
            }

            const video = this.elements.video;
            if (!video.videoWidth || !video.videoHeight) {
                return;
            }

            const canvas = this.elements.canvas;
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            const context = canvas.getContext('2d');

            context.drawImage(video, 0, 0, canvas.width, canvas.height);

            if (this.wallpaperImage) {
                const containerRect = this.elements.container.getBoundingClientRect();
                const overlayRect = this.elements.overlay.getBoundingClientRect();

                const scaleX = canvas.width / containerRect.width;
                const scaleY = canvas.height / containerRect.height;

                const centerX = overlayRect.left + overlayRect.width / 2 - containerRect.left;
                const centerY = overlayRect.top + overlayRect.height / 2 - containerRect.top;

                context.save();
                context.translate(centerX * scaleX, centerY * scaleY);
                context.rotate((this.fallbackState.rotation * Math.PI) / 180);
                context.scale(
                    (overlayRect.width / this.wallpaperImage.width) * scaleX,
                    (overlayRect.height / this.wallpaperImage.height) * scaleY
                );
                context.globalAlpha = this.settings.overlayOpacity || 0.9;
                context.drawImage(
                    this.wallpaperImage,
                    -this.wallpaperImage.width / 2,
                    -this.wallpaperImage.height / 2
                );
                context.restore();
            }

            canvas.toBlob((blob) => {
                if (!blob) {
                    return;
                }
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                const fileName = (this.options.productName || 'wallpaper-preview').replace(/[^a-z0-9\-]+/gi, '-').toLowerCase();
                link.download = `${fileName}-preview.png`;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                URL.revokeObjectURL(url);
                this.callbacks.status(this.settings.strings ? this.settings.strings.snapshotReady : '');
            });
        }

        async stop() {
            this.exiting = true;

            if (this.stream) {
                this.stream.getTracks().forEach((track) => track.stop());
                this.stream = null;
            }

            if (this.elements.video) {
                this.elements.video.pause();
                this.elements.video.srcObject = null;
            }

            if (this.xrSession) {
                try {
                    this.xrSession.removeEventListener('end', this.bound.xrEnd);
                    this.xrSession.removeEventListener('select', this.bound.xrSelect);
                    await this.xrSession.end();
                } catch (error) {
                    // Ignore errors during shutdown.
                }
            }

            if (this.renderer) {
                this.renderer.setAnimationLoop(null);
                const canvas = this.renderer.domElement;
                if (canvas && canvas.parentNode) {
                    canvas.parentNode.removeChild(canvas);
                }
                if (this.renderer.dispose) {
                    this.renderer.dispose();
                }
            }

            this.renderer = null;
            this.scene = null;
            this.camera = null;
            this.reticle = null;
            this.wallpaperMesh = null;
            this.hitTestSource = null;
            this.viewerSpace = null;
            this.referenceSpace = null;
            this.xrSession = null;

            if (this.elements.overlay) {
                this.elements.overlay.removeEventListener('pointerdown', this.bound.pointerDown);
                this.elements.overlay.removeEventListener('pointermove', this.bound.pointerMove);
                this.elements.overlay.removeEventListener('pointerup', this.bound.pointerUp);
                this.elements.overlay.removeEventListener('pointercancel', this.bound.pointerUp);
                this.elements.overlay.removeEventListener('lostpointercapture', this.bound.pointerUp);
            }

            if (this.elements.scale && this.bound.scaleChange) {
                this.elements.scale.removeEventListener('input', this.bound.scaleChange);
            }

            if (this.elements.rotateButtons && this.bound.rotateClick) {
                this.elements.rotateButtons.forEach((button) => {
                    button.removeEventListener('click', this.bound.rotateClick);
                });
            }

            if (this.elements.snapshot && this.bound.snapshot) {
                this.elements.snapshot.removeEventListener('click', this.bound.snapshot);
            }

            if (this.elements.webxrPanel && this.bound.startXR) {
                const startButton = this.elements.webxrPanel.querySelector('.arwp-webxr__start');
                if (startButton) {
                    startButton.removeEventListener('click', this.bound.startXR);
                }
            }

            this.callbacks.status('');
            this.callbacks.fallbackMessage('');
            this.callbacks.snapshotToggle(false);
        }
    }

    window.ARWallpaperViewer = {
        start(options) {
            if (controller) {
                controller.stop();
            }

            controller = new ViewerController(options);
            controller.start();
            return controller;
        },
        stop() {
            if (controller) {
                controller.stop();
                controller = null;
            }
        },
    };
})();
