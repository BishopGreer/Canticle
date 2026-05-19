<?php
namespace Canticle\Core;

class Response
{
    private int $status = 200;
    private array $headers = [];

    public function status(int $code): static
    {
        $this->status = $code;
        return $this;
    }

    public function header(string $name, string $value): static
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        foreach ($this->headers as $k => $v) {
            header("$k: $v");
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function activityJson(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        foreach ($this->headers as $k => $v) {
            header("$k: $v");
        }
        header('Content-Type: application/activity+json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    public function html(string $body, int $status = 200): never
    {
        http_response_code($status);
        foreach ($this->headers as $k => $v) {
            header("$k: $v");
        }
        header('Content-Type: text/html; charset=utf-8');
        echo $body;
        exit;
    }

    public function redirect(string $url, int $status = 302): never
    {
        http_response_code($status);
        header("Location: $url");
        exit;
    }

    public function error(string $message, int $status = 400): never
    {
        $this->json(['error' => $message], $status);
    }

    public function noContent(): never
    {
        http_response_code(204);
        exit;
    }
}
