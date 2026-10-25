<?php

declare(strict_types=1);

namespace Speedat\Core;

/**
 * Outbound HTTP, for the two things that need it: fetching Google's signing
 * certificates to verify a Firebase ID token, and whatever webhook the office
 * asks for later.
 *
 * It never throws. A network failure is a value - `ok` false with an `error`
 * string - because every caller here has a sensible fallback and none of them
 * should take the site down when a third party is slow.
 *
 * Certificate verification is on and there is no flag to turn it off.
 */
final class Http
{
    private function __construct(
        public readonly int $status,
        public readonly string $body,
        /** @var array<string,string> lower-cased response header names */
        public readonly array $headers,
        public readonly string $error,
    ) {
    }

    public function ok(): bool
    {
        return $this->error === '' && $this->status >= 200 && $this->status < 300;
    }

    /** @return array<array-key,mixed> */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Seconds this response may be reused for, from Cache-Control: max-age.
     * Google's certificate endpoint is the reason this exists - it says how
     * long its keys are good for and honouring that avoids a fetch per login.
     */
    public function maxAge(): int
    {
        if (preg_match('/max-age\s*=\s*(\d+)/i', $this->headers['cache-control'] ?? '', $m) === 1) {
            return (int) $m[1];
        }

        return 0;
    }

    /** @param array<string,string> $headers */
    public static function get(string $url, array $headers = [], int $timeout = 10): self
    {
        return self::request('GET', $url, null, $headers, $timeout);
    }

    /** @param array<string,string> $headers */
    public static function postJson(string $url, mixed $data, array $headers = [], int $timeout = 10): self
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return self::request(
            'POST',
            $url,
            $body === false ? '{}' : $body,
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'] + $headers,
            $timeout
        );
    }

    /** @param array<string,string> $headers */
    public static function postForm(string $url, array $fields, array $headers = [], int $timeout = 10): self
    {
        return self::request(
            'POST',
            $url,
            http_build_query($fields),
            ['Content-Type' => 'application/x-www-form-urlencoded'] + $headers,
            $timeout
        );
    }

    /** @param array<string,string> $headers */
    private static function request(string $method, string $url, ?string $body, array $headers, int $timeout): self
    {
        // Only http(s), and only an absolute URL: this function must never
        // become a way to read file:// or a local service.
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return new self(0, '', [], 'Refusing a non-http(s) URL');
        }

        if (!function_exists('curl_init')) {
            return new self(0, '', [], 'cURL is not available on this host');
        }

        $handle = curl_init();
        if ($handle === false) {
            return new self(0, '', [], 'cURL could not be initialised');
        }

        $responseHeaders = [];

        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => max(1, $timeout),
            CURLOPT_CONNECTTIMEOUT => min(5, max(1, $timeout)),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'Speedat/1.0 (+https://speedat.com.pk)',
            CURLOPT_HEADERFUNCTION => static function ($_handle, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ];

        if ($headers !== []) {
            $options[CURLOPT_HTTPHEADER] = array_map(
                static fn (string $k, string $v): string => $k . ': ' . $v,
                array_keys($headers),
                array_values($headers)
            );
        }

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($handle, $options);

        $result = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($handle);
        curl_close($handle);

        if (!is_string($result)) {
            Log::warn('http_failed', ['url' => self::redactUrl($url), 'error' => $error]);

            return new self($status, '', $responseHeaders, $error !== '' ? $error : 'Request failed');
        }

        return new self($status, $result, $responseHeaders, '');
    }

    /** Strip the query string before logging: tokens travel there. */
    private static function redactUrl(string $url): string
    {
        $q = strpos($url, '?');

        return $q === false ? $url : substr($url, 0, $q) . '?…';
    }
}
