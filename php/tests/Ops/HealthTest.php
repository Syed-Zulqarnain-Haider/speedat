<?php

declare(strict_types=1);

namespace Speedat\Tests\Ops;

use Speedat\Core\Config;
use Speedat\Core\Db;
use Speedat\Core\Paths;
use Speedat\Domain\Ops\Health;
use Speedat\Tests\Ops\Support\FakeHold;
use Speedat\Tests\Ops\Support\FakeVersions;

/**
 * The health check, with the database up and with it unreachable.
 *
 * The unreachable case is produced for real - the connection is pointed at a
 * closed port and PDO is allowed to fail - rather than with a stub, because
 * the thing being tested is precisely what happens to a driver error on its
 * way out: it must reach the log and must NOT reach the response.
 */
final class HealthTest extends OpsTestCase
{
    private string $host = '';

    private int $port = 0;

    public function setUp(): void
    {
        parent::setUp();

        $this->host = Config::str('db.host');
        $this->port = Config::int('db.port', 3306);
    }

    public function tearDown(): void
    {
        // Always put the connection back, whatever the test did to it, or every
        // suite after this one fails against a closed port.
        Config::set('db.host', $this->host);
        Config::set('db.port', $this->port);
        Db::reset();

        parent::tearDown();
    }

    public function testADatabaseThatAnswersIsHealthy(): void
    {
        $health = new Health(FakeVersions::publishedAt('2026-09-25 06:00:00', 12), FakeHold::off());
        $report = $health->check();

        $this->assertTrue($report->databaseUp, 'the test database answered');
        $this->assertSame(200, $report->httpStatus());
        $this->assertTrue($report->ok());
        $this->assertSame([], $report->missing, 'every required extension is present on this PHP');
    }

    public function testTheDetailedAnswerHasTheDocumentedShape(): void
    {
        $health = new Health(FakeVersions::publishedAt('2026-09-25 06:00:00', 12), FakeHold::onSince('2026-09-27 00:00:00'));

        $response = $health->respond(true);
        $body     = $response->decoded();

        $this->assertSame(200, $response->status);
        $this->assertStringContains('application/json', $response->contentType());

        // The five keys docs/deploy-hostinger.md tells the operator to look for.
        foreach (['ok', 'php', 'env', 'database', 'time'] as $key) {
            $this->assertTrue(array_key_exists($key, $body), 'the documented key "' . $key . '" is present');
        }

        $this->assertSame('up', $body['database'] ?? null);
        $this->assertSame(PHP_VERSION, $body['php'] ?? null);
        $this->assertSame(Config::env(), $body['env'] ?? null);

        $rates = is_array($body['rates'] ?? null) ? $body['rates'] : [];
        $this->assertSame(12, $rates['version'] ?? null, 'the live version number');
        $this->assertTrue(is_int($rates['ageSeconds'] ?? null), 'the age is a number of seconds');
        $this->assertTrue(($rates['ageSeconds'] ?? -1) > 0, 'a version published in the past has a positive age');

        $hold = is_array($body['hold'] ?? null) ? $body['hold'] : [];
        $this->assertTrue($hold['on'] ?? false, 'the hold state is reported');

        $storage = is_array($body['storage'] ?? null) ? $body['storage'] : [];
        foreach (['logs', 'cache', 'tmp'] as $dir) {
            $this->assertTrue(array_key_exists($dir, $storage), 'storage/' . $dir . ' is reported');
        }

        $extensions = is_array($body['extensions'] ?? null) ? $body['extensions'] : [];
        foreach (Health::REQUIRED as $name) {
            $this->assertTrue($extensions[$name] ?? false, $name . ' is loaded');
        }
    }

    public function testTheAnswerNeverCarriesAPathOrADriverMessage(): void
    {
        $health = new Health(FakeVersions::publishedAt('2026-09-25 06:00:00'), FakeHold::off());
        $body   = $health->respond(true)->body;

        // The server's layout is not something an ops endpoint gives away.
        $this->assertStringNotContains(Paths::root(), $body);
        $this->assertStringNotContains('storage/', $body);
        $this->assertStringNotContains(Config::str('db.name'), $body);
        $this->assertStringNotContains(Config::str('db.user'), $body);
        $this->assertStringNotContains('SQLSTATE', $body);
        $this->assertStringNotContains('PDO', $body);
    }

    public function testAnUnreachableDatabaseIs503AndSaysNothingElse(): void
    {
        // Port 1 on loopback: refused instantly, so the test is fast and the
        // failure is a genuine connection failure rather than a timeout.
        Config::set('db.host', '127.0.0.1');
        Config::set('db.port', 1);
        Db::reset();

        $health = new Health(FakeVersions::none(), FakeHold::off());
        $report = $health->check();

        $this->assertFalse($report->databaseUp);
        $this->assertSame('down', $report->status());
        $this->assertFalse($report->ok());
        $this->assertSame(503, $report->httpStatus());

        $detailed = $health->respond(true);
        $this->assertSame(503, $detailed->status);
        $this->assertSame('down', $detailed->decoded()['database'] ?? null);
        $this->assertStringNotContains('SQLSTATE', $detailed->body);
        $this->assertStringNotContains('Connection refused', $detailed->body);
        $this->assertStringNotContains('127.0.0.1', $detailed->body);

        // And the public answer says only that it is not ok.
        $publicAnswer = $health->respond(false);
        $this->assertSame(503, $publicAnswer->status);
        $this->assertSame('{"ok":false}', $publicAnswer->body, 'the public answer is one boolean, whatever is wrong');
    }

    public function testTheDetailIsLockedBehindTheMetricsToken(): void
    {
        $token = Config::str('tokens.metrics');
        if ($token === '') {
            $this->skip('tokens.metrics is not set in config/config.php');
        }

        $this->assertTrue(Health::detailUnlocked($token));
        $this->assertTrue(Health::detailUnlocked(null, 'Bearer ' . $token));
        $this->assertFalse(Health::detailUnlocked('wrong-' . $token));
        $this->assertFalse(Health::detailUnlocked(null, 'Bearer wrong'));
        $this->assertFalse(Health::detailUnlocked(null, $token), 'the scheme word is part of the comparison');
        $this->assertFalse(Health::detailUnlocked(''), 'an empty token unlocks nothing');
        $this->assertFalse(Health::detailUnlocked(null, null));
    }

    public function testAnUnconfiguredTokenUnlocksNothing(): void
    {
        $original = Config::str('tokens.metrics');

        try {
            Config::set('tokens.metrics', '');

            $this->assertFalse(Health::detailUnlocked(''), 'no token configured, no detail');
            $this->assertFalse(Health::detailUnlocked('anything'));
            $this->assertFalse(Health::detailUnlocked(null, 'Bearer '), 'and not by guessing the empty one');
        } finally {
            Config::set('tokens.metrics', $original);
        }
    }
}
