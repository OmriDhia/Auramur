(function () {
    const settings = window.ARWPSettings || {};
    let viewerPromise = null;
    let activeViewer = null;
    let modal, statusEl, controlsEl, snapshotButton, fallbackPanel, webxrPanel;
    let bodyOverflow;

    function ensureModal() {
        if (!modal) {
            modal = document.getElementById('arwp-modal');
            if (!modal) {
                return null;
            }
            statusEl = modal.querySelector('.arwp-modal__status');
            controlsEl = modal.querySelector('[data-arwp-controls]');
            snapshotButton = modal.querySelector('.arwp-control__snapshot');
            fallbackPanel = modal.querySelector('.arwp-viewer__fallback');
            webxrPanel = modal.querySelector('.arwp-viewer__webxr');
        }
        return modal;
    }

    function updateStatus(message) {
        if (statusEl) {
            statusEl.textContent = message || '';
        }
    }

    function toggleControls(isVisible) {
        if (!controlsEl) {
            return;
        }
        controlsEl.classList.toggle('is-hidden', !isVisible);
    }

    function toggleSnapshot(visible) {
        if (!snapshotButton) {
            return;
        }
        if (!visible) {
            snapshotButton.classList.add('is-hidden');
        } else {
            snapshotButton.classList.remove('is-hidden');
        }
    }

    function showFallbackPanel(message) {
        if (!fallbackPanel) {
            return;
        }
        if (message) {
            fallbackPanel.textContent = message;
            fallbackPanel.hidden = false;
        } else {
            fallbackPanel.hidden = true;
        }
    }

    function showWebXRPanel(show) {
        if (!webxrPanel) {
            return;
        }
        webxrPanel.hidden = !show;
    }

    function lockScroll() {
        if (typeof bodyOverflow === 'undefined') {
            bodyOverflow = document.body.style.overflow;
        }
        document.body.style.overflow = 'hidden';
    }

    function unlockScroll() {
        if (typeof bodyOverflow !== 'undefined') {
            document.body.style.overflow = bodyOverflow;
        }
    }

    function loadViewer() {
        if (viewerPromise) {
            return viewerPromise;
        }

        viewerPromise = new Promise(function (resolve, reject) {
            if (window.ARWallpaperViewer) {
                resolve(window.ARWallpaperViewer);
                return;
            }

            if (!settings.viewerScript) {
                reject(new Error('Viewer script is missing.'));
                return;
            }

            const script = document.createElement('script');
            script.src = settings.viewerScript;
            script.async = true;
            script.onload = function () {
                if (window.ARWallpaperViewer) {
                    resolve(window.ARWallpaperViewer);
                } else {
                    reject(new Error('Viewer script did not expose ARWallpaperViewer.'));
                }
            };
            script.onerror = function () {
                reject(new Error('Failed to load viewer script.'));
            };
            document.body.appendChild(script);
        });

        return viewerPromise;
    }

    function getViewerElements() {
        if (!modal) {
            return null;
        }
        return {
            modal,
            container: modal.querySelector('[data-arwp-viewer]'),
            video: modal.querySelector('.arwp-viewer__video'),
            overlay: modal.querySelector('.arwp-viewer__overlay'),
            canvas: modal.querySelector('.arwp-viewer__canvas'),
            scale: modal.querySelector('.arwp-control__scale'),
            rotateButtons: modal.querySelectorAll('.arwp-control__rotate'),
            snapshot: snapshotButton,
            fallbackPanel,
            webxrPanel,
        };
    }

    function openModal(trigger) {
        const modalEl = ensureModal();
        if (!modalEl) {
            return;
        }

        const imageUrl = trigger.getAttribute('data-ar-image');
        if (!imageUrl) {
            return;
        }

        const productName = trigger.getAttribute('data-product-name') || '';

        modalEl.classList.add('is-active');
        modalEl.setAttribute('aria-hidden', 'false');
        lockScroll();

        showFallbackPanel('');
        showWebXRPanel(false);
        toggleSnapshot(settings.enableSnapshot);
        toggleControls(true);
        updateStatus(settings.strings ? settings.strings.loading : '');

        loadViewer()
            .then(function (Viewer) {
                const elements = getViewerElements();
                if (!elements) {
                    return;
                }

                activeViewer = Viewer;
                Viewer.start({
                    imageUrl,
                    productName,
                    elements,
                    settings,
                    onStatus: updateStatus,
                    onModeChange: function (mode) {
                        if (mode === 'webxr') {
                            toggleControls(false);
                            showWebXRPanel(true);
                            showFallbackPanel('');
                        } else if (mode === 'fallback') {
                            toggleControls(true);
                            showWebXRPanel(false);
                            showFallbackPanel(settings.strings ? settings.strings.fallbackReady : '');
                        }
                    },
                    onSnapshotToggle: function (enabled) {
                        toggleSnapshot(enabled && settings.enableSnapshot);
                    },
                    onFallbackMessage: function (message) {
                        showFallbackPanel(message || '');
                    },
                });
            })
            .catch(function (error) {
                console.error('[AR Wallpaper Preview]', error);
                showFallbackPanel(error.message);
                toggleControls(false);
                toggleSnapshot(false);
                updateStatus('');
            });
    }

    function closeModal() {
        if (!modal) {
            return;
        }

        modal.classList.remove('is-active');
        modal.setAttribute('aria-hidden', 'true');
        unlockScroll();

        if (activeViewer && typeof activeViewer.stop === 'function') {
            activeViewer.stop();
        }
        activeViewer = null;
        updateStatus('');
        showFallbackPanel('');
        showWebXRPanel(false);
    }

    function onDocumentClick(event) {
        const trigger = event.target.closest('[data-arwp-trigger]');
        if (trigger) {
            event.preventDefault();
            openModal(trigger);
            return;
        }

        if (event.target.closest('[data-arwp-dismiss]')) {
            event.preventDefault();
            closeModal();
        }
    }

    function onKeyUp(event) {
        if (event.key === 'Escape') {
            closeModal();
        }
    }

    document.addEventListener('click', onDocumentClick);
    document.addEventListener('keyup', onKeyUp);

    window.addEventListener('unload', function () {
        if (activeViewer && typeof activeViewer.stop === 'function') {
            activeViewer.stop();
        }
    });
})();
