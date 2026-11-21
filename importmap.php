<?php

declare(strict_types=1);

/**
 * Importmapa AssetMappera: moduły JS ładowane bez bundlera i bez CDN.
 * Każda strona ładuje "app" + ewentualnie własny entrypoint (blok importmap w szablonie).
 */
return [
    'app' => ['path' => './assets/app.js', 'entrypoint' => true],
    'dashboard' => ['path' => './assets/js/dashboard.js', 'entrypoint' => true],
    'charts' => ['path' => './assets/js/charts.js', 'entrypoint' => true],
    'device-form' => ['path' => './assets/js/device-form.js', 'entrypoint' => true],
];
