<?php

declare(strict_types=1);

namespace Speedat\Tests\Core;

use Speedat\Core\Paths;
use Speedat\Tests\TestCase;

/**
 * bin/build-release.php, run for real against a copy of the tree with canary
 * secrets planted in it.
 *
 * WHY A SUBPROCESS AND A COPIED TREE. The builder is a script, not a class: its
 * deny list runs at top level and there is nothing a unit test can call. It is
 * also the only place in this project where being wrong means uploading a
 * password to a web server, so the test that matters is the one that asks what
 * is really inside a finished archive - which is the same thing the builder's own
 * final check does, and the same method the audit used to find these two:
 *
 *   #7  The `config.php` / `*.local.php` guards were case-SENSITIVE on a
 *       case-sensitive host. `app/Core/Settings.LOCAL.php` - a file whose entire
 *       content was the production configuration, under a name the rule was
 *       written to catch - shipped in an archive the builder declared clean.
 *   #12 Any `.sql` under `db/migrations/` shipped, by directory. A dump named
 *       `9999_customer_dump.sql`, whose content was the config file, shipped in
 *       an archive both checkers called clean. That directory is exactly where a
 *       developer debugging a migration saves a dump, and a data dump of this
 *       site is the customer list.
 *
 * One copy of the tree is made and then mutated between builds, because copying
 * is the slow part and zipping is not.
 */
final class BuildReleaseTest extends TestCase
{
    /** Values planted in the fake config/config.php. Long enough to be recognisable, and never a real secret. */
    private const CANARY_KEY  = 'CANARY-APP-KEY-ZZZZZZZZZZZZZZZZZZZZZZZZ';
    private const CANARY_PASS = 'CANARY-DB-PASSWORD-ZZZZZZZZZZZZ';

    /**
     * Where the application sits in the archive: domains/<site>/speedat_main/php,
     * the folder public/index.php runs. Every application path below is written
     * through this, so an assertFalse on an entry cannot pass merely because the
     * entry now lives under a prefix the test forgot about.
     */
    private const APP = 'speedat_main/php/';

    private string $tree = '';

    private string $out = '';

    public function setUp(): void
    {
        if (!extension_loaded('zip')) {
            $this->skip('ext-zip is not loaded, so the builder cannot write an archive to inspect');
        }
        if (!function_exists('proc_open')) {
            $this->skip('proc_open is disabled in this PHP, so the builder cannot be run');
        }

        $this->tree = Paths::tmp() . '/release-test-' . bin2hex(random_bytes(4));
        $this->out  = $this->tree . '-out';

        $this->copyTree(Paths::root(), $this->tree);

        // A configuration file that is NOT the developer's. The builder requires
        // this file to extract the literals it compares shipped bytes against,
        // and a test that read the real one would depend on what is in it.
        $this->write($this->tree . '/config/config.php', $this->fakeConfig());
    }

    public function tearDown(): void
    {
        $this->removeTree($this->tree);
        $this->removeTree($this->out);
    }

    /**
     * The baseline, which is also finding 2's regression guard: the builder can
     * build the current tree at all. It could not - `0002_session_csrf_secret.sql`
     * matched the credential-name heuristic, so the archive was written, verified,
     * found wanting and deleted, and there was no deploy artefact.
     */
    public function testACleanTreeBuildsAndShipsTheRightThings(): void
    {
        $build = $this->build('clean.zip');

        $this->assertSame(0, $build['code'], 'the builder refused a clean tree: ' . $build['err']);

        $entries = $this->entriesOf($this->out . '/clean.zip');

        // What must be there.
        foreach ([
            'public_html/index.php', 'public_html/.htaccess', 'INSTALL.txt',
            self::APP . 'app/Core/Bootstrap.php',
            self::APP . 'app/Core/Config.php', self::APP . 'app/Core/Router.php', self::APP . 'app/routes.php',
            self::APP . 'config/config.sample.php', self::APP . 'db/migrations/0001_init.sql',
            self::APP . 'db/migrations/0002_session_csrf_secret.sql', self::APP . 'db/migrations/0003_page_views.sql',
            self::APP . 'bin/cron.php',
            // The bundled copy Paths::publicDir() reads.
            self::APP . 'public/index.php', self::APP . 'public/assets/img/icons.svg',
        ] as $wanted) {
            $this->assertTrue(in_array($wanted, $entries, true), $wanted . ' must be in the release');
        }

        // What must not - in this layout's spelling and in the old one's.
        foreach ([
            'config/config.php', self::APP . 'config/config.php',
            'public_html/router.php', self::APP . 'public/router.php',
            self::APP . 'bin/build-release.php', self::APP . 'bin/test.php',
        ] as $banned) {
            $this->assertFalse(in_array($banned, $entries, true), $banned . ' must not be in the release');
        }

        // THE LAYOUT. Nothing at the top but the web root, the application folder
        // and the install notes. The old archive put app/, config/ and the rest
        // here, beside public_html, where the live index.php never looks - every
        // code fix in that zip was uploaded and never ran (audit, 2026-10-23).
        $tops = [];
        foreach ($entries as $entry) {
            $tops[explode('/', $entry)[0]] = true;
        }
        $tops = array_keys($tops);
        sort($tops);
        $this->assertSame(['INSTALL.txt', 'public_html', 'speedat_main'], $tops, 'the archive top level');

        // THE UPGRADE CLEAN-UP. An extract over an old install deletes nothing,
        // and the migration night left customer-data dumps in var/ that no
        // release ever told the owner to remove. The section must name them,
        // and must say what is never to be deleted - a clean-up list without
        // its "never" half is how config.php gets deleted in File Manager.
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($this->out . '/clean.zip') === true, 'cannot reopen clean.zip');
        $install = (string) $zip->getFromName('INSTALL.txt');
        $zip->close();

        foreach ([
            'AFTER AN UPGRADE: CLEAR OUT WHAT THE OLD RELEASE LEFT BEHIND',
            'var/*.sql   var/*.sql.gz', 'var/*dump*.json', '*.zip anywhere in speedat_main/php',
            'public/app_old   db/app-old', 'storage/logs/',
            'NEVER DELETE:', 'config/config.php      The application key',
            'Any file named .htaccess or index.html',
        ] as $line) {
            $this->assertStringContains($line, $install, 'INSTALL.txt upgrade clean-up');
        }
        // Never the bare word: app/ ships Hold.php and HoldController.php.
        $this->assertSame(0, preg_match('/\*old\*/', $install), 'INSTALL.txt must not tell the owner to delete *old*');

        // app/Core is application code, all of it - so all of it ships. This is
        // the invariant that would have caught the case-folding mistake that
        // refused app/Core/Config.php and app/Core/Router.php as "configuration"
        // and "the dev-server router" while still reporting success.
        $onDisk = [];
        foreach (glob($this->tree . '/app/Core/*.php') ?: [] as $class) {
            $onDisk[] = self::APP . 'app/Core/' . basename($class);
        }
        $this->assertTrue($onDisk !== [], 'the copied tree has app/Core in it');
        foreach ($onDisk as $class) {
            $this->assertTrue(in_array($class, $entries, true), $class . ' is application code and must ship');
        }
    }

    /**
     * FINDING 7, the case-insensitivity, in the four spellings that got through.
     *
     * `Config.PHP` is the one the report could not demonstrate on this machine -
     * a case-insensitive filesystem will not let it coexist with `config.php` -
     * so it is planted in `bin/`, where nothing is called that and the rule has
     * the same job.
     */
    public function testAConfigFileUnderADifferentCapitalisationDoesNotShip(): void
    {
        $planted = [
            'app/Core/Settings.LOCAL.php' => 'the exact file the audit shipped',
            'app/Core/Settings.Local.PHP' => 'another spelling of it',
            'bin/Config.PHP'              => 'the spelling the report could not test locally',
            'bin/CONFIG.php'             => 'and the loud one',
        ];

        foreach (array_keys($planted) as $path) {
            // Harmless content: this test is about the NAME rule. The content
            // rule gets its own test below, and mixing them would let either one
            // passing hide the other failing.
            $this->write($this->tree . '/' . $path, "<?php\nreturn ['nothing' => 'here'];\n");
        }

        $build = $this->build('cased.zip');
        $this->assertSame(0, $build['code'], 'the builder should refuse the files, not the build: ' . $build['err']);

        $entries = $this->entriesOf($this->out . '/cased.zip');

        foreach ($planted as $path => $why) {
            $this->assertFalse(in_array(self::APP . $path, $entries, true), $path . ' shipped - ' . $why);
        }

        // And the class whose name the rule must NOT match is still there.
        $this->assertTrue(in_array(self::APP . 'app/Core/Config.php', $entries, true), 'app/Core/Config.php is a class, not a config file');
    }

    /**
     * FINDING 12: only migrations may ship out of db/migrations.
     *
     * TWO RULES, BECAUSE THE NAME RULE IS NOT ENOUGH. The audit's own file -
     * `9999_customer_dump.sql` - matches the numbered-migration pattern character
     * for character, so a name rule can never refuse it. The dumps below are
     * therefore planted under BOTH kinds of name: ones the pattern catches, and
     * one shaped exactly like a migration, which only the content rule can.
     */
    public function testOnlyMigrationsShipOutOfTheMigrationsDirectory(): void
    {
        // A dump that looks like a dump: banner plus rows, the way hPanel's
        // phpMyAdmin and mysqldump both write one.
        $dump = "-- phpMyAdmin SQL Dump\n-- version 5.2.1\n\n"
            . str_repeat("INSERT INTO `leads` VALUES (1,'Someone','+92300...');\n", 40);

        $this->write($this->tree . '/db/migrations/9999_customer_dump.sql', $dump);
        $this->write($this->tree . '/db/migrations/dump.sql', $dump);
        $this->write($this->tree . '/db/migrations/backup-2026-09-27.sql', $dump);
        $this->write($this->tree . '/db/migrations/0099_a_real_one.sql', "-- a genuine new migration\nALTER TABLE leads ADD COLUMN x INT NULL;\n");
        $this->write($this->tree . '/db/migrations/README.txt', "not a migration at all\n");

        $build = $this->build('migrations.zip');
        $this->assertSame(0, $build['code'], 'the dumps should be refused, not the build: ' . $build['err']);

        $all = $this->entriesOf($this->out . '/migrations.zip');

        $migrations = array_values(array_filter(
            $all,
            static fn (string $e): bool => str_starts_with($e, self::APP . 'db/migrations/') && str_ends_with($e, '.sql')
        ));
        sort($migrations);

        $this->assertSame(
            [
                self::APP . 'db/migrations/0001_init.sql',
                self::APP . 'db/migrations/0002_session_csrf_secret.sql',
                self::APP . 'db/migrations/0003_page_views.sql',
                self::APP . 'db/migrations/0099_a_real_one.sql',
            ],
            $migrations,
            'a data dump in db/migrations is still a data dump'
        );

        // Spelled out, because this is the one the report found in a release it
        // built and the one no name rule can refuse.
        $this->assertFalse(
            in_array(self::APP . 'db/migrations/9999_customer_dump.sql', $migrations, true),
            'a dump named like a migration must still be caught, by its contents'
        );

        $this->assertFalse(
            in_array(self::APP . 'db/migrations/README.txt', $all, true),
            'and nothing else in that directory ships either'
        );
    }

    /**
     * A migration that legitimately seeds a few rows must still ship: a content
     * rule that refuses real migrations has replaced a data leak with an outage.
     */
    public function testAMigrationThatSeedsAFewRowsStillShips(): void
    {
        $this->write(
            $this->tree . '/db/migrations/0004_seed_settings.sql',
            "-- three settings rows, which is what a seed migration looks like\n"
            . "INSERT INTO app_settings (k, v) VALUES ('a', '1');\n"
            . "INSERT INTO app_settings (k, v) VALUES ('b', '2');\n"
            . "INSERT INTO app_settings (k, v) VALUES ('c', '3');\n"
        );

        $build = $this->build('seeded.zip');
        $this->assertSame(0, $build['code'], $build['err']);

        $this->assertTrue(
            in_array(self::APP . 'db/migrations/0004_seed_settings.sql', $this->entriesOf($this->out . '/seeded.zip'), true),
            'a handful of INSERTs is a seed, not a dump'
        );
    }

    /**
     * THE CLASS OF BUG, not the instance: no shipped byte may contain a value
     * from config/config.php, whatever the file is called.
     *
     * A name rule caught `config/config.php` and missed
     * `app/Core/Settings.LOCAL.php`, and no name rule will ever catch
     * `app/Core/Helpers.php`. The build must FAIL here - not quietly skip the
     * file - because a tree in that state is a tree somebody needs to look at.
     */
    public function testAFileContainingTheLiveConfigurationFailsTheBuild(): void
    {
        $this->write($this->tree . '/app/Core/Helpers2.php', $this->fakeConfig());

        $build = $this->build('leak.zip');

        $this->assertNotSame(0, $build['code'], 'a shipped copy of the configuration must fail the build');
        $this->assertStringContains('Helpers2.php', $build['err'], 'and the message must name the file');
        $this->assertFalse(is_file($this->out . '/leak.zip'), 'the archive is deleted, not left lying around');

        // The canary itself must not be echoed into the build log: a build log is
        // a thing people paste into chat.
        $this->assertStringNotContains(self::CANARY_KEY, $build['err']);
        $this->assertStringNotContains(self::CANARY_KEY, $build['out']);
        $this->assertStringNotContains(self::CANARY_PASS, $build['err']);
        $this->assertStringNotContains(self::CANARY_PASS, $build['out']);
    }

    /**
     * FINDING 17's twin, on the builder's side: an editor dropping has no known
     * extension, so nothing refused it.
     */
    public function testEditorAndMergeDroppingsDoNotShip(): void
    {
        foreach ([
            'public/index.php~',
            'public/index.php.save',
            'app/routes.php.rej',
            'app/routes.php.orig',
            'public/Router.PHP',
        ] as $path) {
            $this->write($this->tree . '/' . $path, "<?php\n// left behind\n");
        }

        $build = $this->build('droppings.zip');
        $this->assertSame(0, $build['code'], $build['err']);

        $entries = $this->entriesOf($this->out . '/droppings.zip');

        foreach ([
            'public_html/index.php~',
            'public_html/index.php.save',
            self::APP . 'app/routes.php.rej',
            self::APP . 'app/routes.php.orig',
            'public_html/Router.PHP',
            // ... and none of them in the bundled copy of the web root either.
            self::APP . 'public/index.php~',
            self::APP . 'public/Router.PHP',
        ] as $banned) {
            $this->assertFalse(in_array($banned, $entries, true), $banned . ' would be served as source');
        }

        $this->assertTrue(in_array('public_html/index.php', $entries, true), 'the real front controller still ships');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Run the builder against the copied tree.
     *
     * @return array{code:int,out:string,err:string}
     */
    private function build(string $name): array
    {
        $pipes   = [];
        $process = proc_open(
            [PHP_BINARY, $this->tree . '/bin/build-release.php', '--out=' . $this->out, '--name=' . $name],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->tree
        );

        if (!is_resource($process)) {
            $this->fail('could not start the builder');
        }

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'out' => $out, 'err' => $err];
    }

    /** @return list<string> every entry name in a finished archive */
    private function entriesOf(string $zipPath): array
    {
        if (!is_file($zipPath)) {
            $this->fail('no archive at ' . $zipPath);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            $this->fail('cannot open ' . $zipPath);
        }

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat !== false) {
                $names[] = (string) $stat['name'];
            }
        }
        $zip->close();

        return $names;
    }

    /**
     * A config/config.php that is a real PHP array (the builder `require`s it)
     * with canary values where the secrets go.
     */
    private function fakeConfig(): string
    {
        return "<?php\n\nreturn [\n"
            . "    'app' => ['key' => '" . self::CANARY_KEY . "', 'url' => 'https://example.test'],\n"
            . "    'db'  => ['host' => 'localhost', 'name' => 'x', 'user' => 'x', 'pass' => '" . self::CANARY_PASS . "'],\n"
            . "];\n";
    }

    /**
     * Copy the tree, leaving out what the builder does not need and what would
     * make this test slow: five hundred flag SVGs, the git history, the tests,
     * and storage/ and var/, which the builder ships as empty directories anyway.
     */
    private function copyTree(string $from, string $to): void
    {
        $skip = ['.git', 'node_modules', 'tests', 'docs', 'tools', 'var', 'storage', 'flags'];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            $absolute = str_replace('\\', '/', $item->getPathname());
            $relative = ltrim(substr($absolute, strlen(str_replace('\\', '/', $from))), '/');

            foreach (explode('/', $relative) as $segment) {
                if (in_array($segment, $skip, true)) {
                    continue 2;
                }
            }

            if ($item->isDir()) {
                @mkdir($to . '/' . $relative, 0o775, true);
                continue;
            }
            if ($item->isFile()) {
                @mkdir(dirname($to . '/' . $relative), 0o775, true);
                @copy($absolute, $to . '/' . $relative);
            }
        }
    }

    private function write(string $path, string $contents): void
    {
        @mkdir(dirname($path), 0o775, true);

        if (@file_put_contents($path, $contents) === false) {
            $this->fail('could not write ' . $path);
        }
    }

    private function removeTree(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
