/**
 * Staff site-cover helpers: 10 MB app cap, optional shrink so a 2M PHP
 * upload_max_filesize does not reject a normal desktop screenshot, and
 * mouse-follow hover zoom on the 16:10 preview frame.
 */
(function (window) {
    'use strict';

    var APP_MAX_KB = 10240;
    var DESKTOP_MAX_W = 1920;
    var COMPRESS_QUALITY = 0.82;

    function appMaxBytes() {
        return APP_MAX_KB * 1024;
    }

    function mbLabel(maxKb) {
        return Math.max(1, Math.floor((parseInt(maxKb, 10) || APP_MAX_KB) / 1024));
    }

    function sizeError(maxKb) {
        return 'Site image must be under ' + mbLabel(maxKb) + ' MB';
    }

    function canHoverZoom() {
        if (!window.matchMedia) {
            return true;
        }
        if (window.matchMedia('(any-hover: hover)').matches) {
            return true;
        }
        return window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    }

    function bindHoverZoom(container) {
        if (!container || container.getAttribute('data-hover-zoom') === '1') {
            return;
        }
        container.setAttribute('data-hover-zoom', '1');

        function img() {
            return container.querySelector('img');
        }

        function setOrigin(e) {
            var el = img();
            if (!el || container.classList.contains('is-empty')) {
                return;
            }
            var rect = container.getBoundingClientRect();
            if (!rect.width || !rect.height) {
                return;
            }
            var x = ((e.clientX - rect.left) / rect.width) * 100;
            var y = ((e.clientY - rect.top) / rect.height) * 100;
            el.style.transformOrigin =
                Math.max(0, Math.min(100, x)) + '% ' + Math.max(0, Math.min(100, y)) + '%';
        }

        container.addEventListener('mousemove', function (e) {
            if (!canHoverZoom()) {
                return;
            }
            setOrigin(e);
            container.classList.add('is-zooming');
        });
        container.addEventListener('mouseenter', function (e) {
            if (!canHoverZoom()) {
                return;
            }
            setOrigin(e);
            container.classList.add('is-zooming');
        });
        container.addEventListener('mouseleave', function () {
            container.classList.remove('is-zooming');
            var el = img();
            if (el) {
                el.style.transformOrigin = 'center top';
            }
        });
    }

    function showPreview(container, src, alt) {
        if (!container || !src) {
            return;
        }
        container.classList.remove('is-empty');
        container.innerHTML = '<img src="' + src + '" alt="' + (alt || 'Site image') + '">';
    }

    function compressToBlob(file, maxBytes) {
        return new Promise(function (resolve) {
            var url = URL.createObjectURL(file);
            var image = new Image();
            image.onload = function () {
                URL.revokeObjectURL(url);
                var w = image.naturalWidth || image.width;
                var h = image.naturalHeight || image.height;
                if (w < 1 || h < 1) {
                    resolve(null);
                    return;
                }
                var scale = Math.min(1, DESKTOP_MAX_W / w);
                var cw = Math.max(1, Math.round(w * scale));
                var ch = Math.max(1, Math.round(h * scale));
                var canvas = document.createElement('canvas');
                canvas.width = cw;
                canvas.height = ch;
                var ctx = canvas.getContext('2d');
                if (!ctx) {
                    resolve(null);
                    return;
                }
                ctx.drawImage(image, 0, 0, cw, ch);

                var mime = 'image/jpeg';
                try {
                    if (canvas.toDataURL('image/webp').indexOf('data:image/webp') === 0) {
                        mime = 'image/webp';
                    }
                } catch (e) {
                    mime = 'image/jpeg';
                }

                function tryBlob(q) {
                    if (typeof canvas.toBlob !== 'function') {
                        resolve(null);
                        return;
                    }
                    canvas.toBlob(function (blob) {
                        if (!blob) {
                            resolve(null);
                            return;
                        }
                        if (blob.size <= maxBytes || q <= 0.45) {
                            resolve(blob);
                            return;
                        }
                        tryBlob(Math.max(0.45, q - 0.12));
                    }, mime, q);
                }
                tryBlob(COMPRESS_QUALITY);
            };
            image.onerror = function () {
                URL.revokeObjectURL(url);
                resolve(null);
            };
            image.src = url;
        });
    }

    function blobToFile(blob, original) {
        var ext = blob.type === 'image/webp' ? 'webp' : 'jpg';
        var base = String(original.name || 'site-image').replace(/\.[^.]+$/, '');
        return new File([blob], base + '.' + ext, { type: blob.type, lastModified: Date.now() });
    }

    function assignInputFile(input, file) {
        if (!input || !file) {
            return false;
        }
        try {
            var dt = new DataTransfer();
            dt.items.add(file);
            input.files = dt.files;
            return true;
        } catch (e) {
            return false;
        }
    }

    function prepareSiteImage(file, phpMaxKb) {
        if (!file) {
            return Promise.resolve({ file: null });
        }
        if (file.size > appMaxBytes()) {
            return Promise.resolve({ error: sizeError(APP_MAX_KB) });
        }

        var phpMax = Math.max(256 * 1024, (parseInt(phpMaxKb, 10) || APP_MAX_KB) * 1024);
        if (file.size <= phpMax && file.size <= 1.5 * 1024 * 1024) {
            return Promise.resolve({ file: file });
        }

        return compressToBlob(file, Math.min(phpMax, 1.8 * 1024 * 1024)).then(function (blob) {
            if (!blob) {
                return { file: file };
            }
            if (blob.size > appMaxBytes()) {
                return { error: sizeError(APP_MAX_KB) };
            }
            return { file: blobToFile(blob, file) };
        });
    }

    function bindSiteImageInput(options) {
        var input = options.input;
        var preview = options.preview;
        var maxKb = parseInt(options.maxKb || (input && input.getAttribute('data-max-kb')) || APP_MAX_KB, 10);
        var phpMaxKb = parseInt(options.phpMaxKb || (input && input.getAttribute('data-php-max-kb')) || maxKb, 10);
        var existingSrc = options.existingSrc || (preview && preview.getAttribute('data-existing')) || '';
        var emptyHtml = options.emptyHtml || '<span>No image yet — choose a desktop-size screenshot (16:10)</span>';
        var onError = options.onError || function () {};
        var onReady = options.onReady || function () {};

        if (preview) {
            bindHoverZoom(preview);
        }
        if (!input) {
            return;
        }

        function restore() {
            if (!preview) {
                return;
            }
            if (existingSrc) {
                showPreview(preview, existingSrc, 'Current site image');
            } else {
                preview.classList.add('is-empty');
                preview.innerHTML = emptyHtml;
            }
        }

        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (!file) {
                restore();
                onReady(null);
                return;
            }
            prepareSiteImage(file, phpMaxKb).then(function (result) {
                if (result.error) {
                    input.value = '';
                    restore();
                    onError(result.error);
                    return;
                }
                if (result.file && result.file !== file) {
                    assignInputFile(input, result.file);
                }
                var ready = (input.files && input.files[0]) || result.file;
                if (preview && ready) {
                    var reader = new FileReader();
                    reader.onload = function (e) {
                        showPreview(preview, e.target.result, 'Selected site image');
                    };
                    reader.readAsDataURL(ready);
                }
                onReady(ready);
            });
        });
    }

    window.SiteImageUpload = {
        APP_MAX_KB: APP_MAX_KB,
        sizeError: sizeError,
        canHoverZoom: canHoverZoom,
        bindHoverZoom: bindHoverZoom,
        prepareSiteImage: prepareSiteImage,
        bindSiteImageInput: bindSiteImageInput,
        assignInputFile: assignInputFile,
    };
})(window);
