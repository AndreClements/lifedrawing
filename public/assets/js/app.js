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
    if (!item || !item.dataset.userId) return;

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

/* Confirm dialog for destructive actions (CSP-safe, no inline handlers) */
document.addEventListener('submit', function(e) {
    var form = e.target.closest('.confirm-action');
    if (!form) return;
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

/* Upload progress bar — intercepts upload form, shows real-time progress */
document.addEventListener('submit', function(e) {
    var form = e.target;
    if (!form.querySelector('#upload-progress')) return;

    var fileInput = form.querySelector('input[type="file"]');
    if (!fileInput || !fileInput.files || fileInput.files.length === 0) return;

    e.preventDefault();

    var progress = document.getElementById('upload-progress');
    var bar = document.getElementById('upload-progress-bar');
    var text = document.getElementById('upload-progress-text');
    var btn = document.getElementById('upload-btn');
    var errorDiv = form.closest('section').querySelector('.alert-error');
    var successDiv = form.closest('section').querySelector('.alert-success');

    // Hide any previous messages
    if (errorDiv) errorDiv.style.display = 'none';
    if (successDiv) successDiv.style.display = 'none';

    // Show progress, disable button
    progress.hidden = false;
    bar.style.width = '0%';
    text.textContent = 'Uploading...';
    btn.disabled = true;
    btn.textContent = 'Uploading...';

    var data = new FormData(form);
    var xhr = new XMLHttpRequest();

    xhr.upload.addEventListener('progress', function(evt) {
        if (evt.lengthComputable) {
            var pct = Math.round((evt.loaded / evt.total) * 100);
            bar.style.width = pct + '%';
            text.textContent = 'Uploading... ' + pct + '%';
        }
    });

    xhr.addEventListener('load', function() {
        try {
            var resp = JSON.parse(xhr.responseText);
            if (resp.success) {
                bar.style.width = '100%';
                text.textContent = resp.message || 'Done!';
                // Reset form for next batch
                fileInput.value = '';
                btn.disabled = false;
                btn.textContent = 'Upload Batch';
                // Show success message
                if (!successDiv) {
                    successDiv = document.createElement('div');
                    successDiv.className = 'alert alert-success';
                    form.parentNode.insertBefore(successDiv, form);
                }
                successDiv.textContent = resp.message;
                successDiv.style.display = '';
                // Fade out progress after a moment
                setTimeout(function() { progress.hidden = true; }, 2000);
            } else {
                bar.style.width = '100%';
                bar.style.background = 'var(--color-error, #c33)';
                text.textContent = resp.error || 'Upload failed';
                btn.disabled = false;
                btn.textContent = 'Upload Batch';
            }
        } catch (ex) {
            // Non-JSON response — likely a redirect or HTML error page
            // Fall back to reloading
            window.location.reload();
        }
    });

    xhr.addEventListener('error', function() {
        bar.style.width = '100%';
        bar.style.background = 'var(--color-error, #c33)';
        text.textContent = 'Network error — please retry';
        btn.disabled = false;
        btn.textContent = 'Upload Batch';
    });

    xhr.open('POST', form.action);
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.send(data);
});
