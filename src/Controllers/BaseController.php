<?php
namespace Mailpro\Controllers;

use Mailpro\Kernel\Request;
use Mailpro\Kernel\Response;

/**
 * Base Abstract Controller
 */
abstract class BaseController
{
    abstract public function handle(Request $request): Response;

    protected function db(): ?\PDO
    {
        if (function_exists('db')) {
            return \db();
        }
        return null;
    }

    protected function json(array $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function error(string $message, int $status = 400, array $extra = []): Response
    {
        return Response::error($message, $status, $extra);
    }

    protected function success(string $message = 'OK', array $extra = []): Response
    {
        return Response::success($message, $extra);
    }

    protected function forbidden(string $message = 'Administrator access required'): Response
    {
        return Response::error($message, 403);
    }

    protected function notFound(string $message = 'Resource not found'): Response
    {
        return Response::error($message, 404);
    }
}
