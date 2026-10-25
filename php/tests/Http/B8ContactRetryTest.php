<?php

declare(strict_types=1);

namespace Speedat\Tests\Http;

use Speedat\Core\Clock;
use Speedat\Core\Db;
use Speedat\Http\FormToken;

/**
 * A quick corrected resend is not "That was quick" (fixed 2026-10-25).
 *
 * The customer is told the phone number is wrong, fixes one character and
 * presses Send inside three seconds. The page they were sent back used to carry
 * a render token stamped "now", so the fix was refused as too fast - and again
 * on every quick retry. A refusal for the FIELDS now hands back a token that is
 * already old enough; a refusal for the TOKEN still hands back a fresh one.
 */
final class B8ContactRetryTest extends HttpTestCase
{
    private const WHO = 'Http B8 Retry';

    public function setUp(): void
    {
        parent::setUp();
        $this->clean();
    }

    public function tearDown(): void
    {
        parent::tearDown();
        $this->clean();
    }

    private function clean(): void
    {
        if (!Db::healthy()) {
            return;
        }

        Db::exec("DELETE FROM rate_limits WHERE k LIKE 'contact:%'");
        Db::exec('DELETE FROM leads WHERE name = ?', [self::WHO]);
    }

    public function testAFieldRefusalHandsBackATokenThatCanBeSentAtOnce(): void
    {
        $guards = $this->contactGuards();

        $answer = $this->post('/contact', [
            'name'    => self::WHO,
            'phone'   => '0300.1234567',
            'message' => 'Two boxes of clothes to Canada.',
            '_token'  => $guards['token'],
            't'       => FormToken::issue(Clock::millis() - FormToken::MIN_AGE_MS - 3000),
        ], [], $guards['cookies']);

        $this->assertStatus(422, $answer);
        $this->assertSame(FormToken::OK, FormToken::check($this->renderToken($answer)), 'the corrected resend would be refused as too fast');
        $this->assertStringContains('aria-invalid="true" autofocus', $answer->body(), 'the refused phone takes focus');
    }

    public function testATooFastRefusalStillHandsBackAFreshToken(): void
    {
        $guards = $this->contactGuards();

        $answer = $this->post('/contact', [
            'name'    => self::WHO,
            'phone'   => '03001234567',
            'message' => 'Two boxes of clothes to Canada.',
            '_token'  => $guards['token'],
            't'       => $guards['form'],
        ], [], $guards['cookies']);

        $this->assertStatus(422, $answer);
        $this->assertStringContains('That was quick', $this->formNotice($answer));
        $this->assertSame(FormToken::TOO_FAST, FormToken::check($this->renderToken($answer)), 'the three seconds were skipped');
    }

    private function renderToken(Answer $answer): string
    {
        preg_match('/name="t" value="([^"]*)"/', $answer->body(), $m);

        return $m[1] ?? '';
    }
}
