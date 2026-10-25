<?php

declare(strict_types=1);

namespace Speedat\Tests\Core;

use Speedat\Core\Db;
use Speedat\Core\Paths;
use Speedat\Tests\TestCase;

/**
 * bin/test.php's own start-up check, which is the difference between a useful
 * error and an afternoon.
 *
 * THE FAILURE. Point the runner at a database that exists and is empty - a
 * freshly created one, a colleague's first checkout - and `SELECT 1` succeeds,
 * so Db::healthy() is true, so every test that asks decides to run. Each then
 * dies on its first query with "Table 'x.leads' doesn't exist". A hundred and
 * thirty-nine identical PDOExceptions go past, not one of them containing the
 * word migrate, and the reader's reasonable conclusion is that the suite is
 * broken. It is not. It has never been migrated.
 *
 * HOW THIS IS TESTED WITHOUT CREATING A DATABASE. `information_schema` is
 * reachable on every MySQL and MariaDB, and contains none of this application's
 * thirteen tables - which is exactly the shape being detected. So the child
 * runner is pointed at it. Nothing is created, nothing is dropped, and the
 * database this suite is using is not touched.
 */
final class TestRunnerTest extends TestCase
{
    public function setUp(): void
    {
        if (!function_exists('proc_open')) {
            $this->skip('proc_open is disabled in this PHP, so the runner cannot be run as a child');
        }
    }

    /**
     * A reachable database with no schema: one line, the word migrate in it, and
     * a non-zero exit BEFORE any test has run.
     */
    public function testAnUnmigratedDatabaseIsOneLineAndNotAHundredExceptions(): void
    {
        if (!Db::healthy()) {
            $this->skip('no database server is reachable, so an empty-but-reachable one cannot be demonstrated');
        }

        $result = $this->runRunner(['--database=information_schema', '--skip-js']);

        $this->assertTrue($result['code'] !== 0, 'an unmigrated database is a failure, not a pass');

        $said = $result['err'] . $result['out'];
        $this->assertStringContains('has not been migrated', $said);
        $this->assertStringContains('bin/migrate.php', $said, 'and it names the thing that fixes it');
        $this->assertStringContains('--database=information_schema', $said, 'with the database it means');

        // The whole point: it stopped instead of producing the pile.
        $this->assertStringNotContains('PDOException', $said);
        $this->assertStringNotContains("doesn't exist", $said);
        $this->assertStringNotContains('[OK]', $result['out'], 'no test ran');
        $this->assertStringNotContains('[ERR]', $result['out']);
    }

    /**
     * And it does not fire when there is nothing wrong. A start-up check that
     * refuses a healthy tree is worse than no check.
     */
    public function testAMigratedDatabaseRunsAsNormal(): void
    {
        if (!Db::healthy()) {
            $this->skip('the test database is not reachable, so the happy path cannot be demonstrated');
        }

        // One cheap suite that needs no database of its own, so this stays a
        // start-up test rather than a second full run.
        $result = $this->runRunner(['--filter=Base32Test', '--skip-js']);

        $this->assertSame(0, $result['code'], 'a migrated database runs: ' . trim($result['err']));
        $this->assertStringNotContains('has not been migrated', $result['err'] . $result['out']);
        $this->assertStringContains('[OK]', $result['out'], 'and tests actually ran');
    }

    /**
     * A database name that is not [A-Za-z0-9_] is refused before it can reach a
     * connection string, and says so rather than failing somewhere later.
     */
    public function testARefusedDatabaseNameIsItsOwnExitCode(): void
    {
        $result = $this->runRunner(['--database=speedat; DROP TABLE leads', '--skip-js']);

        $this->assertSame(2, $result['code']);
        $this->assertStringContains('Refusing a database name', $result['err']);
        $this->assertStringNotContains('[OK]', $result['out'], 'nothing ran');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  list<string> $arguments
     * @return array{code:int,out:string,err:string}
     */
    private function runRunner(array $arguments): array
    {
        $runner = Paths::root() . '/bin/test.php';

        $pipes   = [];
        $process = proc_open(
            array_merge([PHP_BINARY, $runner], $arguments),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            Paths::root()
        );

        if (!is_resource($process)) {
            $this->fail('could not start ' . $runner);
        }

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'out' => $out, 'err' => $err];
    }
}
