<?php

declare(strict_types=1);

namespace Speedat\Tests\ViewAdmin;

use Speedat\Core\Cookies;
use Speedat\Core\Csrf;
use Speedat\Core\Paths;
use Speedat\Core\Request;
use Speedat\Core\Session;
use Speedat\Core\View as ViewEngine;
use Speedat\Tests\TestCase;
use Speedat\View\Admin\BulkForm;
use Speedat\View\Admin\DestinationRow;
use Speedat\View\Admin\DiffLine;
use Speedat\View\Admin\HoldPanel;
use Speedat\View\Admin\InboxPage;
use Speedat\View\Admin\LeadCard;
use Speedat\View\Admin\LoginPage;
use Speedat\View\Admin\NewShipmentPage;
use Speedat\View\Admin\RatesPage;
use Speedat\View\Admin\Review;
use Speedat\View\Admin\SecurityPage;
use Speedat\View\Admin\Shell;
use Speedat\View\Admin\ShipmentPage;
use Speedat\View\Admin\ShipmentsPage;
use Speedat\View\Admin\Site;
use Speedat\View\Admin\TestPricePanel;
use Speedat\View\Admin\VersionRow;

/**
 * The base for every admin-template test: a real `View`, a real `Csrf`, and a
 * fixture draft that differs from the live version in the ways the editor has
 * to show - a renamed country, a changed price, a hidden row, a country that
 * is not in the live version at all.
 *
 * The templates are rendered FOR REAL, through the same engine and the same
 * layout the front controller uses, because every bug worth catching here
 * lives in the markup: a form without a token, a value printed unescaped, a
 * publish button an editor should never see, a control that only works with
 * JavaScript. None of that is visible in a view model.
 *
 * Nothing here touches the database. `Csrf` needs a `Session`, and a Session
 * with no cookie never reads one - the anonymous branch mints its secret and
 * returns, which is exactly what a test wants.
 *
 * SELF-CONTAINED ON PURPOSE. It would be shorter to extend the site templates'
 * `ViewTestCase` and borrow its fixture, but that file belongs to another
 * track: a seed changed there for a reason about the public pages would break
 * every assertion here, days later, for no reason anyone could see.
 */
abstract class AdminViewTestCase extends TestCase
{
    /**
     * A string that escapes every context it could be printed in if nothing
     * escapes it: it closes a tag, closes an attribute in both quote styles,
     * and would end a <script> block.
     */
    public const HOSTILE = '<script>alert(1)</script>" and \' and </script> & <img src=x onerror=alert(2)>';

    /** The words the removed rate-sheet feature would have left behind. */
    private const FORBIDDEN = ['excel', 'import', 'spreadsheet', 'upload'];

    /**
     * What `Http\Admin\EditorPage::render()` hands the rates template beside
     * the view model: the published version the editor was OPENED at, and the
     * fingerprint of the draft row it was drawn from. Every writing form on
     * the page prints them back into hidden fields.
     */
    private const EDITOR_KEYS = ['baseVersion' => 11, 'draftRevision' => '1a2b3c4d5e6f7a8b'];

    protected function view(): ViewEngine
    {
        $view = new ViewEngine(Paths::root() . '/app/Views', 'test-nonce-value');
        $view->share(['basePath' => '', 'csrf' => $this->csrf()]);

        return $view;
    }

    protected function csrf(): Csrf
    {
        return new Csrf(new Session(Request::synthetic('GET', '/admin'), new Cookies(false)));
    }

    /* ------------------------------------------------------------------ *
     * The documents
     * ------------------------------------------------------------------ */

    /** @param array<string,mixed> $overrides merged over the top level */
    protected function draft(array $overrides = []): Site
    {
        return Site::fromArray(array_replace_recursive(self::seed(), $overrides));
    }

    /**
     * The published version the draft is compared against: version 11, live,
     * with Canada's 1 kg price lower and Germany still called Germany.
     */
    protected function live(array $overrides = []): Site
    {
        return Site::fromArray(array_replace_recursive(self::seed(), [
            'version' => 11,
            'live'    => true,
            'destinations' => [
                0 => ['rates' => ['exp' => ['grid' => ['1' => 4800]]]],
                2 => ['name' => 'Germany'],
            ],
        ], $overrides));
    }

    /** The same draft with every printed string replaced by HOSTILE. */
    protected function hostileDraft(): Site
    {
        return Site::fromArray(self::poison(self::seed()));
    }

    /* ------------------------------------------------------------------ *
     * The pages
     * ------------------------------------------------------------------ */

    protected function shell(string $current = 'rates', bool $owner = true, array $badges = ['inbox' => 3, 'shipments' => 1]): Shell
    {
        return new Shell(
            companyName: 'Speedat International Courier',
            userEmail: 'boss@speedat.test',
            userRole: $owner ? Shell::OWNER : 'editor',
            current: $current,
            badges: $badges,
            showSections: $current === 'rates',
        );
    }

    /**
     * The rates editor, with everything the page can show switched on: a
     * changed row, a row whose prices are folded open, a hidden row, a hold
     * that is off but whose dialog can be opened, three versions of history.
     *
     * @param array<string,mixed> $overrides constructor arguments by name
     */
    protected function ratesPage(array $overrides = []): RatesPage
    {
        $draft = $overrides['draft'] ?? $this->draft();
        $live  = $overrides['live'] ?? $this->live();

        $args = [
            'draft'           => $draft,
            'live'            => $live,
            'rows'            => $this->rows($draft, $live),
            'versions'        => VersionRow::all([
                ['version' => 11, 'publishedAt' => '2026-09-22T09:14:00.000Z', 'publishedBy' => 'boss@speedat.test', 'summary' => '12 prices, 1 country added'],
                ['version' => 10, 'publishedAt' => '2026-09-01T06:02:00.000Z', 'publishedBy' => 'office@speedat.test', 'summary' => '3 prices'],
            ], 11),
            'hold'            => new HoldPanel(on: false, message: 'We are updating our prices.', isOwner: true),
            'bulk'            => BulkForm::fresh($draft->services()),
            'test'            => TestPricePanel::blank($draft),
            'changeCount'     => 2,
            'draftUpdatedBy'  => 'boss@speedat.test',
            'draftUpdatedAt'  => '2026-09-23T14:05:00.000Z',
            'viewerEmail'     => 'boss@speedat.test',
            'canPublish'      => true,
            'livePublishedBy' => 'boss@speedat.test',
            'liveAgeDays'     => 1,
            'filter'          => '',
            'csv'             => "Destination,On site\r\nCanada,yes\r\n",
            'review'          => null,
            'publishedNotice' => '',
            'toast'           => '',
            'readOnly'        => false,
        ];

        return new RatesPage(...array_replace($args, $overrides));
    }

    /**
     * One row per destination: Canada changed (its 1 kg price moved), Türkiye
     * open, Germany renamed and hidden by the filter, Narnia new - the four
     * states the row template branches on.
     *
     * @return list<DestinationRow>
     */
    private function rows(Site $draft, Site $live): array
    {
        $services = $draft->services();
        $kgs      = $draft->gridWeights();

        $rows     = [];
        $position = 0;
        foreach ($draft->destinations() as $dest) {
            $rows[] = DestinationRow::build(
                draft: $dest,
                live: $live->destination($dest->id),
                services: $services,
                kgs: $kgs,
                position: $position,
                changed: in_array($dest->id, ['ca', 'de'], true),
                open: $dest->id === 'tr',
                hidden: $dest->id === 'de',
            );
            $position++;
        }

        return $rows;
    }

    /** The review card as the server renders it after "Review and publish". */
    protected function review(array $overrides = []): Review
    {
        $args = [
            'nextVersion' => 12,
            'errors'      => [],
            'lines'       => DiffLine::all([
                ['label' => 'Canada Express 1 kg', 'old' => '4,800', 'new' => '5,220', 'flag' => '+9%', 'kind' => 'grid'],
                ['label' => 'Germany', 'old' => 'Germany', 'new' => 'Deutschland', 'kind' => 'renamed'],
                ['label' => 'Narnia added', 'new' => 'shown on the website', 'kind' => 'added'],
            ]),
            'warnings'    => ['Canada Express 1 kg is 9% above the live price'],
            'holdOn'      => false,
            'offerGoLive' => true,
            'goLive'      => true,
            'resume'      => true,
            'message'     => '',
        ];

        return new Review(...array_replace($args, $overrides));
    }

    protected function inboxPage(array $overrides = []): InboxPage
    {
        $leads = $overrides['leads'] ?? [
            LeadCard::fromArray([
                'id' => 7, 'kind' => 'quote', 'quoteId' => 'SP-260923-K7QX', 'name' => 'Ayesha Khan',
                'phone' => '03001234567', 'email' => 'ayesha@example.test', 'message' => "Two boxes of clothes.\nPickup after 5pm.",
                'destination' => 'Canada', 'weightG' => 4500, 'status' => 'new', 'notes' => '',
                'createdAt' => '2026-09-23T09:14:00.000Z', 'updatedAt' => '2026-09-23T09:14:00.000Z', 'updatedBy' => '',
            ]),
            LeadCard::fromArray([
                'id' => 6, 'kind' => 'message', 'quoteId' => '', 'name' => 'Bilal', 'phone' => '', 'email' => 'bilal@example.test',
                'message' => 'Do you collect from Faisalabad?', 'destination' => '', 'weightG' => 0, 'status' => 'contacted',
                'notes' => 'Called, will send the address', 'createdAt' => '2026-09-22T11:00:00.000Z',
                'updatedAt' => '2026-09-22T12:30:00.000Z', 'updatedBy' => 'office@speedat.test',
            ]),
        ];

        return new InboxPage(
            leads: $leads,
            counts: $overrides['counts'] ?? ['new' => 1, 'contacted' => 1, 'booked' => 4, 'lost' => 0],
            status: $overrides['status'] ?? 'new',
            alertsConfigured: $overrides['alertsConfigured'] ?? false,
            filter: $overrides['filter'] ?? '',
        );
    }

    protected function shipmentsPage(array $overrides = []): ShipmentsPage
    {
        $rows = $overrides['rows'] ?? [
            [
                'id' => 'SH-4K7QX2M9', 'quoteId' => 'SP-260923-K7QX', 'customerName' => 'Ayesha Khan',
                'customerPhone' => '03001234567', 'destination' => 'Canada', 'service' => 'Express',
                'carrier' => 'Emirates SkyCargo', 'trackingNo' => 'EK623-99814', 'status' => 'in_transit',
                'updatedAt' => '2026-09-23T14:05:00.000Z',
            ],
            [
                'id' => 'SH-9ZP31TBC', 'quoteId' => '', 'customerName' => '', 'customerPhone' => '',
                'destination' => 'Australia', 'service' => 'Economy', 'carrier' => '', 'trackingNo' => '',
                'status' => 'exception', 'updatedAt' => '2026-09-22T08:00:00.000Z',
            ],
        ];

        return new ShipmentsPage(
            rows: $rows,
            counts: $overrides['counts'] ?? ['open' => 2, 'exception' => 1],
            status: $overrides['status'] ?? 'open',
            filter: $overrides['filter'] ?? '',
        );
    }

    protected function shipmentPage(array $overrides = []): ShipmentPage
    {
        $shipment = $overrides['shipment'] ?? [
            'id' => 'SH-4K7QX2M9', 'quoteId' => 'SP-260923-K7QX', 'customerName' => 'Ayesha Khan',
            'customerPhone' => '03001234567', 'receiverName' => 'R. Khan', 'carrier' => 'Emirates SkyCargo',
            'trackingNo' => 'EK623-99814', 'notes' => "Fragile.\nReceiver works nights.", 'status' => 'in_transit',
            'createdAt' => '2026-09-23T09:20:00.000Z', 'destination' => 'Canada', 'service' => 'Express',
        ];

        $events = $overrides['events'] ?? [
            ['id' => 1, 'status' => 'booked', 'note' => '', 'at' => '2026-09-23T09:20:00.000Z', 'by' => 'boss@speedat.test'],
            ['id' => 2, 'status' => 'in_transit', 'note' => 'Handed to Emirates flight EK623', 'at' => '2026-09-23T14:05:00.000Z', 'by' => 'office@speedat.test'],
        ];

        return new ShipmentPage(
            shipment: $shipment,
            events: $events,
            companyName: $overrides['companyName'] ?? 'Speedat International Courier',
            notice: $overrides['notice'] ?? '',
        );
    }

    protected function newShipmentPage(array $overrides = []): NewShipmentPage
    {
        return new NewShipmentPage(
            destinations: $overrides['destinations'] ?? [['id' => 'au', 'name' => 'Australia'], ['id' => 'ca', 'name' => 'Canada']],
            services: $overrides['services'] ?? [['id' => 'exp', 'name' => 'Express'], ['id' => 'eco', 'name' => 'Economy']],
            values: $overrides['values'] ?? [
                'customerName' => 'Ayesha Khan', 'customerPhone' => '03001234567',
                'destId' => 'ca', 'serviceId' => 'exp', 'notes' => 'Two boxes of clothes.',
            ],
            leadId: $overrides['leadId'] ?? 7,
            quoteId: $overrides['quoteId'] ?? 'SP-260923-K7QX',
            error: $overrides['error'] ?? '',
        );
    }

    protected function securityPage(array $overrides = []): SecurityPage
    {
        return new SecurityPage(
            viewerEmail: $overrides['viewerEmail'] ?? 'boss@speedat.test',
            totpEnabled: $overrides['totpEnabled'] ?? false,
            admins: $overrides['admins'] ?? [
                ['email' => 'boss@speedat.test', 'role' => 'owner', 'totp' => true, 'addedAt' => '2026-08-01T10:00:00.000Z'],
                ['email' => 'office@speedat.test', 'role' => 'editor', 'totp' => false, 'addedAt' => '2026-08-14T10:00:00.000Z'],
            ],
            checks: $overrides['checks'] ?? [
                ['label' => 'Admin sign-in (Google)', 'ok' => true, 'note' => 'configured'],
                ['label' => 'Email alerts (SMTP)', 'ok' => false, 'note' => 'not configured - alerts are logged, not sent'],
                ['label' => 'Cron secret', 'ok' => true, 'note' => 'set'],
            ],
            activity: $overrides['activity'] ?? [
                ['at' => '2026-09-23T14:05:00.000Z', 'action' => 'publish', 'actor' => 'boss@speedat.test', 'ip' => '3f2a91'],
                ['at' => '2026-09-23T09:02:00.000Z', 'action' => 'login', 'actor' => 'office@speedat.test', 'ip' => ''],
            ],
            enrolment: $overrides['enrolment'] ?? null,
            error: $overrides['error'] ?? '',
            toast: $overrides['toast'] ?? '',
        );
    }

    /** A believable enrolment: a 1x1 PNG data URI stands in for the QR. */
    protected function enrolment(string $qr = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUg=='): array
    {
        return [
            'uri'       => 'otpauth://totp/Speedat:boss@speedat.test?secret=JBSWY3DPEHPK3PXP&issuer=Speedat',
            'qrDataUrl' => $qr,
            'secret'    => 'JBSWY3DPEHPK3PXP',
        ];
    }

    protected function loginPage(array $overrides = []): LoginPage
    {
        return new LoginPage(
            companyName: $overrides['companyName'] ?? 'Speedat International Courier',
            tagline: $overrides['tagline'] ?? 'Speed Against Time',
            step: $overrides['step'] ?? LoginPage::STEP_CREDENTIALS,
            error: $overrides['error'] ?? '',
            email: $overrides['email'] ?? '',
            googleReady: $overrides['googleReady'] ?? true,
            configWarning: $overrides['configWarning'] ?? '',
        );
    }

    /* ------------------------------------------------------------------ *
     * Rendering
     * ------------------------------------------------------------------ */

    /**
     * Every admin screen, rendered. The key is the name a failure message
     * prints, so a broken template says which one it was.
     *
     * @return array<string,string>
     */
    protected function everyScreen(): array
    {
        $v = $this->view();

        return [
            // EDITOR_KEYS are the two values every writing form on the editor
            // hands back: the published version it was opened at, and the
            // fingerprint of the draft row it was drawn from. A render without
            // them is a page whose forms fail closed.
            'rates'          => $v->render('admin/rates', ['page' => $this->ratesPage(), 'shell' => $this->shell(), 'newLeads' => 3] + self::EDITOR_KEYS),
            'rates_review'   => $v->render('admin/rates', ['page' => $this->ratesPage(['review' => $this->review()]), 'shell' => $this->shell(), 'newLeads' => 0] + self::EDITOR_KEYS),
            'rates_held'     => $v->render('admin/rates', [
                'page'  => $this->ratesPage(['hold' => new HoldPanel(on: true, message: 'New rates on Monday.', by: 'boss@speedat.test', since: '2026-09-23T08:00:00.000Z', isOwner: true)]),
                'shell' => $this->shell(),
                'newLeads' => 0,
            ] + self::EDITOR_KEYS),
            'inbox'          => $v->render('admin/inbox', ['page' => $this->inboxPage(), 'shell' => $this->shell('inbox')]),
            'shipments'      => $v->render('admin/shipments', ['page' => $this->shipmentsPage(), 'shell' => $this->shell('shipments')]),
            'shipment'       => $v->render('admin/shipment', ['page' => $this->shipmentPage(), 'shell' => $this->shell('shipments')]),
            'shipment_new'   => $v->render('admin/shipment-new', ['page' => $this->newShipmentPage(), 'shell' => $this->shell('shipments')]),
            'security'       => $v->render('admin/security', ['page' => $this->securityPage(), 'shell' => $this->shell('security')]),
            'security_enrol' => $v->render('admin/security', ['page' => $this->securityPage(['enrolment' => $this->enrolment()]), 'shell' => $this->shell('security')]),
            'security_on'    => $v->render('admin/security', ['page' => $this->securityPage(['totpEnabled' => true]), 'shell' => $this->shell('security')]),
            'login'          => $v->render('admin/login', ['page' => $this->loginPage()]),
            'login_code'     => $v->render('admin/login', ['page' => $this->loginPage(['step' => LoginPage::STEP_CODE, 'email' => 'boss@speedat.test'])]),
        ];
    }

    /* ------------------------------------------------------------------ *
     * Assertions the whole suite shares
     * ------------------------------------------------------------------ */

    /**
     * Every form that CHANGES something carries the token.
     *
     * A GET form is exempt and must be: the two search boxes narrow a list and
     * write nothing, and a token in a GET would appear in the browser's
     * address bar and its history.
     */
    protected function assertEveryPostFormHasAToken(string $html, string $where): void
    {
        preg_match_all('#<form\b([^>]*)>(.*?)</form>#si', $html, $forms, PREG_SET_ORDER);
        $this->assertTrue($forms !== [], $where . ': expected at least one form');

        foreach ($forms as $i => $form) {
            [, $attrs, $body] = $form;
            if (stripos($attrs, 'method="post"') === false) {
                continue;
            }
            $this->assertStringContains(
                'name="_token"',
                $body,
                $where . ': form #' . ($i + 1) . ' posts without a CSRF token (' . trim($attrs) . ')'
            );
        }
    }

    /**
     * Nothing a person typed escaped into live markup.
     *
     * It looks for the OPENING TAG, not the whole payload: an escaped value
     * still contains the words "script" and "alert(1)", so searching for those
     * would pass on any page at all. `<script>alert` and `<img src=x` cannot
     * appear from an escaped value.
     *
     * The event handler is COUNTED rather than banned, because neither escaper
     * touches the `=` or the space - only the brackets around them. There are
     * exactly two inert spellings on an admin page and every `onerror=` must be
     * one of them:
     *
     *   1. `&lt;img src=x onerror=` - htmlspecialchars, printed as text.
     *   2. The same thing with the angle bracket written as a JSON escape
     *      (backslash, u003C), which is what JSON_HEX_TAG produces inside the
     *      rates table's `data-live` attribute - the Undo snapshot - whose
     *      quotes e() escapes again on the way into the attribute.
     *
     * In both the tag never opens. A third spelling is a handler a browser
     * would actually run.
     *
     * The needle for the second is assembled from chr(92) rather than typed:
     * a literal backslash-u in a source file is something more than one tool
     * between here and the disk would like to "helpfully" decode.
     */
    protected function assertNoInjection(string $html, string $where): void
    {
        $this->assertStringNotContains('<script>alert', $html, $where . ': a value was printed as a live <script>');
        $this->assertStringNotContains('<img src=x', $html, $where . ': a value was printed as a live <img>');
        $inert = substr_count($html, '&lt;img src=x onerror=')
            + substr_count($html, chr(92) . 'u003Cimg src=x onerror=');
        $this->assertSame(
            $inert,
            substr_count($html, 'onerror='),
            $where . ': a value carried a live event handler into the markup'
        );
        $this->assertStringNotContains('</script>" and', $html, $where . ': a value closed a tag');
        $this->assertStringNotContains('alert(1)</script>"', $html, $where . ': a value broke out of a double-quoted attribute');
        $this->assertStringNotContains("alert(1)</script>'", $html, $where . ': a value broke out of a single-quoted attribute');
    }

    protected function assertOneH1(string $html, string $where): void
    {
        $this->assertSame(1, substr_count($html, '<h1'), $where . ': expected exactly one <h1>');
    }

    /**
     * The rate-sheet feature is gone, so its vocabulary is gone.
     *
     * Whole words only: "stylesheet" is a stylesheet and `!important` is CSS,
     * neither is a spreadsheet. Rendered HTML rather than source, so the
     * comments explaining WHY the feature was removed are allowed to name it.
     */
    protected function assertSaysNothingAboutSheets(string $html, string $where): void
    {
        foreach (self::FORBIDDEN as $word) {
            $this->assertSame(
                0,
                preg_match('/(?<![a-z])' . $word . '(?![a-z])/i', $html),
                $where . ': the page still says "' . $word . '"'
            );
        }
    }

    /* ------------------------------------------------------------------ *
     * The fixture document
     * ------------------------------------------------------------------ */

    /**
     * Grid pricing, two services, four countries: one ordinary, one with an
     * accent in its name, one renamed from the live version, one brand new
     * with holes in its price list.
     *
     * @return array<string,mixed>
     */
    protected static function seed(): array
    {
        return [
            'version'     => 11,
            'publishedAt' => '2026-09-22T09:14:00.000Z',
            'live'        => false,
            'company'     => [
                'name'         => 'Speedat International Courier',
                'tagline'      => 'Speed Against Time',
                'origin'       => 'Pakistan',
                'whatsapp'     => '923157667076',
                'phone'        => '+92 42 3577 1234',
                'email'        => 'hello@speedat.test',
                'includes'     => "Door-to-door \u{b7} Pickup on request",
                'originCities' => 'Lahore',
                'notes'        => "Customs duty is paid by the receiver.\nNo liquids or batteries.",
            ],
            'content'     => [
                'heroTitle'    => '',
                'heroSub'      => '',
                'stats'        => "countries | 4\nkg minimum | 0.5",
                'promise'      => 'We confirm every price on WhatsApp before we collect.',
                'routesTitle'  => 'Rates',
                'routesNote'   => '',
                'servicesLede' => 'Two ways to send, one number to call.',
                'services'     => "plane | Express | Fastest, tracked door to door\nEconomy | Cheaper, a few days longer",
                'ctaTitle'     => 'Talk to a person',
                'ctaSub'       => 'We answer on WhatsApp within the hour.',
                'contactLede'  => 'The fastest way is WhatsApp.',
                'faqLede'      => 'The things people ask us most.',
                'story'        => 'We started in 2014 with one van.',
                'mission'      => 'Get a parcel out of Lahore the same week it is packed.',
                'vision'       => 'The courier a family recommends to another family.',
                'values'       => 'Honesty | We quote the price we charge.',
                'address'      => '12 Main Boulevard, Gulberg III, Lahore',
                'hours'        => 'Mon to Sat, 10 am to 7 pm',
                'mapUrl'       => '',
                'phone2'       => 'Mobile: +92 333 766 7076',
                'faq'          => "How long does it take? | Three to ten working days.\nDo you collect? | Yes, pickup in Lahore is included.",
            ],
            'services'    => [
                ['id' => 'exp', 'name' => 'Express', 'note' => 'Fastest, tracked door to door'],
                ['id' => 'eco', 'name' => 'Economy', 'note' => 'Cheaper, a few days longer'],
            ],
            'settings'    => [
                'pricingMode'       => 'grid',
                'currency'          => 'PKR',
                'volumetricDivisor' => 5000,
                'firstKg'           => 0.5,
                'stepKg'            => 0.5,
                // No docMaxKg and no per-rate `doc`: the flat document rate left
                // the product on 2026-09-27 (owner). A fixture that still
                // carried them would keep testing a column nobody can see.
                'taxPct'            => 17,
                'roundTo'           => 10,
                'maxKg'             => 25,
                'showEta'           => true,
                'cutoffHour'        => 15,
                'workingDays'       => 'Mon, Tue, Wed, Thu, Fri, Sat',
                'holidays'          => '',
                'addons'            => 'Pickup and service charges | 500 | on',
                'disclaimer'        => 'Prices exclude customs duty at the destination.',
            ],
            'destinations' => [
                [
                    'id'     => 'ca',
                    'name'   => 'Canada',
                    'active' => true,
                    'rates'  => [
                        'exp' => ['days' => '4-6', 'grid' => ['1' => 5220, '2' => 6700, '3' => 8100, '5' => 11000, '10' => 19000, '15' => 27000, '20' => 35000, '25' => 43000]],
                        'eco' => ['days' => '8-12', 'grid' => ['1' => 4100, '2' => 5400, '3' => 6600, '5' => 9000, '10' => 15000, '15' => 21000, '20' => 27000, '25' => 33000]],
                    ],
                ],
                [
                    'id'     => 'tr',
                    'name'   => "T\u{fc}rkiye",
                    'active' => true,
                    'rates'  => [
                        // A ladder with both faults in it on purpose: 3 and 4 kg
                        // are blank between priced weights (a GAP - the engine
                        // charges the 5 kg box there), and 6 kg is priced below
                        // 5 kg (the ladder runs BACKWARDS, so a heavier parcel
                        // is cheaper). The editor has to mark both in place.
                        'exp' => ['days' => '3-5', 'grid' => ['1' => 5000, '2' => 6400, '5' => 10400, '6' => 9000, '10' => 18000, '25' => 40500]],
                        'eco' => ['days' => '7-10', 'grid' => ['1' => 3900, '5' => 8500]],
                    ],
                ],
                [
                    'id'     => 'de',
                    'name'   => 'Deutschland',
                    'active' => false,
                    'rates'  => ['exp' => ['days' => '4-6', 'grid' => ['1' => 4800]]],
                ],
                [
                    'id'     => 'zz',
                    'name'   => 'Narnia',
                    'active' => true,
                    'rates'  => ['exp' => ['days' => '6-9', 'grid' => ['1' => 7000, '10' => 25000]]],
                ],
            ],
        ];
    }

    /**
     * Every printed string in the document replaced by HOSTILE; numbers,
     * booleans and the keys the engine PARSES rather than prints left alone,
     * so the page still prices and the markup that only exists when there is a
     * price is still exercised.
     *
     * @param  array<mixed,mixed> $a
     * @return array<mixed,mixed>
     */
    private static function poison(array $a, string $key = ''): array
    {
        foreach ($a as $k => $v) {
            if (is_array($v)) {
                $a[$k] = self::poison($v, is_string($k) ? $k : $key);
                continue;
            }
            if (!is_string($v)) {
                continue;
            }
            if (in_array((string) $k, ['id', 'pricingMode', 'workingDays', 'holidays', 'addons', 'days', 'publishedAt'], true)) {
                continue;
            }
            if ($key === 'grid') {
                continue;
            }
            $a[$k] = self::HOSTILE;
        }

        return $a;
    }
}
