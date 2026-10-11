/**
 * Site-wide UI interactions.
 * Loaded on every page via layouts/main.php. CSP-compliant (no inline scripts).
 */

/* Password show/hide toggle — event delegation on .password-toggle buttons */
document.addEventListener('click', function(e) {
    if (!e.target.classList.contains('password-toggle')) return;
    var wrapper = e.target.closest('.password-wrapper');
    if (!wrapper) return;
    var input = wrapper.querySelector('input');
    if (!input) return;
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    e.target.textContent = show ? 'Hide' : 'Show';
});

/* Copy to clipboard — for WhatsApp schedule and similar */
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.copy-schedule');
    if (!btn) return;
    var targetId = btn.getAttribute('data-target');
    var textarea = document.getElementById(targetId);
    if (!textarea) return;
    navigator.clipboard.writeText(textarea.value).then(function() {
        var orig = btn.textContent;
        btn.textContent = 'Copied!';
        setTimeout(function() { btn.textContent = orig; }, 1500);
    });
});

/* Participant search: click a result to add them via the hidden form.
 * Search results are plain divs with data-user-id. Clicking one fills
 * the hidden #add-participant-form and triggers HTMX submission. */
document.addEventListener('click', function(e) {
    var item = e.target.closest('.search-result-item');
    if (!item || !item.dataset.userId || e.target.closest('a')) return;

    var manager = document.getElementById('participant-manager');
    if (!manager) return;

    // Read current role/tentative selections
    var role = (manager.querySelector('input[name="add-role"]:checked') || {}).value || 'artist';
    var tentative = manager.querySelector('input[name="add-tentative"]');
    var isTentative = tentative && tentative.checked ? '1' : '0';

    // Fill hidden form
    document.getElementById('add-user-id').value = item.dataset.userId;
    document.getElementById('add-role-field').value = role;
    document.getElementById('add-tentative-field').value = isTentative;

    // Trigger HTMX submission on the hidden form
    var form = document.getElementById('add-participant-form');
    if (form) htmx.trigger(form, 'submit');
});

/* Confirm dialog for destructive actions (CSP-safe, no inline handlers)
 *
 * Two handlers, because one is not enough.
 *
 * The document-level 'submit' listener below works for ordinary forms, but it
 * CANNOT stop an HTMX request. HTMX binds its handler on the form element, so
 * it fires during the target phase, before the event bubbles up to document —
 * by the time this runs, the request is already away and preventDefault() does
 * nothing. Every .confirm-action form used to be a plain POST, which is why
 * that was never visible.
 *
 * So HTMX-driven forms go through htmx:confirm instead, reading the same
 * data-confirm attribute. One convention, correct for both.
 */
document.addEventListener('htmx:confirm', function(e) {
    var el = e.detail.elt;
    if (!el || !el.closest('.confirm-action')) return;

    var message = el.closest('.confirm-action').getAttribute('data-confirm') || 'Are you sure?';

    e.preventDefault();                       // hold the request
    if (confirm(message)) {
        e.detail.issueRequest();              // let it go
    }
});

document.addEventListener('submit', function(e) {
    var form = e.target.closest('.confirm-action');
    if (!form) return;
    // Already handled by htmx:confirm above — asking twice is worse than not
    // asking, because people learn to click through the first one.
    if (form.hasAttribute('hx-post') || form.hasAttribute('hx-get')
        || form.hasAttribute('hx-delete') || form.hasAttribute('hx-put')) {
        return;
    }
    var message = form.getAttribute('data-confirm') || 'Are you sure?';
    if (!confirm(message)) {
        e.preventDefault();
    }
});

/* Close search results when clicking outside the search wrapper */
document.addEventListener('click', function(e) {
    if (e.target.closest('.search-wrapper')) return;
    var results = document.getElementById('search-results');
    if (results) results.innerHTML = '';
});

/* Stub account claiming on registration form */
document.addEventListener('click', function(e) {
    var item = e.target.closest('.stub-result-item');
    if (!item || !item.dataset.stubId) return;

    var hidden = document.getElementById('claim-stub-id');
    if (hidden) hidden.value = item.dataset.stubId;

    var name = item.querySelector('.stub-result-name');
    var sessions = item.querySelector('.stub-result-sessions');
    var status = document.getElementById('stub-claim-status');
    if (status && name) {
        status.textContent = 'Will claim history for: ' + name.textContent + ' (' + (sessions ? sessions.textContent : '') + ')';
        status.classList.add('stub-claimed');
    }

    var nameInput = document.getElementById('display_name');
    if (nameInput && name) {
        nameInput.value = name.textContent;
    }

    var results = document.getElementById('stub-results');
    if (results) results.innerHTML = '';
});

/* Close stub results when clicking outside */
document.addEventListener('click', function(e) {
    if (e.target.closest('.stub-results') || e.target.id === 'display_name') return;
    var results = document.getElementById('stub-results');
    if (results) results.innerHTML = '';
});

/* Gallery session filter — navigate on select change (CSP-safe, no inline handler) */
document.addEventListener('change', function(e) {
    var select = e.target.closest('#session-filter');
    if (!select || !select.dataset.filterUrl) return;
    var url = select.dataset.filterUrl;
    if (select.value) url += '?session=' + select.value;
    window.location = url;
});

/* HTMX error handling — surface consent/auth errors instead of silent failure */
document.addEventListener('htmx:responseError', function(e) {
    var xhr = e.detail.xhr;
    var consentUrl = (document.querySelector('meta[name="consent-url"]') || {}).content || '/consent';
    var loginUrl = (document.querySelector('meta[name="login-url"]') || {}).content || '/login';
    if (xhr.status === 403) {
        try {
            var data = JSON.parse(xhr.responseText);
            if (data.consent_state && data.consent_state !== 'granted') {
                window.location = consentUrl;
                return;
            }
        } catch (ex) { /* not JSON, fall through */ }
        alert('This action is not permitted.');
    } else if (xhr.status === 401) {
        window.location = loginUrl;
    }
});

/* Sitter notes inline edit toggle — CSP-safe event delegation */
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.sitter-notes-edit');
    if (!btn) return;
    var container = btn.closest('.queue-sitter-notes');
    if (!container) return;
    var form = container.querySelector('.sitter-notes-form');
    if (form) form.classList.toggle('hidden');
});

/* Private session notes: separate requests and DOM targets from image uploads. */
document.addEventListener('input', function(e) {
    var form = e.target.closest('[data-note-create]');
    if (form) form.dataset.dirty = '1';
});
document.addEventListener('change', function(e) {
    var form = e.target.closest('[data-note-create]');
    if (form) form.dataset.dirty = '1';
});
document.addEventListener('click', function(e) {
    var chip = e.target.closest('[data-note-kind]');
    if (chip) {
        var form = document.querySelector('[data-note-create]');
        if (!form || form._saving) return;
        form.elements.kind.value = chip.dataset.noteKind;
        if (chip.dataset.noteText) form.elements.text.value = chip.dataset.noteText;
        if (chip.hasAttribute('data-sitter-id')) form.elements.participant_id.value = chip.dataset.sitterId;
        form.dataset.dirty = '1';
        form.elements.text.focus();
    }
    var copy = e.target.closest('[data-copy]');
    if (copy && navigator.clipboard) navigator.clipboard.writeText(copy.dataset.copy).then(function() {
        copy.textContent = 'Copied';
    }).catch(function() { copy.textContent = 'Select the text to copy'; });
    var confirmButton = e.target.closest('button[data-confirm]');
    if (confirmButton && !confirm(confirmButton.dataset.confirm)) {
        e.preventDefault();
        e.stopImmediatePropagation();
    }
});
document.addEventListener('keydown', function(e) {
    var form = e.target.closest('[data-note-create]');
    if (form && (e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault();
        form.requestSubmit();
    }
});
async function saveSessionNoteForm(form, submitter) {
    if (form._saving) return form._saving;
    form._saving = (async function() {
        var feedback = form.querySelector('.save-feedback');
        var data = new FormData(form);
        var controls = Array.from(form.querySelectorAll('input, select, textarea, button')).filter(function(control) { return !control.disabled; });
        if (submitter && submitter.name) data.set(submitter.name, submitter.value);
        controls.forEach(function(control) { control.disabled = true; });
        if (feedback) feedback.textContent = 'Saving…';
        try {
            var response = await fetch(form.action, {method: 'POST', body: data, headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
            var result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.error || 'Could not save. Your input has been kept.');
            var panel = document.getElementById('session-notes');
            if (panel && result.notes_html) {
                // Preserve a separate unfinished draft while an existing note is edited.
                var draft = panel.querySelector('[data-note-create]');
                var keep = draft && draft !== form && draft.dataset.dirty === '1' ? new FormData(draft) : null;
                panel.outerHTML = result.notes_html;
                var fresh = document.querySelector('[data-note-create]');
                if (fresh && keep) {
                    keep.forEach(function(value, name) { if (fresh.elements[name]) fresh.elements[name].value = value; });
                    fresh.dataset.dirty = '1';
                }
                var status = document.querySelector('#session-notes .save-feedback');
                if (status) status.textContent = result.message;
                if (window.htmx) htmx.process(document.getElementById('session-notes'));
            } else if (feedback) feedback.textContent = result.message;
            return true;
        } catch (error) {
            if (feedback) feedback.textContent = error.message || 'Could not save. Please retry.';
            return false;
        } finally {
            controls.forEach(function(control) { control.disabled = false; });
            form._saving = null;
        }
    })();
    return form._saving;
}
document.addEventListener('submit', function(e) {
    var form = e.target.closest('[data-manage-form]');
    if (!form) return;
    e.preventDefault();
    saveSessionNoteForm(form, e.submitter);
});
document.addEventListener('htmx:responseError', function(e) {
    if ([404, 409, 422, 500].indexOf(e.detail.xhr.status) === -1) return;
    var message = e.detail.xhr.responseText;
    try { message = JSON.parse(message).error || message; } catch (_) {}
    var status = document.getElementById('management-error');
    if (!status) {
        status = document.createElement('p');
        status.id = 'management-error';
        status.className = 'alert alert-error';
        status.setAttribute('role', 'alert');
        var target = e.detail.elt.closest('section') || document.querySelector('main');
        if (target) target.prepend(status);
    }
    status.textContent = message.replace(/<[^>]*>/g, '');
});

/* Uploads never reload the page on errors, or reset an unfinished note. */
document.addEventListener('submit', async function(e) {
    var form = e.target;
    if (!form.querySelector('#upload-progress')) return;
    e.preventDefault();
    if (form.dataset.uploading === '1') return;
    var fileInput = form.querySelector('input[type="file"]');
    if (!fileInput || !fileInput.files.length) return;
    form.dataset.uploading = '1';
    var btn = form.querySelector('#upload-btn');
    btn.disabled = true;
    var pending = document.querySelector('[data-note-create]');
    if (pending && (pending.dataset.dirty === '1' || pending._saving)) {
        if (!await saveSessionNoteForm(pending)) {
            form.dataset.uploading = '';
            btn.disabled = false;
            return;
        }
    }
    var progress = form.querySelector('#upload-progress');
    var bar = form.querySelector('#upload-progress-bar');
    var text = form.querySelector('#upload-progress-text');
    progress.hidden = false;
    bar.style.width = '0%';
    bar.style.background = '';
    text.textContent = 'Uploading…';
    btn.textContent = 'Uploading…';
    var xhr = new XMLHttpRequest();
    function finish(message, failed) {
        text.textContent = message;
        if (failed) bar.style.background = 'var(--color-error, #c33)';
        btn.disabled = false;
        btn.textContent = 'Upload Batch';
        form.dataset.uploading = '';
    }
    xhr.upload.addEventListener('progress', function(event) {
        if (!event.lengthComputable) return;
        var pct = Math.round(event.loaded / event.total * 100);
        bar.style.width = pct + '%';
        text.textContent = 'Uploading… ' + pct + '%';
    });
    xhr.addEventListener('load', function() {
        try {
            var result = JSON.parse(xhr.responseText);
            if (xhr.status >= 200 && xhr.status < 300 && result.success) {
                fileInput.value = '';
                if (result.batch_key) form.elements.batch_key.value = result.batch_key;
                bar.style.width = '100%';
                finish(result.message, false);
            } else finish(result.error || 'Upload failed. Your selection has been kept; please retry.', true);
        } catch (_) {
            finish('The server returned an unexpected response. Your files and notes have been kept; please retry.', true);
        }
    });
    xhr.addEventListener('error', function() { finish('Network error. Your files and notes have been kept; please retry.', true); });
    xhr.addEventListener('abort', function() { finish('Upload interrupted. Please retry this batch.', true); });
    xhr.open('POST', form.action);
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.send(new FormData(form));
});
