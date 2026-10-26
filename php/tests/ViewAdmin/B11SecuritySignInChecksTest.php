<?php

declare(strict_types=1);

namespace Speedat\Tests\ViewAdmin;

use Speedat\Http\Admin\SecurityController;

/**
 * The Security page's two sign-in lines on the setup the project intends:
 * Google on, passwords off. "Password sign-in: off" used to be a red line
 * there, so a correctly configured site never showed "all checks pass"
 * (review, 2026-10-26). It is now a failure only when there is no way in.
 */
final class B11SecuritySignInChecksTest extends AdminViewTestCase
{
    public function testGoogleOnlyIsAPassAndThePageSaysAllChecksPass(): void
    {
        $checks = SecurityController::signInChecks(google: true, passwords: false);
        $password = $this->line($checks, 'Password sign-in');

        $this->assertTrue($password['ok'], 'Google-only is the intended setup, not a fault');
        $this->assertStringContains('Google only', $password['note']);
        $this->assertStringContains('docs/security.md 7.7', $password['note'], 'names the break-glass route');

        $page = $this->securityPage(['checks' => $checks]);
        $this->assertTrue($page->checksPass());

        $html = $this->view()->render('admin/security', ['page' => $page, 'shell' => $this->shell('security')]);
        $this->assertStringContains('all checks pass', $html);
    }

    public function testNoWayInAtAllFailsThePasswordLine(): void
    {
        $checks = SecurityController::signInChecks(google: false, passwords: false);
        $password = $this->line($checks, 'Password sign-in');

        $this->assertFalse($password['ok']);
        $this->assertStringContains('nobody can sign in', $password['note']);
        $this->assertFalse($this->securityPage(['checks' => $checks])->checksPass());
        $this->assertStringNotContains('password sign-in only', $this->line($checks, 'Admin sign-in (Google)')['note'],
            'with passwords off too, "password sign-in only" would be false');
    }

    public function testPasswordsOnIsAPassWithOrWithoutGoogle(): void
    {
        foreach ([true, false] as $google) {
            $password = $this->line(SecurityController::signInChecks(google: $google, passwords: true), 'Password sign-in');

            $this->assertTrue($password['ok']);
            $this->assertStringContains('on, hashed with', $password['note']);
        }
    }

    /**
     * @param list<array{label:string,ok:bool,note:string}> $checks
     * @return array{label:string,ok:bool,note:string}
     */
    private function line(array $checks, string $label): array
    {
        foreach ($checks as $check) {
            if ($check['label'] === $label) {
                return $check;
            }
        }

        throw new \RuntimeException('no check labelled ' . $label);
    }
}
