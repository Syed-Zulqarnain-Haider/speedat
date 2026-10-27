<?php

declare(strict_types=1);

namespace Speedat\Tests\ViewAdmin;

use Speedat\Domain\Shipments\CustomerMessage;
use Speedat\View\Admin\ShipmentPage;

/**
 * The shipment desk's side of customer tracking: the WhatsApp update ends with
 * the customer's /track link, the carrier box says which carriers are followed
 * automatically, and a "Customer tracking" panel shows the office the link and
 * one sentence on whether this parcel is tracked by itself.
 */
final class ShipmentTrackingDeskTest extends AdminViewTestCase
{
    private const TRACK_URL = 'https://speedat.example/track?id=SH-4K7QX2M9';

    private const LINE = 'Tracked automatically every hour. Last checked 27 Oct, 14:05.';

    public function testTheWhatsAppUpdateEndsWithTheTrackLink(): void
    {
        $message = $this->page(self::TRACK_URL)->customerMessage();

        $this->assertTrue(
            str_ends_with($message, "\nTrack it here: " . self::TRACK_URL),
            'the update must end with the /track link, got: ' . $message,
        );
        $this->assertSame(1, substr_count($message, 'Track it here:'));

        // And it travels inside the wa.me link, encoded.
        $this->assertStringContains(rawurlencode('Track it here: ' . self::TRACK_URL), $this->page(self::TRACK_URL)->waHref());
    }

    public function testWithoutAnAppUrlTheUpdateHasNoTrackLine(): void
    {
        $message = $this->page('')->customerMessage();

        $this->assertStringNotContains('Track it here', $message);
        $this->assertStringNotContains('/track', $message);
        $this->assertFalse(str_ends_with($message, "\n"), 'no blank line left where the link would have been');
        $this->assertStringContains('Tracking number: EK623-99814 (Emirates SkyCargo)', $message, 'the rest of the message is unchanged');
    }

    /** The domain builder and the desk's builder say the same thing. */
    public function testTheDomainMessageAgreesWithTheDesk(): void
    {
        $page   = $this->page(self::TRACK_URL);
        $events = array_map(static fn (array $e): array => ['status' => $e['status'], 'note' => $e['note']], $page->events);

        $built = CustomerMessage::build(
            $page->companyName,
            $page->shipment['id'],
            $page->shipment['customerName'],
            $page->shipment['destination'],
            $page->shipment['status'],
            $page->shipment['trackingNo'],
            $page->shipment['carrier'],
            $events,
            self::TRACK_URL,
        );

        $this->assertSame($page->customerMessage(), $built);

        $without = CustomerMessage::build(
            $page->companyName,
            $page->shipment['id'],
            $page->shipment['customerName'],
            $page->shipment['destination'],
            $page->shipment['status'],
            $page->shipment['trackingNo'],
            $page->shipment['carrier'],
            $events,
        );
        $this->assertStringNotContains('Track it here', $without);
    }

    public function testTheCarrierHintListsTheTrackedCarriers(): void
    {
        $hint = null;
        foreach ($this->page(self::TRACK_URL)->detailFields() as $field) {
            if ($field->name === 'carrier') {
                $hint = $field->hint;
            }
        }

        $this->assertNotNull($hint, 'the carrier field is missing');
        $this->assertStringContains('Tracked automatically:', (string) $hint);
        $this->assertStringContains('DHL Express', (string) $hint);
        $this->assertStringNotContains('TeamGlobal', (string) $hint, 'TeamGlobal is tracked by hand, so it is not offered as automatic');
        $this->assertStringNotContains("\u{2014}", (string) $hint);
    }

    public function testTheDeskShowsTheCustomerTrackingPanel(): void
    {
        $html = $this->render($this->page(self::TRACK_URL));

        $this->assertStringContains('<h2 class="step">Customer tracking</h2>', $html);
        $this->assertStringContains('href="' . htmlspecialchars(self::TRACK_URL, ENT_QUOTES) . '"', $html);
        $this->assertStringContains('rel="noopener noreferrer"', $html);
        $this->assertStringContains('<p class="hint">' . htmlspecialchars(self::LINE, ENT_QUOTES) . '</p>', $html);
        $this->assertNoInjection($html, 'shipment desk');
    }

    public function testWithoutALinkThePanelStillCarriesTheTrackingLine(): void
    {
        $html = $this->render($this->page(''));

        $this->assertStringContains('<h2 class="step">Customer tracking</h2>', $html);
        $this->assertStringNotContains('/track?id=', $html);
        $this->assertStringContains(htmlspecialchars(self::LINE, ENT_QUOTES), $html);
    }

    public function testTheTrackingLineIsEscaped(): void
    {
        $html = $this->render($this->page(self::TRACK_URL, 'Tracked by hand: "' . self::HOSTILE . '" is not covered.'));

        $this->assertStringNotContains('<script>alert(1)</script>', $html);
        $this->assertStringContains('Tracked by hand: &quot;&lt;script&gt;', $html);
    }

    /* ------------------------------------------------------------------ */

    private function page(string $trackUrl, string $line = self::LINE): ShipmentPage
    {
        $base = $this->shipmentPage();

        return new ShipmentPage(
            shipment: $base->shipment,
            events: $base->events,
            companyName: $base->companyName,
            trackUrl: $trackUrl,
            trackingLine: $line,
        );
    }

    private function render(ShipmentPage $page): string
    {
        return $this->view()->render('admin/shipment', ['page' => $page, 'shell' => $this->shell('shipments')]);
    }
}
