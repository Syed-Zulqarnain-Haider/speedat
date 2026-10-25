<?php

declare(strict_types=1);

namespace Speedat\Tests\Core;

use Speedat\Core\Paths;
use Speedat\Core\Upload;
use Speedat\Tests\TestCase;

/**
 * public/.user.ini, LimitRequestBody, and the release builder's test gate.
 *
 * WHY A SECOND FILE BESIDE HtaccessTest. That file's subject is "what the deny
 * rules in public/.htaccess say". This one's subject is "the request-size
 * limits reach the host at all", which is a different claim with a different
 * failure mode: not a rule that denies the wrong thing, but a rule nobody ever
 * reads.
 *
 * THE FAILURE THIS EXISTS TO HOLD. `php_value post_max_size 3M` in .htaccess
 * only applies under mod_php. Hostinger runs LiteSpeed, which ignores the whole
 * <IfModule mod_php.c> block - silently, with no error and no warning, so the
 * file reads in review as a site with a 3 MB body limit while the live site has
 * whatever the plan's default is. The cure is .user.ini, which the CGI/FastCGI
 * SAPI does read, and the new failure mode the cure creates is the two files
 * drifting apart: a host then enforces whichever it happened to read, which is
 * a limit nobody chose. Everything below is about keeping that pair honest.
 *
 * Nothing here proves what the host does. `php -S` reads neither file. What can
 * be proved off the server is that the numbers agree, that they are in the only
 * order that works, and that the release actually carries them.
 */
final class UserIniTest extends TestCase
{
    /** The directives the site depends on, and which every copy must state. */
    private const REQUIRED = ['upload_max_filesize', 'post_max_size', 'max_input_vars'];

    /**
     * PHP_INI_SYSTEM directives. These are not errors in a .user.ini - they are
     * IGNORED, which is worse: the file reads as though the setting was made.
     *
     * (max_file_uploads has been PHP_INI_SYSTEM since 5.3.4; expose_php,
     * open_basedir, disable_functions and allow_url_fopen have always been.)
     */
    private const IGNORED_IN_USER_INI = [
        'expose_php', 'max_file_uploads', 'open_basedir', 'disable_functions', 'allow_url_fopen',
    ];

    public function testTheWebRootShipsAUserIniAtAll(): void
    {
        $this->assertTrue(
            is_file($this->userIniPath()),
            'public/.user.ini is missing. On LiteSpeed it is the ONLY file-based way to set '
            . 'post_max_size: the php_value block in .htaccess is ignored without a word.'
        );
    }

    public function testTheUserIniStatesEveryDirectiveTheSiteDependsOn(): void
    {
        $values = $this->iniValues($this->userIniText());

        foreach (self::REQUIRED as $directive) {
            $this->assertTrue(
                isset($values[$directive]),
                'public/.user.ini does not set ' . $directive
                . ', so the host uses its own default and nobody chose it'
            );
        }
    }

    /**
     * The same numbers in every file that carries them.
     *
     * A host reads .htaccess or .user.ini and never announces which. If they
     * disagree, the site's real limit is an accident of the host's SAPI.
     */
    public function testEveryCopyOfTheLimitsCarriesTheSameNumbers(): void
    {
        $sources = $this->allLimitSources();

        foreach (self::REQUIRED as $directive) {
            /** @var array<string,string> $seen  value => the files that said it */
            $seen = [];

            foreach ($sources as $label => $text) {
                foreach ($this->valuesFor($text, $directive) as $value) {
                    $seen[strtolower($value)] = ($seen[strtolower($value)] ?? '') . ' ' . $label;
                }
            }

            if ($seen === []) {
                continue;       // nobody sets it here; the presence test covers that
            }

            $this->assertSame(
                1,
                count($seen),
                $directive . ' is set to more than one value across the shipped files: '
                . implode(' | ', array_map(
                    static fn (string $v, string $where): string => $v . ' in' . $where,
                    array_keys($seen),
                    array_values($seen)
                ))
            );
        }
    }

    /**
     * post_max_size above upload_max_filesize, in the .user.ini too.
     *
     * HtaccessTest holds this for the .htaccess pair and the sample; the file
     * that actually applies on the host was not in that list until it existed.
     */
    public function testTheUserIniKeepsPostMaxSizeAboveUploadMaxFilesize(): void
    {
        $values = $this->iniValues($this->userIniText());

        $upload = Upload::bytes($values['upload_max_filesize'] ?? '0');
        $post   = Upload::bytes($values['post_max_size'] ?? '0');

        $this->assertTrue(
            $post - $upload >= 512 * 1024,
            'public/.user.ini: post_max_size (' . ($values['post_max_size'] ?? '-') . ') must clear '
            . 'upload_max_filesize (' . ($values['upload_max_filesize'] ?? '-') . ') by at least half a '
            . 'megabyte, or a body at exactly the file limit is discarded whole - empty $_POST, empty '
            . '$_FILES, and a form that appears to have done nothing'
        );
    }

    /**
     * Nothing in .user.ini that PHP will ignore.
     *
     * A PHP_INI_SYSTEM directive here is not an error. It is silently dropped,
     * and the line stays in the file looking like a setting that was made -
     * which is the same class of lie as php_value under LiteSpeed, and the
     * whole reason this file exists.
     */
    public function testTheUserIniSetsNothingPhpWillIgnore(): void
    {
        $values = $this->iniValues($this->userIniText());

        foreach (self::IGNORED_IN_USER_INI as $directive) {
            $this->assertFalse(
                isset($values[$directive]),
                'public/.user.ini sets ' . $directive . ', which is PHP_INI_SYSTEM: PHP ignores it from '
                . 'a .user.ini and the line reads as a setting that was made. Set it in hPanel, or not at all.'
            );
        }
    }

    /**
     * LimitRequestBody, in both .htaccess copies, wrapped, and under
     * post_max_size.
     *
     * UNDER, not over. The point of the web-server cap is that it refuses the
     * body before a PHP worker is engaged; if it were the larger of the two,
     * the cheap refusal would never fire and PHP's would do all the work.
     */
    public function testBothHtaccessCopiesCapTheRequestBodyBeforePhpIsReached(): void
    {
        foreach ($this->htaccessSources() as $label => $text) {
            preg_match_all('/^\s*LimitRequestBody\s+(\d+)/mi', $text, $found);

            $this->assertTrue(
                $found[1] !== [],
                $label . ' sets no LimitRequestBody, so a 50 MB POST is read and buffered by a PHP '
                . 'worker before anything decides to refuse it'
            );

            foreach ($found[1] as $bytes) {
                $this->assertTrue(
                    (int) $bytes > 64 * 1024,
                    $label . ': LimitRequestBody ' . $bytes . ' is below 64 KiB, which is smaller than '
                    . 'the rates editor\'s own form - it would 413 the admin'
                );

                foreach ($this->valuesFor($text, 'post_max_size') as $post) {
                    $this->assertTrue(
                        (int) $bytes <= Upload::bytes($post),
                        $label . ': LimitRequestBody (' . $bytes . ' bytes) is above post_max_size ('
                        . $post . '), so the cheap refusal never fires and PHP does the work anyway'
                    );
                }
            }
        }
    }

    /**
     * And it is wrapped. An unsupported directive in .htaccess is a hard 500,
     * and a 500 in the web root is the whole site - which is exactly why
     * public/.htaccess says at the top that every directive sits inside an
     * <IfModule>. LimitRequestBody is core Apache, but "core Apache" is a claim
     * about Apache and not about LiteSpeed's .htaccess subset.
     */
    public function testLimitRequestBodyIsWrappedSoAnUnsupportedDirectiveCannotTakeTheSiteDown(): void
    {
        foreach ($this->htaccessSources() as $label => $text) {
            $depth = 0;

            foreach (preg_split('/\R/', $text) ?: [] as $raw) {
                $line = trim($raw);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                if (preg_match('/^<IfModule/i', $line) === 1) {
                    $depth++;
                    continue;
                }
                if (preg_match('#^</IfModule>#i', $line) === 1) {
                    $depth--;
                    continue;
                }
                if (preg_match('/^LimitRequestBody\b/i', $line) === 1) {
                    $this->assertTrue(
                        $depth > 0,
                        $label . ' sets LimitRequestBody outside every <IfModule>. On a host that does '
                        . 'not accept it that is a 500 on every page, not a missing limit.'
                    );
                }
            }
        }
    }

    /**
     * The release carries the .user.ini.
     *
     * The builder's own required-entry list is the mechanism; this asserts the
     * entry is named there, so deleting it from that list fails a test rather
     * than quietly shipping a site with the host's default body limits.
     */
    public function testTheReleaseBuilderRequiresTheUserIniInTheWebRoot(): void
    {
        $builder = $this->builderText();

        $this->assertStringContains(
            "'public_html/.user.ini'",
            $builder,
            'bin/build-release.php no longer requires public_html/.user.ini, so a release missing it '
            . 'would build clean and install with the host\'s own post_max_size'
        );

        $this->assertStringContains(
            "'.user.ini'",
            $builder,
            'ALLOWED_DOTFILES no longer names .user.ini, so the collector refuses it as a dotfile'
        );
    }

    /**
     * The builder runs the suite before it packs anything.
     *
     * A rehearsal shipped a release whose views read a removed property: every
     * file parsed, every entry matched its source, every deny-list check passed,
     * and the installed home page answered 500. None of this script's checks
     * runs a line of the application, so none of them could have caught it.
     */
    public function testTheReleaseBuilderRefusesToPackAFailingTree(): void
    {
        $builder = $this->builderText();

        $this->assertStringContains(
            'bin/test.php',
            $builder,
            'bin/build-release.php does not run the test suite, so a red tree still produces a zip'
        );
        $this->assertStringContains(
            'skip-tests',
            $builder,
            'there is no explicit opt-out, so a machine with no test database cannot cut a release at all'
        );
        $this->assertStringContains(
            'TESTS NOT RUN',
            $builder,
            'an archive built with --skip-tests is not stamped, so nothing tells you tomorrow that it '
            . 'was cut from an unproven tree'
        );

        // The gate has to come BEFORE the collector. A build that packs for
        // forty seconds and then reports a red suite has already written a zip
        // somebody may find in var/ later.
        $gateAt    = strpos($builder, 'passthru(');
        $collectAt = strpos($builder, "addTree(\$root . '/public'");

        $this->assertTrue($gateAt !== false, 'the builder does not run the suite in a child process');
        $this->assertTrue($collectAt !== false, 'the builder no longer collects public/ - has it been rewritten?');
        $this->assertTrue(
            $gateAt < $collectAt,
            'the test gate runs after the collector, so a failing tree is packed first and refused second'
        );
    }

    // ---------------------------------------------------------------- helpers

    private function userIniPath(): string
    {
        return Paths::publicDir() . '/.user.ini';
    }

    private function userIniText(): string
    {
        $text = @file_get_contents($this->userIniPath());
        if ($text === false) {
            $this->fail('public/.user.ini is missing');
        }

        return $text;
    }

    private function builderText(): string
    {
        $text = @file_get_contents(dirname(Paths::publicDir()) . '/bin/build-release.php');
        if ($text === false) {
            $this->fail('bin/build-release.php is missing');
        }

        return $text;
    }

    /**
     * Every file that states a PHP body limit, by label.
     *
     * @return array<string,string>
     */
    private function allLimitSources(): array
    {
        $root    = dirname(Paths::publicDir());
        $sources = $this->htaccessSources();

        foreach ([
            'public/.user.ini'                  => $this->userIniPath(),
            'deploy/htaccess/user.ini.sample'   => $root . '/deploy/htaccess/user.ini.sample',
        ] as $label => $path) {
            $text = @file_get_contents($path);
            if ($text === false) {
                $this->fail($label . ' is missing');
            }
            // Commented guidance is documentation, not a setting: a sentence
            // explaining why the value used to be 8M must not be read as 8M.
            $sources[$label] = preg_replace('/^\s*;.*$/m', '', $text) ?? $text;
        }

        return $sources;
    }

    /** @return array<string,string> */
    private function htaccessSources(): array
    {
        $root  = dirname(Paths::publicDir());
        $out   = [];

        foreach ([
            'public/.htaccess'                      => Paths::publicDir() . '/.htaccess',
            'deploy/htaccess/public_html.htaccess'  => $root . '/deploy/htaccess/public_html.htaccess',
        ] as $label => $path) {
            $text = @file_get_contents($path);
            if ($text === false) {
                $this->fail($label . ' is missing');
            }
            // Drop # comments for the same reason: this file explains its own
            // old values at length, and none of those sentences is a setting.
            $out[$label] = preg_replace('/^\s*#.*$/m', '', $text) ?? $text;
        }

        return $out;
    }

    /**
     * Every value a text gives a directive, in either dialect
     * (`php_value name 3M` or `name = 3M`).
     *
     * @return list<string>
     */
    private function valuesFor(string $text, string $directive): array
    {
        $pattern = '/(?:php_value\s+|^\s*)' . preg_quote($directive, '/') . '\s*(?:=\s*)?([A-Za-z0-9_]+)/mi';
        preg_match_all($pattern, $text, $found);

        return array_values(array_unique($found[1]));
    }

    /**
     * The .user.ini as directive => value, comments dropped.
     *
     * @return array<string,string>
     */
    private function iniValues(string $text): array
    {
        $out = [];

        foreach (preg_split('/\R/', $text) ?: [] as $raw) {
            $line = trim($raw);
            if ($line === '' || $line[0] === ';') {
                continue;
            }
            if (preg_match('/^([A-Za-z0-9_.]+)\s*=\s*(\S+)/', $line, $m) === 1) {
                $out[strtolower($m[1])] = $m[2];
            }
        }

        return $out;
    }
}
