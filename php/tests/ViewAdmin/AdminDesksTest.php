<?php

declare(strict_types=1);

namespace Speedat\Tests\ViewAdmin;

use Speedat\View\Admin\LoginPage;
use Speedat\View\Admin\Routes;
use Speedat\View\Admin\ShipmentStatus;

/**
 * The screens either side of the rates editor: the inbox, the shipments list
 * and desk, Security, and the sign-in.
 *
 * What is checked here is what the office actually needs off each screen - the
 * WhatsApp text a customer will read, the tab that says how many are waiting,
 * the second factor's three states - rather than that a template renders.
 */
final class AdminDesksTest extends AdminViewTestCase
{
    /* ---------------- inbox ---------------- */

    public function testTheInboxCardsCarryStatusNotesAndBothWaysOnward(): void
    {
        $html = $this->view()->render('admin/inbox', ['page' => $this->inboxPage(), 'shell' => $this->shell('inbox')]);

        $this->assertStringContains('<span class="qid">SP-260923-K7QX</span>', $html);
        $this->assertStringContains('Ayesha Khan', $html);
        $this->assertStringContains('&#8594; Canada', $html);
        $this->assertStringContains('4.5 kg', $html, 'the weight, in kilograms');
        $this->assertStringContains("Two boxes of clothes.\nPickup after 5pm.", $html, 'the message is printed as typed');

        // Each card is its own form: the status and the notes save together.
        $this->assertSame(2, substr_count($html, 'action="' . Routes::INBOX_UPDATE . '"'), 'one form per lead');
        $this->assertStringContains('<option value="contacted"', $html);
        $this->assertStringContains('name="notes"', $html);
        $this->assertStringContains('maxlength="4000"', $html, 'the notes are capped at the column width');

        // Onward: WhatsApp for the customer, a shipment for the office.
        $this->assertStringContains('href="https://wa.me/923001234567"', $html, 'a local 03xx number becomes 923xx');
        $this->assertStringContains('href="' . Routes::shipmentFromLead(7) . '"', $html);
        $this->assertStringContains('>Create shipment</a>', $html);
    }

    public function testTheInboxTabsCountWhatIsWaiting(): void
    {
        $html = $this->view()->render('admin/inbox', ['page' => $this->inboxPage(), 'shell' => $this->shell('inbox')]);

        $this->assertStringContains('New <span class="n">1</span>', $html);
        $this->assertStringContains('Booked <span class="n">4</span>', $html);
        $this->assertStringContains('All <span class="n">6</span>', $html, 'All is the sum, not a fifth state');
        $this->assertStringContains('href="' . Routes::inbox('booked') . '"', $html);
        $this->assertStringContains('<span class="pill draft">1 new</span>', $html);
    }

    /** A lead with no phone gets no WhatsApp link, rather than one that opens on nothing. */
    public function testALeadWithoutAUsableNumberHasNoWhatsAppLink(): void
    {
        $html = $this->view()->render('admin/inbox', [
            'page'  => $this->inboxPage(['counts' => ['new' => 0, 'contacted' => 1, 'booked' => 0, 'lost' => 0], 'status' => 'contacted']),
            'shell' => $this->shell('inbox'),
        ]);

        $this->assertSame(1, substr_count($html, 'class="btn wa small"'), 'only the lead that has a number');
    }

    /** A deployment with no mail says so on the page rather than losing messages quietly. */
    public function testTheInboxSaysWhenEmailAlertsAreNotConfigured(): void
    {
        $html = $this->view()->render('admin/inbox', ['page' => $this->inboxPage(), 'shell' => $this->shell('inbox')]);

        $this->assertStringContains('Email alerts for new messages are not configured', $html);
        $this->assertStringContains('Messages still land here', $html);
    }

    public function testAnEmptyInboxSaysSoAndOffersNoSearchBox(): void
    {
        $html = $this->view()->render('admin/inbox', [
            'page'  => $this->inboxPage(['leads' => [], 'counts' => []]),
            'shell' => $this->shell('inbox'),
        ]);

        $this->assertStringContains('<p class="hint empty">Nothing here.</p>', $html);
        $this->assertStringNotContains('data-list-filter', $html, 'nothing to filter');
        $this->assertStringContains('nothing new', $html);
    }

    /* ---------------- shipments list ---------------- */

    public function testTheShipmentsListShowsWhatWasBookedAndWhereItIs(): void
    {
        $html = $this->view()->render('admin/shipments', ['page' => $this->shipmentsPage(), 'shell' => $this->shell('shipments')]);

        $this->assertStringContains('<span class="qid">SH-4K7QX2M9</span>', $html);
        $this->assertStringContains('href="' . Routes::shipment('SH-4K7QX2M9') . '"', $html);
        $this->assertStringContains('<div class="hint">SP-260923-K7QX</div>', $html, 'the quote it came from');
        $this->assertStringContains('<div class="hint">EK623-99814</div>', $html, 'the tracking number');
        $this->assertStringContains('<span class="pill s-in_transit">In transit</span>', $html);
        $this->assertStringContains('<span class="pill s-exception">Needs attention</span>', $html);
        // A shipment with nothing in a column reads as a dash, not as blank.
        // The dash comes off the view model rather than being retyped here.
        // Since the owner's plain-hyphen change (2026-10-11) it is "-", which
        // also occurs all over the markup, so the count is of CELLS whose
        // whole text is the dash, not of the character.
        $page = $this->shipmentsPage();
        $dash = $page->table()[1]['customerName'];
        $this->assertSame('-', $dash);
        $this->assertSame(
            2,
            preg_match_all('#<td>\s*' . preg_quote(htmlspecialchars($dash), '#') . '\s*(?:<div|</td>)#', $html),
            'the nameless row shows a dash for name and carrier'
        );
        // The one needing attention is marked the same way a changed rate row is.
        $this->assertSame(1, substr_count($html, '<tr class="changed"'), 'the exception row is marked');
    }

    public function testTheShipmentsTabsCountOpenAndLinkToEachState(): void
    {
        $html = $this->view()->render('admin/shipments', ['page' => $this->shipmentsPage(), 'shell' => $this->shell('shipments')]);

        $this->assertStringContains('href="' . Routes::shipments('open') . '" aria-current="page"', $html, 'Open is the tab being shown');
        $this->assertStringContains('<span class="n">2</span>', $html, 'and it carries the count');
        $this->assertSame(1, substr_count($html, 'class="n"'), 'only Open does: a zero beside every state reads as nothing happening');
        $this->assertStringContains('<span class="pill hold">1 need attention</span>', $html);
        $this->assertStringContains('<span class="meta">2 open</span>', $html);
        foreach (ShipmentStatus::ALL as $status) {
            $this->assertStringContains('href="' . Routes::shipments($status) . '"', $html, $status . ': no tab');
        }
        $this->assertStringContains('href="' . Routes::shipments('all') . '"', $html);
        $this->assertStringContains('href="' . Routes::SHIPMENT_NEW . '"', $html);
    }

    public function testAnEmptyShipmentsListSaysWhereShipmentsComeFrom(): void
    {
        $html = $this->view()->render('admin/shipments', [
            'page'  => $this->shipmentsPage(['rows' => [], 'counts' => []]),
            'shell' => $this->shell('shipments'),
        ]);

        $this->assertStringContains('No shipments here. Create one from a lead in the Inbox', $html);
        $this->assertStringNotContains('<table', $html);
    }

    /* ---------------- the shipment desk ---------------- */

    public function testTheDeskSavesTheDetailsWithOneRealSubmit(): void
    {
        $html = $this->view()->render('admin/shipment', ['page' => $this->shipmentPage(), 'shell' => $this->shell('shipments')]);

        $this->assertStringContains('action="' . Routes::SHIPMENT_UPDATE . '"', $html);
        $this->assertStringContains('<input type="hidden" name="id" value="SH-4K7QX2M9">', $html);
        $this->assertStringContains('name="customerName" value="Ayesha Khan"', $html);
        $this->assertStringContains('type="tel"', $html);
        $this->assertStringContains('name="trackingNo" value="EK623-99814"', $html);
        $this->assertStringContains('class="track"', $html, 'a tracking number is monospace and tabular');
        $this->assertStringContains('maxlength="64"', $html, 'capped at the tracking_no column width');
        $this->assertStringContains('>Save details</button>', $html);
        $this->assertStringContains('Saved when you press Save details.', $html, 'the hint says what actually happens');
    }

    public function testTheDeskAdvancesTheStatusWithANoteInTheSameSubmit(): void
    {
        $html = $this->view()->render('admin/shipment', ['page' => $this->shipmentPage(), 'shell' => $this->shell('shipments')]);

        $this->assertStringContains('action="' . Routes::SHIPMENT_EVENT . '"', $html);
        $this->assertStringContains('<span class="pill s-in_transit">In transit</span>', $html, 'what it is now');
        $this->assertStringContains('<option value="in_transit" selected>In transit</option>', $html, 'the select starts where it is');
        foreach (ShipmentStatus::ALL as $status) {
            $this->assertStringContains('value="' . $status . '"', $html, $status . ': not offered');
        }
        $this->assertStringContains('Note (included in the WhatsApp update)', $html);
        $this->assertStringContains('name="note" maxlength="1000"', $html);
        $this->assertStringContains('>Add update</button>', $html);
    }

    /**
     * The customer's update is built by the server, printed into the wa.me link
     * and into the copy buffer, and the two are the same words.
     */
    public function testTheWhatsAppUpdateIsTheServersOwnTextAndIsOnThePageToRead(): void
    {
        $page = $this->shipmentPage();
        $html = $this->view()->render('admin/shipment', ['page' => $page, 'shell' => $this->shell('shipments')]);

        $message = $page->customerMessage();
        $this->assertStringContains('Hi Ayesha Khan, an update from Speedat International Courier', $message);
        $this->assertStringContains('Your shipment is on its way to the destination country.', $message);
        $this->assertStringContains('Handed to Emirates flight EK623', $message, 'the note of the latest entry travels with it');
        $this->assertStringContains('Tracking number: EK623-99814 (Emirates SkyCargo)', $message);

        // In the link, URL-encoded...
        $this->assertStringContains('href="https://wa.me/923001234567?text=' . rawurlencode($message) . '"', $html);
        // ...in the copy buffer, and on the page for a browser with no clipboard.
        $this->assertStringContains('<pre id="ship-msg" hidden>', $html);
        $this->assertStringContains('data-copy-from="ship-msg" data-copy-ok="Message copied" hidden', $html);
        $this->assertStringContains('<summary>The update the customer reads</summary>', $html);
        // Twice on the page as plain text - the copy buffer and the readable
        // paragraph - and a third time inside the link, URL-encoded, which is
        // why the count is taken on the unencoded greeting.
        $this->assertSame(2, substr_count($html, 'an update from Speedat International Courier'), 'the buffer and the readable copy');
        $this->assertStringContains('an%20update%20from', $html, 'and encoded in the link');
    }

    /** A note written under an older status never travels under a newer headline. */
    public function testANoteFromAnEarlierStatusIsNotSentWithANewerOne(): void
    {
        $page = $this->shipmentPage([
            'shipment' => [
                'id' => 'SH-4K7QX2M9', 'quoteId' => '', 'customerName' => 'Ayesha', 'customerPhone' => '03001234567',
                'receiverName' => '', 'carrier' => '', 'trackingNo' => '', 'notes' => '', 'status' => 'delivered',
                'createdAt' => '2026-09-23T09:20:00.000Z', 'destination' => 'Canada', 'service' => 'Express',
            ],
            'events' => [
                ['id' => 1, 'status' => 'customs', 'note' => 'Duty invoice sent to the receiver', 'at' => '2026-09-24T09:00:00.000Z', 'by' => 'office@speedat.test'],
            ],
        ]);

        $this->assertStringContains('Your shipment has been delivered.', $page->customerMessage());
        $this->assertStringNotContains('Duty invoice', $page->customerMessage(), 'the stale note is left behind');
    }

    /** No phone, no link - and the desk says why rather than showing a dead button. */
    public function testWithoutAPhoneTheDeskExplainsInsteadOfOfferingWhatsApp(): void
    {
        $html = $this->view()->render('admin/shipment', [
            'page'  => $this->shipmentPage(['shipment' => [
                'id' => 'SH-9ZP31TBC', 'quoteId' => '', 'customerName' => '', 'customerPhone' => '',
                'receiverName' => '', 'carrier' => '', 'trackingNo' => '', 'notes' => '', 'status' => 'booked',
                'createdAt' => '2026-09-23T09:20:00.000Z', 'destination' => 'Australia', 'service' => 'Economy',
            ], 'events' => []]),
            'shell' => $this->shell('shipments'),
        ]);

        $this->assertStringNotContains('wa.me', $html);
        $this->assertStringContains('Add the customer&#8217;s phone to send WhatsApp updates.', $html);
        $this->assertStringContains('Nothing yet. The first update you add appears here.', $html, 'an empty timeline says so');
    }

    /** The timeline reads newest first, each entry coloured by its status. */
    public function testTheTimelineIsNewestFirst(): void
    {
        $html = $this->view()->render('admin/shipment', ['page' => $this->shipmentPage(), 'shell' => $this->shell('shipments')]);

        $newest = strpos($html, '<li class="s-in_transit">');
        $oldest = strpos($html, '<li class="s-booked">');
        $this->assertTrue($newest !== false && $oldest !== false, 'both entries are listed');
        $this->assertTrue($newest < $oldest, 'newest first');
        $this->assertStringContains('<span class="tl-note">Handed to Emirates flight EK623</span>', $html);
        $this->assertStringContains('<span class="hint">office@speedat.test</span>', $html, 'who did it');
    }

    /* ---------------- new shipment ---------------- */

    public function testNewShipmentArrivesPrefilledFromTheLead(): void
    {
        $html = $this->view()->render('admin/shipment-new', ['page' => $this->newShipmentPage(), 'shell' => $this->shell('shipments')]);

        $this->assertStringContains('action="' . Routes::SHIPMENT_CREATE . '"', $html);
        $this->assertStringContains('<span class="meta">From lead #7</span>', $html);
        $this->assertStringContains('<input type="hidden" name="leadId" value="7">', $html);
        $this->assertStringContains('<input type="hidden" name="quoteId" value="SP-260923-K7QX">', $html);
        $this->assertStringContains('name="customerName" value="Ayesha Khan"', $html);
        $this->assertStringContains('<option value="ca" selected>Canada</option>', $html);
        $this->assertStringContains('<option value="exp" selected>Express</option>', $html);
        $this->assertStringContains('Two boxes of clothes.', $html);
        $this->assertStringContains('>Create shipment</button>', $html);
    }

    /** A refusal keeps every box filled and says why, so nothing is retyped. */
    public function testARefusedNewShipmentKeepsWhatWasTypedAndSaysWhy(): void
    {
        $html = $this->view()->render('admin/shipment-new', [
            'page'  => $this->newShipmentPage(['error' => 'Choose a destination.', 'values' => [
                'customerName' => 'Bilal', 'customerPhone' => '03001112222', 'destId' => '', 'serviceId' => 'eco',
                'receiverName' => 'R. Ahmed', 'carrier' => 'DHL', 'trackingNo' => 'X1', 'notes' => 'Careful',
            ]]),
            'shell' => $this->shell('shipments'),
        ]);

        $this->assertStringContains('<div class="notice err" role="alert">Choose a destination.</div>', $html);
        $this->assertStringContains('value="Bilal"', $html);
        $this->assertStringContains('value="R. Ahmed"', $html);
        $this->assertStringContains('value="DHL"', $html);
        $this->assertStringContains('>Careful</textarea>', $html);
        $this->assertStringContains('<option value="" disabled selected>Choose</option>', $html, 'and the empty destination is still empty');
    }

    /** No shipment form anywhere reads a file. */
    public function testNoShipmentScreenAcceptsAFile(): void
    {
        foreach (['shipments', 'shipment', 'shipment-new'] as $template) {
            $page = match ($template) {
                'shipments'     => $this->shipmentsPage(),
                'shipment'      => $this->shipmentPage(),
                default         => $this->newShipmentPage(),
            };
            $html = $this->view()->render('admin/' . $template, ['page' => $page, 'shell' => $this->shell('shipments')]);
            $this->assertStringNotContains('type="file"', $html, $template);
            $this->assertStringNotContains('multipart/form-data', $html, $template);
        }
    }

    /* ---------------- security ---------------- */

    public function testSecurityOffersEnrolmentAndSaysWhatTwoFactorWouldMean(): void
    {
        $html = $this->view()->render('admin/security', ['page' => $this->securityPage(), 'shell' => $this->shell('security')]);

        $this->assertStringContains('<span class="pill">off</span>', $html);
        $this->assertStringContains('Your account signs in with Google or a password only.', $html);
        $this->assertStringContains('action="' . Routes::TOTP_BEGIN . '"', $html);
        $this->assertStringContains('>Set up an authenticator app</button>', $html);
        $this->assertStringNotContains('name="code"', $html, 'nothing to confirm yet');
    }

    public function testTheEnrolmentStepShowsTheQrTheKeyAndTheCodeBox(): void
    {
        $html = $this->view()->render('admin/security', [
            'page'  => $this->securityPage(['enrolment' => $this->enrolment()]),
            'shell' => $this->shell('security'),
        ]);

        $this->assertStringContains('Scan this with Google Authenticator, Authy or 1Password', $html);
        $this->assertStringContains('<img src="data:image/png;base64,', $html);
        $this->assertStringContains('alt="QR code for the authenticator app" width="200" height="200"', $html);
        $this->assertStringContains('<summary>Cannot scan? Enter the key by hand</summary>', $html);
        $this->assertStringContains('<code class="secret">JBSWY3DPEHPK3PXP</code>', $html);
        $this->assertStringContains('action="' . Routes::TOTP_CONFIRM . '"', $html);
        $this->assertStringContains('autocomplete="one-time-code"', $html);
        $this->assertStringContains('>Turn on</button>', $html);
        $this->assertStringContains('href="' . Routes::SECURITY . '">Cancel</a>', $html, 'Cancel changes nothing, so it is a link');
    }

    /**
     * gd can be switched off on this host, and then there is no PNG to show.
     * The key must still be reachable, and the fold is open so it is the first
     * thing read rather than something to discover.
     */
    public function testWithoutTheQrImageTheKeyIsStillOfferedAndTheFoldIsOpen(): void
    {
        $html = $this->view()->render('admin/security', [
            'page'  => $this->securityPage(['enrolment' => $this->enrolment('')]),
            'shell' => $this->shell('security'),
        ]);

        $this->assertStringNotContains('<img src=""', $html, 'no broken image');
        $this->assertStringContains('This server cannot draw the QR image.', $html);
        $this->assertStringContains('<details class="fold" open>', $html);
        $this->assertStringContains('JBSWY3DPEHPK3PXP', $html);
    }

    public function testTurningTwoFactorOffNeedsACurrentCodeAndAConfirmation(): void
    {
        $html = $this->view()->render('admin/security', [
            'page'  => $this->securityPage(['totpEnabled' => true]),
            'shell' => $this->shell('security'),
        ]);

        $this->assertStringContains('<span class="pill live">on</span>', $html);
        $this->assertStringContains('a 6-digit code from your authenticator app is required', $html);
        $this->assertStringContains('action="' . Routes::TOTP_DISABLE . '"', $html);
        $this->assertStringContains('Current code to turn off', $html);
        $this->assertStringContains('data-confirm="Confirm turn off"', $html);
        $this->assertStringNotContains('Set up an authenticator app', $html);
    }

    public function testSecurityListsTheAdminsTheChecksAndWhatWasDone(): void
    {
        $html = $this->view()->render('admin/security', ['page' => $this->securityPage(), 'shell' => $this->shell('security')]);

        $this->assertStringContains($this->securityPage()->adminsMeta(), $html);
        $this->assertStringContains('2 admins', $this->securityPage()->adminsMeta());
        $this->assertStringContains('signed in as boss@speedat.test', $this->securityPage()->adminsMeta());
        $this->assertStringContains('<span class="tag role">owner</span>', $html);
        $this->assertStringContains('<span class="tag">2FA on</span>', $html);
        $this->assertStringContains('<span class="meta">2FA off</span>', $html);

        $this->assertStringContains('<li class="check ok">', $html);
        $this->assertStringContains('<li class="check bad">', $html);
        $this->assertStringContains('not configured - alerts are logged, not sent', $html);
        $this->assertStringContains('<span class="pill hold">1 check needs attention</span>', $html);

        $this->assertStringContains('<strong>publish</strong>', $html);
        $this->assertStringContains('boss@speedat.test', $html);
    }

    /** All green reads as all green. */
    public function testWhenEveryCheckPassesThePillSaysSo(): void
    {
        $html = $this->view()->render('admin/security', [
            'page'  => $this->securityPage(['checks' => [['label' => 'Cron secret', 'ok' => true, 'note' => 'set']]]),
            'shell' => $this->shell('security'),
        ]);

        $this->assertStringContains('<span class="pill live">all checks pass</span>', $html);
    }

    /** Nothing on Security mentions an inbound rate sheet, because nothing arrives by email. */
    public function testSecurityHasNoSheetsWebhookCheck(): void
    {
        $html = $this->view()->render('admin/security', [
            'page'  => $this->securityPage(['enrolment' => $this->enrolment(), 'totpEnabled' => false]),
            'shell' => $this->shell('security'),
        ]);

        $this->assertSaysNothingAboutSheets($html, 'security');
        $this->assertStringNotContains('webhook', $html);
    }

    /* ---------------- sign in ---------------- */

    public function testTheSignInOffersGoogleAndEmailAndPassword(): void
    {
        $html = $this->view()->render('admin/login', ['page' => $this->loginPage()]);

        $this->assertStringContains('<h1>Rates admin</h1>', $html);
        $this->assertStringContains('Sign in with the account that was added as an admin.', $html);
        $this->assertStringContains('action="' . Routes::LOGIN_GOOGLE . '"', $html);
        $this->assertStringContains('>Continue with Google</button>', $html);
        $this->assertStringContains('or with email and password', $html);
        $this->assertStringContains('action="' . Routes::LOGIN_PASSWORD . '"', $html);
        $this->assertStringContains('autocomplete="username"', $html);
        $this->assertStringContains('autocomplete="current-password"', $html);
        $this->assertStringContains('>Sign in</button>', $html);
        $this->assertStringContains('&#8592; Back to the website', $html);
        // The signed-in frame is not here, and neither is its script.
        $this->assertStringNotContains('class="admin-app"', $html);
        $this->assertStringNotContains('js/admin.js', $html);
    }

    public function testTheCodeStepAsksForSixDigitsAndNothingElse(): void
    {
        $page = $this->loginPage(['step' => LoginPage::STEP_CODE]);
        $html = $this->view()->render('admin/login', ['page' => $page]);

        $this->assertStringContains('<span class="eyebrow">Second step</span>', $html);
        $this->assertStringContains($page->codePrompt(), $html);
        $this->assertStringContains('Enter the 6-digit code from your authenticator app.', $html);
        $this->assertStringContains('action="' . Routes::LOGIN_CODE . '"', $html);
        $this->assertStringContains('class="code" name="code"', $html);
        $this->assertStringContains('>Continue</button>', $html);
        // The password is not asked again, and no token is parked in the page.
        $this->assertStringNotContains('type="password"', $html);
        $this->assertStringNotContains('idToken', $html);
        // There is a way out of a half-finished sign-in.
        $this->assertStringContains('action="' . Routes::LOGOUT . '"', $html);
        $this->assertStringContains('>Start again</button>', $html);
    }

    /** A refusal is worded the same either way, so nothing enumerates accounts. */
    public function testARefusalSaysTheSameThingWhicheverHalfFailed(): void
    {
        $html = $this->view()->render('admin/login', [
            'page' => $this->loginPage(['error' => 'Email or password is not right', 'email' => 'boss@speedat.test']),
        ]);

        $this->assertStringContains('<div class="notice err" role="alert">Email or password is not right</div>', $html);
        $this->assertStringContains('value="boss@speedat.test"', $html, 'the second attempt does not start empty');
        $this->assertStringNotContains('no such admin', $html);
    }

    /** No Google on this deployment: the button and its "or" line both go. */
    public function testWithoutGoogleOnlyThePasswordFormIsOffered(): void
    {
        $html = $this->view()->render('admin/login', ['page' => $this->loginPage(['googleReady' => false])]);

        $this->assertStringNotContains('Continue with Google', $html);
        $this->assertStringNotContains('or with email and password', $html);
        $this->assertStringContains('action="' . Routes::LOGIN_PASSWORD . '"', $html);
    }

    /** A deployment that cannot sign anyone in says so instead of refusing every attempt. */
    public function testAMisconfiguredDeploymentReplacesTheFormWithAnExplanation(): void
    {
        $html = $this->view()->render('admin/login', [
            'page' => $this->loginPage(['configWarning' => 'Sign-in is not configured on this deployment yet.']),
        ]);

        $this->assertStringContains('<div class="notice warn">Sign-in is not configured on this deployment yet.</div>', $html);
        $this->assertStringNotContains('type="password"', $html);
        $this->assertStringNotContains('Continue with Google', $html);
        $this->assertStringContains('&#8592; Back to the website', $html, 'the way out is still there');
    }
}
