<?php

declare(strict_types=1);

/**
 * View helper functions.
 *
 * These are available in all templates. They handle the repetitive
 * security and rendering concerns so templates stay clean.
 */

/** Escape HTML entities — the default output filter. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Generate a CSRF token hidden input. */
function csrf_field(): string
{
    $token = $_SESSION['_csrf_token'] ?? '';
    return '<input type="hidden" name="_csrf_token" value="' . e($token) . '">';
}

/** Generate a method spoofing hidden input for PUT/DELETE. */
function method_field(string $method): string
{
    return '<input type="hidden" name="_method" value="' . e(strtoupper($method)) . '">';
}

/** Generate an asset URL with cache-busting. */
function asset(string $path): string
{
    $basePath = config('app.base_path', '/lifedrawing/public');
    $fullPath = LDR_ROOT . '/public/assets/' . ltrim($path, '/');
    $version = file_exists($fullPath) ? filemtime($fullPath) : 0;
    return rtrim($basePath, '/') . '/assets/' . ltrim($path, '/') . '?v=' . $version;
}

/** Get the URL for a named route. */
function route(string $name, array $params = []): string
{
    return app('router')->url($name, $params);
}

/** Format a date nicely. */
function format_date(string $date, string $format = 'D j M Y'): string
{
    return date($format, strtotime($date));
}

/** Output an active class if the current path matches. */
function active_if(string $path): string
{
    $current = $_SERVER['REQUEST_URI'] ?? '';
    return str_contains($current, $path) ? ' class="active"' : '';
}

/** Truncate text with ellipsis. */
function excerpt(string $text, int $length = 150): string
{
    if (mb_strlen($text) <= $length) {
        return $text;
    }
    return mb_substr($text, 0, $length) . '...';
}

/** Format a pose duration in seconds to a human-readable string. */
function format_duration(int $seconds): string
{
    if ($seconds < 60) {
        return $seconds . 's';
    }
    $minutes = $seconds / 60;
    if ($minutes == (int) $minutes) {
        return (int) $minutes . ' min';
    }
    return rtrim(rtrim(number_format($minutes, 1), '0'), '.') . ' min';
}

/** Generate a full absolute URL from a route path. */
function full_url(string $path): string
{
    $base = rtrim(config('app.url', 'http://localhost/lifedrawing'), '/');
    $basePath = rtrim(config('app.base_path', '/lifedrawing/public'), '/');
    return $base . '/public' . '/' . ltrim(str_replace($basePath, '', $path), '/');
}

/** Generate social share buttons for a given URL and text. */
function share_buttons(string $url, string $text = ''): string
{
    $encodedUrl = urlencode($url);
    $encodedText = urlencode($text ?: 'Life Drawing Randburg');
    $id = 'share-' . substr(md5($url), 0, 8);

    return '<div class="share-buttons">'
        . '<a href="https://www.facebook.com/sharer/sharer.php?u=' . $encodedUrl . '" target="_blank" rel="noopener" class="share-btn share-fb" title="Share on Facebook">FB</a>'
        . '<a href="https://twitter.com/intent/tweet?url=' . $encodedUrl . '&text=' . $encodedText . '" target="_blank" rel="noopener" class="share-btn share-x" title="Share on X">X</a>'
        . '<a href="https://wa.me/?text=' . $encodedText . '%20' . $encodedUrl . '" target="_blank" rel="noopener" class="share-btn share-wa" title="Share on WhatsApp">WA</a>'
        . '<button type="button" class="share-btn share-copy" title="Copy link" onclick="navigator.clipboard.writeText(\'' . e($url) . '\');this.textContent=\'Copied!\';setTimeout(()=>this.textContent=\'Link\',1500)">Link</button>'
        . '</div>';
}

/**
 * Can the current viewer see real names?
 * Requires: logged in AND (facilitator/admin OR participated in at least one session).
 * Cached per request — one DB query at most.
 */
function can_see_names(): bool
{
    static $result = null;
    if ($result !== null) return $result;

    $auth = app('auth');
    if (!$auth->isLoggedIn()) {
        return $result = false;
    }
    if ($auth->hasRole('admin', 'facilitator')) {
        return $result = true;
    }

    $userId = $auth->currentUserId();
    $row = app('db')->fetch(
        "SELECT 1 FROM ld_session_participants WHERE user_id = ? LIMIT 1",
        [$userId]
    );
    return $result = ($row !== null && $row !== false);
}

/**
 * Display a name, masked for non-participant viewers.
 * Use for inline display_name references that don't go through profile_name().
 */
function visible_name(?string $name, string $fallback = 'Participant'): string
{
    if ($name === null || $name === '') return $fallback;
    return can_see_names() ? e($name) : $fallback;
}

/**
 * Display a user's name with pseudonym logic + participation gate.
 * Non-participants: pseudonym if set, else generic fallback.
 * Participants: display_name + pseudonym subtitle.
 */
function profile_name(array $user, bool $withSubtitle = true): string
{
    $canSee = can_see_names();
    $name = e($user['display_name']);
    $pseudonym = $user['pseudonym'] ?? null;

    if (!$canSee && $pseudonym) {
        return e($pseudonym);
    }
    if (!$canSee) {
        return 'Participant';
    }
    if ($pseudonym && $withSubtitle) {
        return $name . '<span class="pseudonym">' . e($pseudonym) . '</span>';
    }
    return $name;
}

/** Generate a hex ID with optional slug: 1a-saturday-open-session. */
function hex_id(int $id, string $text = ''): string
{
    $hex = dechex($id);
    if ($text === '') return $hex;
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($text)));
    return $hex . '-' . trim($slug, '-');
}

/** Parse a hex ID from a slug parameter. Inverse of hex_id(). */
function from_hex(string $param): int
{
    $hex = explode('-', $param, 2)[0];
    return (int) hexdec($hex);
}

/** Get an axiom by key, or a random rotating one. */
function axiom(string $key = 'rotating'): string
{
    static $axioms = null;
    $axioms ??= config('axioms') ?? [];
    if ($key === 'rotating') {
        $pool = $axioms['rotating'] ?? [];
        return $pool ? $pool[array_rand($pool)] : '';
    }
    return $axioms[$key] ?? '';
}

/** Get a deterministic axiom from the rotating pool (stable per seed value). */
function axiom_at(int $seed): string
{
    static $pool = null;
    $pool ??= config('axioms')['rotating'] ?? [];
    return $pool ? $pool[$seed % count($pool)] : '';
}

/** Display title for a session — explicit title if set, else deterministic axiom.
 *  Works with both $session (has 'title','id') and $artwork (has 'session_title','session_id'). */
function session_title(array $row): string
{
    $title = $row['title'] ?? $row['session_title'] ?? null;
    if (!empty($title)) return $title;
    $id = (int) ($row['id'] ?? $row['session_id'] ?? 0);
    return axiom_at($id);
}
