/**
 * Center gallery for admin blog featured + inline Quill images.
 * Allows preview, replace, and delete without hunting through the editor.
 */
(function (window) {
    'use strict';

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function normalizeSrc(src) {
        if (!src) {
            return '';
        }
        try {
            var url = new URL(src, window.location.origin);
            return url.pathname + url.search;
        } catch (e) {
            return String(src).split('#')[0];
        }
    }

    function AdminBlogImages(options) {
        this.quills = options.quills || {};
        this.uploadUrl = options.uploadUrl;
        this.deleteUrl = options.deleteUrl || null;
        this.csrfToken = options.csrfToken || '';
        this.grid = document.getElementById('articleImagesGrid');
        this.empty = document.getElementById('articleImagesEmpty');
        this.replaceInput = document.getElementById('articleImageReplaceInput');
        this.pendingReplace = null;
        this.refreshTimer = null;

        if (!this.grid) {
            return;
        }

        var self = this;
        Object.keys(this.quills).forEach(function (locale) {
            self.quills[locale].on('text-change', function () {
                self.scheduleRender();
            });
        });

        var featuredInput = document.getElementById('featuredImageInput');
        if (featuredInput) {
            featuredInput.addEventListener('change', function () {
                self.scheduleRender();
            });
        }

        ['featuredImageRemoveBtn', 'featuredImageClearBtn'].forEach(function (id) {
            var btn = document.getElementById(id);
            if (btn) {
                btn.addEventListener('click', function () {
                    setTimeout(function () {
                        self.scheduleRender();
                    }, 0);
                });
            }
        });

        var refreshBtn = document.getElementById('refreshArticleImages');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', function () {
                self.render();
            });
        }

        if (this.replaceInput) {
            this.replaceInput.addEventListener('change', function () {
                self.handleReplaceFile(this.files && this.files[0]);
                this.value = '';
            });
        }

        this.grid.addEventListener('click', function (event) {
            var btn = event.target.closest('[data-image-action]');
            if (!btn) {
                return;
            }
            var action = btn.getAttribute('data-image-action');
            var role = btn.getAttribute('data-role');
            var locale = btn.getAttribute('data-locale') || '';
            var src = btn.getAttribute('data-src') || '';

            if (action === 'change') {
                self.beginReplace({ role: role, locale: locale, src: src });
            } else if (action === 'delete') {
                self.beginDelete({ role: role, locale: locale, src: src });
            }
        });

        this.render();
    }

    AdminBlogImages.prototype.scheduleRender = function () {
        var self = this;
        clearTimeout(this.refreshTimer);
        this.refreshTimer = setTimeout(function () {
            self.render();
        }, 120);
    };

    AdminBlogImages.prototype.collect = function () {
        var items = [];
        var featuredPreview = document.getElementById('featuredImagePreview');
        var featuredImg = featuredPreview ? featuredPreview.querySelector('img') : null;
        if (featuredImg && featuredImg.getAttribute('src')) {
            items.push({
                role: 'featured',
                locale: '',
                src: featuredImg.getAttribute('src'),
                label: 'Featured image',
            });
        }

        var self = this;
        Object.keys(this.quills).forEach(function (locale) {
            var imgs = self.quills[locale].root.querySelectorAll('img');
            var index = 0;
            imgs.forEach(function (img) {
                var src = img.getAttribute('src');
                if (!src) {
                    return;
                }
                index += 1;
                items.push({
                    role: 'inline',
                    locale: locale,
                    src: src,
                    label: 'Inline ' + locale.toUpperCase() + ' #' + index,
                });
            });
        });

        return items;
    };

    AdminBlogImages.prototype.render = function () {
        if (!this.grid) {
            return;
        }

        var items = this.collect();
        this.grid.innerHTML = '';

        if (this.empty) {
            this.empty.classList.toggle('d-none', items.length > 0);
        }

        var self = this;
        items.forEach(function (item) {
            var col = document.createElement('div');
            col.className = 'col-sm-6 col-lg-4';
            col.innerHTML =
                '<div class="border rounded bg-white h-100 p-2 text-center article-image-card">' +
                '<div class="article-image-frame mb-2 d-flex align-items-center justify-content-center">' +
                '<img src="' +
                escapeHtml(item.src) +
                '" alt="' +
                escapeHtml(item.label) +
                '" class="img-fluid rounded" loading="lazy">' +
                '</div>' +
                '<div class="small fw-semibold mb-2">' +
                escapeHtml(item.label) +
                '</div>' +
                '<div class="d-flex flex-wrap justify-content-center gap-2">' +
                '<button type="button" class="btn btn-outline-primary btn-sm" data-image-action="change" data-role="' +
                escapeHtml(item.role) +
                '" data-locale="' +
                escapeHtml(item.locale) +
                '" data-src="' +
                escapeHtml(item.src) +
                '"><i class="fa fa-exchange me-1"></i> Change</button>' +
                '<button type="button" class="btn btn-outline-danger btn-sm" data-image-action="delete" data-role="' +
                escapeHtml(item.role) +
                '" data-locale="' +
                escapeHtml(item.locale) +
                '" data-src="' +
                escapeHtml(item.src) +
                '"><i class="fa fa-trash me-1"></i> Delete</button>' +
                '</div>' +
                '</div>';
            self.grid.appendChild(col);
        });
    };

    AdminBlogImages.prototype.beginReplace = function (target) {
        this.pendingReplace = target;
        if (target.role === 'featured') {
            var featuredInput = document.getElementById('featuredImageInput');
            if (featuredInput) {
                featuredInput.click();
            }
            this.pendingReplace = null;
            return;
        }
        if (this.replaceInput) {
            this.replaceInput.click();
        }
    };

    AdminBlogImages.prototype.handleReplaceFile = function (file) {
        var self = this;
        var target = this.pendingReplace;
        this.pendingReplace = null;
        if (!file || !target || target.role !== 'inline') {
            return;
        }

        var formData = new FormData();
        formData.append('image', file);

        fetch(this.uploadUrl, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': this.csrfToken,
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: formData,
            credentials: 'same-origin',
        })
            .then(function (response) {
                return response.json().then(function (data) {
                    return { ok: response.ok, data: data };
                });
            })
            .then(function (result) {
                if (!result.ok || !result.data.success || !result.data.url) {
                    throw new Error((result.data && result.data.error) || 'Image upload failed.');
                }
                self.replaceInlineSrc(target.locale, target.src, result.data.url);
                self.render();
            })
            .catch(function (error) {
                if (window.Swal) {
                    Swal.fire('Error', error.message || 'Failed to replace image.', 'error');
                } else {
                    window.slbAlert({ icon: 'error', title: error.message || 'Failed to replace image.' });
                }
            });
    };

    AdminBlogImages.prototype.replaceInlineSrc = function (locale, oldSrc, newSrc) {
        var editor = this.quills[locale];
        if (!editor) {
            return;
        }
        var oldNorm = normalizeSrc(oldSrc);
        editor.root.querySelectorAll('img').forEach(function (img) {
            if (normalizeSrc(img.getAttribute('src')) === oldNorm || img.getAttribute('src') === oldSrc) {
                img.setAttribute('src', newSrc);
            }
        });
    };

    AdminBlogImages.prototype.beginDelete = function (target) {
        var self = this;
        // slbConfirm owns the SweetAlert / native fallback.
        var confirmFn = window.slbConfirm({
            title: 'Delete image?',
            text:
                target.role === 'featured'
                    ? 'Remove the featured image from this post.'
                    : 'Remove this image from the article body.',
            confirmText: 'Yes, delete',
            danger: true,
        });

        confirmFn.then(function (confirmed) {
            if (!confirmed) {
                return;
            }
            if (target.role === 'featured') {
                self.deleteFeatured();
            } else {
                self.deleteInline(target.locale, target.src);
            }
            self.render();
        });
    };

    AdminBlogImages.prototype.deleteFeatured = function () {
        var removeBtn =
            document.getElementById('featuredImageRemoveBtn') ||
            document.getElementById('featuredImageClearBtn');
        if (removeBtn) {
            removeBtn.click();
            return;
        }
        var featuredInput = document.getElementById('featuredImageInput');
        if (featuredInput) {
            featuredInput.value = '';
        }
        var removeFlag = document.getElementById('removeFeaturedImage');
        if (removeFlag) {
            removeFlag.value = '1';
        }
        var preview = document.getElementById('featuredImagePreview');
        if (preview) {
            preview.innerHTML =
                '<div id="noImagePlaceholder" class="text-center">' +
                '<i class="fa fa-image fa-3x text-muted mb-2"></i>' +
                '<p class="text-muted small">No image selected</p>' +
                '</div>';
        }
    };

    AdminBlogImages.prototype.deleteInline = function (locale, src) {
        var editor = this.quills[locale];
        if (!editor) {
            return;
        }
        var oldNorm = normalizeSrc(src);
        Array.prototype.slice.call(editor.root.querySelectorAll('img')).forEach(function (img) {
            if (normalizeSrc(img.getAttribute('src')) !== oldNorm && img.getAttribute('src') !== src) {
                return;
            }
            var blot = window.Quill ? Quill.find(img) : null;
            if (blot) {
                var index = editor.getIndex(blot);
                var length = typeof blot.length === 'function' ? blot.length() : 1;
                editor.deleteText(index, length || 1, 'user');
            } else {
                img.remove();
            }
        });
    };

    AdminBlogImages.prototype.isStoredBlogImageSrc = function (src) {
        var path = normalizeSrc(src);
        return path.indexOf('/storage/blogs/') !== -1 || path.indexOf('/media/blogs/') !== -1;
    };

    AdminBlogImages.prototype.maybeDeleteStoredFile = function (src) {
        if (!this.deleteUrl || !this.isStoredBlogImageSrc(src)) {
            return;
        }

        fetch(this.deleteUrl, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': this.csrfToken,
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ url: src }),
            credentials: 'same-origin',
        }).catch(function () {
            // Best-effort storage cleanup; HTML edit already applied.
        });
    };

    window.AdminBlogImages = AdminBlogImages;
})(window);
