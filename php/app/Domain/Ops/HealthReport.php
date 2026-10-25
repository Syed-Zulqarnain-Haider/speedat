<?php

declare(strict_types=1);

namespace Speedat\Domain\Ops;

/**
 * The answer to "is this site working", as data rather than as JSON, so the
 * rules can be tested without parsing a response body.
 *
 * WHAT IS NOT IN HERE IS THE POINT. No driver message, no file path, no host
 * name, no query. A health endpoint that reports which component is broken and
 * why is a map for whoever is deciding what to attack, and the detail is
 * already in storage/logs where it is useful and unreachable. The strongest
 * thing this will ever say about the database is "down".
 *
 * Three states:
 *   ok        everything the site needs is working
 *   degraded  the site is serving, but something an operator must fix is wrong
 *             (a storage directory it cannot write to)
 *   down      the site cannot do its job - the database is unreachable, or an
 *             extension it cannot run without is missing. This, and only this,
 *             answers 503.
 */
final class HealthReport
{
    /**
     * @param array<string,bool> $storage    directory name => writable
     * @param array<string,bool> $extensions extension name => loaded
     * @param list<string>       $missing    required extensions that are absent
     */
    public function __construct(
        public readonly bool $databaseUp,
        public readonly int $databaseMs,
        public readonly ?LiveVersion $live,
        public readonly Hold $hold,
        public readonly array $storage,
        public readonly array $extensions,
        public readonly array $missing,
        public readonly \DateTimeImmutable $at,
        public readonly string $environment,
    ) {
    }

    public function status(): string
    {
        if (!$this->databaseUp || $this->missing !== []) {
            return 'down';
        }

        return in_array(false, $this->storage, true) ? 'degraded' : 'ok';
    }

    public function ok(): bool
    {
        return $this->status() !== 'down';
    }

    /**
     * 503 when genuinely down. Degraded stays 200 on purpose: the site IS
     * answering customers, and an uptime monitor that pages the owner at 3am
     * because a log directory is read-only is a monitor he turns off.
     */
    public function httpStatus(): int
    {
        return $this->ok() ? 200 : 503;
    }

    /**
     * What anybody may see. One boolean - enough for an uptime check to go red,
     * not enough to learn anything from.
     *
     * @return array{ok:bool}
     */
    public function publicBody(): array
    {
        return ['ok' => $this->ok()];
    }

    /**
     * What the metrics token unlocks. `ok`, `php`, `env`, `database` and `time`
     * are the shape docs/deploy-hostinger.md §12 tells the operator to expect
     * and must not be renamed; the rest were added with this endpoint.
     *
     * @return array<string,mixed>
     */
    public function detailBody(): array
    {
        return [
            'ok'         => $this->ok(),
            'status'     => $this->status(),
            'php'        => PHP_VERSION,
            'env'        => $this->environment,
            'database'   => $this->databaseUp ? 'up' : 'down',
            'databaseMs' => $this->databaseMs,
            'time'       => $this->at->format('c'),
            'rates'      => [
                // -1, not 0: "never published" and "published this second" are
                // different answers and an alert rule has to tell them apart.
                'version'     => $this->live?->version ?? 0,
                'publishedAt' => $this->live?->publishedAt?->format('Y-m-d\TH:i:s\Z'),
                'ageSeconds'  => $this->live === null ? -1 : $this->live->ageSeconds($this->at),
                'live'        => $this->live?->live ?? false,
            ],
            'hold' => [
                'on'      => $this->hold->on,
                'since'   => $this->hold->since?->format('Y-m-d\TH:i:s\Z'),
                'seconds' => $this->hold->seconds($this->at),
            ],
            'storage'    => $this->storage,
            'extensions' => $this->extensions,
            'missing'    => $this->missing,
        ];
    }
}
