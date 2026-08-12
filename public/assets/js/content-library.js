/* Content Library page logic — boot config from window.ContentLibraryBoot */
(function () {
'use strict';
const boot = window.ContentLibraryBoot || {};
const libraryUpdateUrl = boot.libraryUpdateUrl;
const libraryContentUrl = boot.libraryContentUrl;
const libraryImageUploadUrl = boot.libraryImageUploadUrl;
const libraryOrderUrlBase = boot.libraryOrderUrlBase;
const libraryPreviewUrlBase = boot.libraryPreviewUrlBase;
const libraryCsrf = boot.libraryCsrf;
const libraryLanguageCountryMap = boot.libraryLanguageCountryMap || {};
const libraryCountryLanguageMap = boot.libraryCountryLanguageMap || {};
const libraryPreferredCountry = boot.libraryPreferredCountry || '';
const libraryPreferredLanguage = boot.libraryPreferredLanguage || '';
const libraryUploadsEnabled = !!boot.uploadsEnabled;
const libraryOpenUpload = !!boot.openUpload;
const libraryEditSubmission = boot.editSubmission || null;

let articleQuill = null;
let articleEditorSubmissionId = null;
let articleEditorDetectedLinks = [];
let previewModalState = { title: '', submissionId: null, editable: false, html: '' };

function refreshLibraryLanguages(preferredLanguage) {
    const countrySelect = document.getElementById('libraryCountry');
    const langSelect = document.getElementById('libraryLanguage');
    if (!countrySelect || !langSelect) return;
    const country = (countrySelect.value || '').toLowerCase();
    const options = libraryCountryLanguageMap[country] || [];
    const keep = (preferredLanguage || langSelect.value || '').toLowerCase();
    langSelect.innerHTML = '';
    if (!country) {
        langSelect.disabled = true;
        langSelect.innerHTML = '<option value="">Select country first</option>';
        return;
    }
    langSelect.disabled = false;
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Select language';
    langSelect.appendChild(placeholder);
    options.forEach(function (item) {
        const opt = document.createElement('option');
        opt.value = item.code;
        opt.textContent = item.name;
        if (keep && keep === item.code) opt.selected = true;
        langSelect.appendChild(opt);
    });
    if (options.length === 1) {
        langSelect.value = options[0].code;
    } else if (keep && !Array.from(langSelect.options).some(function (o) { return o.value === keep; })) {
        langSelect.value = '';
    }
}
document.getElementById('libraryCountry')?.addEventListener('change', function () {
    refreshLibraryLanguages('');
});
document.addEventListener('DOMContentLoaded', function () {
    if (libraryPreferredCountry) {
        const countrySelect = document.getElementById('libraryCountry');
        if (countrySelect) countrySelect.value = libraryPreferredCountry;
    }
    refreshLibraryLanguages(libraryPreferredLanguage);
});
document.getElementById('uploadContentModal')?.addEventListener('shown.bs.modal', function () {
    refreshLibraryLanguages(libraryPreferredLanguage || document.getElementById('libraryLanguage')?.value || '');
});

function escapeHtml(str) {
    if (str == null || str === '') return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function setFeedbackHtml(el, ok, message) {
    if (!el) return;
    el.innerHTML = '<span class="text-' + (ok ? 'success' : 'danger') + '">' + escapeHtml(message) + '</span>';
}

function showLibraryFlash(message, ok) {
    const el = document.getElementById('libraryFlash');
    if (!el) return;
    el.className = 'alert alert-' + (ok ? 'success' : 'danger');
    el.textContent = message;
    el.classList.remove('d-none');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function openPreviewModal(title, html, links, submissionId, editable) {
    const tools = window.ArticlePreviewTools;
    previewModalState = {
        title: title || 'Article preview',
        submissionId: submissionId || null,
        editable: !!editable && !!submissionId,
        html: html || '',
    };
    document.getElementById('articlePreviewTitle').textContent = previewModalState.title;
    const body = document.getElementById('articlePreviewBody');
    body.innerHTML = html || '';
    fixPreviewImages(body);
    if (tools) tools.enhanceImages(body);

    const heading = tools ? tools.extractHeading(body, previewModalState.title) : previewModalState.title;
    const hint = document.getElementById('articlePreviewHeadingHint');
    if (hint) hint.textContent = heading ? ('Heading: ' + heading) : '';

    const list = document.getElementById('articlePreviewLinksList');
    const saveBtn = document.getElementById('articleLinksSaveBtn');
    const help = document.getElementById('articleLinksHelp');
    let linkRows = Array.isArray(links) ? links : [];
    if ((!linkRows.length) && tools && html) {
        linkRows = tools.extractLinksFromHtml(html);
    }
    if (tools) tools.renderLinkRows(list, linkRows, previewModalState.editable);
    if (saveBtn) saveBtn.classList.toggle('d-none', !previewModalState.editable);
    if (help) {
        help.textContent = previewModalState.editable
            ? 'Edit any anchor or URL, then save. The first link is used for checkout.'
            : 'Shown outside the article so you can review every anchor and URL.';
    }

    new bootstrap.Modal(document.getElementById('articlePreviewModal')).show();
}

async function fetchSubmissionPayload(submissionId) {
    const res = await fetch(libraryPreviewUrlBase + '/' + submissionId + '/preview', {
        headers: { 'Accept': 'application/json' },
    });
    const data = await res.json();
    if (!res.ok || !data.success) {
        throw new Error((data && data.message) || 'Could not load article');
    }
    return data;
}

document.querySelectorAll('.js-open-preview').forEach(function (btn) {
    btn.addEventListener('click', async function () {
        const id = btn.getAttribute('data-submission-id');
        if (!id) {
            showLibraryFlash('Could not open preview', false);
            return;
        }
        btn.disabled = true;
        try {
            const payload = await fetchSubmissionPayload(id);
            openPreviewModal(
                payload.title || 'Article preview',
                payload.html || payload.preview_html || '',
                payload.links || payload.detected_links || [],
                payload.id || parseInt(id, 10),
                !!payload.editable
            );
        } catch (e) {
            console.error('Failed to open preview', e);
            showLibraryFlash(e.message || 'Could not open preview', false);
        } finally {
            btn.disabled = false;
        }
    });
});

document.querySelectorAll('.js-open-editor').forEach(function (btn) {
    btn.addEventListener('click', async function () {
        const id = btn.getAttribute('data-submission-id');
        if (!id) {
            showLibraryFlash('Could not open editor', false);
            return;
        }
        btn.disabled = true;
        try {
            const payload = await fetchSubmissionPayload(id);
            openArticleEditor({
                id: payload.id || parseInt(id, 10),
                title: payload.title,
                country: payload.country,
                language: payload.language,
                preview_html: payload.preview_html || payload.html || '',
                word_count: payload.word_count,
                moderation_status: payload.moderation_status,
                can_order: !!payload.can_order,
                anchor_text: payload.anchor_text,
                target_url: payload.target_url,
                detected_links: payload.detected_links || payload.links || [],
                feature_image_url: payload.feature_image_url || null,
            });
        } catch (e) {
            console.error('Failed to open editor', e);
            showLibraryFlash(e.message || 'Could not open editor', false);
        } finally {
            btn.disabled = false;
        }
    });
});

document.getElementById('articleCopyHeadingBtn')?.addEventListener('click', async function () {
    const tools = window.ArticlePreviewTools;
    const body = document.getElementById('articlePreviewBody');
    const heading = tools ? tools.extractHeading(body, previewModalState.title) : previewModalState.title;
    if (!tools) {
        showLibraryFlash('Copy tools failed to load', false);
        return;
    }
    try {
        await tools.copyText(heading);
        tools.toast('Heading copied');
    } catch (e) {
        tools.toast('Could not copy heading', false);
    }
});

document.getElementById('articleCopyContentBtn')?.addEventListener('click', async function () {
    const tools = window.ArticlePreviewTools;
    const body = document.getElementById('articlePreviewBody');
    if (!tools) {
        showLibraryFlash('Copy tools failed to load', false);
        return;
    }
    try {
        await tools.copyHtml(body.innerHTML, body.innerText);
        tools.toast('Article copied — paste into your CMS');
    } catch (e) {
        tools.toast('Could not copy article', false);
    }
});

document.getElementById('articleLinksSaveBtn')?.addEventListener('click', async function () {
    const tools = window.ArticlePreviewTools;
    if (!previewModalState.editable || !previewModalState.submissionId) return;
    if (!tools) {
        showLibraryFlash('Preview tools failed to load', false);
        return;
    }
    const links = tools.readLinkRows(document.getElementById('articlePreviewLinksList'));
    const btn = this;
    btn.disabled = true;
    try {
        const res = await fetch(libraryUpdateUrl + '/' + previewModalState.submissionId, {
            method: 'PATCH',
            headers: {
                'X-CSRF-TOKEN': libraryCsrf,
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                links: links,
                preview_html: document.getElementById('articlePreviewBody').innerHTML,
            }),
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
            tools.toast((data && data.message) || 'Could not save links', false);
            return;
        }
        const sub = data.submission || {};
        const html = sub.preview_html || document.getElementById('articlePreviewBody').innerHTML;
        const stillApproved = data.approved !== false;
        const editable = stillApproved;
        openPreviewModal(sub.title || previewModalState.title, html, sub.detected_links || links, previewModalState.submissionId, editable);
        if (!stillApproved) {
            const msg = data.message || (data.report && data.report.summary) || 'Content moderation failed after your link changes. Fix restricted links before ordering.';
            tools.toast(msg, false);
            showLibraryFlash(msg, false);
            setTimeout(function () { window.location.reload(); }, 1200);
        } else {
            tools.toast(data.message || 'Links saved — content re-checked and approved');
            if (data.approved === true) {
                showLibraryFlash(data.message || 'Article still approved after re-check.', true);
            }
        }
    } catch (e) {
        tools.toast('Network error while saving links', false);
    } finally {
        btn.disabled = false;
    }
});

/**
 * Rewrite absolute /storage/... image URLs onto the current origin so previews
 * still work when APP_URL differs from the browser host.
 */
function fixPreviewImages(root) {
    if (!root) return;
    root.querySelectorAll('img').forEach(function (img) {
        const src = img.getAttribute('src') || '';
        const match = src.match(/^(?:https?:)?\/\/[^/]+(\/storage\/.+)$/i);
        if (match) {
            img.setAttribute('src', match[1]);
        }
        img.addEventListener('error', function () {
            if (img.dataset.fallbackApplied) return;
            img.dataset.fallbackApplied = '1';
            // Last resort: if relative path failed and we still have an absolute, try same-origin.
            const again = (img.getAttribute('src') || '').match(/^(?:https?:)?\/\/[^/]+(\/storage\/.+)$/i);
            if (again) {
                img.setAttribute('src', again[1]);
                return;
            }
            img.alt = 'Image failed to load';
            img.style.outline = '1px dashed #e2e8f0';
            img.style.minHeight = '48px';
            img.style.background = '#f8fafc';
        });
    });
}

function ensureArticleQuill() {
    if (articleQuill || typeof Quill === 'undefined') {
        return articleQuill;
    }

    const toolbarOptions = [
        [{ header: [1, 2, 3, false] }],
        ['bold', 'italic', 'underline'],
        [{ list: 'ordered' }, { list: 'bullet' }],
        ['link', 'image'],
        ['clean'],
    ];

    articleQuill = new Quill('#articleQuillEditor', {
        theme: 'snow',
        placeholder: 'Edit your article…',
        modules: { toolbar: toolbarOptions },
    });

    const toolbar = articleQuill.getModule('toolbar');
    toolbar.addHandler('image', function () {
        const input = document.createElement('input');
        input.setAttribute('type', 'file');
        input.setAttribute('accept', 'image/png,image/jpeg,image/gif,image/webp');
        input.click();
        input.onchange = async function () {
            const file = input.files && input.files[0];
            if (!file) return;
            const feedback = document.getElementById('articleEditorFeedback');
            feedback.textContent = 'Uploading image…';
            const fd = new FormData();
            fd.append('image', file);
            try {
                const res = await fetch(libraryImageUploadUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': libraryCsrf, 'Accept': 'application/json' },
                    body: fd,
                });
                const data = await res.json();
                if (!res.ok || !data.success || !data.url) {
                    setFeedbackHtml(feedback, false, data.message || data.error || 'Image upload failed');
                    return;
                }
                const range = articleQuill.getSelection(true) || { index: articleQuill.getLength() };
                articleQuill.insertEmbed(range.index, 'image', data.url, 'user');
                articleQuill.setSelection(range.index + 1);
                setFeedbackHtml(feedback, true, 'Image added. You can remove it with Backspace/Delete.');
            } catch (e) {
                setFeedbackHtml(feedback, false, 'Network error while uploading image.');
            }
        };
    });

    return articleQuill;
}

function openArticleEditor(submission) {
    if (!submission || !submission.id) return;
    articleEditorSubmissionId = submission.id;
    articleEditorDetectedLinks = Array.isArray(submission.detected_links) ? submission.detected_links : [];
    ensureArticleQuill();
    document.getElementById('articleEditorTitle').value = submission.title || '';
    const market = ((submission.country || '') + '/' + (submission.language || '')).toUpperCase();
    const status = submission.moderation_status || '';
    document.getElementById('articleEditorMeta').textContent =
        market + (status ? ' · ' + status.replace(/_/g, ' ') : '') +
        (submission.word_count ? ' · ' + submission.word_count + ' words' : '');
    document.getElementById('articleEditorFeedback').textContent = '';
    if (articleQuill) {
        articleQuill.root.innerHTML = submission.preview_html || '<p><br></p>';
    }
    const orderBtn = document.getElementById('articleEditorOrderBtn');
    if (submission.can_order) {
        orderBtn.href = libraryOrderUrlBase + '/' + submission.id + '/order';
        orderBtn.classList.remove('d-none');
    } else {
        orderBtn.classList.add('d-none');
    }
    const uploadModalEl = document.getElementById('uploadContentModal');
    const uploadModal = bootstrap.Modal.getInstance(uploadModalEl);
    if (uploadModal) uploadModal.hide();
    new bootstrap.Modal(document.getElementById('articleEditorModal')).show();
}

/**
 * Only send a declaration when the editor is actually showing one, so a normal
 * text edit keeps whatever the article already declared.
 */
function articleEditorRightsPayload() {
    const wrap = document.getElementById('articleEditorImageRights');
    if (!wrap || wrap.classList.contains('d-none') || !window.readImageRights) {
        return {};
    }

    const rights = window.readImageRights(wrap);
    if (!rights.ok) {
        return {};
    }

    return rights.source
        ? { image_rights: rights.rights, image_rights_source: rights.source }
        : { image_rights: rights.rights };
}

async function saveArticleEditor() {
    if (!articleEditorSubmissionId || !articleQuill) return;
    const feedback = document.getElementById('articleEditorFeedback');
    const btn = document.getElementById('articleEditorSaveBtn');
    const html = articleQuill.root.innerHTML;
    const title = (document.getElementById('articleEditorTitle').value || '').trim();
    btn.disabled = true;
    feedback.textContent = 'Saving and re-checking content moderation…';
    try {
        const res = await fetch(libraryContentUrl + '/' + articleEditorSubmissionId + '/content', {
            method: 'PUT',
            headers: {
                'X-CSRF-TOKEN': libraryCsrf,
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(Object.assign(
                { preview_html: html, title: title },
                articleEditorRightsPayload()
            )),
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
            setFeedbackHtml(feedback, false, data.message || 'Could not save article.');
            // Images were added without a declaration that covers them — reveal
            // the declaration so it can be answered without leaving the editor.
            if (data.needs_image_rights) {
                document.getElementById('articleEditorImageRights')?.classList.remove('d-none');
            }
            btn.disabled = false;
            return;
        }
        const stillApproved = data.approved !== false;
        const msg = data.message
            || (stillApproved
                ? 'Article saved and re-approved.'
                : 'Article saved, but content moderation failed. Fix restricted links/keywords before ordering.');
        setFeedbackHtml(feedback, stillApproved, msg);
        if (data.submission) {
            openArticleEditor(data.submission);
        }
        if (!stillApproved) {
            showLibraryFlash(msg, false);
        }
        setTimeout(function () { window.location.reload(); }, stillApproved ? 900 : 1400);
    } catch (e) {
        setFeedbackHtml(feedback, false, 'Network error while saving.');
        btn.disabled = false;
    }
}

document.getElementById('articleEditorSaveBtn')?.addEventListener('click', saveArticleEditor);
document.getElementById('articleEditorPreviewBtn')?.addEventListener('click', function () {
    if (!articleQuill) return;
    const tools = window.ArticlePreviewTools;
    const html = articleQuill.root.innerHTML;
    let links = Array.isArray(articleEditorDetectedLinks) ? articleEditorDetectedLinks.slice() : [];
    if ((!links.length) && tools) {
        links = tools.extractLinksFromHtml(html);
    }
    openPreviewModal(
        document.getElementById('articleEditorTitle').value || 'Article preview',
        html,
        links,
        articleEditorSubmissionId,
        true
    );
});

function toggleLibraryTitleEdit(id, open) {
    const edit = document.querySelector('[data-title-edit="' + id + '"]');
    if (!edit) return;
    edit.classList.toggle('d-none', !open);
    if (open) {
        const input = document.querySelector('[data-title-input="' + id + '"]');
        input?.focus();
        input?.select();
    }
}

async function copyLibraryLiveUrl(btn) {
    const url = (btn?.getAttribute('data-copy-url') || '').trim();
    if (!url) return;
    const markCopied = function () {
        btn.classList.add('is-copied');
        const icon = btn.querySelector('i');
        if (icon) {
            icon.classList.remove('fa-copy');
            icon.classList.add('fa-check');
        }
        setTimeout(function () {
            btn.classList.remove('is-copied');
            if (icon) {
                icon.classList.remove('fa-check');
                icon.classList.add('fa-copy');
            }
        }, 1400);
    };
    try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(url);
            markCopied();
            return;
        }
    } catch (e) { /* fall through */ }
    const ta = document.createElement('textarea');
    ta.value = url;
    ta.setAttribute('readonly', '');
    ta.style.position = 'absolute';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    try {
        document.execCommand('copy');
        markCopied();
    } finally {
        document.body.removeChild(ta);
    }
}

async function saveLibraryTitle(id) {
    const input = document.querySelector('[data-title-input="' + id + '"]');
    if (!input) return;
    const title = (input.value || '').trim();
    try {
        const res = await fetch(libraryUpdateUrl + '/' + id, {
            method: 'PATCH',
            headers: {
                'X-CSRF-TOKEN': libraryCsrf,
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ title: title }),
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
            showLibraryFlash(data.message || 'Could not rename article.', false);
            return;
        }
        const display = document.querySelector('[data-title-display="' + id + '"]');
        const nextTitle = (data.submission && data.submission.title) || title || (data.submission && data.submission.original_filename) || 'Article';
        if (display) {
            display.textContent = nextTitle;
            display.title = nextTitle;
        }
        toggleLibraryTitleEdit(id, false);
        showLibraryFlash('Article renamed.', true);
    } catch (e) {
        showLibraryFlash('Network error while renaming.', false);
    }
}

async function deleteLibraryArticle(id, label) {
    const ok = await window.slbConfirm({
            title: 'Delete article?',
            text: 'Delete "' + (label || 'this article') + '"? This cannot be undone.',
            confirmText: 'Delete',
            danger: true,
        });
    if (!ok) {
        return;
    }
    try {
        const res = await fetch(libraryUpdateUrl + '/' + id, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': libraryCsrf, 'Accept': 'application/json' },
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
            showLibraryFlash(data.message || 'Could not delete article.', false);
            if (window.slbAlert) await window.slbAlert({ icon: 'error', title: data.message || 'Could not delete article.' });
            return;
        }
        document.getElementById('library-row-' + id)?.remove();
        showLibraryFlash('Article deleted.', true);
        if (window.slbAlert) await window.slbAlert({ icon: 'success', title: 'Article deleted.' });
    } catch (e) {
        showLibraryFlash('Network error while deleting.', false);
        if (window.slbAlert) await window.slbAlert({ icon: 'error', title: 'Network error while deleting.' });
    }
}

async function archiveLibraryArticle(id) {
    const ok = await window.slbConfirm({
            title: 'Archive article?',
            text: 'Archived articles are hidden from the active library. You can restore them later.',
            confirmText: 'Archive',
            icon: 'question',
        });
    if (!ok) {
        return;
    }
    try {
        const res = await fetch(libraryUpdateUrl + '/' + id + '/archive', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': libraryCsrf, 'Accept': 'application/json' },
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
            showLibraryFlash(data.message || 'Could not archive article.', false);
            if (window.slbAlert) await window.slbAlert({ icon: 'error', title: data.message || 'Could not archive article.' });
            return;
        }
        document.getElementById('library-row-' + id)?.remove();
        showLibraryFlash('Article archived.', true);
        if (window.slbAlert) await window.slbAlert({ icon: 'success', title: 'Article archived.' });
    } catch (e) {
        showLibraryFlash('Network error while archiving.', false);
        if (window.slbAlert) await window.slbAlert({ icon: 'error', title: 'Network error while archiving.' });
    }
}

async function restoreLibraryArticle(id) {
    const ok = await window.slbConfirm({
            title: 'Restore article?',
            text: 'Move this article back to the active library?',
            confirmText: 'Restore',
            icon: 'question',
        });
    if (!ok) {
        return;
    }
    try {
        const res = await fetch(libraryUpdateUrl + '/' + id + '/restore', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': libraryCsrf, 'Accept': 'application/json' },
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
            showLibraryFlash(data.message || 'Could not restore article.', false);
            if (window.slbAlert) await window.slbAlert({ icon: 'error', title: data.message || 'Could not restore article.' });
            return;
        }
        document.getElementById('library-row-' + id)?.remove();
        showLibraryFlash('Article restored.', true);
        if (window.slbAlert) await window.slbAlert({ icon: 'success', title: 'Article restored.' });
    } catch (e) {
        showLibraryFlash('Network error while restoring.', false);
        if (window.slbAlert) await window.slbAlert({ icon: 'error', title: 'Network error while restoring.' });
    }
}

document.getElementById('libraryUploadForm')?.addEventListener('submit', async function (e) {
    e.preventDefault();
    const fileInput = document.getElementById('libraryFileInput');
    const file = fileInput.files && fileInput.files[0];
    const feedback = document.getElementById('libraryUploadFeedback');
    const btn = document.getElementById('libraryUploadBtn');
    const progress = document.getElementById('libraryUploadProgress');
    const bar = progress.querySelector('.progress-bar');

    if (!file) return;
    if (!/\.docx$/i.test(file.name)) {
        setFeedbackHtml(feedback, false, 'Please upload a Microsoft Word (.docx) document only.');
        return;
    }
    if (!document.getElementById('libraryCountry').value || !document.getElementById('libraryLanguage').value) {
        setFeedbackHtml(feedback, false, 'Please select country and language before uploading.');
        return;
    }
    const rights = window.readImageRights ? window.readImageRights(this) : { ok: true };
    if (!rights.ok) {
        setFeedbackHtml(feedback, false, rights.message);
        return;
    }

    const fd = new FormData(this);
    btn.disabled = true;
    progress.classList.remove('d-none');
    bar.style.width = '40%';
    feedback.textContent = 'Uploading your article…';

    try {
        const res = await fetch(boot.uploadUrl, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': libraryCsrf, 'Accept': 'application/json' },
            body: fd,
        });
        bar.style.width = '100%';
        const data = await res.json();
        if (!data.success) {
            setFeedbackHtml(feedback, false, data.message || 'Upload failed');
            btn.disabled = false;
            return;
        }
        setFeedbackHtml(feedback, true, (data.message || 'Uploaded') + ' Opening editor…');
        if (data.submission) {
            openArticleEditor(Object.assign({}, data.submission, {
                can_order: !!(data.submission.can_order || data.approved),
            }));
        } else {
            setTimeout(function () { window.location.href = boot.libraryIndexUrl; }, 800);
        }
        btn.disabled = false;
        progress.classList.add('d-none');
        bar.style.width = '0%';
    } catch (err) {
        setFeedbackHtml(feedback, false, 'Network error while uploading.');
        btn.disabled = false;
    }
});

if (libraryOpenUpload && libraryUploadsEnabled) {
    document.addEventListener('DOMContentLoaded', function () {
        new bootstrap.Modal(document.getElementById('uploadContentModal')).show();
    });
}

if (window.location.hash === '#upload' && libraryUploadsEnabled) {
    document.addEventListener('DOMContentLoaded', function () {
        new bootstrap.Modal(document.getElementById('uploadContentModal')).show();
    });
}
})();
