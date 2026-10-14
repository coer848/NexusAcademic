<?php
/**
 * NexusAcademic - Enterprise Clean Architecture
 * Core HTTP Request Wrapper & Input Sanitizer
 */

namespace NexusAcademic\Core;

class Request {
    private string $method;
    private string $uri;
    private string $route;
    private array $headers;
    private array $query;
    private array $body;
    private array $params = [];
    private ?array $user = null;

    public function __construct() {
        $this->method  = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->uri     = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $this->query   = $_GET ?? [];
        $this->headers = $this->extractHeaders();
        $this->body    = $this->parseBody();
        $this->route   = $this->resolveRoute();
    }

    private function extractHeaders(): array {
        $headers = [];
        if (function_exists('getallheaders')) {
            $headers = getallheaders() ?: [];
        } else {
            foreach ($_SERVER as $key => $value) {
                if (str_starts_with($key, 'HTTP_')) {
                    $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                    $headers[$headerName] = $value;
                }
            }
        }
        return $headers;
    }

    private function parseBody(): array {
        $raw = file_get_contents('php://input');
        if (!empty($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return !empty($_POST) ? $_POST : [];
    }

    private function resolveRoute(): string {
        if (!empty($_GET['route'])) {
            return trim($_GET['route'], '/');
        }

        $uri = $this->uri;
        $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        if ($scriptDir !== '/' && !empty($scriptDir) && str_starts_with($uri, $scriptDir)) {
            $uri = substr($uri, strlen($scriptDir));
        }

        $uri = preg_replace('#^/NexusAcademic#i', '', $uri);
        $uri = preg_replace('#^/api(/index\.php)?#i', '', $uri);
        return trim($uri, '/');
    }

    public function getMethod(): string {
        return $this->method;
    }

    public function getRoute(): string {
        return $this->route;
    }

    public function getHeaders(): array {
        return $this->headers;
    }

    public function getHeader(string $name, ?string $default = null): ?string {
        foreach ($this->headers as $key => $val) {
            if (strcasecmp($key, $name) === 0) {
                return $val;
            }
        }
        return $default;
    }

    public function getQuery(?string $key = null, $default = null) {
        if ($key === null) return $this->query;
        return $this->query[$key] ?? $default;
    }

    public function getBody(): array {
        return $this->body;
    }

    public function input(?string $key = null, $default = null) {
        if ($key === null) return $this->body;
        return $this->body[$key] ?? $default;
    }

    public function has(string ...$keys): bool {
        foreach ($keys as $k) {
            if (!isset($this->body[$k]) || (is_string($this->body[$k]) && trim($this->body[$k]) === '')) {
                return false;
            }
        }
        return true;
    }

    public function setParams(array $params): void {
        $this->params = $params;
    }

    public function getParam(string $key, $default = null) {
        return $this->params[$key] ?? $default;
    }

    public function setUser(?array $user): void {
        $this->user = $user;
    }

    public function getUser(): ?array {
        return $this->user;
    }

    public function getClientIp(): string {
        return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}
