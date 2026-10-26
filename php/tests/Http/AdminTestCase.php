<?php

declare(strict_types=1);

namespace Speedat\Tests\Http;

use Speedat\Core\Config;
use Speedat\Core\Cookies;
use Speedat\Core\Csrf;
use Speedat\Core\Db;
use Speedat\Core\Paths;
use Speedat\Core\Request;
use Speedat\Core\Session;
use Speedat\Domain\Auth\SecretBox;
use Speedat\Domain\Auth\Totp;
use Speedat\Domain\SiteDoc\Live;
use Speedat\Domain\SiteDoc\PublishArgs;
use Speedat\Domain\SiteDoc\Repo;
use Speedat\Domain\SiteDoc\Seed;
use Speedat\Http\Admin\Screen;

/**
 * The ground every admin route test stands on: a migrated database with one
 * published version, one admin, and a real session cookie.
 *
 * NOTHING IS STUBBED HERE EITHER. These tests go through `app/routes.php`, the
 * real `Guard`, the real templates and the real repositories, because every bug
 * this suite exists to catch lives in the wiring: a route registered without the
 * guard, a form whose token is never checked, a publish an editor can reach, an
 * audit line nobody writes. A test with a fake session in it cannot see any of
 * them.
 *
 * IT OWNS THE TABLES IT EMPTIES. The runner points at `speedat_php_test` (see
 * bin/test.php), and each test starts from an empty `admins`, `sessions`,
 * `draft`, `versions`, `leads`, `shipments`, `audit_log` and `rate_limits` - the
 * last because a login test that spends the limiter would otherwise fail the next
 * one. The live-document cache is pointed at a scratch directory, so busting it
 * never touches what a developer's browser is being served.
 */
abstract class AdminTestCase extends HttpTestCase
{
    protected const OWNER  = 'boss@speedat.test';
    protected const EDITOR = 'office@speedat.test';

    /** Long enough for the policy, and nowhere near any real password. */
    protected const PASSWORD = 'correct-horse-battery-staple-9';

    private string $cacheDir = '';

    /** The session cookie of whoever signIn() last signed in. */
    protected string $sid = '';

    public function setUp(): void
    {
        parent::setUp();

        $this->needDatabase();

        $this->cacheDir = Paths::tmp() . '/admin-http-' . bin2hex(random_bytes(4));
        Config::set('paths.cache', $this->cacheDir);
        Paths::ensureWritable($this->cacheDir . '/site');

        Screen::forget();
        Live::forget();

        $this->empty();
        $this->publishSeed();
    }

    public function tearDown(): void
    {
        parent::tearDown();

        Screen::forget();

        // AND IT LEAVES THEM EMPTY. Emptying in setUp() alone is not enough:
        // the LAST admin test of the run leaves its published seed behind, and
        // that row is a whole site document with a `destinations` array in it.
        // `Shipments\NameLookup` answers "what was this country called?" by
        // searching EVERY row of `versions`, so a forgotten seed makes it name
        // a country whose own fixture had just been deleted - a failure in a
        // suite that passes on its own and fails only after this one ran. A
        // suite that fills shared tables empties them again.
        if (Db::healthy()) {
            $this->empty();
        }

        foreach (glob($this->cacheDir . '/site/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->cacheDir . '/site');
        @rmdir($this->cacheDir);

        $this->cacheDir = '';
        $this->sid      = '';
    }

    protected function needDatabase(): void
    {
        if (!Db::healthy()) {
            $this->skip(
                'the test database is not reachable - start MariaDB (bin/local/start-db.ps1) '
                . 'and run bin/migrate.php --database=speedat_php_test'
            );
        }
    }

    /* ------------------------------------------------------------- fixtures */

    /** Everything these tests write, gone. */
    protected function empty(): void
    {
        foreach (['sessions', 'admins', 'draft', 'versions', 'leads', 'shipment_events', 'shipments', 'audit_log', 'rate_limits'] as $table) {
            Db::exec('DELETE FROM ' . $table);
        }

        Db::exec('DELETE FROM app_settings WHERE k IN (?, ?) OR k LIKE ?', ['hold', 'draft_rebase', 'plogin:%']);

        Live::forget();
    }

    /** Version 1: the sample document, exactly as bin/seed.php publishes it. */
    protected function publishSeed(): void
    {
        Repo::publishVersion(new PublishArgs(
            data: Seed::gridSite(),
            by: 'seed',
            source: 'seed',
            summary: 'Sample rates',
            changeCount: 0,
        ));

        Live::forget();
    }

    /**
     * Add an admin.
     *
     * @param string|null $password null for an account with no password at all
     * @param string      $secret   a base32 TOTP secret to enrol, or "" for none
     */
    protected function admin(
        string $email = self::OWNER,
        string $role = 'owner',
        ?string $password = self::PASSWORD,
        string $secret = '',
    ): void {
        $row = [
            'email'      => $email,
            'name'       => 'Test ' . $role,
            'role'       => $role,
            'added_by'   => 'test',
            'created_at' => '2026-08-01 10:00:00',
        ];

        if ($password !== null) {
            $row['password_hash'] = password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);
        }

        if ($secret !== '') {
            $row['totp_secret_enc'] = (new SecretBox(Config::need('app.key')))->encrypt($secret, $email);
            $row['totp_enabled']    = 1;
        }

        Db::insert('admins', $row);
    }

    /** A TOTP secret and the code showing on the phone right now. */
    protected function totp(): Totp
    {
        return new Totp();
    }

    /**
     * Sign in the way the routes do - by starting a real session - and keep the
     * cookie. The admin row has to exist already: the guard asks the table on
     * every request, which is the behaviour being tested.
     */
    protected function signIn(string $email = self::OWNER): string
    {
        $cookies = new Cookies(false);
        $session = new Session(Request::synthetic('GET', '/admin'), $cookies);
        $session->start($email);

        $sid = $cookies->queuedValue(Session::COOKIE);

        $this->assertTrue(is_string($sid) && $sid !== '', 'the session cookie was not issued');

        return $this->sid = (string) $sid;
    }

    /** The cookies a signed-in request carries. @return array<string,string> */
    protected function cookies(): array
    {
        return $this->sid === '' ? [] : [Session::COOKIE => $this->sid];
    }

    /**
     * A CSRF token valid for the current session.
     *
     * Built from the same session row the request will read, so this is the
     * token a rendered page would have carried - not a bypass: an empty or wrong
     * one is refused with 419, and the tests check that too.
     */
    protected function token(array $cookies = []): string
    {
        $cookies = $cookies === [] ? $this->cookies() : $cookies;

        return (new Csrf(new Session(
            Request::synthetic('GET', '/admin', [], [], [], $cookies),
            new Cookies(false),
        )))->token();
    }

    /* -------------------------------------------------------------- requests */

    /** @param array<string,mixed> $query */
    protected function adminGet(string $path, array $query = []): Answer
    {
        return $this->get($path, $query, [], $this->cookies());
    }

    /**
     * A signed-in POST with its token.
     *
     * @param array<string,mixed>  $post
     * @param array<string,string> $headers
     */
    protected function adminPost(string $path, array $post = [], array $headers = []): Answer
    {
        $cookies = $this->cookies();

        return $this->post($path, $post + [Csrf::FIELD => $this->token($cookies)], $headers, $cookies);
    }

    /* ------------------------------------------------------------ assertions */

    /** The Location header of a redirect. */
    protected function location(Answer $answer): string
    {
        return $answer->header('Location');
    }

    protected function assertRedirects(string $expectedPrefix, Answer $answer, string $where = ''): void
    {
        $label = $where !== '' ? $where . ': ' : '';

        $this->assertTrue(
            $answer->response->status >= 300 && $answer->response->status < 400,
            $label . 'expected a redirect, got ' . $answer->response->status,
        );
        $this->assertStringContains($expectedPrefix, $this->location($answer), $label . 'redirect target');
    }

    /** How many audit rows carry this action. */
    protected function audited(string $action): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM audit_log WHERE action = ?', [$action], 0);
    }

    /** The newest audit row for an action, decoded. @return array<string,mixed> */
    protected function auditDetail(string $action): array
    {
        $row = Db::one('SELECT detail FROM audit_log WHERE action = ? ORDER BY id DESC LIMIT 1', [$action]);

        return $row === null ? [] : Db::unjson($row['detail'] ?? null);
    }

    /** Is anybody signed in with this cookie? Asked of the database, not the page. */
    protected function sessionExists(string $sid): bool
    {
        return Db::value('SELECT 1 FROM sessions WHERE id = ?', [hash('sha256', $sid)]) !== null;
    }

    /** The live document's number. */
    protected function liveVersion(): int
    {
        return (int) Db::value('SELECT MAX(version) FROM versions', [], 0);
    }
}
