<?php

declare(strict_types=1);

class CompetitorValidator
{
    public function __construct(private readonly CompetitorRepository $repository)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{data: array<string, mixed>, errors: list<string>}
     */
    public function validate(array $input, ?int $excludeId = null): array
    {
        $errors = [];

        $name = trim((string) ($input['name'] ?? ''));
        $url = trim((string) ($input['url'] ?? ''));
        $notes = trim((string) ($input['notes'] ?? ''));

        if ($name === '') {
            $errors[] = 'Name ist ein Pflichtfeld.';
        } elseif ($this->repository->existsByName($name, $excludeId)) {
            $errors[] = 'Ein Wettbewerber mit diesem Namen existiert bereits.';
        }

        if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) === false) {
            $errors[] = 'Die URL ist ungültig.';
        }

        $priority = self::parsePriority($input['priority'] ?? '', $errors);
        $active = !empty($input['active']) ? 1 : 0;

        $data = [
            'name' => $name,
            'url' => $url !== '' ? $url : null,
            'priority' => $priority,
            'active' => $active,
            'notes' => $notes !== '' ? $notes : null,
        ];

        return ['data' => $data, 'errors' => $errors];
    }

    private static function parsePriority(mixed $value, array &$errors): int
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return 0;
        }

        if (!ctype_digit($raw) && !preg_match('/^\d+$/', $raw)) {
            $errors[] = 'Priorität muss eine ganze Zahl sein.';
            return 0;
        }

        $priority = (int) $raw;

        if ($priority < 0) {
            $errors[] = 'Priorität muss größer oder gleich 0 sein.';
            return 0;
        }

        return $priority;
    }
}
