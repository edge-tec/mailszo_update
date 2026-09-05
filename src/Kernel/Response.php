<?php
namespace Mailpro\Kernel;

/**
 * Standardized JSON HTTP Response
 */
class Response
{
    private array $data;
    private int $statusCode;

    public function __construct(array $data, int $statusCode = 200)
    {
        $this->data = $data;
        $this->statusCode = $statusCode;
    }

    public static function json(array $data, int $status = 200): self
    {
        return new self($data, $status);
    }

    public static function error(string $message, int $status = 400, array $extra = []): self
    {
        return new self(array_merge(['ok' => false, 'error' => $message], $extra), $status);
    }

    public static function success(string $message = 'OK', array $extra = []): self
    {
        return new self(array_merge(['ok' => true, 'message' => $message], $extra), 200);
    }

    public function getData(): array { return $this->data; }
    public function getStatusCode(): int { return $this->statusCode; }

    public function send(): void
    {
        http_response_code($this->statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($this->data);
        exit;
    }
}
