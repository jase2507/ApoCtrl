<?php

declare(strict_types=1);

class ImportValidator
{
    /** @var list<string> */
    private const REQUIRED_COLUMNS = ['pzn', 'competitor', 'price'];

    /** @var list<string> */
    private const OPTIONAL_COLUMNS = ['shipping_cost', 'availability'];

    public function detectDelimiter(string $headerLine): string
    {
        $semicolonCount = substr_count($headerLine, ';');
        $commaCount = substr_count($headerLine, ',');

        return $semicolonCount >= $commaCount ? ';' : ',';
    }

    /**
     * @param list<string> $headers
     * @return array{ok: bool, missing: list<string>}
     */
    public function validateHeaders(array $headers): array
    {
        $normalized = array_map([$this, 'normalizeHeader'], $headers);
        $missing = [];

        foreach (self::REQUIRED_COLUMNS as $required) {
            if (!in_array($required, $normalized, true)) {
                $missing[] = $required;
            }
        }

        return ['ok' => $missing === [], 'missing' => $missing];
    }

    /**
     * @param list<string> $headers
     * @return array<string, int>
     */
    public function buildHeaderMap(array $headers): array
    {
        $map = [];
        foreach ($headers as $index => $header) {
            $map[$this->normalizeHeader($header)] = $index;
        }

        return $map;
    }

    /**
     * @param array<int, string|null> $row
     * @param array<string, int> $headerMap
     * @return array{data: array<string, mixed>, errors: list<string>}
     */
    public function validateRow(array $row, array $headerMap): array
    {
        $errors = [];

        $pzn = $this->rowValue($row, $headerMap, 'pzn');
        $competitor = $this->rowValue($row, $headerMap, 'competitor');
        $priceRaw = $this->rowValue($row, $headerMap, 'price');
        $shippingRaw = $this->rowValue($row, $headerMap, 'shipping_cost');
        $availability = $this->rowValue($row, $headerMap, 'availability');

        if ($pzn === '') {
            $errors[] = 'PZN fehlt';
        }

        if ($competitor === '') {
            $errors[] = 'Wettbewerber fehlt';
        }

        $price = $this->parseNonNegativeNumber($priceRaw, 'Preis', $errors);
        $shippingCost = $this->parseNonNegativeNumber($shippingRaw, 'Versand', $errors, true);

        return [
            'data' => [
                'pzn' => $pzn,
                'competitor' => $competitor,
                'price' => $price,
                'shipping_cost' => $shippingCost ?? 0.0,
                'availability' => $availability !== '' ? $availability : null,
            ],
            'errors' => $errors,
        ];
    }

    /**
     * @return list<string>
     */
    public function knownColumns(): array
    {
        return array_merge(self::REQUIRED_COLUMNS, self::OPTIONAL_COLUMNS);
    }

    private function normalizeHeader(string $value): string
    {
        return strtolower(trim($value));
    }

    /**
     * @param array<int, string|null> $row
     * @param array<string, int> $headerMap
     */
    private function rowValue(array $row, array $headerMap, string $column): string
    {
        if (!isset($headerMap[$column])) {
            return '';
        }

        $raw = $row[$headerMap[$column]] ?? '';
        return trim((string) $raw);
    }

    /**
     * @param list<string> $errors
     */
    private function parseNonNegativeNumber(string $raw, string $label, array &$errors, bool $emptyAsNull = false): ?float
    {
        if ($raw === '') {
            if ($emptyAsNull) {
                return null;
            }
            $errors[] = $label . ' fehlt';
            return null;
        }

        $normalized = str_replace(',', '.', $raw);
        if (!is_numeric($normalized)) {
            $errors[] = $label . ' ist keine Zahl';
            return null;
        }

        $number = (float) $normalized;
        if ($number < 0) {
            $errors[] = $label . ' muss >= 0 sein';
            return null;
        }

        return $number;
    }
}
