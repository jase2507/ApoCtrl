<?php

declare(strict_types=1);

class ShopSearchUrlBuilder
{
    public function __construct(
        private readonly ShopUrlValidator $urlValidator,
        private readonly string $searchUrlTemplate,
    ) {
    }

    public function build(string $pzn): string
    {
        $normalized = ShopHtmlParser::normalizePzn(trim($pzn));

        if ($normalized === '') {
            throw new InvalidArgumentException('PZN fehlt.');
        }

        $url = str_replace('{PZN}', rawurlencode($normalized), $this->searchUrlTemplate);

        if (!$this->urlValidator->isAllowed($url)) {
            throw new RuntimeException('Konfigurierte Such-URL ist nicht erlaubt.');
        }

        return $url;
    }
}
