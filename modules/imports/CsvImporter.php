<?php

declare(strict_types=1);

class CsvImporter
{
    public function __construct(
        private readonly ImportRepository $repository,
        private readonly ImportValidator $validator
    ) {
    }

    /**
     * @return array{
     *   filename: string,
     *   delimiter: string,
     *   headers: list<string>,
     *   headerMap: array<string, int>,
     *   rows: list<array{line: int, raw: array<int, string|null>, data: array<string, mixed>, errors: list<string>}>,
     *   totalRows: int,
     *   validRows: int,
     *   invalidRows: int
     * }
     */
    public function buildPreview(string $csvPath, string $filename): array
    {
        $handle = fopen($csvPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('CSV-Datei konnte nicht geöffnet werden.');
        }

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            throw new RuntimeException('CSV-Datei ist leer.');
        }

        $delimiter = $this->validator->detectDelimiter($firstLine);

        rewind($handle);
        $headers = fgetcsv($handle, 0, $delimiter, '"', '\\');
        if ($headers === false) {
            fclose($handle);
            throw new RuntimeException('CSV-Header konnte nicht gelesen werden.');
        }

        $headerValidation = $this->validator->validateHeaders($headers);
        if (!$headerValidation['ok']) {
            fclose($handle);
            throw new RuntimeException(
                'Pflichtspalten fehlen: ' . implode(', ', $headerValidation['missing'])
            );
        }

        $headerMap = $this->validator->buildHeaderMap($headers);
        $rows = [];
        $lineNumber = 1;

        while (($rawRow = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $lineNumber++;

            if ($this->isEmptyRow($rawRow)) {
                continue;
            }

            $validated = $this->validator->validateRow($rawRow, $headerMap);

            if ($validated['errors'] === []) {
                $competitorName = (string) ($validated['data']['competitor'] ?? '');
                $competitor = $this->repository->findCompetitorByName($competitorName);
                if ($competitor === null) {
                    $validated['errors'][] = 'Unbekannter Wettbewerber: ' . $competitorName;
                }
            }

            $rows[] = [
                'line' => $lineNumber,
                'raw' => $rawRow,
                'data' => $validated['data'],
                'errors' => $validated['errors'],
            ];
        }

        fclose($handle);

        $invalidRows = 0;
        foreach ($rows as $row) {
            if ($row['errors'] !== []) {
                $invalidRows++;
            }
        }

        return [
            'filename' => $filename,
            'delimiter' => $delimiter,
            'headers' => $headers,
            'headerMap' => $headerMap,
            'rows' => $rows,
            'totalRows' => count($rows),
            'validRows' => count($rows) - $invalidRows,
            'invalidRows' => $invalidRows,
        ];
    }

    /**
     * @param array{
     *   filename: string,
     *   rows: list<array{line: int, data: array<string, mixed>, errors: list<string>}>
     * } $preview
     * @return array{
     *   importLogId: int,
     *   filename: string,
     *   total: int,
     *   imported: int,
     *   errors: int,
     *   createdProducts: int,
     *   errorRows: list<array{line: int, message: string}>
     * }
     */
    public function importPreview(array $preview, int $userId): array
    {
        $importLogId = $this->repository->startImportLog($preview['filename']);
        Auth::logAudit($userId, 'import_started', 'Upload gestartet: ' . $preview['filename']);

        $imported = 0;
        $errors = 0;
        $createdProducts = 0;
        $errorRows = [];

        foreach ($preview['rows'] as $row) {
            if ($row['errors'] !== []) {
                $errors++;
                $errorRows[] = [
                    'line' => $row['line'],
                    'message' => implode('; ', $row['errors']),
                ];
                continue;
            }

            $data = $row['data'];
            $product = $this->repository->findProductByPzn((string) $data['pzn']);
            if ($product === null) {
                $productId = $this->repository->createImportedProduct((string) $data['pzn']);
                $createdProducts++;
                Auth::logAudit(
                    $userId,
                    'import_product_created',
                    'Produkt automatisch angelegt für PZN ' . $data['pzn']
                );
            } else {
                $productId = (int) $product['id'];
            }

            $competitor = $this->repository->findCompetitorByName((string) $data['competitor']);
            if ($competitor === null) {
                $errors++;
                $errorRows[] = [
                    'line' => $row['line'],
                    'message' => 'Unbekannter Wettbewerber: ' . $data['competitor'],
                ];
                continue;
            }

            $this->repository->insertSnapshot(
                $productId,
                (int) $competitor['id'],
                (float) $data['price'],
                (float) ($data['shipping_cost'] ?? 0),
                is_string($data['availability'] ?? null) ? $data['availability'] : null
            );
            $imported++;
        }

        $total = count($preview['rows']);
        $this->repository->finishImportLog($importLogId, $total, $errors);

        Auth::logAudit(
            $userId,
            'import_finished',
            sprintf(
                'Upload abgeschlossen: %s; Datensätze=%d; importiert=%d; Fehler=%d',
                $preview['filename'],
                $total,
                $imported,
                $errors
            )
        );

        return [
            'importLogId' => $importLogId,
            'filename' => $preview['filename'],
            'total' => $total,
            'imported' => $imported,
            'errors' => $errors,
            'createdProducts' => $createdProducts,
            'errorRows' => $errorRows,
        ];
    }

    /**
     * @param array<int, string|null> $row
     */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
