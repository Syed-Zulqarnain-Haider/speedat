<?php

declare(strict_types=1);

namespace Speedat\Http;

use Speedat\Core\App;
use Speedat\Core\Ip;
use Speedat\Core\Log;
use Speedat\Core\RateLimit;
use Speedat\Core\Response;
use Speedat\Core\Str;
use Speedat\Domain\Pricing\Engine;
use Speedat\Domain\Pricing\Piece;
use Speedat\Domain\Pricing\RateCard;
use Speedat\Domain\Quotes\QuoteInput;
use Speedat\Domain\Quotes\QuoteRecord;
use Speedat\Domain\Quotes\QuoteRejected;
use Speedat\Domain\Quotes\QuoteRepository;
use Speedat\Domain\Quotes\QuoteService;

/**
 * POST /api/quotes - log a quote the customer acted on. Port of
 * `src/app/api/quotes/route.ts`, WITHOUT its flaw.
 *
 * The browser fires this with `keepalive` when Book is tapped and never waits
 * for the answer, so nothing a customer sees depends on it. What the office
 * sees does: the row this writes is the price they will be asked to honour.
 *
 * ==========================================================================
 * THE BROWSER SENDS INPUTS. THE SERVER DECIDES NUMBERS.
 * ==========================================================================
 *
 * The Next.js route priced with the BILLED weight the browser sent, so a forged
 * body could say "these 20 kg weigh 0.4 kg" and be charged for 0.4 kg.
 * `Domain\Quotes\QuoteInput` refuses to carry that assertion and `QuoteService`
 * derives the billed weight, the shipping, every add-on amount and the total
 * from the published document with the same engine the browser ran. This
 * controller's whole job is to hand those two the request and turn what they
 * refuse into an HTTP status.
 *
 * The body no longer carries a shipment type: the document rate left the product
 * on 2026-09-27 (owner), so `type` is neither required nor read. A body from a
 * tab opened before the change still logs, with the key ignored.
 *
 * ==========================================================================
 * WHY THERE IS NO CSRF TOKEN ON THIS ROUTE
 * ==========================================================================
 *
 * CSRF protects an action taken with somebody's authority. This endpoint has no
 * session, no cookie and no authority: it appends a row to a log whose every
 * number the server computes for itself, and the worst a forged request can
 * achieve is a junk row that says what the engine says. Two controls are in
 * place instead, and both are the right shape for the threat:
 *
 *   - THE CONTENT TYPE MUST BE JSON. A cross-site `<form>` can only post
 *     `application/x-www-form-urlencoded`, `multipart/form-data` or `text/plain`,
 *     so requiring JSON already excludes the one cross-origin request a browser
 *     will send without asking us first.
 *   - THE ORIGIN, WHEN THERE IS ONE, MUST BE OURS. `Core\Csrf::originOk()` is
 *     the same check the forms use, and it allows an absent Origin for the
 *     clients that strip it.
 *
 * Plus the per-IP rate limit below, which is what actually stops somebody
 * filling the table.
 *
 * ==========================================================================
 * A REFERENCE IS WRITTEN ONCE. IT IS NEVER REWRITTEN INTO ANOTHER SHIPMENT
 * ==========================================================================
 *
 * The row is stored by quote reference and the write is an UPSERT, because the
 * same reference legitimately arrives twice - the browser fires this with
 * `keepalive` and a customer who taps Book again sends it again. That is also
 * how the record could be TAKEN OVER: the reference is printed in the
 * customer's own WhatsApp message, so anybody who has seen that message (a
 * forwarded chat, a shared phone, a screenshot) could post the same reference
 * back with a different country and a different weight, and the office's record
 * of what was quoted would quietly become something nobody quoted.
 *
 * Re-pricing does not close that: the server computing the price honestly from
 * a forged destination still stores an honest price for the WRONG SHIPMENT, and
 * the office is asked to honour the row, not the message.
 *
 * So: WHERE IT GOES, HOW IT TRAVELS AND WHAT IT WEIGHS ARE FIXED BY THE FIRST
 * POST. A second post under the same reference may confirm the quote and may
 * change the add-ons a customer ticks; it may not move the shipment. The three
 * pinned fields are exactly the inputs the price is a function of, and they are
 * compared against the STORED row rather than against anything the body claims.
 *
 * WHY NOT AN HMAC BINDING IT TO THE BROWSER THAT MINTED IT. The reference is
 * minted in the browser (`Pricing\Format::quoteId()` and its JavaScript twin)
 * before anything is posted, because the WhatsApp message is composed first.
 * There is no server round trip to sign it in, so a signature would mean a new
 * endpoint, a new secret in the page, and a customer whose booking is lost when
 * the two disagree - to protect a log row whose numbers the server already
 * computes for itself. Pinning the shipment costs one read and refuses the same
 * attack.
 *
 * WHY ADD-ONS ARE NOT PINNED. Insurance and packing are ticked on the same
 * screen after the price is shown, and in the quick calculator the reference
 * does not change when they are (it is seeded from the country, the weight and
 * the service). Refusing that would lose the real booking it describes. Their
 * amounts are the published document's in any case - `QuoteText::resolveAddons`
 * looks each label up and drops what it does not have - so an add-on cannot
 * invent a number, only pick a published one.
 *
 * THE REFUSAL IS THE SAME FLAT 400 as every other one. "That reference already
 * exists" would turn this endpoint into a way to test whether a reference is
 * real, one guess at a time, which is exactly the oracle the rest of the route
 * is built not to be.
 *
 * ==========================================================================
 * IT NEVER RETURNS 500
 * ==========================================================================
 *
 * Every refusal is a documented code in the project's envelope,
 * `{"error": {"code": "...", "message": "..."}}`, and garbage in any field is a
 * 400 rather than an exception: the browser fires this from a page the customer
 * has already left, so a 500 here is an alert nobody can act on about a request
 * nobody is waiting for. The only non-4xx failure is 503, when the row could not
 * be written.
 */
final class QuoteApiController
{
    /**
     * Sixty a minute per IP, as route.ts sets it. Deliberately generous: one
     * customer comparing four countries at three weights fires a dozen, and a
     * limiter that refuses a real shopper has cost more than it saved.
     *
     * NOT `config('limits.quote_per_hour')`, for the reason given on
     * `ContactController::LIMIT`: that key names an hour, not a minute.
     */
    private const LIMIT        = 60;
    private const LIMIT_WINDOW = 60;

    /**
     * A quote body is a few hundred bytes; ten pieces with dimensions is under
     * two kilobytes. 32 KB is room to spare and small enough that a megabyte of
     * nonsense is refused before `json_decode()` is asked to parse it.
     */
    private const MAX_BODY = 32768;

    public function log(App $app): Response
    {
        $request = $app->request;

        /* the limit first: it is the cheapest check and the one that has to
           hold when the others are being probed in a loop. */
        $limit = RateLimit::hit('quotes', $request->ip(), self::LIMIT, self::LIMIT_WINDOW);
        if (!$limit->ok) {
            Log::warn('quote_rate_limited', ['ip' => Ip::hash($request->ip()), 'count' => $limit->count]);

            return self::error('rate_limited', 'Too many requests', 429)
                ->header('Retry-After', (string) $limit->retryAfter);
        }

        /* the two things that stand in for a CSRF token here - see the note above */
        if (!str_contains(strtolower($request->header('content-type')), 'application/json')) {
            return self::error('bad_request', 'Invalid quote', 400);
        }
        if (!$app->csrf()->originOk($request)) {
            Log::warn('quote_origin_rejected', ['origin' => Str::limit($request->header('origin'), 100)]);

            return self::error('bad_request', 'Invalid quote', 400);
        }

        $body = $request->json(self::MAX_BODY);
        if ($body === []) {
            return self::error('bad_request', 'Invalid quote', 400);
        }

        // No quotes are issued while prices are on hold: nothing on the site
        // offers one, so anything arriving here is stale or forged. Checked
        // before the body is parsed so a held site tells a probe nothing about
        // which fields it would have wanted.
        $doc = Page::doc();
        if ($doc->holdOn()) {
            return self::error('held', 'Prices are being updated', 409);
        }

        try {
            /** @var array<string,mixed> $body */
            $input = QuoteInput::fromArray(self::normalise($body));

            // The reference is somebody's already-issued quote, or it is new.
            // If it is somebody's, this post may confirm it; it may not turn it
            // into a different shipment. See the note above.
            $stored = (new QuoteRepository())->find($input->id);
            if ($stored !== null && self::movesTheShipment($doc->card(), $stored, $input)) {
                Log::warn('quote_reference_rewrite_refused', [
                    'id' => $input->id,
                    'ip' => Ip::hash($request->ip()),
                ]);

                throw QuoteRejected::badRequest();
            }

            $record = (new QuoteService($doc->card()))->log($input);
        } catch (QuoteRejected $e) {
            // The code and the sentence are the domain's; neither says which of
            // several checks failed, so probing the endpoint teaches nothing.
            return self::error($e->errorCode, $e->getMessage(), $e->httpStatus());
        } catch (\Throwable $e) {
            // A driver error, a full disk, a column that moved. Logged in full,
            // reported as one word.
            Log::exception('quote_log_failed', $e);

            return self::error('unavailable', 'Could not save the quote', 503);
        }

        Log::info('quote_logged', [
            'id'       => $record->id,
            'booked'   => $record->booked,
            'tampered' => $record->wasTampered(),
        ]);

        // What route.ts answers: `{ok:true}` and nothing else. The price is not
        // echoed back - the browser has its own copy, and the row is the record.
        return Response::json(['ok' => true])->noStore();
    }

    /**
     * Would this post change WHICH SHIPMENT the stored reference describes?
     *
     * Three fields, and they are three rather than four because they are
     * exactly the inputs the price is a function of: the destination, the
     * service, and the chargeable weight. Everything else on the row is either
     * derived from those (the billed weight, the shipping, the total) or
     * descriptive (the ETA, the piece list as text, what is inside).
     *
     * THE WEIGHT IS THE ENGINE'S, ON BOTH SIDES. `detail.chargeG` was written by
     * `Domain\Quotes\QuoteService` from the engine, so the value it is compared
     * with has to come from the engine too - otherwise a detailed quote, whose
     * chargeable weight is the greater of actual and volumetric, would look
     * different from itself on every repeat. The single-piece fallback mirrors
     * `QuoteService::weigh()`: when only the chargeable grams were sent there is
     * one line, that weight, no dimensions.
     *
     * WHAT A CHANGED RATE DOCUMENT DOES. Republishing can move the billed weight
     * (the slab rounding) and the price; it cannot move the chargeable weight
     * unless the volumetric divisor changes. So an honest repeat across an
     * ordinary publish still matches, and the one case that does not - a divisor
     * change between two taps of Book - refuses a LOG the browser never waits
     * for and leaves the first row standing. That is the right way round: the
     * record the office already has survives.
     *
     * A STORED ROW WITH NO `chargeG` cannot be compared on weight, so it is
     * compared on the other two. There is no such row today (`QuoteService`
     * always writes it), and a guard that guesses at a missing number would be
     * refusing honest posts over a field it invented.
     */
    private static function movesTheShipment(RateCard $card, QuoteRecord $stored, QuoteInput $input): bool
    {
        if ($stored->destId !== $input->destId || $stored->serviceId !== $input->serviceId) {
            return true;
        }

        $wasG = $stored->detail['chargeG'] ?? null;

        if (!is_int($wasG) && !(is_float($wasG) && is_finite($wasG))) {
            return false;
        }

        $rows = $input->pieces === []
            ? [new Piece(kg: (float) $input->chargeG / 1000, qty: 1.0)]
            : $input->pieces;

        return Engine::computeWeights($card->settings, $rows)->chargeG !== (int) $wasG;
    }

    /**
     * Adapt the body the live browser actually sends to the shape `QuoteInput`
     * reads.
     *
     * ONE FIELD COLLIDES. `public/assets/js/calculator.js` sends `pieces` as a
     * COUNT - `pieces: 1` on the quick quote, `pieces: res.weights.pieces` on
     * the detailed one - because that is what the Next.js route's zod schema
     * ignored (it declared no `pieces` field, and zod strips what it does not
     * declare). `QuoteInput::pieces()` reads the same name as the piece ROWS and
     * throws `Invalid quote` on a scalar, so every genuine quote from the live
     * calculator would be refused with 400.
     *
     * A count carries no information the server can use - the weights come from
     * `chargeG` or from real rows - so a non-list `pieces` is dropped here, at
     * the boundary, and a genuine list is passed through untouched. This is a
     * translation, not a repair: the mismatch is reported so that one of the two
     * sides can be renamed properly.
     *
     * @param  array<array-key,mixed> $body
     * @return array<string,mixed>
     */
    private static function normalise(array $body): array
    {
        if (array_key_exists('pieces', $body) && !is_array($body['pieces'])) {
            unset($body['pieces']);
        }

        $out = [];
        foreach ($body as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** The project's error envelope. Never a stack trace, a path or a query. */
    private static function error(string $code, string $message, int $status): Response
    {
        return Response::json(['error' => ['code' => $code, 'message' => $message]], $status)->noStore();
    }
}
