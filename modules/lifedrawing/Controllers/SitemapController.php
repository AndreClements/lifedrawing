<?php

declare(strict_types=1);

namespace Modules\Lifedrawing\Controllers;

use App\Request;
use App\Response;

/**
 * Sitemap Controller — generates dynamic XML sitemap.
 *
 * Includes all public pages: static pages, sessions, artworks, and profiles.
 * Submitted to search engines via robots.txt reference.
 */
final class SitemapController extends BaseController
{
    public function index(Request $request): Response
    {
        $baseUrl = rtrim(config('app.url', ''), '/');

        $urls = [];

        // Static pages
        $urls[] = ['loc' => $baseUrl . '/', 'priority' => '1.0', 'changefreq' => 'weekly'];
        $urls[] = ['loc' => $baseUrl . '/sessions', 'priority' => '0.9', 'changefreq' => 'daily'];
        $urls[] = ['loc' => $baseUrl . '/gallery', 'priority' => '0.8', 'changefreq' => 'weekly'];
        $urls[] = ['loc' => $baseUrl . '/artists', 'priority' => '0.7', 'changefreq' => 'weekly'];
        $urls[] = ['loc' => $baseUrl . '/sitters', 'priority' => '0.7', 'changefreq' => 'weekly'];
        $urls[] = ['loc' => $baseUrl . '/faq', 'priority' => '0.6', 'changefreq' => 'monthly'];
        $urls[] = ['loc' => $baseUrl . '/pose', 'priority' => '0.6', 'changefreq' => 'monthly'];

        // All sessions
        $sessions = $this->db->fetchAll(
            "SELECT id, title, session_date, updated_at FROM ld_sessions ORDER BY session_date DESC"
        );
        foreach ($sessions as $session) {
            $urls[] = [
                'loc' => $baseUrl . '/sessions/' . hex_id((int) $session['id'], session_title($session)),
                'lastmod' => date('Y-m-d', strtotime($session['updated_at'] ?? $session['session_date'])),
                'priority' => '0.7',
                'changefreq' => 'monthly',
            ];
        }

        // All public artworks
        $artworks = $this->db->fetchAll(
            "SELECT a.id, a.caption, a.created_at FROM ld_artworks a
             WHERE a.visibility IN ('claimed', 'public')
             ORDER BY a.created_at DESC"
        );
        foreach ($artworks as $artwork) {
            $urls[] = [
                'loc' => $baseUrl . '/artworks/' . hex_id((int) $artwork['id'], $artwork['caption'] ?? ''),
                'lastmod' => date('Y-m-d', strtotime($artwork['created_at'])),
                'priority' => '0.5',
                'changefreq' => 'monthly',
            ];
        }

        // All consented user profiles with at least one session
        $profiles = $this->db->fetchAll(
            "SELECT u.id, u.display_name FROM users u
             WHERE u.consent_state = 'granted'
               AND (SELECT COUNT(*) FROM ld_session_participants sp WHERE sp.user_id = u.id) > 0
             ORDER BY u.display_name"
        );
        foreach ($profiles as $profile) {
            $urls[] = [
                'loc' => $baseUrl . '/profile/' . hex_id((int) $profile['id'], $profile['display_name']),
                'priority' => '0.4',
                'changefreq' => 'monthly',
            ];
        }

        // Build XML
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $url) {
            $xml .= "  <url>\n";
            $xml .= "    <loc>" . htmlspecialchars($url['loc'], ENT_XML1, 'UTF-8') . "</loc>\n";
            if (!empty($url['lastmod'])) {
                $xml .= "    <lastmod>" . $url['lastmod'] . "</lastmod>\n";
            }
            if (!empty($url['changefreq'])) {
                $xml .= "    <changefreq>" . $url['changefreq'] . "</changefreq>\n";
            }
            if (!empty($url['priority'])) {
                $xml .= "    <priority>" . $url['priority'] . "</priority>\n";
            }
            $xml .= "  </url>\n";
        }

        $xml .= "</urlset>\n";

        return Response::html($xml)->withHeader('Content-Type', 'application/xml; charset=utf-8');
    }
}
