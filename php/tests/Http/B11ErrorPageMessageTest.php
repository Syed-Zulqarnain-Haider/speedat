<?php

declare(strict_types=1);

namespace Speedat\Tests\Http;

use Speedat\Core\App;
use Speedat\Core\HttpError;
use Speedat\Core\Request;
use Speedat\Core\Router;
use Speedat\Http\Kernel;
use Speedat\Http\Page;
use Speedat\View\Admin\Routes as AdminRoutes;
use Speedat\View\ErrorView;

/**
 * A refusal the site MEANT says what it means.
 *
 * The designed error page used to print "This page did not load" under every
 * status but 404, so an owner whose admin tab outlived its CSRF token pressed
 * Publish and never saw "This form expired. Please reload the page and try
 * again." - the one sentence that would have fixed it (review, 2026-10-23).
 * What is held here: a 4xx `HttpError` shows its public message, a 500 still
 * shows nothing but ERROR_LINE, and an admin refusal carries a way back to the
 * admin rather than only the customer buttons.
 */
final class B11ErrorPageMessageTest extends HttpTestCase
{
    public function testAnExpiredFormTokenSaysToReloadInsteadOfDidNotLoad(): void
    {
        $answer = $this->throwing(AdminRoutes::PUBLISH, HttpError::invalidToken());

        $this->assertStatus(403, $answer);
        $this->assertStringContains(ErrorView::ERROR_TITLE, $answer->body());
        $this->assertStringContains('This form expired. Please reload the page and try again.', $answer->body());
        $this->assertStringNotContains(ErrorView::ERROR_LINE, $answer->body(), 'the generic line replaced the real one');
        $this->assertOneH1($answer);
    }

    public function testAnOwnerOnlyRefusalAndA429KeepTheirOwnSentences(): void
    {
        $owner = $this->throwing(AdminRoutes::HOLD, HttpError::forbidden('Only an owner can show prices again.'));
        $this->assertStatus(403, $owner);
        $this->assertStringContains('Only an owner can show prices again.', $owner->body());

        $limited = $this->throwing('/contact-test', HttpError::tooManyRequests(30));
        $this->assertStatus(429, $limited);
        $this->assertStringContains('Too many requests. Please wait a moment and try again.', $limited->body());
        $this->assertSame('30', $limited->header('Retry-After'));
    }

    /** A 5xx stays silent even when it arrives as an HttpError: rule 2 of ErrorPage. */
    public function testA5xxStillSaysOnlyTheGenericLine(): void
    {
        $answer = $this->throwing('/boom-b11', new HttpError(503, 'Database pool exhausted on db-7'));

        $this->assertStatus(503, $answer);
        $this->assertStringContains(ErrorView::ERROR_LINE, $answer->body());
        $this->assertStringNotContains('db-7', $answer->body(), 'a 5xx message reached the designed page');
    }

    /**
     * The admin link is data on the view; `site/error` is what draws it. An
     * empty line falls back to ERROR_LINE rather than an empty lede.
     */
    public function testFailedCarriesTheAdminLinkAndNeverAnEmptyLine(): void
    {
        $page = Page::forError(new App(Request::synthetic('GET', AdminRoutes::RATES)), ErrorView::ERROR_TITLE);

        $admin = ErrorView::failed($page, '', AdminRoutes::RATES);
        $this->assertSame(ErrorView::ERROR_LINE, $admin->line);
        $this->assertSame(AdminRoutes::RATES, $admin->adminHref);

        $this->assertSame('', ErrorView::failed($page)->adminHref, 'a customer page was offered the admin');
    }

    /** A caught Throwable is not an HttpError and says nothing of itself. */
    public function testAPlainExceptionStillSaysOnlyTheGenericLine(): void
    {
        $answer = $this->throwing('/boom-b11-plain', new \RuntimeException('secret detail'));

        $this->assertStatus(500, $answer);
        $this->assertStringContains(ErrorView::ERROR_LINE, $answer->body());
        $this->assertStringNotContains('secret detail', $answer->body());
    }

    /* ------------------------------------------------------------ helpers */

    /** One POST through a router whose only route throws `$error`. */
    private function throwing(string $path, \Throwable $error): Answer
    {
        $router = new Router();
        $router->post($path, static function () use ($error): never {
            throw $error;
        });

        $app = new App(Request::synthetic('POST', $path));

        return new Answer(Kernel::handle($app, $router), $app);
    }
}
