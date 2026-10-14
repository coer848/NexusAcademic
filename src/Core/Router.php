<?php
/**
 * NexusAcademic - Enterprise Clean Architecture
 * Modular URL Router with Middleware Pipeline & Regex Parameter Binding
 */

namespace NexusAcademic\Core;

class Router {
    private array $routes = [];
    private array $globalMiddlewares = [];
    private string $currentPrefix = '';
    private array $currentGroupMiddlewares = [];

    /**
     * Register global middleware
     */
    public function use(callable $middleware): self {
        $this->globalMiddlewares[] = $middleware;
        return $this;
    }

    /**
     * Define route group with prefix and optional middlewares
     */
    public function group(string $prefix, callable $callback, array $middlewares = []): void {
        $previousPrefix = $this->currentPrefix;
        $previousMiddlewares = $this->currentGroupMiddlewares;

        $this->currentPrefix = trim($previousPrefix . '/' . trim($prefix, '/'), '/');
        $this->currentGroupMiddlewares = array_merge($previousMiddlewares, $middlewares);

        $callback($this);

        $this->currentPrefix = $previousPrefix;
        $this->currentGroupMiddlewares = $previousMiddlewares;
    }

    /**
     * Add a route to the collection
     */
    public function addRoute(string $method, string $path, $handler, array $middlewares = []): self {
        $fullPath = trim($this->currentPrefix . '/' . trim($path, '/'), '/');
        $mergedMiddlewares = array_merge($this->currentGroupMiddlewares, $middlewares);

        // Convert route pattern with {param} or {param:\d+} to regex
        $pattern = preg_replace_callback('/\{([a-zA-Z0-9_]+)(?::([^}]+))?\}/', function ($matches) {
            $paramName = $matches[1];
            $customRegex = $matches[2] ?? '[^/]+';
            return '(?P<' . $paramName . '>' . $customRegex . ')';
        }, $fullPath);

        $regex = '#^' . $pattern . '$#i';

        $this->routes[] = [
            'method'      => strtoupper($method),
            'path'        => $fullPath,
            'regex'       => $regex,
            'handler'     => $handler,
            'middlewares' => $mergedMiddlewares
        ];

        return $this;
    }

    public function get(string $path, $handler, array $middlewares = []): self {
        return $this->addRoute('GET', $path, $handler, $middlewares);
    }

    public function post(string $path, $handler, array $middlewares = []): self {
        return $this->addRoute('POST', $path, $handler, $middlewares);
    }

    public function put(string $path, $handler, array $middlewares = []): self {
        return $this->addRoute('PUT', $path, $handler, $middlewares);
    }

    public function delete(string $path, $handler, array $middlewares = []): self {
        return $this->addRoute('DELETE', $path, $handler, $middlewares);
    }

    /**
     * Dispatch the request against registered routes
     */
    public function dispatch(Request $request): void {
        $method = $request->getMethod();
        $targetRoute = $request->getRoute();

        // 1. Execute Global Middlewares
        foreach ($this->globalMiddlewares as $mw) {
            $mw($request);
        }

        $methodAllowed = false;

        // 2. Find matching route
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $targetRoute, $matches)) {
                if ($route['method'] !== $method) {
                    $methodAllowed = true;
                    continue;
                }

                // Extract named URL parameters
                $params = [];
                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        $params[$key] = $value;
                    }
                }
                $request->setParams($params);

                // 3. Execute Route Middlewares
                foreach ($route['middlewares'] as $mw) {
                    if (is_callable($mw)) {
                        $mw($request);
                    }
                }

                // 4. Execute Route Handler
                $handler = $route['handler'];
                if (is_callable($handler)) {
                    $handler($request);
                    return;
                }

                if (is_array($handler) && count($handler) === 2) {
                    [$class, $methodName] = $handler;
                    if (is_string($class)) {
                        $class = new $class();
                    }
                    $class->$methodName($request);
                    return;
                }

                Response::serverError("El controlador asignado no es ejecutable.");
                return;
            }
        }

        if ($methodAllowed) {
            Response::error("Método HTTP '{$method}' no permitido para este recurso.", 'METHOD_NOT_ALLOWED', 405);
            return;
        }

        Response::notFound("El recurso solicitado '{$targetRoute}' no existe en la API.");
    }
}
