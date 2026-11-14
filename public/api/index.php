<?php

declare(strict_types=1);

/**
 * Front controller de la API. Apache reescribe /api/v1/* hacia este archivo.
 * No hay archivos sensibles dentro de public/.
 */

require dirname(__DIR__, 2) . '/app/bootstrap.php';

App\Core\App::run();
