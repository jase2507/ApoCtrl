<?php

declare(strict_types=1);

interface CollectorProviderInterface
{
    /**
     * Rohdaten (z. B. HTML) für eine PZN abrufen.
     *
     * @throws RuntimeException bei Abruffehler
     */
    public function fetchByPzn(string $pzn): string;
}
