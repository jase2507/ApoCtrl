<?php

declare(strict_types=1);

class ShopUrlValidator
{
    public function __construct(private readonly string $allowedHost)
    {
    }

    public function isAllowed(?string $url): bool
    {
        if ($url === null || trim($url) === '') {
            return false;
        }

        $parts = parse_url(trim($url));

        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https') {
            return false;
        }

        if ($host !== strtolower($this->allowedHost)) {
            return false;
        }

        return $host === strtolower($this->allowedHost);
    }

    public function validateOrError(?string $url): ?string
    {
        $trimmed = trim((string) $url);

        if ($trimmed === '') {
            return 'Shop-URL ist erforderlich für den Shop-Sync.';
        }

        if (!$this->isAllowed($trimmed)) {
            return 'Nur HTTPS-URLs unter ' . $this->allowedHost . ' sind erlaubt.';
        }

        return null;
    }
}
