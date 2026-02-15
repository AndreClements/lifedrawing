<?php

declare(strict_types=1);

namespace Modules\Lifedrawing\Controllers;

use App\Request;
use App\Response;

/**
 * Static pages controller.
 *
 * Serves content pages (FAQ, about, etc.) that don't need
 * dynamic data or authentication.
 */
final class PageController extends BaseController
{
    public function faq(Request $request): Response
    {
        return $this->render('pages.faq', [], 'FAQ');
    }
}
