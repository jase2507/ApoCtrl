<?php

declare(strict_types=1);

/** @var array<string, string> $flashes */
foreach ($flashes as $type => $message):
    $alertClass = match ($type) {
        'error' => 'alert-error',
        'warning' => 'alert-warning',
        'success' => 'alert-success',
        default => 'alert-success',
    };
?>
    <div class="alert <?= e($alertClass) ?>" role="alert">
        <?= e($message) ?>
    </div>
<?php endforeach; ?>
