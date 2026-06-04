<?php

declare(strict_types=1);

class ShopSyncService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly OwnShopRepository $ownShop,
        private readonly ShopUrlValidator $urlValidator,
        private readonly ShopFetcher $fetcher,
        private readonly ShopHtmlParser $parser,
        private readonly RankingEngine $rankingEngine,
    ) {
    }

    /**
     * @return array{success:bool,message:string}
     */
    public function syncProduct(int $productId, ?string $htmlOverride = null): array
    {
        $product = $this->products->findById($productId);

        if ($product === null) {
            return ['success' => false, 'message' => 'Produkt nicht gefunden.'];
        }

        if ((int) ($product['active'] ?? 0) !== 1) {
            return ['success' => false, 'message' => 'Shop-Sync ist nur für aktive Produkte möglich.'];
        }

        $shopUrl = trim((string) ($product['shop_url'] ?? ''));
        $urlError = $this->urlValidator->validateOrError($shopUrl);

        if ($urlError !== null) {
            $this->products->recordShopSyncError($productId, $urlError);

            return ['success' => false, 'message' => $urlError];
        }

        if ($htmlOverride === null) {
            $fetch = $this->fetcher->fetch($shopUrl);
            if (!$fetch['ok']) {
                $error = (string) ($fetch['error'] ?? 'Unbekannter Abruffehler.');
                $this->products->recordShopSyncError($productId, $error);

                return ['success' => false, 'message' => $error];
            }
            $html = (string) $fetch['html'];
        } else {
            $html = $htmlOverride;
        }

        try {
            $parsed = $this->parser->parse($html, (string) ($product['pzn'] ?? ''));
        } catch (Throwable $e) {
            $this->products->recordShopSyncError($productId, $e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }

        if ($parsed['price'] === null) {
            $message = 'Shop-Preis konnte nicht extrahiert werden.';
            $this->products->recordShopSyncError($productId, $message);

            return ['success' => false, 'message' => $message];
        }

        $this->products->applyShopSyncSuccess($productId, $parsed, $product);

        $shipping = (float) ($product['own_shipping_cost'] ?? 0);
        $this->ownShop->insertOwnSnapshot(
            $productId,
            (float) $parsed['price'],
            $shipping,
            $parsed['delivery_status']
        );

        $this->rankingEngine->runForProduct($productId);

        return [
            'success' => true,
            'message' => 'Shopdaten wurden aktualisiert und Snapshot erzeugt.',
        ];
    }

    /**
     * Snapshot und Ranking aus Feed-/Autofill-Daten (ohne HTML-Abruf).
     *
     * @param array{
     *   product_name:?string,
     *   manufacturer:?string,
     *   package_size:?string,
     *   pzn:?string,
     *   price:?float,
     *   avp_price:?float,
     *   delivery_status:?string
     * } $parsed
     * @return array{success:bool,message:string}
     */
    public function syncProductFromParsed(int $productId, array $parsed): array
    {
        $product = $this->products->findById($productId);

        if ($product === null) {
            return ['success' => false, 'message' => 'Produkt nicht gefunden.'];
        }

        if ((int) ($product['active'] ?? 0) !== 1) {
            return ['success' => false, 'message' => 'Shop-Sync ist nur für aktive Produkte möglich.'];
        }

        if ($parsed['price'] === null) {
            $message = 'Feed-Preis fehlt – Snapshot kann nicht erzeugt werden.';
            $this->products->recordShopSyncError($productId, $message);

            return ['success' => false, 'message' => $message];
        }

        $this->products->applyShopSyncSuccess($productId, $parsed, $product);

        $shipping = (float) ($product['own_shipping_cost'] ?? 0);
        $this->ownShop->insertOwnSnapshot(
            $productId,
            (float) $parsed['price'],
            $shipping,
            $parsed['delivery_status'] ?? null,
        );

        $this->rankingEngine->runForProduct($productId);

        return [
            'success' => true,
            'message' => 'Feeddaten übernommen, Snapshot erzeugt und Ranking aktualisiert.',
        ];
    }
}
