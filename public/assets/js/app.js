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
