<?php

declare(strict_types=1);

namespace Speedat\Tests\View;

use Speedat\View\ContactView;

/**
 * Where the contact page puts the visitor after a submit (fixed 2026-10-25).
 *
 * The form starts about 905px down a 375px phone. The answer - a refusal or the
 * thank-you - used to open at the top of the page, out of sight, so customers
 * sent their message twice or gave up. The page now focuses the answer, which
 * also scrolls it into view, and marks each refused field aria-invalid.
 */
final class B8ContactFocusTest extends ViewTestCase
{
    public function testTheFirstRefusedFieldTakesFocusAndEveryRefusedFieldIsMarked(): void
    {
        $html = $this->render(errors: [
            'phone'   => 'Enter a phone number we can call or WhatsApp',
            'message' => 'Tell us what you would like to send',
        ]);

        $this->assertSame(1, substr_count($html, ' autofocus'), 'one element, and only one, asks for focus');
        $this->assertSame(1, preg_match('#<input type="tel" name="phone"[^>]* aria-invalid="true" autofocus>#', $html), 'the phone, drawn first, takes focus');
        $this->assertSame(1, preg_match('#<textarea[^>]*name="message"[^>]* aria-invalid="true">#', $html), 'the message is marked but not focused');
        $this->assertSame(0, preg_match('#name="name"[^>]*aria-invalid#', $html), 'a field that passed is not marked');
    }

    public function testAFormLevelRefusalIsFocusedAndStillAnAlert(): void
    {
        $html = $this->render(errors: ['_' => 'This form expired. Please reload the page and try again.']);

        $this->assertStringContains('<div class="contact-form-said" tabindex="-1" autofocus>', $html);
        $this->assertStringContains('<div class="notice err" role="alert">This form expired.', $html);
        $this->assertSame(1, substr_count($html, ' autofocus'));
    }

    public function testTheThankYouTakesFocus(): void
    {
        $view = ContactView::build($this->pageView($this->doc(), '/contact', 'Contact'), sent: true);
        $html = $this->view()->render('site/contact', ['view' => $view]);

        $this->assertStringContains('<div class="card contact-done" role="status" tabindex="-1" autofocus>', $html);
    }

    public function testAFreshFormFocusesNothing(): void
    {
        $html = $this->render(errors: []);

        $this->assertStringNotContains('autofocus', $html, 'a page that opens on a field steals the scroll from the visitor');
        $this->assertStringNotContains('aria-invalid', $html);
    }

    /** @param array<string,string> $errors */
    private function render(array $errors): string
    {
        $view = ContactView::build(
            $this->pageView($this->doc(), '/contact', 'Contact'),
            csrf: 'c',
            formToken: 't',
            values: ['name' => 'Ayesha', 'phone' => '0300.1234567', 'message' => 'hi'],
            errors: $errors,
        );

        return $this->view()->render('site/contact', ['view' => $view]);
    }
}
