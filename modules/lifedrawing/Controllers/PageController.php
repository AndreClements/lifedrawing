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
        $faqItems = [
            ['question' => 'What is Life Drawing Randburg about?', 'answer' => 'A casual space conducive to going deep with your art practice, that is safe but also brave, inclusive, diverse and authentically creative. Regular sessions since 2017, limited to around 7 artists plus facilitator and model. All ability levels welcome.'],
            ['question' => 'How long or challenging will the poses be?', 'answer' => 'We start with gestural warm-up movement of around 20 minutes, then build up from 3 min to 9 min to 30 min poses, with a longer 45-60 min pose at the end. Shorter poses are dynamic (standing, crouching), longer ones are seated or reclining.'],
            ['question' => 'Do I have to be naked?', 'answer' => 'Yes, a large part of life-drawing is about figure study. However, we use drapes as loose sheets or sarongs. Your comfort is important — please discuss with the facilitator as necessary.'],
            ['question' => 'How much does it cost?', 'answer' => 'The suggested contribution is R 350 per session, or as near as is affordable. If you cancel less than 48 hours before a fully booked session, a 50% cancellation fee is appreciated.'],
            ['question' => 'Should I bring my own materials?', 'answer' => 'Yes for materials. Easels, backing boards, and some basic paper and charcoal are available. A college-style drawing table and drafting table are also available first-come-first-served.'],
            ['question' => 'How do I become a model?', 'answer' => "Contact André on 082 812 0549. He will ask for a photograph to help with planning, and discuss any questions you may have. Model pay is R 700 per session."],
        ];

        $faqJsonLd = '<script type="application/ld+json">'
            . json_encode([
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'mainEntity' => array_map(fn($item) => [
                    '@type' => 'Question',
                    'name' => $item['question'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $item['answer'],
                    ],
                ], $faqItems),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . '</script>';

        return $this->render('pages.faq', [], 'FAQ', [
            'meta_description' => 'Information and frequently asked questions about Life Drawing Randburg — what to bring, how sessions work, pricing, and how to become a model.',
            'json_ld' => $faqJsonLd,
        ]);
    }
}
