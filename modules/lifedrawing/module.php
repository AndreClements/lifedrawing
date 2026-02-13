<?php

declare(strict_types=1);

/**
 * Life Drawing Randburg — Module Manifest.
 *
 * This is the first "table" in the Artistry Caffe.
 * Empty slug means routes mount at root /.
 */

return [
    'name'       => 'lifedrawing',
    'slug'       => '',  // Root — this IS the site for now
    'label'      => 'Life Drawing Randburg',
    'routes'     => __DIR__ . '/routes.php',
    'migrations' => __DIR__ . '/migrations/',
    'middleware'  => [],
    'nav' => [
        ['label' => 'Sessions', 'route' => 'sessions.index', 'icon' => 'calendar'],
        ['label' => 'Gallery',  'route' => 'gallery.index',  'icon' => 'image'],
        ['label' => 'Artists',  'route' => 'profiles.artists', 'icon' => 'users'],
    ],
];
