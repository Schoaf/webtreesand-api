<?php

/**
 * WebtreesAnd API – lesende JSON-Schnittstelle fuer die native Android-App.
 *
 * Installation: diesen Ordner nach modules_v4/webtreesand-api kopieren und das
 * Modul in der Verwaltung aktivieren. Der webtrees-Kern bleibt unveraendert.
 */

declare(strict_types=1);

namespace WebtreesAnd\Api;

require_once __DIR__ . '/WebtreesAndApiModule.php';

return new WebtreesAndApiModule();
