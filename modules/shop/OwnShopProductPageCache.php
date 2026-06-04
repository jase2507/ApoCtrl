<?php

declare(strict_types=1);

class OwnShopProductPageCache
{
    public function __construct(
        private readonly string $cacheDir,
        private readonly int $ttlMinutes = 15,
    ) {
    }

    public function pathForPzn(string $pzn): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', ShopHtmlParser::normalizePzn($pzn)) ?? $pzn;

        return rtrim($this->cacheDir, '/\\') . '/' . $safe . '.html';
    }

    public function isValid(string $pzn): bool
    {
        $path = $this->pathForPzn($pzn);
        if (!is_file($path)) {
            return false;
        }

        $ttlSeconds = max(60, $this->ttlMinutes * 60);
        $mtime = filemtime($path);

        return $mtime !== false && (time() - $mtime) < $ttlSeconds;
    }

    public function read(string $pzn): ?string
    {
        if (!$this->isValid($pzn)) {
            return null;
        }

        $content = file_get_contents($this->pathForPzn($pzn));

        return is_string($content) && $content !== '' ? $content : null;
    }

    public function write(string $pzn, string $html): void
    {
        $dir = rtrim($this->cacheDir, '/\\');
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Produktseiten-Cache nicht beschreibbar: ' . $dir);
        }

        file_put_contents($this->pathForPzn($pzn), $html);
    }
}
