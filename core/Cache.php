<?php
namespace Canticle\Core;

class Cache
{
    private string $dir;

    public function __construct(string $storageDir)
    {
        $this->dir = rtrim($storageDir, '/') . '/cache';
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0750, true);
        }
    }

    public function get(string $key): mixed
    {
        $file = $this->path($key);
        if (!file_exists($file)) return null;
        $data = unserialize(file_get_contents($file));
        if ($data['expires'] !== 0 && $data['expires'] < time()) {
            unlink($file);
            return null;
        }
        return $data['value'];
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        file_put_contents($this->path($key), serialize([
            'expires' => $ttl === 0 ? 0 : time() + $ttl,
            'value'   => $value,
        ]), LOCK_EX);
    }

    public function forget(string $key): void
    {
        $file = $this->path($key);
        if (file_exists($file)) unlink($file);
    }

    public function remember(string $key, int $ttl, callable $fn): mixed
    {
        $cached = $this->get($key);
        if ($cached !== null) return $cached;
        $value = $fn();
        $this->set($key, $value, $ttl);
        return $value;
    }

    private function path(string $key): string
    {
        return $this->dir . '/' . md5($key) . '.cache';
    }
}
