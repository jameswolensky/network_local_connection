<?php

namespace Local\Energy;

/** Tiny file cache, so a token survives between one-shot CLI runs. */
final class Cache
{
    public function __construct(private readonly string $dir)
    {
        if (! is_dir($this->dir)) {
            mkdir($this->dir, 0700, true);
        }
    }

    public function get(string $key): ?string
    {
        $file = $this->path($key);
        if (! is_file($file)) {
            return null;
        }

        $raw = json_decode((string) file_get_contents($file), true);
        if (! is_array($raw) || ($raw['expires'] ?? 0) < time()) {
            return null;
        }

        return $raw['value'] ?? null;
    }

    public function put(string $key, string $value, int $ttl): void
    {
        // 0600: this holds a gateway bearer token.
        $file = $this->path($key);
        file_put_contents($file, json_encode(['value' => $value, 'expires' => time() + $ttl]));
        chmod($file, 0600);
    }

    /**
     * Run $fn while holding an exclusive lock keyed on $key, so concurrent
     * callers serialise a read-modify-write instead of racing it. The lock is
     * a sidecar file next to the entry; if it can't be opened, $fn still runs.
     *
     * @template T
     * @param  callable():T  $fn
     * @return T
     */
    public function locked(string $key, callable $fn): mixed
    {
        $handle = fopen($this->path($key) . '.lock', 'c');
        if ($handle === false) {
            return $fn();
        }

        try {
            flock($handle, LOCK_EX);

            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function path(string $key): string
    {
        return $this->dir . '/' . preg_replace('/[^a-z0-9._-]/i', '_', $key) . '.json';
    }
}
