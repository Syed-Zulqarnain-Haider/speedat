<?php

declare(strict_types=1);

namespace Speedat\Domain\SiteDoc;

/**
 * What `Repo::catchUpDraft()` did to a draft that had fallen behind, kept in
 * app_settings until the next publish or discard so the editor can say it on
 * every visit and not only on the one request that happened to do it.
 *
 * `conflicts` are the fields both the draft and the newer live version had
 * changed, each with both values: the merge kept the live one, and the owner
 * is shown the one they typed so it can be put back if it was the right one.
 */
final class RebaseNote
{
    /** Shown on screen at most this many; the rest are counted. */
    public const SHOWN = 20;

    /** @param list<array{path:string, live:mixed, draft:mixed}> $conflicts */
    public function __construct(
        public readonly int $from,
        public readonly int $to,
        public readonly int $carried,
        public readonly array $conflicts,
        public readonly string $at,
    ) {
    }

    /** "Your draft was started from version 17 ..." - the one sentence the editor leads with. */
    public function sentence(): string
    {
        $edits = $this->carried === 1 ? '1 unpublished change was' : $this->carried . ' unpublished changes were';

        return 'Version ' . $this->to . ' went live after this draft was started from version ' . $this->from
            . '. The draft has been moved onto version ' . $this->to . ': ' . $edits . ' kept, and everything '
            . 'version ' . $this->to . ' changed is kept too, so publishing will not undo it.';
    }

    /** "Changed in both" lines as text, value-for-value: "Settings > addons: kept 'X', you had 'Y'". */
    public function conflictLines(): array
    {
        $out = [];
        foreach (array_slice($this->conflicts, 0, self::SHOWN) as $c) {
            $out[] = $c['path'] . ': kept the live ' . self::show($c['live']) . ' - your draft had ' . self::show($c['draft']);
        }

        return $out;
    }

    public function moreConflicts(): int
    {
        return max(0, count($this->conflicts) - self::SHOWN);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'from'      => $this->from,
            'to'        => $this->to,
            'carried'   => $this->carried,
            'conflicts' => $this->conflicts,
            'at'        => $this->at,
        ];
    }

    /** Forgiving: a damaged row reads as "nothing to say" rather than an error page in the editor. */
    public static function fromArray(mixed $raw): ?self
    {
        if (!is_array($raw) || !isset($raw['from'], $raw['to'])) {
            return null;
        }

        $conflicts = [];
        foreach (is_array($raw['conflicts'] ?? null) ? $raw['conflicts'] : [] as $c) {
            if (is_array($c) && isset($c['path'])) {
                $conflicts[] = ['path' => (string) $c['path'], 'live' => $c['live'] ?? null, 'draft' => $c['draft'] ?? null];
            }
        }

        return new self((int) $raw['from'], (int) $raw['to'], (int) ($raw['carried'] ?? 0), $conflicts, (string) ($raw['at'] ?? ''));
    }

    private static function show(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '(blank)';
        }
        if (is_bool($v)) {
            return $v ? 'on' : 'off';
        }
        if (is_scalar($v)) {
            return '"' . mb_strimwidth((string) $v, 0, 80, '...') . '"';
        }

        return '(a list)';
    }
}
