<?php

declare(strict_types=1);

namespace Speedat\Tests\ViewAdmin;

use Speedat\View\Admin\DiffLine;
use Speedat\View\Admin\HoldPanel;
use Speedat\View\Admin\LeadCard;
use Speedat\View\Admin\LoginPage;
use Speedat\View\Admin\Routes;

/**
 * The rules that must hold on EVERY admin screen, checked on every one of them
 * rather than reviewed once per template.
 *
 * These are the five a careful reviewer stops catching after the tenth
 * template: a form that posts without a token, a value printed unescaped, a
 * second h1, a control that does nothing without JavaScript, and a leftover
 * word from a feature that was removed.
 */
final class AdminTemplatesTest extends AdminViewTestCase
{
    public function testEveryScreenRenders(): void
    {
        foreach ($this->everyScreen() as $name => $html) {
            $this->assertStringContains('<!doctype html>', $html, $name);
            $this->assertStringContains('</html>', $html, $name);
            // A template that renders an empty page passes every other check
            // in this file, so the size is asserted too.
            $this->assertTrue(strlen($html) > 1500, $name . ': suspiciously short output (' . strlen($html) . ' bytes)');
        }
    }

    public function testEveryScreenHasExactlyOneH1(): void
    {
        foreach ($this->everyScreen() as $name => $html) {
            $this->assertOneH1($html, $name);
        }
    }

    public function testEveryFormThatChangesSomethingCarriesACsrfToken(): void
    {
        foreach ($this->everyScreen() as $name => $html) {
            $this->assertEveryPostFormHasAToken($html, $name);
        }
    }

    /**
     * The whole point of the escaping rule, proved rather than asserted: every
     * string a person can type is replaced by a working XSS payload, and no
     * screen may print a live script, a live img or a broken attribute.
     */
    public function testNoValueEscapesIntoLiveMarkup(): void
    {
        foreach ($this->hostileScreens() as $name => $html) {
            $this->assertNoInjection($html, $name);
            // ...and it really did print the value, escaped, rather than
            // dropping it - otherwise this would pass on an empty page.
            $this->assertStringContains('&lt;script&gt;alert(1)', $html, $name . ': the hostile value was not printed at all');
        }
    }

    /** Quotes and apostrophes inside a value stay inside it. */
    public function testQuotesAndApostrophesSurviveWithoutBreakingAnAttribute(): void
    {
        $v    = $this->view();
        $name = "C\u{f4}te d'Ivoire \"North\" <b>";
        $html = $v->render('admin/shipment', [
            'page'  => $this->shipmentPage(['shipment' => [
                'id' => 'SH-4K7QX2M9', 'quoteId' => '', 'customerName' => $name, 'customerPhone' => '03001234567',
                'receiverName' => "O'Brien", 'carrier' => 'DHL "Express"', 'trackingNo' => '1"2\'3',
                'notes' => 'She said "no liquids"', 'status' => 'booked', 'createdAt' => '2026-09-23T09:20:00.000Z',
                'destination' => $name, 'service' => 'Express',
            ]]),
            'shell' => $this->shell('shipments'),
        ]);

        $this->assertStringContains('&quot;', $html, 'a double quote must be escaped');
        $this->assertStringContains('&#039;', $html, 'an apostrophe must be escaped');
        $this->assertStringContains('&lt;b&gt;', $html, 'a tag typed into a name must be printed as text');
        $this->assertStringNotContains('<b>', $html, 'a tag typed into a name must not become markup');
        // The WhatsApp link carries the same name inside a URL, where a raw
        // quote would end the href.
        $this->assertStringNotContains('href="https://wa.me/923001234567?text=Hi C', $html, 'the wa.me text must be URL-encoded');
    }

    /**
     * No screen mentions the rate-sheet feature the owner removed on
     * 2026-09-27 - not as a section, not as a placeholder, not as a promise.
     */
    public function testNoScreenMentionsSheetsOrImporting(): void
    {
        foreach ($this->everyScreen() as $name => $html) {
            $this->assertSaysNothingAboutSheets($html, $name);
        }
    }

    /**
     * A control that only works with JavaScript is not shown to a browser
     * without it.
     *
     * `type="button"` is the marker: it submits nothing, so it does nothing at
     * all unless a script is listening. Every one of them must be rendered
     * `hidden`, which is what admin.js un-hides - and each has a plain
     * alternative beside it (a download link for Copy as CSV, the message
     * itself for Copy message).
     */
    public function testEveryJavaScriptOnlyButtonStartsHidden(): void
    {
        foreach ($this->everyScreen() as $name => $html) {
            preg_match_all('#<button\b[^>]*type="button"[^>]*>#i', $html, $buttons);
            foreach ($buttons[0] as $button) {
                $this->assertTrue(
                    str_contains($button, ' hidden'),
                    $name . ': a JavaScript-only button is shown without JavaScript - ' . $button
                );
            }
        }
    }

    /**
     * Every action has a real submit somewhere.
     *
     * This is the no-JS contract in one assertion: if an action's URL appears
     * only inside a data- attribute, then the only way to reach it is through
     * a script. Each URL below must be a form `action` or a button's
     * `formaction`, or - for the two that only READ - an `href`.
     */
    public function testEveryActionIsReachableByAPlainSubmit(): void
    {
        $screens = $this->everyScreen();

        $expected = [
            'rates' => [
                Routes::SAVE, Routes::ADD_DESTINATION, Routes::REMOVE_DESTINATION, Routes::SORT_DESTINATIONS,
                Routes::UNDO_ROW, Routes::FILL, Routes::REVIEW, Routes::DISCARD, Routes::RESTORE,
                Routes::BULK, Routes::TEST,
            ],
            'rates_review'   => [Routes::PUBLISH],
            'rates_held'     => [Routes::HOLD],
            'inbox'          => [Routes::INBOX_UPDATE],
            'shipment'       => [Routes::SHIPMENT_UPDATE, Routes::SHIPMENT_EVENT],
            'shipment_new'   => [Routes::SHIPMENT_CREATE],
            'security'       => [Routes::TOTP_BEGIN],
            'security_enrol' => [Routes::TOTP_CONFIRM],
            'security_on'    => [Routes::TOTP_DISABLE],
            'login'          => [Routes::LOGIN_GOOGLE, Routes::LOGIN_PASSWORD],
            'login_code'     => [Routes::LOGIN_CODE, Routes::LOGOUT],
        ];

        foreach ($expected as $screen => $routes) {
            foreach ($routes as $route) {
                $this->assertTrue(
                    str_contains($screens[$screen], 'action="' . $route . '"'),
                    $screen . ': ' . $route . ' is not the action of any form or submit button'
                );
            }
        }

        // The two that only read: a GET link is the right shape for both.
        $this->assertStringContains('href="' . Routes::EXPORT_CSV . '" download', $screens['rates'], 'the CSV export must be a plain download link');
        $this->assertStringContains('href="' . Routes::holdDialog() . '"', $screens['rates'], 'the hold dialog must have a no-JS link');
    }

    /** The hold button is a link to `?hold=1`, so the dialog opens without JavaScript. */
    public function testTheHoldDialogOpensWithoutJavaScript(): void
    {
        $v    = $this->view();
        $open = $v->render('admin/rates', [
            'page'     => $this->ratesPage(['hold' => new HoldPanel(on: false, message: 'Back on Monday.', isOwner: true, dialogOpen: true)]),
            'shell'    => $this->shell(),
            'newLeads' => 0,
        ]);

        $this->assertStringContains('data-hold-dialog role="dialog"', $open, 'the dialog is rendered open by the server');
        $this->assertStringNotContains('data-hold-dialog role="dialog" aria-label="Hold prices" hidden', $open, 'it must not be hidden when ?hold=1');
    }

    /** Both navigations are in the markup on every screen; CSS decides which is seen. */
    public function testBothNavigationsAreAlwaysRendered(): void
    {
        foreach ($this->everyScreen() as $name => $html) {
            if (str_starts_with($name, 'login')) {
                // The sign-in has no nav: four links that all bounce back to it.
                $this->assertStringNotContains('class="anav', $html, $name . ': the sign-in page must not carry the admin nav');
                continue;
            }
            $this->assertStringContains('class="anav anav-side"', $html, $name);
            $this->assertStringContains('class="anav anav-top"', $html, $name);
            $this->assertStringContains('aria-current="page"', $html, $name . ': the current page is not marked');
        }
    }

    /** Nothing in the admin is indexable, including the sign-in. */
    public function testNoAdminScreenIsIndexable(): void
    {
        foreach ($this->everyScreen() as $name => $html) {
            $this->assertStringContains('<meta name="robots" content="noindex, nofollow">', $html, $name);
        }
    }

    /**
     * Every screen, rendered from values that are all XSS payloads - the
     * document, the leads, the shipment, the admins list and the sign-in.
     *
     * @return array<string,string>
     */
    private function hostileScreens(): array
    {
        $v      = $this->view();
        $draft  = $this->hostileDraft();
        $poison = self::HOSTILE;

        $lead = LeadCard::fromArray([
            'id' => 7, 'kind' => 'quote', 'quoteId' => $poison, 'name' => $poison, 'phone' => '03001234567',
            'email' => $poison, 'message' => $poison, 'destination' => $poison, 'weightG' => 4500,
            'status' => 'new', 'notes' => $poison, 'createdAt' => '2026-09-23T09:14:00.000Z',
            'updatedAt' => '2026-09-23T09:14:00.000Z', 'updatedBy' => $poison,
        ]);

        return [
            'rates'          => $v->render('admin/rates', [
                'page'     => $this->ratesPage(['draft' => $draft, 'live' => $draft, 'csv' => $poison, 'toast' => $poison, 'publishedNotice' => $poison]),
                'shell'    => $this->shell(),
                'newLeads' => 1,
            ]),
            'rates_review'   => $v->render('admin/rates', [
                'page' => $this->ratesPage([
                    'draft'  => $draft,
                    'live'   => $draft,
                    'review' => $this->review([
                        'lines'    => DiffLine::all([['label' => $poison, 'old' => $poison, 'new' => $poison, 'flag' => $poison]]),
                        'warnings' => [$poison],
                        'message'  => $poison,
                    ]),
                ]),
                'shell'    => $this->shell(),
                'newLeads' => 0,
            ]),
            'rates_errors'   => $v->render('admin/rates', [
                'page'     => $this->ratesPage(['draft' => $draft, 'live' => $draft, 'review' => $this->review(['errors' => [$poison]])]),
                'shell'    => $this->shell(),
                'newLeads' => 0,
            ]),
            'rates_held'     => $v->render('admin/rates', [
                'page'     => $this->ratesPage(['draft' => $draft, 'live' => $draft, 'hold' => new HoldPanel(on: true, message: $poison, by: $poison, since: '2026-09-23T08:00:00.000Z', isOwner: true)]),
                'shell'    => $this->shell(),
                'newLeads' => 0,
            ]),
            'inbox'          => $v->render('admin/inbox', ['page' => $this->inboxPage(['leads' => [$lead], 'filter' => $poison]), 'shell' => $this->shell('inbox')]),
            'shipments'      => $v->render('admin/shipments', [
                'page' => $this->shipmentsPage(['rows' => [[
                    'id' => $poison, 'quoteId' => $poison, 'customerName' => $poison, 'customerPhone' => $poison,
                    'destination' => $poison, 'service' => $poison, 'carrier' => $poison, 'trackingNo' => $poison,
                    'status' => 'exception', 'updatedAt' => '2026-09-23T14:05:00.000Z',
                ]], 'filter' => $poison]),
                'shell' => $this->shell('shipments'),
            ]),
            'shipment'       => $v->render('admin/shipment', [
                'page'  => $this->shipmentPage([
                    'shipment' => [
                        'id' => $poison, 'quoteId' => $poison, 'customerName' => $poison, 'customerPhone' => '03001234567',
                        'receiverName' => $poison, 'carrier' => $poison, 'trackingNo' => $poison, 'notes' => $poison,
                        'status' => 'in_transit', 'createdAt' => '2026-09-23T09:20:00.000Z',
                        'destination' => $poison, 'service' => $poison,
                    ],
                    'events'      => [['id' => 1, 'status' => 'in_transit', 'note' => $poison, 'at' => '2026-09-23T14:05:00.000Z', 'by' => $poison]],
                    'companyName' => $poison,
                    'notice'      => $poison,
                ]),
                'shell' => $this->shell('shipments'),
            ]),
            'shipment_new'   => $v->render('admin/shipment-new', [
                'page'  => $this->newShipmentPage([
                    'destinations' => [['id' => 'ca', 'name' => $poison]],
                    'services'     => [['id' => 'exp', 'name' => $poison]],
                    'values'       => ['customerName' => $poison, 'customerPhone' => $poison, 'destId' => 'ca', 'serviceId' => 'exp', 'receiverName' => $poison, 'carrier' => $poison, 'trackingNo' => $poison, 'notes' => $poison],
                    'quoteId'      => $poison,
                    'error'        => $poison,
                ]),
                'shell' => $this->shell('shipments'),
            ]),
            'security_enrol' => $v->render('admin/security', [
                'page'  => $this->securityPage([
                    'viewerEmail' => $poison,
                    'admins'      => [['email' => $poison, 'role' => $poison, 'totp' => false, 'addedAt' => '2026-08-01T10:00:00.000Z']],
                    'checks'      => [['label' => $poison, 'ok' => false, 'note' => $poison]],
                    'activity'    => [['at' => '2026-09-23T14:05:00.000Z', 'action' => $poison, 'actor' => $poison, 'ip' => $poison]],
                    'enrolment'   => ['uri' => $poison, 'qrDataUrl' => $poison, 'secret' => $poison],
                    'error'       => $poison,
                    'toast'       => $poison,
                ]),
                'shell' => $this->shell('security'),
            ]),
            'login'          => $v->render('admin/login', ['page' => $this->loginPage(['companyName' => $poison, 'tagline' => $poison, 'error' => $poison, 'email' => $poison])]),
            'login_code'     => $v->render('admin/login', ['page' => $this->loginPage(['step' => LoginPage::STEP_CODE, 'companyName' => $poison, 'tagline' => $poison, 'error' => $poison])]),
            'login_blocked'  => $v->render('admin/login', ['page' => $this->loginPage(['configWarning' => $poison])]),
        ];
    }
}
