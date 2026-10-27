<?php

declare(strict_types=1);

namespace Speedat\Http;

use Speedat\Core\App;
use Speedat\Core\Clock;
use Speedat\Core\Config;
use Speedat\Core\RateLimit;
use Speedat\Core\Response;
use Speedat\Domain\Shipments\NameLookup;
use Speedat\Domain\Shipments\ShipmentId;
use Speedat\Domain\Shipments\ShipmentRepository;
use Speedat\Domain\Shipments\ShipmentStatus;
use Speedat\Domain\Tracking\Carriers;
use Speedat\Domain\Tracking\PublicTimeline;
use Speedat\Domain\Tracking\TrackingRepository;
use Speedat\View\PageView;
use Speedat\View\TrackView;

/**
 * GET /track - a customer types their Speedat shipment number and sees where
 * the parcel is.
 *
 * WHAT IT SHOWS: the status in the office's own customer wording, the
 * destination country, the carrier's name, and a timeline of date, place and
 * status. WHAT IT NEVER SHOWS: a name, a phone number, an address, the
 * office's notes or the carrier's own wording. PublicTimeline is where that
 * is enforced; this controller only hands it records.
 *
 * WHY THE NUMBER IS THE ONLY KEY. Shipment numbers are random, about 39 bits
 * (ShipmentId), so holding one is the proof of being entitled to see it - the
 * same as a carrier's own tracking number. Guessing is made pointless by the
 * rate limit (limits.track_per_hour per address, default 120) and by the
 * answer to a wrong number being the same whatever is wrong with it: a badly
 * typed number and a well-formed one that does not exist read identically,
 * so the page cannot be used to learn which numbers are real.
 *
 * NO JAVASCRIPT, NO OUTBOUND CALL. It is a GET form; the result page carries a
 * `Refresh` header so a phone left open on it updates itself, and the data is
 * whatever the hourly cron last stored - a visitor pressing reload never
 * causes a request to the carrier. Not cached by the browser or a proxy
 * (every HTML page is no-store), because a shared phone must not show the
 * previous person's parcel from cache.
 *
 * NOT IN THE SITEMAP AND NOINDEX. It is not one of the Domain\Seo\Routes
 * pages, so it takes the fallback head, which says noindex for anything
 * outside that list (like /terms). A results page is one customer's parcel;
 * nothing about it belongs in a search engine. robots.txt does not disallow
 * it, deliberately: a crawler must be able to fetch the page to read the
 * noindex.
 */
final class TrackController
{
    public const PATH = '/track';

    /** Thirty minutes: the data changes at most hourly. */
    private const REFRESH_SECONDS = 1800;

    public function __construct(
        private readonly ShipmentRepository $shipments = new ShipmentRepository(),
        private readonly TrackingRepository $tracking = new TrackingRepository(),
    ) {
    }

    public function show(App $app): Response
    {
        $doc   = Page::doc();
        $typed = trim($app->request->query('id', '', 40));

        $page = new PageView(
            doc: $doc,
            path: self::PATH,
            title: 'Track your shipment',
            description: 'Enter your Speedat shipment number to see where your parcel is.',
            theme: PageView::themeFromCookie($app->request->cookie('theme')),
            year: (int) Clock::now()->format('Y'),
            scripts: ['js/ui.js'],
        );

        if ($typed === '') {
            return $app->page('site/track', ['view' => new TrackView($page, TrackView::STATE_FORM)]);
        }

        // Counted only when a number is looked up: the empty form is free.
        RateLimit::enforce('track', $app->request, max(1, Config::int('limits.track_per_hour', 120)), 3600);

        $id       = ShipmentId::normalise($typed);
        $shipment = $id !== null ? $this->shipments->find($id) : null;
        $history  = $shipment !== null ? $this->shipments->history($shipment->id) : null;

        if ($shipment === null || $history === null) {
            return $app->page('site/track', [
                'view' => new TrackView($page, TrackView::STATE_NOT_FOUND, typed: $typed, officeWaHref: self::officeWa($doc)),
            ], 404);
        }

        $zone = Clock::zone();
        $rows = [];
        foreach (PublicTimeline::rows($history->events, $this->tracking->checkpoints($shipment->id)) as $row) {
            $at     = $row['at']->setTimezone($zone);
            $rows[] = [
                'date'  => $at->format('j M Y'),
                'time'  => $at->format('H:i'),
                'label' => $row['label'],
                'place' => $row['place'],
                'stage' => $row['stage'],
            ];
        }

        $state   = $this->tracking->state($shipment->id);
        $checked = $state !== null && $state['state'] !== TrackingRepository::STATE_MANUAL ? $state['checked_at'] : null;

        $link  = PublicTimeline::link($shipment->id);
        $share = $link !== ''
            ? 'https://wa.me/?text=' . rawurlencode('Track my Speedat shipment ' . $shipment->id . ': ' . $link)
            : '';

        $view = new TrackView(
            page: $page,
            state: TrackView::STATE_FOUND,
            typed: $shipment->id,
            id: $shipment->id,
            status: $shipment->status,
            statusLabel: ShipmentStatus::label($shipment->status),
            statusText: ShipmentStatus::customerText($shipment->status),
            destination: (new NameLookup())->destinationName($shipment->destId),
            // Only a carrier we know by name. The column is free text and the
            // page promises it prints nothing a person typed.
            carrier: Carriers::match($shipment->carrier) !== null ? Carriers::displayName($shipment->carrier) : '',
            rows: $rows,
            checkedAt: $checked !== null ? $checked->setTimezone($zone)->format('j M Y, H:i') : '',
            shareHref: $share,
            officeWaHref: self::officeWa($doc),
            refreshSeconds: $shipment->isOpen() ? self::REFRESH_SECONDS : 0,
        );

        $response = $app->page('site/track', ['view' => $view]);

        return $view->refreshSeconds > 0 ? $response->header('Refresh', (string) $view->refreshSeconds) : $response;
    }

    /** The office's WhatsApp link, or '' when the owner has not set a number. */
    private static function officeWa(\Speedat\View\Doc $doc): string
    {
        return $doc->company()->whatsapp !== '' ? $doc->waHref() : '';
    }
}
