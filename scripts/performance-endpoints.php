<?php

declare(strict_types=1);

/**
 * Runner de carga HTTP para endpoints criticos.
 * Mede p95, media, erros e concorrencia.
 *
 * Uso:
 *   php scripts/performance-endpoints.php --base-url=http://127.0.0.1:8091 --requests=120 --concurrency=20
 */

$options = getopt('', [
    'base-url::',
    'email::',
    'password::',
    'requests::',
    'concurrency::',
    'timeout::',
    'out-json::',
]);

$baseUrl = rtrim((string) ($options['base-url'] ?? 'http://127.0.0.1:8091'), '/');
$email = (string) ($options['email'] ?? 'empresa@teste.local');
$password = (string) ($options['password'] ?? 'teste123');
$requestsPerEndpoint = max(1, (int) ($options['requests'] ?? 120));
$concurrency = max(1, (int) ($options['concurrency'] ?? 20));
$timeoutSeconds = max(2, (int) ($options['timeout'] ?? 20));
$outJson = (string) ($options['out-json'] ?? 'docs/perf-initial-report.json');

$cookieJar = tempnam(sys_get_temp_dir(), 'perf_cookie_');
if ($cookieJar === false) {
    fwrite(STDERR, "Nao foi possivel criar cookie jar temporario.\n");
    exit(1);
}

$endpoints = [
    [
        'name' => 'Auth Me',
        'method' => 'GET',
        'path' => '/api/me',
    ],
    [
        'name' => 'Inbox Conversas',
        'method' => 'GET',
        'path' => '/api/minha-conta/conversas?per_page=15&page=1',
    ],
    [
        'name' => 'Chat Conversas',
        'method' => 'GET',
        'path' => '/api/chat/conversations?per_page=20',
    ],
    [
        'name' => 'Notificacoes',
        'method' => 'GET',
        'path' => '/api/notifications?per_page=50',
    ],
    [
        'name' => 'Tickets Mine',
        'method' => 'GET',
        'path' => '/api/suporte/minhas-solicitacoes',
    ],
];

try {
    loginWithSession($baseUrl, $email, $password, $cookieJar, $timeoutSeconds);

    $suiteStartedAt = microtime(true);
    $results = [];

    foreach ($endpoints as $endpoint) {
        $results[] = runLoadForEndpoint(
            baseUrl: $baseUrl,
            endpoint: $endpoint,
            cookieJar: $cookieJar,
            totalRequests: $requestsPerEndpoint,
            concurrency: $concurrency,
            timeoutSeconds: $timeoutSeconds,
        );
    }

    $suiteDurationMs = (microtime(true) - $suiteStartedAt) * 1000;

    $aggregate = aggregateSuiteMetrics($results, $suiteDurationMs, $concurrency);

    $payload = [
        'generated_at' => gmdate('c'),
        'base_url' => $baseUrl,
        'requests_per_endpoint' => $requestsPerEndpoint,
        'concurrency' => $concurrency,
        'timeout_seconds' => $timeoutSeconds,
        'suite' => $aggregate,
        'endpoints' => $results,
    ];

    $outDir = dirname($outJson);
    if (! is_dir($outDir)) {
        mkdir($outDir, 0777, true);
    }

    file_put_contents($outJson, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    printConsoleSummary($payload);

    exit(0);
} finally {
    if (is_file($cookieJar)) {
        @unlink($cookieJar);
    }
}

function loginWithSession(string $baseUrl, string $email, string $password, string $cookieJar, int $timeoutSeconds): void
{
    $csrfUrl = $baseUrl.'/api/sanctum/csrf-cookie';
    $csrfResponse = singleRequest(
        method: 'GET',
        url: $csrfUrl,
        cookieJar: $cookieJar,
        timeoutSeconds: $timeoutSeconds,
        headers: ['Accept: application/json']
    );

    if ($csrfResponse['curl_error'] !== '') {
        throw new RuntimeException('Falha ao obter CSRF cookie: '.$csrfResponse['curl_error']);
    }

    if ($csrfResponse['status_code'] >= 400) {
        throw new RuntimeException('Falha ao obter CSRF cookie, status '.$csrfResponse['status_code']);
    }

    $xsrfToken = readCookieValue($cookieJar, 'XSRF-TOKEN');
    if ($xsrfToken === null) {
        throw new RuntimeException('Cookie XSRF-TOKEN nao encontrado apos /sanctum/csrf-cookie');
    }

    $loginPayload = json_encode([
        'email' => $email,
        'password' => $password,
    ], JSON_UNESCAPED_SLASHES);

    $loginHeaders = [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-XSRF-TOKEN: '.urldecode($xsrfToken),
    ];

    $loginResponse = singleRequest(
        method: 'POST',
        url: $baseUrl.'/api/login',
        cookieJar: $cookieJar,
        timeoutSeconds: $timeoutSeconds,
        headers: $loginHeaders,
        body: $loginPayload
    );

    if ($loginResponse['curl_error'] !== '') {
        throw new RuntimeException('Falha no login: '.$loginResponse['curl_error']);
    }

    if ($loginResponse['status_code'] >= 400) {
        throw new RuntimeException('Login falhou com status '.$loginResponse['status_code'].': '.$loginResponse['body']);
    }

    $meResponse = singleRequest(
        method: 'GET',
        url: $baseUrl.'/api/me',
        cookieJar: $cookieJar,
        timeoutSeconds: $timeoutSeconds,
        headers: ['Accept: application/json']
    );

    if ($meResponse['status_code'] >= 400) {
        throw new RuntimeException('Falha em /api/me apos login, status '.$meResponse['status_code']);
    }
}

function runLoadForEndpoint(
    string $baseUrl,
    array $endpoint,
    string $cookieJar,
    int $totalRequests,
    int $concurrency,
    int $timeoutSeconds,
): array {
    $url = $baseUrl.$endpoint['path'];
    $method = (string) $endpoint['method'];

    $multi = curl_multi_init();
    $handles = [];
    $latencies = [];
    $statusCounts = [];
    $successCount = 0;
    $errorCount = 0;
    $networkErrorCount = 0;
    $launched = 0;
    $completed = 0;
    $startedAt = microtime(true);

    while ($completed < $totalRequests) {
        while ($launched < $totalRequests && count($handles) < $concurrency) {
            $ch = buildCurlHandle(
                method: $method,
                url: $url,
                cookieJar: $cookieJar,
                timeoutSeconds: $timeoutSeconds,
                headers: ['Accept: application/json']
            );

            $id = spl_object_id($ch);
            $handles[$id] = $ch;
            curl_multi_add_handle($multi, $ch);
            $launched++;
        }

        do {
            $execStatus = curl_multi_exec($multi, $running);
        } while ($execStatus === CURLM_CALL_MULTI_PERFORM);

        while ($info = curl_multi_info_read($multi)) {
            $ch = $info['handle'];
            $id = spl_object_id($ch);

            $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $latencyMs = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000;
            $curlError = (string) curl_error($ch);

            $latencies[] = $latencyMs;
            $statusKey = (string) $statusCode;
            $statusCounts[$statusKey] = (int) ($statusCounts[$statusKey] ?? 0) + 1;

            if ($curlError !== '') {
                $networkErrorCount++;
                $errorCount++;
            } elseif ($statusCode >= 400 || $statusCode <= 0) {
                $errorCount++;
            } else {
                $successCount++;
            }

            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
            unset($handles[$id]);
            $completed++;
        }

        if ($running > 0) {
            curl_multi_select($multi, 1.0);
        }
    }

    curl_multi_close($multi);

    $durationMs = max(1.0, (microtime(true) - $startedAt) * 1000);

    sort($latencies);

    $avgMs = average($latencies);
    $p95Ms = percentile($latencies, 95);
    $minMs = $latencies[0] ?? 0.0;
    $maxMs = $latencies[count($latencies) - 1] ?? 0.0;
    $errorRate = $totalRequests > 0 ? ($errorCount / $totalRequests) * 100 : 0.0;
    $throughputRps = $durationMs > 0 ? ($totalRequests / ($durationMs / 1000)) : 0.0;

    ksort($statusCounts);

    return [
        'name' => (string) $endpoint['name'],
        'method' => $method,
        'path' => (string) $endpoint['path'],
        'requests' => $totalRequests,
        'concurrency' => $concurrency,
        'duration_ms' => round($durationMs, 2),
        'latency_ms' => [
            'avg' => round($avgMs, 2),
            'p95' => round($p95Ms, 2),
            'min' => round($minMs, 2),
            'max' => round($maxMs, 2),
        ],
        'throughput_rps' => round($throughputRps, 2),
        'success_count' => $successCount,
        'error_count' => $errorCount,
        'network_error_count' => $networkErrorCount,
        'error_rate_pct' => round($errorRate, 2),
        'status_codes' => $statusCounts,
    ];
}

function aggregateSuiteMetrics(array $results, float $suiteDurationMs, int $concurrency): array
{
    $totalRequests = 0;
    $totalErrors = 0;
    $allLatencies = [];

    foreach ($results as $result) {
        $totalRequests += (int) $result['requests'];
        $totalErrors += (int) $result['error_count'];
        $allLatencies[] = (float) $result['latency_ms']['avg'];
    }

    $avgOfAverages = average($allLatencies);
    $globalErrorRate = $totalRequests > 0 ? ($totalErrors / $totalRequests) * 100 : 0.0;

    return [
        'duration_ms' => round($suiteDurationMs, 2),
        'total_requests' => $totalRequests,
        'total_errors' => $totalErrors,
        'error_rate_pct' => round($globalErrorRate, 2),
        'average_latency_avg_ms' => round($avgOfAverages, 2),
        'configured_concurrency' => $concurrency,
    ];
}

function buildCurlHandle(
    string $method,
    string $url,
    string $cookieJar,
    int $timeoutSeconds,
    array $headers,
    ?string $body = null,
): CurlHandle {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    return $ch;
}

function singleRequest(
    string $method,
    string $url,
    string $cookieJar,
    int $timeoutSeconds,
    array $headers,
    ?string $body = null,
): array {
    $ch = buildCurlHandle($method, $url, $cookieJar, $timeoutSeconds, $headers, $body);
    $bodyContent = curl_exec($ch);

    $response = [
        'status_code' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        'body' => is_string($bodyContent) ? $bodyContent : '',
        'curl_error' => (string) curl_error($ch),
    ];

    curl_close($ch);

    return $response;
}

function readCookieValue(string $cookieJar, string $cookieName): ?string
{
    $content = file_get_contents($cookieJar);
    if (! is_string($content)) {
        return null;
    }

    $lines = preg_split('/\R/', $content);
    if (! is_array($lines)) {
        return null;
    }

    foreach ($lines as $line) {
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = explode("\t", $line);
        if (count($parts) < 7) {
            continue;
        }

        $name = $parts[5] ?? '';
        $value = $parts[6] ?? '';

        if ($name === $cookieName) {
            return $value;
        }
    }

    return null;
}

function average(array $values): float
{
    if ($values === []) {
        return 0.0;
    }

    return array_sum($values) / count($values);
}

function percentile(array $sortedValues, int $p): float
{
    if ($sortedValues === []) {
        return 0.0;
    }

    $count = count($sortedValues);
    $rank = (int) ceil(($p / 100) * $count);
    $index = max(0, min($count - 1, $rank - 1));

    return (float) $sortedValues[$index];
}

function printConsoleSummary(array $payload): void
{
    echo "\n=== PERFORMANCE SUITE ===\n";
    echo 'Base URL: '.$payload['base_url']."\n";
    echo 'Requests por endpoint: '.$payload['requests_per_endpoint']."\n";
    echo 'Concorrencia: '.$payload['concurrency']."\n\n";

    foreach ($payload['endpoints'] as $endpoint) {
        echo '- '.$endpoint['name'].' ['.$endpoint['method'].' '.$endpoint['path']."]\n";
        echo '  avg: '.$endpoint['latency_ms']['avg'].' ms | p95: '.$endpoint['latency_ms']['p95'].' ms';
        echo ' | erros: '.$endpoint['error_count'].'/'.$endpoint['requests'].' ('.$endpoint['error_rate_pct']."%)\n";
        echo '  throughput: '.$endpoint['throughput_rps']." req/s\n";
    }

    echo "\nSuite: erros=".$payload['suite']['total_errors'].'/'.$payload['suite']['total_requests'];
    echo ' | taxa erro='.$payload['suite']['error_rate_pct'].'%';
    echo ' | avg(ms) dos avgs='.$payload['suite']['average_latency_avg_ms'];
    echo "\n";
}
