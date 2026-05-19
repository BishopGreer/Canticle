<?php
namespace Canticle\Core;

class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable|array $handler): void
    {
        $this->routes[] = [
            'method'  => strtoupper($method),
            'pattern' => $this->compile($pattern),
            'handler' => $handler,
        ];
    }

    public function get(string $pattern, callable|array $handler): void    { $this->add('GET',    $pattern, $handler); }
    public function post(string $pattern, callable|array $handler): void   { $this->add('POST',   $pattern, $handler); }
    public function put(string $pattern, callable|array $handler): void    { $this->add('PUT',    $pattern, $handler); }
    public function patch(string $pattern, callable|array $handler): void  { $this->add('PATCH',  $pattern, $handler); }
    public function delete(string $pattern, callable|array $handler): void { $this->add('DELETE', $pattern, $handler); }

    public function dispatch(Request $request, Response $response): void
    {
        $method = $request->method();
        // Strip trailing slash so /public/ and /public both match the same route.
        // Preserve bare "/" as-is.
        $rawPath = parse_url($request->uri(), PHP_URL_PATH) ?? '/';
        $path    = rtrim('/' . ltrim($rawPath, '/'), '/') ?: '/';

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method && $route['method'] !== 'ANY') {
                continue;
            }
            if (preg_match($route['pattern'], $path, $matches)) {
                $params = array_filter($matches, fn($k) => is_string($k), ARRAY_FILTER_USE_KEY);
                $request->setParams($params);
                $this->call($route['handler'], $request, $response);
                return;
            }
        }

        $response->json(['error' => 'Not found'], 404);
    }

    private function compile(string $pattern): string
    {
        // :id → named group (?P<id>[^/]+)
        $pattern = preg_replace('/:([a-zA-Z_]+)/', '(?P<$1>[^/]+)', $pattern);
        // *  → catch-all
        $pattern = str_replace('*', '.*', $pattern);
        return '#^' . $pattern . '$#';
    }

    private function call(callable|array $handler, Request $req, Response $res): void
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            $obj = new $class();
            $obj->$method($req, $res);
        } else {
            $handler($req, $res);
        }
    }
}
