<?php

declare(strict_types=1);

class OwnShopFeedCache
{
    public function __construct(private readonly string $storageRoot)
    {
    }

    public function getFeedPath(): string
    {
        return $this->storageRoot . '/own_shop_feed.csv';
    }

    public function getLastUpdatePath(): string
    {
        return $this->storageRoot . '/own_shop_feed_last_update.txt';
    }

    public function ensureStorageDir(): void
    {
        if (!is_dir($this->storageRoot)) {
            mkdir($this->storageRoot, 0755, true);
        }
    }

    public function hasCachedFeed(): bool
    {
        $path = $this->getFeedPath();

        return is_file($path) && filesize($path) > 0;
    }

    public function readCachedFeed(): ?string
    {
        if (!$this->hasCachedFeed()) {
            return null;
        }

        $content = file_get_contents($this->getFeedPath());

        return is_string($content) && $content !== '' ? $content : null;
    }

    public function readCachedLastUpdate(): ?string
    {
        $path = $this->getLastUpdatePath();
        if (!is_file($path)) {
            return null;
        }

        $content = trim((string) file_get_contents($path));

        return $content !== '' ? $content : null;
    }

    public function writeCache(string $csv, string $lastUpdate): void
    {
        $this->ensureStorageDir();
        file_put_contents($this->getFeedPath(), $csv);
        file_put_contents($this->getLastUpdatePath(), trim($lastUpdate));
    }
}
