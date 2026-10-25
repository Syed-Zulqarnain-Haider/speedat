<?php

declare(strict_types=1);

namespace Speedat\Tests\Core;

use Speedat\Core\HttpError;
use Speedat\Core\Response;
use Speedat\Tests\TestCase;

/**
 * THE RULE THIS FILE EXISTS TO HOLD: no refusal this application can produce
 * may carry a status a web server cannot put on the wire as itself.
 *
 * It exists because the application broke that rule for months without anybody
 * being able to see it. `HttpError::invalidToken()` answered **419** - Laravel's
 * invention, and not in the IANA registry - so every rejected form token,
 * meaning every expired contact form and every stale admin login, went down the
 * wire from real Apache as **500 Internal Server Error**. The page underneath
 * was correct, the sentence on it was correct, and only the status line lied:
 * nobody noticed by looking, while every uptime monitor and every error budget
 * counted a customer's stale tab as the site falling over.
 *
 * `php -S` cannot show it. The built-in server has no status table and sends
 * whatever integer it is handed, so the development machine answered 419 all
 * along and agreed with the tests. It took a rehearsal on real Apache
 * (2.4.68, mod_php 8.2.34, 2026-09-29) to see it:
 *
 *     POST /contact (no token, app picks 419) -> 500
 *     GET  /nope    (app picks 404)           -> 404
 *     GET  /api/metrics (app picks 401)       -> 401
 *
 * A NUMBER IN A TEST WOULD NOT HAVE CAUGHT IT, which is why this file asserts
 * the property instead. `Core\Response::TRANSPORTABLE` is Apache's own status
 * table; anything outside it is a status whose meaning on the wire is decided
 * by the web server rather than by us, and LiteSpeed - which is what the live
 * site runs and what nobody here can measure - is entitled to do exactly what
 * Apache does.
 */
final class HttpErrorTest extends TestCase
{
    /**
     * Every factory on `HttpError`, called, and its status checked against the
     * table.
     *
     * Reflection rather than a hand-written list: a list is a thing somebody
     * adds a factory without. The next `HttpError::somethingElse()` is in this
     * test the moment it is written, which is the only version of this test
     * worth having.
     */
    public function testEveryErrorThisApplicationCanThrowCarriesAStatusAServerCanSend(): void
    {
        $factories = $this->factories();

        $this->assertTrue(count($factories) >= 7, 'reflection found only ' . count($factories) . ' factories');

        foreach ($factories as $name => $error) {
            $this->assertTrue(
                Response::isTransportable($error->status),
                'HttpError::' . $name . '() answers ' . $error->status
                . ', which no server has a reason phrase for - Apache puts it on the wire as a 500'
            );

            // Said the other way round, against the code send() actually runs:
            // a status that needs a hand-written line is a status this
            // application must not be choosing, because LSAPI drops the line.
            $this->assertSame(
                null,
                Response::statusLine($error->status),
                'HttpError::' . $name . '() needs a hand-written status line to survive Apache'
            );
        }
    }

    /**
     * The one that was wrong, pinned by name - and the word a machine client
     * used to read off the status, pinned with it.
     *
     * 403 is not an arbitrary replacement. Nothing about a request with a stale
     * token is malformed, so 400 would be a lie; RFC 9110 §15.5.4 is the server
     * understanding the request and refusing it with the supplied credentials
     * judged insufficient, which is exactly what a rejected token and a foreign
     * Origin are. It is also what Django, Symfony and Spring answer for the same
     * event.
     */
    public function testARejectedTokenIsAForbiddenThatStillNamesItself(): void
    {
        $error = HttpError::invalidToken(['path' => '/contact']);

        $this->assertSame(403, $error->status);
        $this->assertSame('invalid_token', $error->detail['code'] ?? null, 'the machine-readable word went missing');
        $this->assertSame('/contact', $error->detail['path'] ?? null, 'the caller\'s own detail was overwritten');

        // The visitor's sentence is the part that must NOT have changed: the
        // status was wrong, the words never were.
        $this->assertStringContains('This form expired', $error->getMessage());

        // A caller that wants its own code keeps it. `$detail + [...]` in the
        // factory means the caller's key wins, and that is the direction that
        // matters: the default must never overwrite what a caller decided.
        $this->assertSame(
            'origin_rejected',
            HttpError::invalidToken(['code' => 'origin_rejected'])->detail['code'] ?? null,
        );
    }

    /**
     * Every HttpError factory, called with its defaults.
     *
     * @return array<string,HttpError>
     */
    private function factories(): array
    {
        $out = [];

        foreach ((new \ReflectionClass(HttpError::class))->getMethods(\ReflectionMethod::IS_STATIC) as $method) {
            if (!$method->isPublic()) {
                continue;
            }

            // Only the factories: a static that needs an argument it cannot be
            // given by default is not one, and calling it would be this test
            // inventing a call nobody makes.
            if ($method->getNumberOfRequiredParameters() !== 0) {
                continue;
            }

            $error = $method->invoke(null);

            if ($error instanceof HttpError) {
                $out[$method->getName()] = $error;
            }
        }

        return $out;
    }
}
