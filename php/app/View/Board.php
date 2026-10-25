<?php

declare(strict_types=1);

namespace Speedat\View;

use Speedat\Domain\Pricing\Engine;
use Speedat\Domain\Pricing\Piece;

/**
 * The rates board: every active destination priced for a 1 kg parcel per
 * service. Port of `boardRows` in src/components/site/home/routes.ts, minus its
 * Documents column - the flat document rate left the product on 2026-09-27
 * (owner), so every number on the board is a weight price.
 *
 * Computed on the server, and under hold no price is produced at all - not
 * null-out-later, not hidden by CSS: `priceAll` is never called, so nothing
 * rate-shaped is in the HTML for a reader of the source to find.
 */
final class Board
{
    /** @return list<BoardRow> */
    public static function rows(Doc $doc): array
    {
        $addonsOn = Copy::addonsOnTotal($doc);
        $rows     = [];

        // Under hold the engine is never ASKED for a price - not asked and
        // told "no" afterwards. The difference decides what a cell may print,
        // so it is a name rather than a re-test of holdOn() further down.
        $asked = !$doc->holdOn();

        foreach ($doc->active() as $dest) {
            $result = $asked
                ? Engine::priceAll($doc->card(), $dest->id, [new Piece(kg: 1.0, qty: 1.0)])
                : null;

            // SKIPPABLE WEIGHTS (owner, 2026-09-28). A service whose 1 kg box is
            // blank answers null instead of falling to the 2 kg price, and the
            // owner's rule for that is "show those that do and say nothing about
            // the others" - so THE DAYS GO WITH THE PRICE.
            //
            // A cell printing "3-5 days" and no figure is not nothing: it is the
            // board promising a delivery time for a service the calculator two
            // inches away refuses to quote at the same 1 kg. That was live -
            // Germany's Express Saver carried days and no rate card at all - and
            // it is the dash the rule forbids, spelled in words.
            //
            // The days survive exactly one case, which is why $asked exists:
            // UNDER HOLD nothing was asked of the engine, every price on the
            // board is absent for that reason alone, and the caption says so.
            // The timetable is the whole of what a held board shows.
            $cells = [];
            foreach ($doc->services() as $service) {
                $price = $result !== null && $result->ok ? $result->price($service->id) : null;
                $days  = Copy::daysText($dest->rate($service->id)?->days);
                $mute  = $asked && $price === null;

                $cells[] = new BoardCell(
                    serviceId: $service->id,
                    price: $price === null ? null : $price->total + $addonsOn,
                    days: $days === '' || $mute ? null : $days,
                );
            }

            $rows[] = new BoardRow(
                id: $dest->id,
                name: $dest->name,
                cells: $cells,
                // The board sits below the fold on every page that carries it,
                // so its flags are the one set that may load lazily.
                flag: Flag::for($dest->name, $dest->id, 24, true),
            );
        }

        return $rows;
    }
}
