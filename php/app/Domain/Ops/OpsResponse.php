<?php

declare(strict_types=1);

namespace Speedat\Domain\Ops;

/**
 * What an operational endpoint answers with, before anything knows whether it
 * is being served over HTTP or printed by a cron job.
 *
 * The Ops classes deliberately do NOT return Speedat\Core\Response: the same
 * three answers (health, metrics, cron) are produced from a route AND from the
 * command line, and a CLI job that has to build an HTTP response object to
 * print a line is a job that will one day send headers into a cron log. This
 * carries the three things both callers need - a status code, headers, a body
 * - and the route adapter turns it into a Core\Response in one line.
 *
 * Every answer is no-store. None of these endpoints is ever correct to cache:
 * a cached health check is a lie with a timestamp on it.
 */
final class OpsResponse
{
    /** The JSON flags used everywhere in this application. */
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    /** @param array<string,string> $headers */
    private function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $encoded = json_encode($data, self::JSON_FLAGS);

        return new self(
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'] + self::noStore(),
            $encoded === false ? '{}' : $encoded,
        );
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/plain; charset=utf-8'] + self::noStore(), $body);
    }

    /**
     * The exposition format Prometheus scrapes. The version parameter is part
     * of the media type and not decoration: a scraper that does not see it
     * falls back to guessing.
     */
    public static function prometheus(string $body): self
    {
        return new self(200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8'] + self::noStore(), $body);
    }

    /**
     * The error envelope the TypeScript endpoints used, kept verbatim so an
     * existing monitor's matcher still fires: {"error":{"code":…,"message":…}}.
     */
    public static function error(string $code, string $message, int $status): self
    {
        return self::json(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    public static function unauthorized(string $message): self
    {
        return self::error('unauthorized', $message, 401);
    }

    /**
     * The body parsed back, for tests and for a CLI caller that wants the
     * numbers rather than the text.
     *
     * @return array<array-key,mixed>
     */
    public function decoded(): array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function contentType(): string
    {
        return $this->headers['Content-Type'] ?? '';
    }

    /** @return array<string,string> */
    private static function noStore(): array
    {
        return ['Cache-Control' => 'no-store, no-cache, must-revalidate, private'];
    }
}
