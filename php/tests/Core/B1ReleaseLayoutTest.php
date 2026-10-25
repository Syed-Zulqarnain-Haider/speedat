<?php

declare(strict_types=1);

namespace Speedat\Tests\Core;

use Speedat\Core\Paths;
use Speedat\Tests\TestCase;

/**
 * The release layout, end to end: what bin/build-release.php writes is what
 * deploy/verify-release.php accepts, and what both of them insist on is the
 * folder public/index.php actually runs.
 *
 * WHY THIS EXISTS. The live site runs from domains/<site>/speedat_main/php -
 * index.php looks there first and uses it whenever it exists. The release zip
 * put app/, config/ and the rest straight beside public_html instead, and both
 * checkers called that archive clean. Extracting it replaced public_html and
 * left the old application running under new CSS and JS; a cron pointed at the
 * documented domains/<site>/bin/cron.php booted a tree with no config; and a
 * rollback by extracting an older zip never touched the running code at all
 * (audit, 2026-10-23). Nothing failed loudly, because every file was the right
 * file - in a folder nobody reads.
 *
 * So three things are pinned here, each against a real built archive:
 *   1. the front controller's first choice is the folder the archive fills;
 *   2. the verifier passes the archive the builder makes;
 *   3. the verifier REFUSES the old shape - an app/ beside public_html.
 */
final class B1ReleaseLayoutTest extends TestCase
{
    private string $tree = '';

    private string $out = '';

    public function setUp(): void
    {
        if (!extension_loaded('zip')) {
            $this->skip('ext-zip is not loaded, so no archive can be written or inspected');
        }
        if (!function_exists('proc_open')) {
            $this->skip('proc_open is disabled in this PHP, so the builder cannot be run');
        }

        $this->tree = Paths::tmp() . '/release-layout-' . bin2hex(random_bytes(4));
        $this->out  = $this->tree . '-out';

        $this->copyTree(Paths::root(), $this->tree);

        // Not the developer's configuration: the builder compares every shipped
        // byte against the secrets in this file, and a test that read the real
        // one would depend on what is in it.
        $this->write(
            $this->tree . '/config/config.php',
            "<?php\n\nreturn ['app' => ['key' => 'B1-CANARY-KEY-ZZZZZZZZZZZZZZZZZZ']];\n"
        );
    }

    public function tearDown(): void
    {
        $this->removeTree($this->tree);
        $this->removeTree($this->out);
    }

    /**
     * The constant the builder fills and the folder the front controller reads
     * are the same string. Two files, one fact: if either moves, this fails
     * before an upload does.
     */
    public function testTheArchivePrefixIsTheFolderIndexPhpRuns(): void
    {
        $builder = (string) file_get_contents(Paths::root() . '/bin/build-release.php');
        $front   = (string) file_get_contents(Paths::publicDir() . '/index.php');

        $this->assertSame(1, preg_match("/const APP_PREFIX = '([^']+)';/", $builder, $m), 'the builder names its prefix');
        $this->assertStringContains(
            "dirname(__DIR__) . '/" . $m[1] . "'",
            $front,
            'public/index.php no longer looks first in the folder the release fills'
        );
    }

    public function testTheVerifierPassesWhatTheBuilderBuilds(): void
    {
        $zip = $this->buildRelease('layout.zip');

        $verify = $this->run([PHP_BINARY, $this->tree . '/deploy/verify-release.php', $zip]);

        $this->assertSame(0, $verify['code'], 'the verifier refused a fresh release: ' . $verify['out']);
        $this->assertStringContains('The archive is safe to upload.', $verify['out']);
    }

    /**
     * The old shape, rebuilt by hand: the same release with application code
     * also sitting straight beside public_html. Every file in it is a good file,
     * which is exactly why it must fail - that tree is the one nothing runs.
     */
    public function testTheVerifierRefusesAnAppBesidePublicHtml(): void
    {
        $zipPath = $this->buildRelease('old-shape.zip');

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true, 'the built archive opens');
        $zip->addFromString('app/routes.php', "<?php\n// the copy nothing runs\n");
        $zip->close();

        $verify = $this->run([PHP_BINARY, $this->tree . '/deploy/verify-release.php', $zipPath]);

        $this->assertNotSame(0, $verify['code'], 'an app/ beside public_html must not be "safe to upload"');
        $this->assertStringContains('the top level is public_html, speedat_main and INSTALL.txt', $verify['out']);
    }

    // ---------------------------------------------------------------- helpers

    private function buildRelease(string $name): string
    {
        // --skip-tests is not needed: bin/test.php sets SPEEDAT_TEST_SUITE=1,
        // which the builder honours by not starting a second suite.
        $build = $this->run([PHP_BINARY, $this->tree . '/bin/build-release.php', '--out=' . $this->out, '--name=' . $name]);
        $this->assertSame(0, $build['code'], 'the builder refused a clean tree: ' . $build['err']);

        $path = $this->out . '/' . $name;
        $this->assertTrue(is_file($path), 'the builder wrote ' . $path);

        return $path;
    }

    /**
     * @param list<string> $command
     * @return array{code:int,out:string,err:string}
     */
    private function run(array $command): array
    {
        $pipes   = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->tree);

        if (!is_resource($process)) {
            $this->fail('could not start ' . $command[1]);
        }

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'out' => $out, 'err' => $err];
    }

    /** The same trimmed copy BuildReleaseTest makes, for the same reason: speed. */
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
