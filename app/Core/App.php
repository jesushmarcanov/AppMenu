<?php

declare(strict_types=1);

namespace App\Core;

use App\Exceptions\ApiException;
use Throwable;

final class App
{
    private static ?string $requestId = null;

    public static function requestId(): string
    {
        if (self::$requestId === null) {
            self::$requestId = 'req_' . bin2hex(random_bytes(6));
        }

        return self::$requestId;
    }

    public static function run(): void
    {
        self::requestId();

        date_default_timezone_set((string) Config::get('app.timezone', 'America/Caracas'));
        error_reporting(E_ALL);
        ini_set('display_errors', '0');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        // Detecta la subcarpeta pública (p. ej. /AppMenu) cuando no está en .env.
        if ((string) Config::get('app.base_path', '') === '') {
            $uriPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
            if (is_string($uriPath)) {
                $apiPosition = strpos($uriPath, '/api/v1');
                if ($apiPosition !== false && $apiPosition > 0) {
                    Config::set('app.base_path', rtrim(substr($uriPath, 0, $apiPosition), '/'));
                }
            }
        }

        try {
            Session::start();
            $request = Request::capture();
            $routes = require dirname(__DIR__, 2) . '/config/routes.php';
            $router = new Router($routes);
            $response = $router->dispatch($request);
        } catch (ApiException $e) {
            $response = Response::error($e->errorCode, $e->getMessage(), $e->status, $e->fields);
        } catch (Throwable $e) {
            Logger::error($e);
            $message = (bool) Config::get('app.debug', false)
                ? $e->getMessage()
                : 'Ocurrió un error interno. Intenta de nuevo.';
            $response = Response::error('SERVER_ERROR', $message, 500);
        }

        $response->send();
    }
}
