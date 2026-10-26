<?php

declare(strict_types=1);

namespace Speedat\View;

use Speedat\Domain\Pricing\Service;

/**
 * The rates board as the template prints it: the caption, the column heads and
 * the rows. Port of `src/components/site/home/RatesBoard.tsx`, minus its
 * Documents column and the `hasDoc` flag that used to switch it on - the flat
 * document rate left the product on 2026-09-27 (owner), so the columns are the
 * country and one per service, always.
 *
 * A reference, not a control: nothing here is tappable. Rendered on the home
 * page (third child of `section.calc`) and again on /services. Under hold the
 * prices are null, only the days print, and the caption says so - so no pickup
 * charge and no limit leak.
 */
final class BoardView
{
    /** @param list<BoardRow> $rows */
    private function __construct(
        public readonly array $rows,
        /** `content.routesTitle`, or "Rates". */
        public readonly string $title,
        /** `content.routesNote`, or the generated caption. */
        public readonly string $note,
        public readonly string $currency,
        /** @var list<Service> */
        public readonly array $services,
        /** "Sample prices for now." renders under the board until the rates are live. */
        public readonly bool $sampleNotice,
    ) {
    }

    public static function build(Doc $doc): self
    {
        $rows = Board::rows($doc);

        return new self(
            rows: $rows,
            title: $doc->trimmed('routesTitle') !== '' ? $doc->trimmed('routesTitle') : 'Rates',
            // Under a hold the board shows days only, so the owner's caption
            // ("1 kg parcel - rates apply ...") would describe prices that are
            // not there; the automatic one says what the board does show.
            note: !$doc->holdOn() && $doc->trimmed('routesNote') !== '' ? $doc->trimmed('routesNote') : Copy::boardNote($doc),
            currency: $doc->currency(),
            services: $doc->services(),
            sampleNotice: $doc->sampleNotice(),
        );
    }

    /** No rows, no table: RatesBoard returns null rather than an empty frame. */
    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
