<?php

declare(strict_types=1);

namespace Speedat\Tests\Http;

use Speedat\Core\Config;
use Speedat\Core\Csrf;
use Speedat\Core\Session;
use Speedat\Http\Admin\SetupController;

/**
 * The first-run setup limit counts guesses at the KEY, not form mistakes.
 *
 * An operator holding the right key who failed the password policy five times
 * got a 429 on the sixth try and a dead page for fifteen minutes (review,
 * 2026-10-23). What is held here: right-key mistakes never draw the limit,
 * wrong keys still do, and once the limit is hit even the right key is refused
 * - so a caller past the limit cannot learn which guess was right.
 */
final class B11SetupLimitTest extends AdminTestCase
{
    /** The controller's ATTEMPTS. */
    private const ATTEMPTS = 5;

    /** 32 characters, over the controller's MIN_TOKEN, and nothing real. */
    private const KEY = 'b11-setup-key-0123456789abcdef00';

    private mixed $tokenBefore = null;

    private mixed $passwordLoginBefore = null;

    public function setUp(): void
    {
        parent::setUp();

        $this->tokenBefore         = Config::get('auth.setup_token');
        $this->passwordLoginBefore = Config::get('auth.allow_password_login');

        Config::set('auth.setup_token', self::KEY);
        Config::set('auth.allow_password_login', true);
    }

    public function tearDown(): void
    {
        Config::set('auth.setup_token', $this->tokenBefore);
        Config::set('auth.allow_password_login', $this->passwordLoginBefore);

        parent::tearDown();
    }

    public function testRightKeyFormMistakesNeverDrawTheLimit(): void
    {
        for ($i = 0; $i < self::ATTEMPTS * 2; $i++) {
            $answer = $this->postSetup([
                'setup_key'      => self::KEY,
                'email'          => self::OWNER,
                'password'       => self::PASSWORD,
                'password_again' => self::PASSWORD . '-typo',
            ]);

            $this->assertStatus(422, $answer, 'right-key attempt ' . ($i + 1));
        }
    }

    public function testWrongKeysStillDrawTheLimitAndThenTheRightKeyIsRefusedToo(): void
    {
        for ($i = 0; $i < self::ATTEMPTS; $i++) {
            $this->assertStatus(401, $this->postSetup(['setup_key' => 'not-the-key-' . $i]), 'wrong key ' . ($i + 1));
        }

        $this->assertStatus(429, $this->postSetup(['setup_key' => 'not-the-key-x']), 'the sixth wrong key');

        $right = $this->postSetup([
            'setup_key'      => self::KEY,
            'email'          => self::OWNER,
            'password'       => self::PASSWORD,
            'password_again' => self::PASSWORD,
        ]);

        $this->assertStatus(429, $right, 'a caller past the limit had its guess compared');
    }

    /* ------------------------------------------------------------ helpers */

    /** As B7LoginCodeLimitTest: read the page, take its token and cookie, then post. */
    private function postSetup(array $post): Answer
    {
        $page    = $this->get(SetupController::PATH);
        $cookies = [];
        $secret  = $page->cookie(Session::ANON_COOKIE);

        if (is_string($secret)) {
            $cookies[Session::ANON_COOKIE] = $secret;
        }

        preg_match('/name="' . Csrf::FIELD . '" value="([^"]*)"/', $page->body(), $m);

        return $this->post(SetupController::PATH, $post + [Csrf::FIELD => $m[1] ?? ''], [], $cookies);
    }
}
