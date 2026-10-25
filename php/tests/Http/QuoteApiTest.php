<?php

declare(strict_types=1);

namespace Speedat\Tests\Http;

use Speedat\Core\Db;
use Speedat\Domain\SiteDoc\Hold;
use Speedat\Domain\SiteDoc\Live;

/**
 * POST /api/quotes over HTTP.
 *
 * `tests/Quotes/QuoteServiceTest.php` proves the domain reprices a forged body;
 * this proves the ROUTE does - that the request actually reaches it, that the
 * body the live browser sends is accepted, that the price in the row is the
 * engine's, and that no shape of nonsense gets a 500 out of it.
 *
 * WHY THAT LAST ONE MATTERS HERE MORE THAN USUAL. calculator.js fires this with
 * `keepalive` when Book is tapped and never reads the answer; the customer is
 * already in WhatsApp. So a 500 is an alert about a request nobody is waiting
 * for, and a stack trace in the response body is one nobody would ever see - it
 * would go straight into a log somebody else reads.
 */
final class QuoteApiTest extends HttpTestCase
{
    /** Every quote id this file writes starts with this, so tearDown can find them. */
    private const PREFIX = 'SP-990101-';

    public function setUp(): void
    {
        parent::setUp();

        if (!Db::healthy()) {
            $this->skip('the test database is not reachable - start MariaDB (bin/local/start-db.ps1) and run bin/migrate.php --database=speedat_php_test');
        }

        $this->clean();
    }

    public function tearDown(): void
    {
        if (Db::healthy()) {
            // Put the hold back however the test left it, before anything else
            // reads it: a hold left on would take the prices off every page in
            // every test after this one.
            Hold::set(false, 'tests');
            $this->clean();
        }

        parent::tearDown();
    }

    private function clean(): void
    {
        Db::exec("DELETE FROM rate_limits WHERE k LIKE 'quotes:%'");
        Db::exec('DELETE FROM leads WHERE quote_id LIKE ?', [self::PREFIX . '%']);
        Db::exec('DELETE FROM quotes WHERE id LIKE ?', [self::PREFIX . '%']);
    }

    private function id(string $tail): string
    {
        return self::PREFIX . $tail;
    }

    /**
     * The body `public/assets/js/calculator.js` posts on a quick quote, field
     * for field - including `pieces` as a COUNT, which is what it really sends.
     *
     * No `type` and no `docRate`: the document rate left the product on
     * 2026-09-27 and the browser stopped sending either. A body that still
     * carries them is covered by its own test below.
     *
     * @param  array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function browserBody(string $id, array $overrides = []): array
    {
        return array_merge([
            'id'         => $id,
            'from'       => '',
            'dest'       => 'Canada',
            'destId'     => 'ca',
            'service'    => 'Express',
            'serviceId'  => 'express',
            'days'       => '4-6',
            'eta'        => '2 to 4 October',
            'pickup'     => 'Monday',
            'pieces'     => 1,
            'piecesText' => '1 x 5 kg',
            'billableG'  => 5000,
            'chargeG'    => 5000,
            'total'      => 12345,
            'version'    => 0,
            'booked'     => true,
            'mode'       => 'quick',
            'addons'     => [],
        ], $overrides);
    }

    /** @return array<string,mixed>|null */
    private function row(string $id): ?array
    {
        return Db::one('SELECT dest_id, service_id, type, billable_g, total, booked, detail FROM quotes WHERE id = ?', [$id]);
    }

    /* ===================================================================== */
    /*  THE HAPPY PATH IS THE BROWSER'S ACTUAL BODY                          */
    /* ===================================================================== */

    /**
     * The endpoint accepts what the live calculator sends and stores the price
     * the ENGINE computed, not the one in the request.
     *
     * The body claims a total of 12,345. Whatever the published document prices
     * 5 kg express to Canada at, the row holds that - and the claim is kept
     * beside it as `clientTotal`, which is how a disagreement leaves a trace
     * instead of a price.
     */
    public function testTheBodyTheBrowserActuallySendsIsAccepted(): void
    {
        $id     = $this->id('AAAA');
        $answer = $this->postJson('/api/quotes', (string) json_encode($this->browserBody($id)));

        $this->assertStatus(200, $answer);
        $this->assertContentType('application/json; charset=utf-8', $answer);
        $this->assertSame(['ok' => true], $answer->json(), 'route.ts answers {ok:true} and nothing else');

        $row = $this->row($id);
        $this->assertNotNull($row, 'nothing was written');

        $detail = json_decode((string) ($row['detail'] ?? ''), true);
        $this->assertTrue(is_array($detail), 'the detail column did not decode');

        $total    = (int) ($row['total'] ?? 0);
        $shipping = is_array($detail) && is_numeric($detail['shipping'] ?? null) ? (float) $detail['shipping'] : -1.0;

        $this->assertSame(5000, (int) ($row['billable_g'] ?? 0), 'the billed weight came from the engine');
        $this->assertTrue($total > 0, 'the stored total is not a price');
        $this->assertNotSame(12345, $total, 'the browser\'s number was stored as the price');
        $this->assertSame((float) $total, $shipping, 'no add-ons were on, so the total is the shipping');
        $this->assertSame(12345.0, (float) ($detail['clientTotal'] ?? 0), 'the claim was not recorded');
    }

    /** A tapped Book button is a lead for the inbox, keyed on the quote. */
    public function testABookedQuoteMakesOneLeadHoweverManyTimesItIsSent(): void
    {
        $id   = $this->id('BBBB');
        $body = (string) json_encode($this->browserBody($id));

        $this->assertStatus(200, $this->postJson('/api/quotes', $body));
        $this->assertStatus(200, $this->postJson('/api/quotes', $body));

        $leads = Db::value('SELECT COUNT(*) FROM leads WHERE quote_id = ?', [$id], 0);
        $this->assertSame(1, is_numeric($leads) ? (int) $leads : -1, 'a second tap made a second lead');
    }

    /* ===================================================================== */
    /*  THE REFERENCE IS NOT A WRITE KEY ANYBODY MAY PICK UP                 */
    /* ===================================================================== */

    /**
     * MINT A QUOTE IN ONE BROWSER; TRY TO OVERWRITE IT FROM ANOTHER.
     *
     * The reference is printed in the customer's own WhatsApp message, so
     * "somebody who knows it" is not a hypothetical - it is anybody the chat was
     * forwarded to. The second post sends the same reference with a different
     * country and a heavier parcel, which is the shape that matters: re-pricing
     * on the server does not save the office here, because an honest price for
     * the wrong shipment is still the wrong record.
     *
     * The row the office reads afterwards must still be the first quote,
     * untouched, and the refusal must be the endpoint's flat "Invalid quote" so
     * that it cannot be used to find out which references exist.
     */
    public function testASecondBrowserCannotRewriteAQuoteItOnlyKnowsTheReferenceOf(): void
    {
        $id = $this->id('CCCC');

        $this->assertStatus(200, $this->postJson('/api/quotes', (string) json_encode($this->browserBody($id))));

        $minted = $this->row($id);
        $this->assertNotNull($minted, 'the first quote was not logged');

        $attack = $this->postJson('/api/quotes', (string) json_encode($this->browserBody($id, [
            'dest'       => 'United Kingdom',
            'destId'     => 'gb',
            'piecesText' => '1 x 20 kg',
            'billableG'  => 20000,
            'chargeG'    => 20000,
            'total'      => 99999,
        ])));

        $this->assertStatus(400, $attack, 'a second browser was allowed to rewrite the quote');
        $this->assertSame('bad_request', $attack->json()['error']['code'] ?? null, 'the refusal names the reference');
        $this->assertSame('Invalid quote', $attack->json()['error']['message'] ?? null, 'the refusal is not the flat one');

        $after = $this->row($id);
        $this->assertNotNull($after, 'the stored quote disappeared');
        $this->assertSame($minted['dest_id'] ?? null, $after['dest_id'] ?? null, 'the destination was overwritten');
        $this->assertSame($minted['billable_g'] ?? null, $after['billable_g'] ?? null, 'the billed weight was overwritten');
        $this->assertSame($minted['total'] ?? null, $after['total'] ?? null, 'the price the office will be asked to honour was overwritten');
    }

    /** Same reference, same country, a different service is a different price - and refused too. */
    public function testTheServiceOnAnIssuedReferenceCannotBeSwappedEither(): void
    {
        $id = $this->id('CCCD');

        $this->assertStatus(200, $this->postJson('/api/quotes', (string) json_encode($this->browserBody($id))));

        $answer = $this->postJson('/api/quotes', (string) json_encode($this->browserBody($id, [
            'service'   => 'Economy',
            'serviceId' => 'normal',
        ])));

        $this->assertStatus(400, $answer, 'the service was swapped under an issued reference');
        $this->assertSame('express', $this->row($id)['service_id'] ?? null, 'the stored service moved');
    }

    /**
     * THE HONEST REPEAT STILL WORKS, which is the other half of the guard.
     *
     * `keepalive` means the browser never learns whether the first post landed,
     * and a customer who taps Book twice sends the same body twice. That is one
     * row, updated, and never a refusal - a guard that refused it would lose the
     * booked flag the inbox is built on.
     */
    public function testTheSameQuoteSentTwiceIsStillAcceptedAndStillOneRow(): void
    {
        $id   = $this->id('CCCE');
        $body = (string) json_encode($this->browserBody($id, ['booked' => false]));

        $this->assertStatus(200, $this->postJson('/api/quotes', $body));
        $this->assertStatus(200, $this->postJson('/api/quotes', (string) json_encode($this->browserBody($id))));

        $rows = Db::value('SELECT COUNT(*) FROM quotes WHERE id = ?', [$id], 0);
        $this->assertSame(1, is_numeric($rows) ? (int) $rows : -1, 'the repeat made a second row');
        $this->assertSame(1, (int) ($this->row($id)['booked'] ?? 0), 'the repeat did not record the booking');
    }

    /**
     * An add-on ticked between the two taps is not "a different shipment".
     *
     * The quick calculator seeds the reference from the country, the weight and
     * the service and NOT from the add-ons, so a customer who ticks insurance
     * and books again sends the same reference with a different total. Refusing
     * it would leave the office holding a price the customer's own message does
     * not show. The amount is still the published document's, never the body's.
     */
    public function testTickingAnAddOnUnderTheSameReferenceIsNotTreatedAsARewrite(): void
    {
        $id = $this->id('CCCF');

        $this->assertStatus(200, $this->postJson('/api/quotes', (string) json_encode($this->browserBody($id))));

        $answer = $this->postJson('/api/quotes', (string) json_encode($this->browserBody($id, [
            'addons' => ['Pickup and service charges'],
        ])));

        $this->assertStatus(200, $answer, 'a customer ticking an add-on was refused');
    }

    /* ===================================================================== */
    /*  THE ATTACK                                                           */
    /* ===================================================================== */

    /**
     * The hole this endpoint exists to have closed: a body that sends 20 kg and
     * asserts it is billed as 0.5 kg.
     *
     * The Next.js route priced with the billed weight the browser sent, so the
     * assertion was the price. Here the chargeable weight is the only weight
     * input, the billed one is derived from it, and the browser's claim is kept
     * only as a note beside the real number.
     *
     * The body still carries the retired `type: "doc"` and `docRate` keys on
     * purpose - a tab opened before 2026-09-27 sends them - and they must change
     * nothing: no flat rate, no refusal, no missing lead.
     */
    public function testAForgedBilledWeightIsStoredAsTheParcelItReallyIs(): void
    {
        $id = $this->id('CCCC');

        $answer = $this->postJson('/api/quotes', (string) json_encode($this->browserBody($id, [
            'type'      => 'doc',
            'docRate'   => true,
            'chargeG'   => 20000,
            'billableG' => 500,
            'total'     => 2200,
            'booked'    => false,
        ])));

        $this->assertStatus(200, $answer);

        $row = $this->row($id);
        $this->assertNotNull($row);
        $this->assertSame(20000, (int) ($row['billable_g'] ?? 0), 'the billed weight was taken from the request');

        $detail = json_decode((string) ($row['detail'] ?? ''), true);
        $this->assertTrue(is_array($detail));
        $this->assertSame(20000, $detail['chargeG'] ?? null);

        // Neither retired key survives into the stored row.
        $this->assertFalse(array_key_exists('docRate', $detail), 'a docRate was stored');
        $this->assertFalse(array_key_exists('type', $detail), 'a shipment type was stored');

        // The attempt is recorded rather than lost.
        $this->assertSame(2200.0, (float) ($detail['clientTotal'] ?? 0));
        $this->assertSame(500, $detail['clientBillableG'] ?? null);
        $this->assertTrue((int) ($row['total'] ?? 0) > 2200, 'the stored price is the browser\'s claim');
    }

    /**
     * Nothing in any field can produce a 500.
     *
     * Each of these is a real shape somebody sends: a truncated body, a scalar
     * where an object belongs, an array where a string belongs, numbers as
     * words, a weight from another line of business.
     */
    public function testNoShapeOfNonsenseEverCausesAServerError(): void
    {
        $bodies = [
            'not JSON at all'                => 'this is not json',
            'an empty body'                  => '',
            'an empty object'                => '{}',
            'a JSON array'                   => '[1,2,3]',
            'a bare string'                  => '"hello"',
            'a bare number'                  => '42',
            'null'                           => 'null',
            'a truncated object'             => '{"id":"SP-990101-ZZZZ","destId":',
            'a bad quote reference'          => (string) json_encode($this->browserBody('not-a-reference')),
            'an unknown destination'         => (string) json_encode($this->browserBody($this->id('DDDD'), ['destId' => 'atlantis'])),
            'an unknown service'             => (string) json_encode($this->browserBody($this->id('EEEE'), ['serviceId' => 'teleport'])),
            'a retired shipment type'        => (string) json_encode($this->browserBody($this->id('FFFF'), ['type' => 'livestock'])),
            'no weight of any kind'          => (string) json_encode(['id' => $this->id('GGGG'), 'destId' => 'ca', 'serviceId' => 'express', 'type' => 'pkg', 'version' => 0]),
            'a weight past the cargo limit'  => (string) json_encode($this->browserBody($this->id('HHHH'), ['chargeG' => 900000, 'billableG' => 900000])),
            'a negative weight'              => (string) json_encode($this->browserBody($this->id('IIII'), ['chargeG' => -5])),
            'words where numbers belong'     => (string) json_encode($this->browserBody($this->id('JJJJ'), ['chargeG' => 'heavy', 'total' => 'free'])),
            'arrays where strings belong'    => (string) json_encode(['id' => ['x'], 'destId' => ['y'], 'serviceId' => ['z'], 'type' => ['w'], 'chargeG' => ['v']]),
            'piece rows that are not rows'   => (string) json_encode($this->browserBody($this->id('KKKK'), ['pieces' => ['not a row', 7]])),
            'two hundred piece rows'         => (string) json_encode($this->browserBody($this->id('LLLL'), ['pieces' => array_fill(0, 200, ['kg' => 1])])),
            'a hundred add-on labels'        => (string) json_encode($this->browserBody($this->id('MMMM'), ['addons' => array_fill(0, 100, 'Pickup')])),
            'a megabyte of padding'          => (string) json_encode($this->browserBody($this->id('NNNN'), ['contents' => str_repeat('x', 1_000_000)])),
            'a deeply nested body'           => (string) json_encode(['id' => $this->id('OOOO'), 'destId' => 'ca', 'serviceId' => 'express', 'type' => 'pkg', 'chargeG' => 1000, 'pieces' => [['kg' => ['deeper' => ['deeper' => ['deeper' => 1]]]]]]),
        ];

        foreach ($bodies as $what => $body) {
            $answer = $this->postJson('/api/quotes', $body);

            $this->assertTrue(
                $answer->response->status >= 200 && $answer->response->status < 500,
                $what . ': answered ' . $answer->response->status,
            );

            $json = $answer->json();
            if ($answer->response->status === 200) {
                continue;
            }

            // The project's envelope, every time, with a code a client can act
            // on and a sentence a person can read.
            $error = is_array($json['error'] ?? null) ? $json['error'] : [];
            $this->assertTrue(is_string($error['code'] ?? null), $what . ': no error code');
            $this->assertTrue(is_string($error['message'] ?? null), $what . ': no message');

            // And nothing about the inside of the server.
            foreach (['Exception', 'Stack trace', 'SQLSTATE', 'app/Domain', 'app/Http', '.php'] as $leak) {
                $this->assertStringNotContains($leak, $answer->body(), $what . ': the response leaked "' . $leak . '"');
            }
        }
    }

    /**
     * A cross-site `<form>` cannot post JSON, so requiring the content type is
     * what stands in for a CSRF token on an endpoint that has no session.
     */
    public function testAFormContentTypeIsRefused(): void
    {
        $answer = $this->post('/api/quotes', $this->browserBody($this->id('PPPP')), [
            'content-type' => 'application/x-www-form-urlencoded',
        ]);

        $this->assertStatus(400, $answer);
        $this->assertSame('bad_request', $answer->json()['error']['code'] ?? null);
    }

    public function testAForeignOriginIsRefused(): void
    {
        $answer = $this->postJson(
            '/api/quotes',
            (string) json_encode($this->browserBody($this->id('QQQQ'))),
            ['origin' => 'https://evil.example'],
        );

        $this->assertStatus(400, $answer);
        $this->assertNull($this->row($this->id('QQQQ')), 'a foreign origin still wrote a row');
    }

    /* ===================================================================== */
    /*  THE HOLD                                                             */
    /* ===================================================================== */

    /**
     * While prices are on hold nothing on the site offers a price, so a quote
     * arriving here is stale or forged either way: 409, and nothing stored.
     */
    public function testNoQuoteIsLoggedWhileThePricesAreOnHold(): void
    {
        Hold::set(true, 'tests', 'Rates are being updated.');
        Live::forget();

        $id     = $this->id('RRRR');
        $answer = $this->postJson('/api/quotes', (string) json_encode($this->browserBody($id)));

        $this->assertStatus(409, $answer);
        $this->assertSame('held', $answer->json()['error']['code'] ?? null);
        $this->assertNull($this->row($id), 'a quote was logged during a hold');
    }

    /** And the quote page shows the by-hand form instead, with no price in it. */
    public function testTheHomePageUnderHoldCarriesNoPriceAndNoPricingEngine(): void
    {
        Hold::set(true, 'tests', 'Rates are being updated right now.');
        Live::forget();

        $answer = $this->get('/');
        $html   = $answer->body();

        $this->assertStatus(200, $answer);
        $this->assertStringContains('data-hold-panel', $html, 'the hold panel is missing');
        $this->assertStringNotContains('id="speedat-calculator"', $html, 'the rate document reached a held page');

        preg_match_all('#<script defer src="/assets/([^"?]+)#', $html, $m);
        $this->assertSame(['js/ui.js', 'js/calculator.js'], $m[1], 'pricing.js was shipped to a held page');

        // NO PRICE IN THE PAGE. The currency appearing nowhere in the document
        // body is the shortest proof, because every printed figure on this site
        // is printed with it.
        //
        // The BODY, not the whole response: the head's JSON-LD still carries
        // `priceRange` and `currenciesAccepted`, which `Domain\Seo\SiteFacts`
        // computes from the rate card with no knowledge that the hold exists -
        // the hold lives outside the published document and `SiteFacts` is only
        // given the document. That is a gap in the SEO layer, not in this
        // route, and it is reported rather than papered over here; when
        // `SiteFacts` learns about the hold this assertion can widen to the
        // whole response.
        $head = strpos($html, '</head>');
        $this->assertTrue($head !== false, 'the page has no head');
        $this->assertStringNotContains('PKR', substr($html, (int) $head), 'a price survived the hold in the page body');
    }

    /* ===================================================================== */
    /*  THE LIMIT                                                            */
    /* ===================================================================== */

    /** Sixty a minute per address, then 429 with a Retry-After. */
    public function testTheEndpointIsRateLimitedPerAddress(): void
    {
        $body = (string) json_encode($this->browserBody($this->id('SSSS'), ['booked' => false]));

        // 60 are allowed; the 61st is not. The first is the real one, the rest
        // are repeats of the same id (an upsert, so one row).
        $last = null;
        for ($i = 1; $i <= 61; $i++) {
            $last = $this->postJson('/api/quotes', $body);
            if ($i < 61) {
                $this->assertTrue($last->response->status < 400, 'request ' . $i . ' answered ' . $last->response->status);
            }
        }

        $this->assertNotNull($last);
        $this->assertStatus(429, $last);
        $this->assertSame('rate_limited', $last->json()['error']['code'] ?? null);
        $this->assertNotSame('', $last->header('Retry-After'), 'a 429 without Retry-After is a 429 nobody can obey');
    }

    /** GET is not a way to write to the log. */
    public function testGettingTheEndpointIsAMethodError(): void
    {
        $answer = $this->get('/api/quotes');

        $this->assertStatus(405, $answer);
        // The one method the path is registered for, so a client is told what to
        // do instead of guessing.
        $this->assertSame('POST', $answer->header('Allow'));
        // A browser following a stale link sends `Accept: text/html`; a machine
        // endpoint still answers in the envelope rather than with a page.
        $this->assertContentType('application/json; charset=utf-8', $answer);
        $this->assertSame('method_not_allowed', $answer->json()['error']['code'] ?? null);
    }
}
