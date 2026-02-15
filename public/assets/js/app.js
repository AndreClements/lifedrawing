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
