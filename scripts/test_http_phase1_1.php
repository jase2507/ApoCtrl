<?php

declare(strict_types=1);

$base = 'http://127.0.0.1:8766';
$cookie = tempnam(sys_get_temp_dir(), 'apo11_');

function http(string $url, string $method = 'GET', array $post = [], ?string $cookie = null): array
{
    $opts = [
        'http' => [
            'method' => $method,
            'header' => "User-Agent: ApoCtrl-Test/1.1\r\n",
            'ignore_errors' => true,
            'follow_location' => false,
        ],
    ];
    if ($cookie !== null) {
        $opts['http']['header'] .= 'Cookie: ' . (file_exists($cookie) ? trim((string) file_get_contents($cookie)) : '') . "\r\n";
    }
    if ($method === 'POST' && $post !== []) {
        $opts['http']['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
        $opts['http']['content'] = http_build_query($post);
    }
    $ctx = stream_context_create($opts);
    $body = (string) @file_get_contents($url, false, $ctx);
    $headers = $http_response_header ?? [];
    $status = 0;
    $location = '';
    foreach ($headers as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
            $status = (int) $m[1];
        }
        if (stripos($h, 'Location:') === 0) {
            $location = trim(substr($h, 9));
        }
        if (stripos($h, 'Set-Cookie:') === 0 && $cookie !== null) {
            file_put_contents($cookie, $h);
        }
    }

    return ['status' => $status, 'location' => $location, 'body' => $body];
}

$fail = 0;

// 1) index ohne Login
@unlink($cookie);
touch($cookie);
$r1 = http($base . '/index.php', 'GET', [], $cookie);
$r2 = http($base . '/' . ltrim($r1['location'] ?: 'login.php', '/'), 'GET', [], $cookie);
$badFlash = str_contains($r2['body'], 'Sitzung ist abgelaufen');
echo $badFlash ? "[FAIL] Falsche Timeout-Meldung auf Login\n" : "[OK] Keine falsche Timeout-Meldung\n";
if ($badFlash) {
    $fail++;
}

// 2) Login + Dashboard ohne Spurious Flash
$r3 = http($base . '/login.php', 'GET', [], $cookie);
preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $r3['body'], $m);
$r4 = http($base . '/login.php', 'POST', [
    'username' => 'admin',
    'password' => 'admin123',
    '_csrf_token' => $m[1] ?? '',
], $cookie);
$r5 = http($base . '/index.php', 'GET', [], $cookie);
$spurious = str_contains($r5['body'], 'Sitzung ist abgelaufen');
$dashboard = str_contains($r5['body'], 'Dashboard');
echo $spurious ? "[FAIL] Spurious Flash auf Dashboard\n" : "[OK] Kein Spurious Flash auf Dashboard\n";
echo $dashboard ? "[OK] Dashboard erreichbar\n" : "[FAIL] Dashboard nicht erreichbar\n";
if ($spurious || !$dashboard) {
    $fail++;
}

// 3) Sicherheitswarnung bei admin123
$warn = str_contains($r5['body'], 'Sicherheitswarnung');
echo $warn ? "[OK] Standard-Passwort-Warnung sichtbar\n" : "[WARN] Standard-Passwort-Warnung nicht sichtbar\n";

@unlink($cookie);
exit($fail > 0 ? 1 : 0);
