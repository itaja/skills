<?php
// Itaja adapter — plain PHP 8.1+, no framework, no dependency (ext-curl, ext-json).
// Reference implementation of the contract in itaja-adapter/SKILL.md. Server-side only.

declare(strict_types=1);

final class ItajaError extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
        public readonly array $details = [],
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $status);
    }
}

final class ItajaClient
{
    private const RETRYABLE_STATUS = [429, 502, 503];
    private const MAX_RETRIES = 2;

    private string $apiKey;
    private string $baseUrl;

    public function __construct(?string $apiKey = null, ?string $baseUrl = null, private int $timeoutSeconds = 30)
    {
        $this->apiKey = $apiKey ?? (string) getenv('ITAJA_API_KEY');
        $this->baseUrl = rtrim($baseUrl ?? (getenv('ITAJA_API_URL') ?: 'https://api.getitaja.com/api/v1'), '/');
        if (!preg_match('/^itj[sp]_/', $this->apiKey)) {
            throw new ItajaError('ITAJA_API_KEY is missing or is not an Itaja key', 'CONFIG', 0);
        }
    }

    /** `sandbox` for itjs_ keys, `live` for itjp_ keys. */
    public function mode(): string
    {
        return str_starts_with($this->apiKey, 'itjs_') ? 'sandbox' : 'live';
    }

    /**
     * Any developer-API route. Returns `data` of the envelope, throws ItajaError otherwise.
     * Every write carries an Idempotency-Key, reused on each retry of this call.
     */
    public function request(string $method, string $path, ?array $body = null, array $query = [], ?string $idempotencyKey = null): mixed
    {
        $method = strtoupper($method);
        $isWrite = $method !== 'GET';
        if ($isWrite && $idempotencyKey === null) {
            $idempotencyKey = bin2hex(random_bytes(16));
        }
        $url = $this->baseUrl . '/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : '');

        for ($attempt = 0; ; $attempt++) {
            [$status, $headers, $raw, $networkError] = $this->send($method, $url, $body, $isWrite ? $idempotencyKey : null);
            $json = is_string($raw) ? json_decode($raw, true) : null;
            $code = is_array($json) ? (string) ($json['code'] ?? '') : '';
            $retryAfter = isset($headers['retry-after']) ? (int) $headers['retry-after'] : null;

            $retryable = $networkError !== null
                || in_array($status, self::RETRYABLE_STATUS, true)
                || ($status === 409 && $code === 'IDEMPOTENCY_KEY_IN_PROGRESS');
            if ($retryable && $attempt < self::MAX_RETRIES) {
                usleep(min(30, $retryAfter ?? (2 ** $attempt)) * 1_000_000);
                continue;
            }
            if ($networkError !== null) {
                throw new ItajaError($networkError, 'CONNECTION', 0);
            }
            if ($status >= 200 && $status < 300 && is_array($json) && ($json['success'] ?? false) === true) {
                return $json['data'] ?? null;
            }
            // A missing key answers a plain-text 401: never echo the request, only the status.
            throw new ItajaError(
                is_array($json) ? (string) ($json['error'] ?? "HTTP $status") : "HTTP $status",
                $code !== '' ? $code : "HTTP_$status",
                $status,
                is_array($json) && is_array($json['details'] ?? null) ? $json['details'] : [],
                $retryAfter,
            );
        }
    }

    /** @return array{0:int,1:array<string,string>,2:string|false,3:?string} */
    private function send(string $method, string $url, ?array $body, ?string $idempotencyKey): array
    {
        $headers = ['x-itj-key: ' . $this->apiKey, 'accept: application/json'];
        if ($body !== null) {
            $headers[] = 'content-type: application/json';
        }
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        $responseHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        $raw = curl_exec($ch);
        $error = $raw === false ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return [$status, $responseHeaders, $raw, $error];
    }
}

final class ItajaWebhook
{
    /**
     * Verifies a delivery and returns the decoded event, or null when it must be refused (answer 400).
     * $rawBody is the exact request body (php://input), never a re-encoded array.
     */
    public static function verify(string $rawBody, ?string $signatureHeader, ?string $timestampHeader, string $secret, int $toleranceSeconds = 300, ?int $now = null): ?array
    {
        if ($signatureHeader === null || $timestampHeader === null || !ctype_digit($timestampHeader)) {
            return null;
        }
        if (abs(($now ?? time()) - (int) $timestampHeader) > $toleranceSeconds) {
            return null;
        }
        $expected = hash_hmac('sha256', $timestampHeader . '.' . $rawBody, $secret, true);
        $parts = explode(',', $signatureHeader);
        if (array_shift($parts) !== 'v1') {
            return null;
        }
        foreach ($parts as $candidate) {
            $given = base64_decode($candidate, true);
            if ($given !== false && hash_equals($expected, $given)) {
                $event = json_decode($rawBody, true);
                return is_array($event) ? $event : null;
            }
        }
        return null;
    }
}
