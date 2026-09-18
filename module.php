<?php

/**
 * WebtreesAnd API - JSON-Schnittstelle fuer die native Android-App webtreesAnd.
 *
 * Installation: diesen Ordner nach modules_v4/webtreesand-api kopieren. Der webtrees-Kern bleibt unveraendert.
 * Die Modulklasse liegt daneben, ihre Teile (Traits, Helfer) unter src/ - siehe Kopf von WebtreesAndApiModule.php.
 */

declare(strict_types=1);

namespace WebtreesAnd\Api;

use function is_file;
use function spl_autoload_register;
use function str_starts_with;
use function strlen;
use function substr;

spl_autoload_register(static function (string $class): void {
    $prefix = __NAMESPACE__ . '\\';

    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/src/' . substr($class, strlen($prefix)) . '.php';

        if (is_file($file)) {
            require $file;
        }
    }
});

require_once __DIR__ . '/WebtreesAndApiModule.php';

return new WebtreesAndApiModule();
