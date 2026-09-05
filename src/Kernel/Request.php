<?php
namespace Mailpro\Kernel;

/**
 * HTTP Request Abstraction
 * Encapsulates method, route parts, query parameters, payload, and user session.
 */
class Request
{
    private string $method;
    private string $route;
    private string $resource;
    private ?string $id;
    private ?string $action;
    private array $query;
    private array $body;
    private ?array $user;

    public function __construct(
        string $method,
        string $route,
        string $resource,
        ?string $id = null,
        ?string $action = null,
        array $query = [],
        array $body = [],
        ?array $user = null
    ) {
        $this->method   = strtoupper($method);
        $this->route    = $route;
        $this->resource = $resource;
        $this->id       = $id;
        $this->action   = $action;
        $this->query    = $query;
        $this->body     = $body;
        $this->user     = $user;
    }

    public static function capture(?array $user = null): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $route  = trim($_GET['r'] ?? '', '/');
        $parts  = explode('/', $route);
        $res    = $parts[0] ?? '';
        $id     = $parts[1] ?? null;
        $action = $parts[2] ?? null;

        $body = [];
        $raw = file_get_contents('php://input');
        if (!empty($raw)) {
            $parsed = json_decode($raw, true);
            if (is_array($parsed)) {
                $body = $parsed;
            }
        }
        if (empty($body) && !empty($_POST)) {
            $body = $_POST;
        }

        return new self($method, $route, $res, $id, $action, $_GET, $body, $user);
    }

    public function getMethod(): string { return $this->method; }
    public function getRoute(): string { return $this->route; }
    public function getResource(): string { return $this->resource; }
    public function getId(): ?string { return $this->id; }
    public function getAction(): ?string { return $this->action; }
    public function getQuery(?string $key = null, $default = null) {
        if ($key === null) return $this->query;
        return $this->query[$key] ?? $default;
    }
    public function getBody(): array { return $this->body; }
    public function input(string $key, $default = null) {
        return $this->body[$key] ?? $default;
    }
    public function getUser(): ?array { return $this->user; }
    public function getUserId(): int { return (int)($this->user['id'] ?? 0); }
    public function isAdmin(): bool { return !empty($this->user['is_admin']); }
}
