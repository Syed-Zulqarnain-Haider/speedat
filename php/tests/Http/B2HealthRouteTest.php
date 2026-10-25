<?php

declare(strict_types=1);

namespace Speedat\Tests\Http;

use Speedat\Core\Config;
use Speedat\Core\Db;

/**
 * `/health` carries the real check's status code.
 *
 * It used to answer 200 `{"ok":true}` without asking anything, and the detailed
 * form answered 200 beside `"database":"down"` - so with MariaDB refusing
 * connections the go-live uptime monitor stayed green while quotes, leads and
 * sign-in all failed (found 2026-10-25). The route now goes through
 * Domain\Ops\Health, which does a real round-trip and answers 503 when the
 * site cannot do its job.
 */
final class B2HealthRouteTest extends HttpTestCase
{
    private const TOKEN = 'b2-health-metrics-token-5d1e8a';

    private string $savedMetrics = '';

    private string $savedDbHost = '';

    private int $savedDbPort = 0;

    public function setUp(): void
    {
        parent::setUp();

        if (!Db::healthy()) {
            $this->skip('the test database is not reachable, so "up" cannot be told from "down"');
        }

        $this->savedMetrics = Config::str('tokens.metrics');
        $this->savedDbHost  = Config::str('db.host');
        $this->savedDbPort  = Config::int('db.port', 3306);

        Config::set('tokens.metrics', self::TOKEN);
    }

    public function tearDown(): void
    {
        Config::set('tokens.metrics', $this->savedMetrics);

        // The outage test points the connection at a closed port; every test
        // after this one needs it back.
        Config::set('db.host', $this->savedDbHost);
        Config::set('db.port', $this->savedDbPort);
        Db::reset();

        parent::tearDown();
    }

    public function testAHealthySiteAnswers200AndOneBoolean(): void
    {
        $answer = $this->get('/health');

        $this->assertStatus(200, $answer);
        $this->assertContentType('application/json; charset=utf-8', $answer);
        $this->assertSame(['ok' => true], $answer->json(), 'one boolean and nothing else');
        $this->assertStringContains('no-store', $answer->header('Cache-Control'), 'a cached health check is not one');
    }

    public function testADatabaseThatIsDownAnswers503EvenWithoutTheToken(): void
    {
        $this->goDown();

        $answer = $this->get('/health');

        $this->assertStatus(503, $answer, 'an uptime monitor that only calls the public form must go red');
        $this->assertSame(['ok' => false], $answer->json(), 'and still names no component');
    }

    public function testTheDetailCarriesTheSameStatus(): void
    {
        $up = $this->get('/health', [], ['authorization' => 'Bearer ' . self::TOKEN]);

        $this->assertStatus(200, $up);
        foreach (['ok', 'php', 'env', 'database', 'time'] as $key) {
            $this->assertTrue(array_key_exists($key, $up->json()), 'the documented detail has "' . $key . '"');
        }
        $this->assertSame('up', $up->json()['database'] ?? null);

        $this->goDown();

        $down = $this->get('/health', [], ['authorization' => 'Bearer ' . self::TOKEN]);

        $this->assertStatus(503, $down, 'a 200 beside "database":"down" is the bug this replaced');
        $this->assertSame('down', $down->json()['database'] ?? null);
        $this->assertSame(false, $down->json()['ok'] ?? null);
    }

    public function testAQueryTokenStillGetsOnlyTheThinAnswer(): void
    {
        $answer = $this->get('/health', ['token' => self::TOKEN]);

        $this->assertStatus(200, $answer);
        $this->assertSame(['ok' => true], $answer->json(), 'the detail takes Authorization: Bearer only');
    }

    /** Port 1 on loopback: refused at once, a real connection failure rather than a timeout. */
    private function goDown(): void
    {
        Config::set('db.host', '127.0.0.1');
        Config::set('db.port', 1);
        Db::reset();
    }
}
