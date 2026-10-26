<?php

declare(strict_types=1);

namespace Speedat\Domain\SiteDoc;

use Speedat\Domain\Pricing\Format;

/**
 * The admin's working copy of the document. Port of `Draft` in
 * src/lib/site/repo.ts.
 *
 * There is exactly one, row id 1. `baseVersion` is the version it was branched
 * from: publishing when a newer version exists is a conflict somebody has to
 * resolve, not a silent overwrite of the other person's rates.
 *
 * ==========================================================================
 * ONE ROW, SEVERAL ADMINS - WHY THERE IS A REVISION
 * ==========================================================================
 *
 * `baseVersion` guards the draft against a PUBLISH that moved underneath it.
 * Nothing guarded the draft against another DRAFT WRITE: two admins with the
 * editor open both post the whole form, and the second save replaced the
 * first one's prices without a word on either screen. Worse, the owner could
 * then publish an edit he never made, because the review card renders
 * whatever the row now says.
 *
 * `revision` is a fingerprint of the row as it stands - the document, the
 * moment it was written, and who wrote it. The editor is rendered with the
 * revision it opened at and hands it back on save; `Repo::saveDraft()` refuses
 * when the stored row no longer has that fingerprint. It is DERIVED rather
 * than stored, so the guard needs no new column on a shared host.
 *
 * Two saves of the SAME bytes by the SAME person inside the same second share
 * a fingerprint and so do not collide. That is correct rather than merely
 * tolerable: there is nothing to lose when the loser would have written what
 * the winner wrote.
 */
final class Draft
{
    public function __construct(
        public readonly int $baseVersion,
        public readonly SiteData $data,
        /** ISO 8601 in UTC. */
        public readonly string $updatedAt,
        public readonly string $updatedBy,
        /**
         * The fingerprint of this exact row. Hand it back to
         * `Repo::saveDraft()` to say "I am editing what I was shown".
         */
        public readonly string $revision,
    ) {
    }

    /** A draft with its fingerprint computed from the three fields it covers. */
    public static function of(int $baseVersion, SiteData $data, string $updatedAt, string $updatedBy): self
    {
        return new self($baseVersion, $data, $updatedAt, $updatedBy, self::revisionOf($data, $updatedAt, $updatedBy));
    }

    /**
     * The fingerprint: the document's canonical JSON plus the two columns
     * that say who wrote it and when.
     *
     * Computed from `SiteData::toArray()` rather than from the bytes MySQL
     * handed back, because MySQL 8 reformats a JSON column and MariaDB does
     * not - a guard that behaves differently on the two is a guard nobody can
     * reason about. Short (16 hex characters) because it travels in a hidden
     * field on every autosave, and it is a change detector, not a secret.
     */
    public static function revisionOf(SiteData $data, string $updatedAt, string $updatedBy): string
    {
        $doc  = $data->toArray();
        $text = json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        // A document that cannot be encoded cannot be stored either, so this
        // arm is unreachable through the application. It is here so that a
        // fingerprint is always a fingerprint, never an empty string that
        // every broken document would share.
        if ($text === false) {
            $text = serialize($doc);
        }

        return substr(hash('sha256', $text . "\n" . $updatedAt . "\n" . $updatedBy), 0, 16);
    }

    /**
     * "edited by owner@speedat.pk at 28 Sept 2026, 14:05" - the sentence the
     * editor prints above the form, so nobody has to wonder whose prices are
     * on the screen.
     *
     * Empty when the row says neither who nor when, which is a row no person
     * wrote; the caller then prints nothing rather than a sentence about
     * nobody.
     */
    public function editedBy(): string
    {
        $who  = trim($this->updatedBy);
        $when = Format::fmtDateTime($this->updatedAt);

        // `Repo::catchUpDraft()` writes the draft as 'system' when it moves it
        // onto a newer live version; that is not a person to be named.
        if ($who === Repo::SYSTEM) {
            return 'brought up to date with the live website automatically' . ($when === '' ? '' : ' at ' . $when);
        }

        if ($who !== '' && $when !== '') {
            return 'edited by ' . $who . ' at ' . $when;
        }
        if ($who !== '') {
            return 'edited by ' . $who;
        }

        return $when === '' ? '' : 'edited at ' . $when;
    }
}
