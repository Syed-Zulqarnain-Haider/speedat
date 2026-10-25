<?php

declare(strict_types=1);

namespace Speedat\Tests\Http;

use Speedat\Core\Csrf;
use Speedat\Core\HttpError;
use Speedat\Core\Request;
use Speedat\Domain\SiteDoc\Repo;
use Speedat\View\Admin\Routes;

/**
 * THE EDITOR IS ONE FORM CARRYING THE WHOLE RATE CARD, AND PHP WILL CUT IT OFF.
 *
 * `max_input_vars` stops the request parser after a fixed number of variables -
 * 1000 by default, 1000 in `deploy/htaccess/user.ini.sample`, 2000 on staging.
 * It is not an error and nothing is raised: `$_POST` simply ends early. So a
 * rate card past that size would save its first thousand fields over the rest
 * and answer "Draft saved", and the owner would find out when a customer was
 * quoted last week's price.
 *
 * MEASURED ON THE DEV SERVER, NOT SUPPOSED: ten countries priced per kilogram
 * to a 25 kg threshold post 667 fields; raising the threshold to 200 kg put
 * 4167 on the wire, PHP kept 998 of them, and `settings[maxKg]` - field 4136 -
 * silently did not save.
 *
 * So the save routes refuse a body that arrived short instead of writing half
 * of it. These tests drive that decision from both sides: a body that was cut
 * off is refused and changes nothing, and an ordinary body - including the
 * duplicate names a checkbox legitimately posts - is saved exactly as before.
 *
 * HOW THE CUT IS FAKED. `max_input_vars` cannot be changed at runtime, so the
 * test does what the server does to the application: it hands the route a
 * `$_POST` with fewer values in it than the body on the wire asked for.
 * `HttpTestCase`'s stream wrapper stands in for `php://input`, exactly as the
 * JSON tests use it.
 */
final class AdminFormSizeTest extends AdminTestCase
{
    private const DEST = 'gb';

    public function setUp(): void
    {
        parent::setUp();

        $this->admin();
        $this->signIn();
    }

    /* --------------------------------------------------------------- refused */

    public function testASubmitThatArrivedShortIsRefusedAndWritesNothing(): void
    {
        $before = $this->price(1);

        $answer = $this->postTruncated(Routes::SAVE, ['grid' => ['1' => '7777']]);

        $this->assertStringContains('err=toobig', $this->location($answer), 'the redirect does not say the form was too big');
        $this->assertSame($before, $this->price(1), 'a truncated submit wrote to the draft anyway');
        $this->assertSame(0, $this->audited('draft_saved'), 'a truncated submit was recorded as a save');
    }

    public function testATableActionThatArrivedShortIsRefusedToo(): void
    {
        $countBefore = count(Repo::getDraft()->data->toArray()['destinations']);

        $answer = $this->postTruncated(Routes::SORT_DESTINATIONS, [], ['sort' => 'az']);

        $this->assertStringContains('err=toobig', $this->location($answer), 'sorting on a truncated body was not refused');
        $this->assertSame($countBefore, count(Repo::getDraft()->data->toArray()['destinations']), 'the draft moved');
        $this->assertSame(0, $this->audited('destinations_sorted'), 'a refused sort was audited');
    }

    public function testReviewingATruncatedSubmitGoesBackToTheEditorWithoutSaving(): void
    {
        $before = $this->price(1);

        $answer = $this->postTruncated(Routes::REVIEW, ['grid' => ['1' => '8888']]);

        $this->assertStringContains(Routes::RATES, $this->location($answer), 'a truncated review did not go back to the editor');
        $this->assertStringContains('err=toobig', $this->location($answer), 'the redirect does not say why');
        $this->assertSame($before, $this->price(1), 'a truncated review saved the draft anyway');
    }

    /**
     * THE REFUSAL NAMES THE FIX, in the words of the buttons on the screen.
     *
     * "This rate card is too big" was true and useless: it left the owner
     * pressing the same button again, and the other half of that sentence -
     * "ask for max_input_vars to be raised" - is not something he can do at
     * six in the evening. The editor saves one country at a time now, so the
     * message points at the Save on the row he is looking at.
     */
    public function testTheOwnerIsToldWhatToDoAboutIt(): void
    {
        $body = $this->adminGet(Routes::RATES, ['err' => 'toobig'])->body();

        $this->assertStringContains('more than the server will accept in one go', $body, 'the editor does not explain the refusal');
        $this->assertStringContains('nothing was saved', $body, 'the message does not say the draft is untouched');
        $this->assertStringContains('Save this country', $body, 'the message does not name the fix');
        $this->assertStringContains('fewer changes at once', $body, 'nor the other half of it');
    }

    /* ------------------------------------------------- one country at a time */

    /**
     * THE EDITOR POSTS ONE COUNTRY, AND THAT IS WHY IT FITS.
     *
     * The rates table used to be a single form carrying every country; with a
     * third service it reached 1009 variables against a `max_input_vars` of
     * 1000, and the editor could not save at all. A row is its own form now,
     * so a save is one country's worth however many countries the document
     * has - and the rows it does not carry are left exactly as they were,
     * which is the property that makes the small post safe.
     */
    public function testOneCountrySavesOnItsOwnAndLeavesTheOthersAlone(): void
    {
        $before = [];
        foreach (Repo::getDraft()->data->toArray()['destinations'] as $row) {
            $before[(string) $row['id']] = $row;
        }

        $this->assertTrue(count($before) > 1, 'the seed has only one country, so this test proves nothing');

        $post = ['dest' => [self::DEST => [
            'name'     => (string) $before[self::DEST]['name'],
            'position' => '0',
            'active'   => '1',
            'rates'    => ['normal' => ['grid' => ['1' => '4242']]],
        ]]];

        $answer = $this->postWithBody(Routes::SAVE, $post, self::encode($post));

        $this->assertStringNotContains('err=', $this->location($answer), 'a one-country save was refused');
        $this->assertSame(4242.0, $this->price(1), 'the country that was posted did not save');

        $after = [];
        foreach (Repo::getDraft()->data->toArray()['destinations'] as $row) {
            $after[(string) $row['id']] = $row;
        }

        $this->assertSame(array_keys($before), array_keys($after), 'a one-country save reordered or dropped rows');

        foreach ($before as $id => $row) {
            if ($id === self::DEST) {
                continue;
            }
            $this->assertSame($row, $after[$id], $id . ' changed when another country was saved');
        }
    }

    /**
     * And what it puts on the wire is nowhere near the limit. Counted the way
     * `max_input_vars` counts - every scalar leaf, plus the three keys every
     * writing form carries - because that is what PHP stops at.
     */
    public function testOneCountrysPostIsFarUnderTheLimit(): void
    {
        $grid = [];
        foreach (range(1, 25) as $kg) {
            $grid[(string) $kg] = (string) ($kg * 1000);
        }

        // Three services, which is what the document has since Express Saver
        // was added and what made the old single form impossible.
        $rates = [];
        foreach (['normal', 'express', 'saver'] as $service) {
            $rates[$service] = ['days' => '3-5', 'fill' => ['start' => '', 'step' => ''], 'grid' => $grid];
        }

        $post = ['dest' => [self::DEST => [
            'name'     => 'United Kingdom',
            'position' => '0',
            'active'   => '1',
            'rates'    => $rates,
        ]]];

        $variables = substr_count(self::encode($post), '&') + 1 + 3; // + token, revision, expectedBase

        $this->assertTrue(
            $variables < 200,
            'one country with three 25 kg ladders posts ' . $variables . ' variables; the whole document posted 1009',
        );
    }

    /* --------------------------------------------------------------- accepted */

    public function testAnOrdinaryFullSubmitIsNotMistakenForATruncatedOne(): void
    {
        $post = $this->smallForm(['grid' => ['1' => '6161']]);

        $answer = $this->postWithBody(Routes::SAVE, $post, self::encode($post));

        $this->assertStringNotContains('err=toobig', $this->location($answer), 'a whole submit was refused as truncated');
        $this->assertSame(6161.0, $this->price(1), 'the whole submit did not save');
        $this->assertSame(1, $this->audited('draft_saved'), 'the save was not recorded');
    }

    /**
     * A CHECKBOX POSTS ITS NAME TWICE - a hidden "0" and then its own "1" - and
     * PHP keeps the last, so the body legitimately holds more PAIRS than `$_POST`
     * holds values. Counting pairs would refuse every real submit the editor
     * makes; counting distinct names is why this one is accepted.
     */
    public function testTheHiddenHalfOfACheckboxIsNotMistakenForAMissingField(): void
    {
        $post = $this->smallForm(['grid' => ['1' => '6262']]);
        $body = self::encode($post);

        // Every `active` box, posted the way a browser posts a checkbox.
        foreach (array_keys($post['dest']) as $id) {
            $body = 'dest%5B' . rawurlencode((string) $id) . '%5D%5Bactive%5D=0&' . $body;
        }

        $answer = $this->postWithBody(Routes::SAVE, $post, $body);

        $this->assertStringNotContains('err=toobig', $this->location($answer), 'the checkbox pattern was read as a cut-off body');
        $this->assertSame(6262.0, $this->price(1), 'the submit did not save');
    }

    public function testAPostWithNoBodyAtAllIsNotCalledTruncated(): void
    {
        // The guard must never fire on a request it cannot measure: no body
        // means nothing to compare, and `save()` has its own answer for that.
        $answer = $this->postWithBody(Routes::SAVE, [], '');

        $this->assertStringContains('err=nothing', $this->location($answer), 'an empty post should be refused as empty, not as too big');
    }

    /* ------------------------------------------------- past post_max_size */

    /**
     * A BODY PHP THREW AWAY WHOLE IS A 400 - NOT "THIS FORM EXPIRED".
     *
     * `max_input_vars` cuts a body SHORT, which is everything above.
     * `post_max_size` discards it ENTIRELY: `$_POST` is empty, `$_FILES` is
     * empty, no error code is raised, and the only trace left is the
     * Content-Length the client announced. So the CSRF check had no token to
     * find and answered "This form expired. Please reload the page" - sending
     * the owner off to reload a page that was never the problem, about a rate
     * card that is simply bigger than one request.
     *
     * 400 is the quality bar's answer to an input cap ("input caps make a giant
     * payload a 400"), and it is the status `/contact` gives for the same thing,
     * so the site has ONE story about a body it cannot read. Measured before
     * this changed: a 12 MB post spent three and a half seconds on the wire and
     * was answered 200. `deploy/htaccess` should be refusing the body before PHP
     * starts at all; this branch is the floor under that, not the plan.
     */
    public function testAPostWhosePayloadWasDiscardedIsABadRequestAndNotATokenError(): void
    {
        $before = $this->price(1);

        $answer = $this->discarded(Routes::SAVE);

        $this->assertStatus(400, $answer, 'a discarded body was not a bad request');
        $this->assertSame($before, $this->price(1), 'a discarded body changed the draft');
        $this->assertSame(0, $this->audited('draft_saved'), 'a discarded body was recorded as a save');

        // The token error is what this used to be, and it was the wrong thing to
        // say: the token was not wrong, it was never delivered. That refusal is
        // a 403 now - it was 419 until Apache was measured turning an
        // unregistered status into a 500 on the wire
        // (Core\HttpError::invalidToken) - so this asks the factory what the
        // token refusal is rather than pinning a number nothing answers.
        $this->assertNotSame(
            HttpError::invalidToken()->status,
            $answer->response->status,
            'a discarded body is still being called an expired form'
        );
    }

    /** The same answer everywhere, because it is the door and not one handler. */
    public function testEveryAdminWriteAnswersADiscardedPayloadTheSameWay(): void
    {
        foreach ([Routes::PUBLISH, Routes::DISCARD, Routes::BULK, Routes::SORT_DESTINATIONS] as $path) {
            $this->assertStatus(400, $this->discarded($path), $path);
        }

        $this->assertSame(1, $this->liveVersion(), 'a discarded body published a version');
    }

    /* ------------------------------------------------------------ internals */

    /**
     * A signed-in POST that announced a body and arrived with nothing in it -
     * the shape a request has after PHP discarded it for being past
     * `post_max_size`. No token, because there is nowhere for one to have
     * survived.
     */
    private function discarded(string $path): Answer
    {
        return $this->send(Request::synthetic(
            'POST',
            $path,
            [],
            [],
            ['content-type' => 'application/x-www-form-urlencoded', 'content-length' => '12582912'],
            $this->cookies(),
        ));
    }

    /**
     * A signed-in POST whose `$_POST` is $post but whose body on the wire is
     * $body - the shape a request has after PHP stopped parsing it early.
     *
     * @param array<string,mixed> $post
     */
    private function postWithBody(string $path, array $post, string $body): Answer
    {
        $cookies = $this->cookies();
        $post    = $post + [Csrf::FIELD => $this->token($cookies)];
        $headers = ['content-type' => 'application/x-www-form-urlencoded'];

        InputStream::$body = $body;
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', InputStream::class);

        try {
            return $this->send(Request::synthetic('POST', $path, $post, [], $headers, $cookies));
        } finally {
            stream_wrapper_restore('php');
            InputStream::$body = '';
        }
    }

    /**
     * The same POST, with a body that asks for far more fields than `$_POST`
     * carries - which is exactly what the parser leaves behind when it stops.
     *
     * @param array<string,mixed> $rates one destination's changed cells
     * @param array<string,mixed> $extra a button, for the table actions
     */
    private function postTruncated(string $path, array $rates = [], array $extra = []): Answer
    {
        $post = $this->smallForm($rates) + $extra;
        $body = self::encode($post);

        // The tail PHP threw away: names that are in the body and in no $_POST.
        for ($i = 0; $i < 40; $i++) {
            $body .= '&settings%5Bdropped' . $i . '%5D=1';
        }

        return $this->postWithBody($path, $post, $body);
    }

    /**
     * The editor's post, small enough to read: every destination's identity and
     * one destination's grid, which is all these tests change.
     *
     * @param  array<string,mixed> $rates
     * @return array<string,mixed>
     */
    private function smallForm(array $rates = []): array
    {
        $document = Repo::getDraft()->data->toArray();
        $dest     = [];

        foreach ($document['destinations'] as $index => $row) {
            $id        = (string) $row['id'];
            $dest[$id] = [
                'name'     => (string) $row['name'],
                'position' => (string) $index,
                'active'   => ($row['active'] ?? false) === true ? '1' : '0',
            ];

            if ($id === self::DEST && $rates !== []) {
                $dest[$id]['rates'] = ['normal' => $rates];
            }
        }

        return ['dest' => $dest];
    }

    /** @param array<string,mixed> $post */
    private static function encode(array $post): string
    {
        return http_build_query($post);
    }

    /** One stored draft price for the destination these tests edit. */
    private function price(int $kg): ?float
    {
        foreach (Repo::getDraft()->data->toArray()['destinations'] as $row) {
            if ((string) ($row['id'] ?? '') !== self::DEST) {
                continue;
            }

            $value = $row['rates']['normal']['grid'][(string) $kg] ?? null;

            return is_numeric($value) ? (float) $value : null;
        }

        return null;
    }
}
