<?php

declare(strict_types=1);

namespace Speedat\Tests\Ops;

use Speedat\Core\Config;
use Speedat\Core\Db;
use Speedat\Domain\Ops\Counts;
use Speedat\Domain\Ops\Metrics;
use Speedat\Tests\Ops\Support\FakeHold;
use Speedat\Tests\Ops\Support\FakeVersions;

/**
 * The metrics scrape: the token, the exposition format, the numbers, and the
 * two metrics that must no longer exist.
 */
final class MetricsTest extends OpsTestCase
{
    private const TOKEN = 'ops-test-metrics-token-6a1f3c9d';

    /** @var array<string,string> */
    private array $savedTokens = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->savedTokens = [
            'metrics' => Config::str('tokens.metrics'),
            'cron'    => Config::str('tokens.cron'),
        ];

        Config::set('tokens.metrics', self::TOKEN);
    }

    public function tearDown(): void
    {
        Config::set('tokens.metrics', $this->savedTokens['metrics'] ?? '');
        Config::set('tokens.cron', $this->savedTokens['cron'] ?? '');

        parent::tearDown();
    }

    private function metrics(\DateTimeImmutable $now): Metrics
    {
        return new Metrics(
            new Counts($now),
            FakeVersions::publishedAt('2026-09-20 06:00:00', 12),
            FakeHold::off(),
            $now,
        );
    }

    public function testWithoutATokenItIs401(): void
    {
        $response = $this->metrics(self::utc('now'))->respond(null);

        $this->assertSame(401, $response->status);
        $this->assertStringContains('application/json', $response->contentType());
        $this->assertSame(
            '{"error":{"code":"unauthorized","message":"Bad or missing token"}}',
            $response->body,
            'the wording an existing monitor matches on'
        );
        $this->assertStringNotContains('speedat_', $response->body, 'a refused scrape leaks no numbers');
    }

    public function testWithTheWrongTokenItIs401(): void
    {
        $metrics = $this->metrics(self::utc('now'));

        $this->assertSame(401, $metrics->respond('Bearer wrong')->status);
        $this->assertSame(401, $metrics->respond('Bearer ' . self::TOKEN . 'x')->status, 'a longer guess');
        $this->assertSame(401, $metrics->respond(substr('Bearer ' . self::TOKEN, 0, -1))->status, 'a shorter guess');
        $this->assertSame(401, $metrics->respond(self::TOKEN)->status, 'the scheme word is part of the comparison');
        $this->assertSame(401, $metrics->respond('bearer ' . self::TOKEN)->status, 'and it is case sensitive');
    }

    public function testWithTheTokenItServesPrometheusText(): void
    {
        $response = $this->metrics(self::utc('now'))->respond('Bearer ' . self::TOKEN);

        $this->assertSame(200, $response->status);
        $this->assertSame('text/plain; version=0.0.4; charset=utf-8', $response->contentType());
        $this->assertStringContains('no-store', $response->headers['Cache-Control'] ?? '');

        foreach ([
            'speedat_prices_held',
            'speedat_prices_held_seconds',
            'speedat_quotes_24h',
            'speedat_quotes_booked_24h',
            'speedat_leads_new',
            'speedat_shipments_open',
            'speedat_shipments_exception',
            'speedat_notify_failed_24h',
            'speedat_rates_version',
            'speedat_rates_publish_age_seconds',
        ] as $name) {
            $this->assertStringContains('# TYPE ' . $name . ' gauge', $response->body, $name . ' is exposed');
        }

        $this->assertTrue(str_ends_with($response->body, "\n"), 'the exposition format ends with a newline');
    }

    public function testTheCronTokenAlsoWorksWhenNoMetricsTokenIsSet(): void
    {
        Config::set('tokens.metrics', '');
        Config::set('tokens.cron', 'ops-test-cron-token-5b2e');

        $metrics = $this->metrics(self::utc('now'));

        $this->assertSame(200, $metrics->respond('Bearer ops-test-cron-token-5b2e')->status);
        $this->assertSame(401, $metrics->respond('Bearer ' . self::TOKEN)->status);
    }

    public function testNoTokenAtAllRefusesEverybody(): void
    {
        Config::set('tokens.metrics', '');
        Config::set('tokens.cron', '');

        $metrics = $this->metrics(self::utc('now'));

        $this->assertSame(401, $metrics->respond('Bearer ')->status);
        $this->assertSame(401, $metrics->respond('Bearer anything')->status);
        $this->assertSame(401, $metrics->respond(null)->status);
    }

    /**
     * A GUARD, NOT A LEFTOVER. The two gauges the TypeScript exported about the
     * Excel rate-sheet feature are gone with the feature (owner, 2026-09-27),
     * and the exposition must not grow them back: a gauge that always reads zero
     * is worse than no gauge, because somebody eventually writes an alert rule
     * against it and then believes the silence.
     */
    public function testNothingAboutRateSheetsIsExported(): void
    {
        $body = $this->metrics(self::utc('now'))->render();

        $this->assertStringNotContains('speedat_imports_failed_24h', $body);
        $this->assertStringNotContains('speedat_imports_pending', $body);
        $this->assertStringNotContains('import', $body, 'nothing about rate sheets is exported any more');
        $this->assertStringNotContains('sheet', $body);
    }

    public function testTheNumbersAreTheOnesInTheDatabase(): void
    {
        $now   = self::utc('now');
        $since = self::sql($now->sub(new \DateInterval('PT24H')));

        $this->insertQuote($now->sub(new \DateInterval('PT1H')));
        $this->insertQuote($now->sub(new \DateInterval('PT2H')), true);
        $this->insertLead('new');
        $this->insertShipment('exception');

        $body = $this->metrics($now)->render();

        $this->assertStringContains(
            "\nspeedat_quotes_24h " . (int) Db::value('SELECT COUNT(*) FROM quotes WHERE created_at >= ?', [$since]) . "\n",
            $body
        );
        $this->assertStringContains(
            "\nspeedat_quotes_booked_24h " . (int) Db::value('SELECT COUNT(*) FROM quotes WHERE created_at >= ? AND booked = 1', [$since]) . "\n",
            $body
        );
        $this->assertStringContains(
            "\nspeedat_leads_new " . (int) Db::value("SELECT COUNT(*) FROM leads WHERE status = 'new'") . "\n",
            $body
        );
        $this->assertStringContains(
            "\nspeedat_shipments_open " . (int) Db::value("SELECT COUNT(*) FROM shipments WHERE status <> 'delivered'") . "\n",
            $body
        );
        $this->assertStringContains(
            "\nspeedat_shipments_exception " . (int) Db::value("SELECT COUNT(*) FROM shipments WHERE status = 'exception'") . "\n",
            $body
        );
    }

    public function testTheHoldAndPublishGaugesReadTheClock(): void
    {
        $now = self::utc('2026-09-27 12:00:00');

        $held = new Metrics(
            new Counts($now),
            FakeVersions::publishedAt('2026-09-26 12:00:00', 12),
            FakeHold::onSince('2026-09-27 09:00:00'),
            $now,
        );

        $body = $held->render();

        $this->assertStringContains("\nspeedat_prices_held 1\n", $body);
        $this->assertStringContains("\nspeedat_prices_held_seconds 10800\n", $body, 'three hours');
        $this->assertStringContains("\nspeedat_rates_version 12\n", $body);
        $this->assertStringContains("\nspeedat_rates_publish_age_seconds 86400\n", $body, 'one day');
    }

    public function testNothingPublishedReadsMinusOne(): void
    {
        $now = self::utc('2026-09-27 12:00:00');

        $body = (new Metrics(new Counts($now), FakeVersions::none(), FakeHold::off(), $now))->render();

        $this->assertStringContains("\nspeedat_rates_version 0\n", $body);
        $this->assertStringContains(
            "\nspeedat_rates_publish_age_seconds -1\n",
            $body,
            '"never published" must stay distinguishable from "published just now"'
        );
        $this->assertStringContains("\nspeedat_prices_held 0\n", $body);
        $this->assertStringContains("\nspeedat_prices_held_seconds 0\n", $body);
    }
}
