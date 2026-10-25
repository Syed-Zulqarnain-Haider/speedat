<?php

declare(strict_types=1);

namespace Speedat\Domain\Quotes;

use Speedat\Core\Money;
use Speedat\Domain\Leads\LeadRepository;
use Speedat\Domain\Pricing\Engine;
use Speedat\Domain\Pricing\Piece;
use Speedat\Domain\Pricing\QuoteText;
use Speedat\Domain\Pricing\RateCard;
use Speedat\Domain\Pricing\Weights;

/**
 * Logging a quote the customer acted on. Port of src/app/api/quotes/route.ts,
 * with the hole in it closed.
 *
 * ==========================================================================
 * THE BUG THIS FIXES, AND WHY IT MATTERED
 * ==========================================================================
 *
 * The Next.js route took TWO weights from the browser and treated them
 * differently:
 *
 *     const chargeG = Math.min(clientChargeG ?? billableG, billableG);
 *     const price = priceService(settings, dest, serviceId, billableG, type, chargeG);
 *                                                           ^^^^^^^^^
 *
 * The chargeable weight was clamped - and the BILLED weight, the one the price
 * is actually computed from, was used exactly as sent. Nothing recomputed it.
 * So a forged body could say:
 *
 *     { type: "doc", chargeG: 400, billableG: 400 }   for a 20 kg parcel
 *
 * and the server would price a 0.4 kg document, store the document rate, and
 * hand the office a lead saying the customer had booked a letter. The two
 * fields are not independent facts: the billed weight is the chargeable weight
 * rounded up to the next step, and a system that accepts both is a system that
 * accepts the assertion "these do not agree".
 *
 * The document rate itself is gone as of 2026-09-27 (owner), so that exact
 * forgery buys nothing today. The hole it came through is still closed, because
 * the same shape of lie - "bill me for less weight than I am sending" - is the
 * one every price on this site is exposed to.
 *
 * ==========================================================================
 * WHAT HAPPENS INSTEAD
 * ==========================================================================
 *
 * The server takes ONE weight input - the chargeable grams, or better, the
 * pieces - and derives everything else with the same engine the browser ran:
 *
 *   1. the billed weight comes from Engine::computeWeights(), never from the
 *      request;
 *   2. the price comes from Engine::priceService() against the published
 *      settings and the weight the server derived, never a weight the request
 *      asserted;
 *   3. add-on amounts come from the settings by label, never from the request;
 *   4. the total is the engine's, and the browser's number is kept only as a
 *      note when the two disagree.
 *
 * The browser's `billableG` and `total` are still accepted, because a tab
 * opened before this change still sends them and a rejected log would lose a
 * lead. They are recorded as `clientBillableG` and `clientTotal` in the stored
 * detail when they disagree with the engine, which is how a tampered request
 * leaves a trace instead of a price.
 *
 * WHY THE PIECES ARE PREFERRED WHEN THEY ARE SENT. The chargeable weight is
 * itself derived - it is the greater of actual and volumetric weight, per
 * piece, summed. Given the pieces the server can check that derivation too;
 * given only `chargeG` it has to take that one number on trust. Taking one
 * number on trust is unavoidable somewhere (the customer types the weight), but
 * it should be as close to the customer's own input as possible.
 */
final class QuoteService
{
    public function __construct(
        /** The published rate card the price must come from. Never the browser's copy. */
        private readonly RateCard $card,
        private readonly QuoteRepository $quotes = new QuoteRepository(),
        private readonly LeadRepository $leads = new LeadRepository(),
    ) {
    }

    /**
     * Price the quote again, store it, and - when Book was tapped - put it in
     * the inbox.
     *
     * @throws QuoteRejected
     */
    public function log(QuoteInput $input): QuoteRecord
    {
        $settings = $this->card->settings;

        // An inactive destination is the same refusal as an unknown one: which
        // of the two it is, is not something an endpoint tells a stranger.
        //
        // And it is now the same refusal as a malformed reference and as any
        // other bad field: one code, one sentence, one status. It used to be
        // `not_found` with a 404 and a sentence of its own, which meant the
        // three shapes of "no" were three different answers and a prober could
        // sort its guesses into "the id was wrong" and "the destination was
        // wrong". See the note on QuoteRejected.
        $dest    = $this->card->activeDestination($input->destId);
        $service = $this->card->service($input->serviceId);

        if ($dest === null || $service === null) {
            throw QuoteRejected::badRequest();
        }

        $weights = $this->weigh($input);

        // Above the cargo threshold the site offers no price at all - it shows
        // the "ask us for a cargo rate" message - so a quote arriving here for
        // one is stale or forged either way. The TypeScript route never checked
        // this because it never derived the weight; now that it does, refusing
        // costs nothing and closes the last way to get an arbitrary number into
        // the price.
        $maxG = $settings->maxKg > 0 ? Money::kgToG($settings->maxKg) : 0;
        if ($maxG > 0 && $weights->billableG > $maxG) {
            throw QuoteRejected::overMax();
        }

        $price = Engine::priceService(
            $settings,
            $dest,
            $input->serviceId,
            $weights->billableG,
        );

        // "This destination does not price that service" is a fact about the
        // rate card, not about the caller, and saying so out loud maps the
        // card one request at a time. Same answer as every other refusal.
        if ($price === null) {
            throw QuoteRejected::badRequest();
        }

        // Labels in, amounts out: resolveAddons looks each label up in the
        // published settings and drops the ones it no longer has.
        $addons = QuoteText::resolveAddons($settings, $input->addonLabels);
        $total  = (int) Money::jsRound(QuoteText::bookedTotal($price->total, $addons));

        $detail = $this->detail($input, $weights, $price->total, $addons, $total);

        $this->quotes->upsert(
            $input->id,
            $input->version,
            $input->destId,
            $input->serviceId,
            $weights->billableG,
            $total,
            $detail,
            $input->booked,
        );

        if ($input->booked) {
            // A tapped Book button is a lead for the inbox; the customer's
            // WhatsApp message is the conversation. Idempotent per quote id, so
            // a second tap does not make a second lead.
            $this->leads->upsertQuoteLead(
                $input->id,
                $input->destId,
                $weights->billableG,
                QuoteText::leadSummary(
                    $service->name,
                    $dest->name,
                    $weights->billableG,
                    $price->total,
                    $addons,
                    $settings->currency,
                    $input->piecesText,
                ),
                $input->contents,
            );
        }

        $stored = $this->quotes->find($input->id);
        if ($stored === null) {
            throw QuoteRejected::unavailable();
        }

        return $stored;
    }

    /**
     * The authoritative weights.
     *
     * Both paths go through Engine::computeWeights() rather than repeating its
     * arithmetic, so there is exactly one implementation of "round the
     * chargeable weight up to the next step, but never below the first slab" in
     * this codebase. When only the chargeable grams were sent, they are handed
     * to the engine as a single piece with no dimensions: actual equals
     * chargeable, no volumetric weight, and the billed weight falls out of the
     * same line of code the detailed form uses.
     */
    private function weigh(QuoteInput $input): Weights
    {
        $rows = $input->pieces;

        if ($rows === []) {
            // QuoteInput guarantees one of the two is present.
            $rows = [new Piece(kg: (float) $input->chargeG / 1000, qty: 1.0)];
        }

        $weights = Engine::computeWeights($this->card->settings, $rows);

        if ($weights->chargeG < 1 || $weights->billableG < 1) {
            throw QuoteRejected::badRequest();
        }
        if ($weights->billableG > QuoteInput::MAX_GRAMS) {
            throw QuoteRejected::overMax();
        }

        return $weights;
    }

    /**
     * What goes in the JSON column: the descriptive fields the browser sent,
     * plus the numbers the ENGINE produced, plus the browser's numbers only
     * when they disagreed.
     *
     * @param  list<\Speedat\Domain\Pricing\Addon> $addons
     * @return array<string,mixed>
     */
    private function detail(
        QuoteInput $input,
        Weights $weights,
        float $shipping,
        array $addons,
        int $total,
    ): array {
        $detail = [
            'chargeG'  => $weights->chargeG,
            'shipping' => $shipping,
            'addons'   => array_map(
                static fn (\Speedat\Domain\Pricing\Addon $a): array => ['label' => $a->label, 'amount' => $a->amount],
                $addons,
            ),
        ];

        foreach (
            [
                'piecesText' => $input->piecesText,
                'eta'        => $input->eta,
                'pickup'     => $input->pickup,
                'from'       => $input->from,
                'mode'       => $input->mode,
                'contents'   => $input->contents,
            ] as $key => $value
        ) {
            if ($value !== '') {
                $detail[$key] = $value;
            }
        }

        if ($input->pieces !== []) {
            $detail['actualG']        = $weights->actualG;
            $detail['volG']           = $weights->volG;
            $detail['pieces']         = $weights->pieces;
            $detail['volumetricWins'] = $weights->volumetricWins;
        }

        // The tamper trail. Both are "what was asked for", never "what was
        // charged"; the columns beside them hold what the engine said.
        if ($input->claimedTotal !== null && (int) Money::jsRound($input->claimedTotal) !== $total) {
            $detail['clientTotal'] = $input->claimedTotal;
        }
        if ($input->claimedBillableG !== null && $input->claimedBillableG !== $weights->billableG) {
            $detail['clientBillableG'] = $input->claimedBillableG;
        }

        return $detail;
    }
}
