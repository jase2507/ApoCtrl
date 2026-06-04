<?php

declare(strict_types=1);

class OwnShopFeedParser
{
    /** @var array<string, list<string>> */
    private const COLUMN_ALIASES = [
        'pzn' => ['pzn', 'artnr', 'artikelnummer', 'item_number', 'itemnumber'],
        'name' => ['name', 'product_name', 'artikelname', 'bezeichnung', 'title'],
        'manufacturer' => ['manufacturer', 'hersteller', 'anbieter', 'brand'],
        'price' => ['price', 'preis', 'vk', 'verkaufspreis'],
        'avp' => ['avp', 'uvp', 'listenpreis', 'alter_preis', 'listprice'],
        'availability' => ['availability', 'lieferstatus', 'status', 'available', 'verfuegbarkeit'],
        'package_size' => ['package_size', 'einheit', 'packung', 'packungsgroesse', 'packungsgröße', 'packungsgroesse'],
    ];

    /**
     * @return array<string, array{
     *   pzn:string,
     *   name:?string,
     *   manufacturer:?string,
     *   price:?float,
     *   avp:?float,
     *   package_size:?string,
     *   availability:?string
     * }>
     */
    public function parseIndex(string $csv): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($csv)) ?: [];
        $lines = array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));

        if ($lines === []) {
            return [];
        }

        $delimiter = $this->detectDelimiter($lines[0]);
        $rows = [];
        foreach ($lines as $line) {
            $rows[] = str_getcsv($line, $delimiter, '"', '');
        }

        $header = null;
        $dataRows = $rows;

        if ($this->looksLikeHeaderRow($rows[0])) {
            $header = $this->normalizeHeaderCells($rows[0]);
            $dataRows = array_slice($rows, 1);
        }

        $columnMap = $header !== null
            ? $this->mapColumns($header)
            : $this->guessPositionalColumns($dataRows);

        $index = [];
        foreach ($dataRows as $cells) {
            if ($cells === [] || $this->rowIsEmpty($cells)) {
                continue;
            }

            $entry = $this->rowToEntry($cells, $columnMap);
            if ($entry === null) {
                continue;
            }

            $index[(string) $entry['pzn']] = $entry;
        }

        return $index;
    }

    /**
     * @param array<string, array<string, mixed>> $index
     * @return array<string, mixed>|null
     */
    public function findByPzn(array $index, string $pzn): ?array
    {
        $needle = ShopHtmlParser::normalizePzn($pzn);
        if (isset($index[$needle])) {
            return $index[$needle];
        }

        $short = ltrim($needle, '0');
        foreach ($index as $key => $row) {
            $keyStr = (string) $key;
            if ($keyStr === $needle || ltrim($keyStr, '0') === $short) {
                return $row;
            }
        }

        return null;
    }

    private function detectDelimiter(string $line): string
    {
        $semicolon = substr_count($line, ';');
        $comma = substr_count($line, ',');

        return $semicolon >= $comma ? ';' : ',';
    }

    /**
     * @param list<string> $cells
     */
    private function looksLikeHeaderRow(array $cells): bool
    {
        $joined = strtolower(implode(' ', array_map('trim', $cells)));

        foreach (self::COLUMN_ALIASES as $aliases) {
            foreach ($aliases as $alias) {
                if (str_contains($joined, strtolower($alias))) {
                    return true;
                }
            }
        }

        $first = trim((string) ($cells[0] ?? ''));
        if ($first === '') {
            return false;
        }

        return !preg_match('/^\d{7,8}$/', $first);
    }

    /**
     * @param list<string> $cells
     * @return list<string>
     */
    private function normalizeHeaderCells(array $cells): array
    {
        return array_map(static function (string $cell): string {
            $cell = trim($cell);
            $cell = preg_replace('/^\xEF\xBB\xBF/', '', $cell) ?? $cell;

            return strtolower($cell);
        }, $cells);
    }

    /**
     * @param list<string> $header
     * @return array<string, int>
     */
    private function mapColumns(array $header): array
    {
        $map = [];
        foreach (self::COLUMN_ALIASES as $field => $aliases) {
            foreach ($header as $index => $columnName) {
                $normalized = $this->normalizeColumnName($columnName);
                foreach ($aliases as $alias) {
                    if ($normalized === $this->normalizeColumnName($alias)) {
                        $map[$field] = $index;
                        break 2;
                    }
                }
            }
        }

        if (!isset($map['pzn'])) {
            $map['pzn'] = 0;
        }

        return $map;
    }

    /**
     * @param list<list<string>> $rows
     * @return array<string, int>
     */
    private function guessPositionalColumns(array $rows): array
    {
        $map = ['pzn' => 0];
        $sample = $rows[0] ?? [];

        if (count($sample) >= 2) {
            $map['price'] = 1;
        }
        if (count($sample) >= 3) {
            $map['name'] = 2;
        }
        if (count($sample) >= 4) {
            $map['manufacturer'] = 3;
        }
        if (count($sample) >= 5) {
            $map['avp'] = 4;
        }
        if (count($sample) >= 6) {
            $map['package_size'] = 5;
        }
        if (count($sample) >= 7) {
            $map['availability'] = 6;
        }

        return $map;
    }

    /**
     * @param list<string> $cells
     * @param array<string, int> $columnMap
     * @return array{
     *   pzn:string,
     *   name:?string,
     *   manufacturer:?string,
     *   price:?float,
     *   avp:?float,
     *   package_size:?string,
     *   availability:?string
     * }|null
     */
    private function rowToEntry(array $cells, array $columnMap): ?array
    {
        $pznRaw = $this->cell($cells, $columnMap['pzn'] ?? 0);
        if ($pznRaw === null || !preg_match('/\d{7,8}/', $pznRaw, $match)) {
            return null;
        }

        $pzn = ShopHtmlParser::normalizePzn($match[0]);
        $name = $this->cell($cells, $columnMap['name'] ?? -1);
        $manufacturer = $this->cell($cells, $columnMap['manufacturer'] ?? -1);
        $priceRaw = $this->cell($cells, $columnMap['price'] ?? -1);
        $avpRaw = $this->cell($cells, $columnMap['avp'] ?? -1);
        $package = $this->cell($cells, $columnMap['package_size'] ?? -1);
        $availability = $this->cell($cells, $columnMap['availability'] ?? -1);

        if ($name === null && $manufacturer !== null && $package !== null) {
            $name = trim($manufacturer . ' ' . $package);
        }

        return [
            'pzn' => $pzn,
            'name' => $name,
            'manufacturer' => $manufacturer,
            'price' => $this->parseOptionalPrice($priceRaw),
            'avp' => $this->parseOptionalPrice($avpRaw),
            'package_size' => $package,
            'availability' => $availability,
        ];
    }

    /**
     * @param list<string> $cells
     */
    private function rowIsEmpty(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $cells
     */
    private function cell(array $cells, int $index): ?string
    {
        if ($index < 0 || !isset($cells[$index])) {
            return null;
        }

        $value = trim((string) $cells[$index]);

        return $value !== '' ? $value : null;
    }

    private function parseOptionalPrice(?string $raw): ?float
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        return ShopHtmlParser::parseGermanPrice($raw);
    }

    private function normalizeColumnName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $name);
        $name = preg_replace('/[^a-z0-9_]/', '', $name) ?? $name;

        return $name;
    }
}
