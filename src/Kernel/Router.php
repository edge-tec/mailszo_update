<?php
namespace Mailpro\Kernel;

/**
 * Front Controller Route Registry & Dispatcher
 */
class Router
{
    private static array $controllers = [
        'dkim'     => \Mailpro\Controllers\DkimController::class,
        'bounces'  => \Mailpro\Controllers\BounceController::class,
        'queue'    => \Mailpro\Controllers\QueueController::class,
        'database' => \Mailpro\Controllers\DatabaseController::class,
        'idle'     => \Mailpro\Controllers\IdleController::class,
    ];

    public static function register(string $resource, string $controllerClass): void
    {
        self::$controllers[strtolower($resource)] = $controllerClass;
    }

    public static function hasRoute(string $resource): bool
    {
        return isset(self::$controllers[strtolower($resource)]);
    }

    public static function dispatch(Request $request): ?Response
    {
        $res = strtolower($request->getResource());
        if (!isset(self::$controllers[$res])) {
            return null; // Not handled by modular router; fall through to legacy handlers
        }

        $controllerClass = self::$controllers[$res];
        if (!class_exists($controllerClass)) {
            return Response::error("Controller {$controllerClass} not found", 500);
        }

        /** @var \Mailpro\Controllers\BaseController $controller */
        $controller = new $controllerClass();
        return $controller->handle($request);
    }
}
