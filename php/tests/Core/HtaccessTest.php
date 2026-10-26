<?php

declare(strict_types=1);

namespace Speedat\Tests\Core;

use Speedat\Core\Paths;
use Speedat\Core\Upload;
use Speedat\Tests\TestCase;

/**
 * public/.htaccess, checked as far as it can be checked off the server.
 *
 * `php -S` ignores .htaccess entirely, so nothing here proves what Apache or
 * LiteSpeed will do; README.md carries the `curl -I` list that does, and it has
 * to be run on the host. What CAN be proved locally is the part that was
 * actually wrong:
 *
 *   1. Every deny was written for mod_authz_core only. On a host that
 *      authorises through mod_access_compat, `Require all denied` parses and
 *      denies NOTHING - a silent no-op, and a silent no-op in a security rule
 *      is worse than no rule, because it reads as protection in review.
 *   2. Belt and braces: with BOTH modules absent neither block applies, so the
 *      only thing standing between the internet and a .sql file is that there
 *      is no .sql file in the web root. That is a property of the tree, and a
 *      test can hold it.
 *   3. post_max_size was equal to upload_max_filesize, which silently
 *      discards an upload at exactly the documented limit.
 */
final class HtaccessTest extends TestCase
{
    /** Extensions and names that must never be reachable over HTTP. */
    private const SENSITIVE = [
        '/\.sql$/i', '/\.sqlite3?$/i', '/\.log$/i', '/\.ini$/i', '/\.ya?ml$/i', '/\.lock$/i',
        '/\.sh$/i', '/\.ps1$/i', '/\.bak$/i', '/\.pem$/i', '/\.key$/i', '/\.env/i',
        '/^\.env/i', '/^config\.php$/i', '/^config\..*json$/i', '/^secrets/i', '/^credentials/i',
        '/^service-account/i', '/^composer\./i', '/^package\.json$/i',
    ];

    public function testEveryDirectiveIsInsideAnIfModuleSoAMissingModuleIsNotA500(): void
    {
        $depth   = 0;
        $inIf    = 0;
        $strays  = [];

        foreach ($this->lines() as $number => $line) {
            if (preg_match('/^<IfModule/i', $line) === 1) {
                $depth++;
                $inIf++;
                continue;
            }
            if (preg_match('#^</IfModule>#i', $line) === 1) {
                $inIf--;
                continue;
            }

            // The directives that are core Apache and safe unguarded. Anything
            // else outside an <IfModule> is a hard 500 on a host without the
            // module that provides it.
            if (preg_match('/^(Options|DirectoryIndex|AddDefaultCharset)\b/i', $line) === 1) {
                continue;
            }

            if ($inIf === 0) {
                $strays[] = $number . ': ' . $line;
            }
        }

        $this->assertSame([], $strays, 'unguarded directives: ' . implode(' | ', $strays));
        $this->assertSame(0, $inIf, 'every <IfModule> is closed');
        $this->assertTrue($depth >= 6, 'the file still has its blocks: ' . $depth);
    }

    public function testEveryDenyIsWrittenInBothApacheDialects(): void
    {
        $text = $this->text();

        // The 2.4 block.
        $this->assertStringContains('<IfModule mod_authz_core.c>', $text);
        $this->assertTrue(substr_count($text, 'Require all denied') >= 5, 'five things are denied');

        // The 2.2 fallback, which is what was missing.
        $this->assertStringContains('<IfModule !mod_authz_core.c>', $text);
        $this->assertStringContains('mod_access_compat.c', $text, 'the fallback is itself guarded, or it is a 500');
        $this->assertTrue(substr_count($text, 'Deny from all') >= 5, 'and denied again the old way');
        $this->assertSame(
            substr_count($text, 'Deny from all'),
            substr_count($text, 'Order allow,deny'),
            'every Deny has its Order'
        );

        // The same patterns in both blocks. Compared as sets, because the whole
        // failure mode is one block quietly covering less than the other.
        [$modern, $legacy] = $this->twoBlocks($text);

        $this->assertSame(
            $this->patternsIn($modern),
            $this->patternsIn($legacy),
            'the two blocks must deny exactly the same things'
        );
        $this->assertTrue(count($this->patternsIn($modern)) >= 5, 'and there are five of them');
    }

    /**
     * WHAT THE DENY PATTERNS ACTUALLY MATCH, run as regexes against the names
     * they are there to stop.
     *
     * Two findings live here, and neither was visible by reading the file:
     *
     *   - #17: the extension list is extension-driven, so `index.php~`,
     *     `config.php.save`, `routes.php.rej` and `index.php.orig~` matched
     *     nothing at all - not the list, not `^\.` - and LiteSpeed served them as
     *     plain text. The source of a file whose live version PHP executes.
     *   - #7: every pattern was case-SENSITIVE, on a case-sensitive host. So
     *     `Config.PHP`, `DUMP.SQL`, `Secrets.JSON` and `Router.PHP` were four
     *     names the rules did not know about. `Config.PHP` is the exact name the
     *     report proved leaks through the release builder's twin of this rule.
     *
     * PCRE is what Apache and LiteSpeed both use for <FilesMatch>, so running the
     * patterns through preg_match here is the same engine that will run them on
     * the host - which is as close as a local test can get to the server.
     */
    public function testTheDenyPatternsMatchTheNamesTheyAreWrittenFor(): void
    {
        [$modern] = $this->twoBlocks($this->text());
        $patterns = $this->patternsIn($modern);

        $mustBeDenied = [
            // the plain cases, which already worked
            '.env', '.git', '.htpasswd', 'dump.sql', 'notes.md', 'app.log',
            'config.json', 'service-account.json', 'router.php', 'key.pem',
            // #17: editor and merge droppings with no known extension
            'index.php~', 'config.php.save', 'routes.php.rej', 'index.php.orig~',
            'index.php.old', 'index.php.tmp', '.index.php.swp', 'config.php.copy',
            // #7: the same names as the host spells them
            'Config.PHP.save', 'DUMP.SQL', 'Notes.MD', 'Secrets.JSON',
            'Router.PHP', 'Key.PEM', 'INDEX.PHP.ORIG', 'app.LOG',
        ];

        foreach ($mustBeDenied as $name) {
            $this->assertTrue(
                $this->anyPatternMatches($patterns, $name),
                $name . ' is served as plain text: no deny pattern matches it'
            );
        }
    }

    /**
     * And the other direction, which matters just as much: a deny rule that
     * catches the site's own assets is an outage, not a security control.
     */
    public function testTheDenyPatternsDoNotCatchTheSiteItself(): void
    {
        [$modern] = $this->twoBlocks($this->text());
        $patterns = $this->patternsIn($modern);

        $mustBeServed = [
            'index.php', 'site.css', 'calculator.js', 'barlow-400-latin.woff2',
            'pk.svg', 'logo.png', 'site.webmanifest', 'favicon.ico',
            'og-card.jpg', 'sitemap.xml', 'robots.txt',
        ];

        foreach ($mustBeServed as $name) {
            $this->assertFalse(
                $this->anyPatternMatches($patterns, $name),
                $name . ' is part of the site and must still be served'
            );
        }
    }

    /**
     * Fonts are cached for a year (audit round 1, finding 18).
     *
     * The type DECLARED and the type given an EXPIRY were two different strings -
     * `application/font-woff2` in mod_mime, `font/woff2` in mod_expires - so
     * .woff2 fell through to ExpiresDefault "access plus 0 seconds" and every
     * self-hosted font was refetched on every page view. Nothing looks wrong; it
     * is just slow, on the mobile target, for ever.
     */
    public function testEverySelfHostedFontTypeIsBothDeclaredAndGivenAnExpiry(): void
    {
        $text = $this->text();

        // Whatever type the file declares for .woff2 must be a type the expiry
        // block also names. This is the invariant, not the specific spelling.
        preg_match_all('/^\s*AddType\s+(\S+)\s+\.woff2\b/mi', $text, $declared);
        $this->assertTrue($declared[1] !== [], 'the file still declares a type for .woff2');

        preg_match_all('/^\s*ExpiresByType\s+(\S+)\s+"access plus (\d+) (\w+)"/mi', $text, $expiries);
        $withExpiry = [];
        foreach ($expiries[1] as $i => $type) {
            $withExpiry[strtolower($type)] = (int) $expiries[2][$i] . ' ' . strtolower($expiries[3][$i]);
        }

        foreach ($declared[1] as $type) {
            $this->assertTrue(
                isset($withExpiry[strtolower($type)]),
                'AddType says .woff2 is "' . $type . '" but no ExpiresByType names that type, '
                . 'so it falls through to ExpiresDefault - which is 0 seconds'
            );
            $this->assertSame('365 days', $withExpiry[strtolower($type)], 'a hashed font never changes');
        }

        // And the pre-RFC spelling keeps its year too, because a host may
        // already know that name and win.
        $this->assertSame('365 days', $withExpiry['application/font-woff2'] ?? '', 'the legacy type is covered as well');

        // Every font binary the site serves really is a .woff2, or this test is
        // guarding a type the site does not use. The fonts directory also holds
        // the typefaces' LICENSE.txt, which is not a font and is meant to be
        // readable, so the filter is by font extension rather than by directory.
        $fontExtensions = ['woff2', 'woff', 'ttf', 'otf', 'eot'];
        $fonts          = [];

        foreach ($this->webRootFiles() as $path) {
            $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($extension, $fontExtensions, true)) {
                $fonts[] = $path;
            }
        }

        $this->assertTrue($fonts !== [], 'the site does self-host fonts');
        foreach ($fonts as $font) {
            $this->assertTrue(
                str_ends_with(strtolower($font), '.woff2'),
                basename($font) . ' is a font format this file gives no expiry to'
            );
        }
    }

    /**
     * HTML is never cached - prices change and a stale quote page is a wrong
     * quote - so the default must stay at zero however many types get a year.
     */
    public function testHtmlAndTheDefaultAreStillNotCached(): void
    {
        $text = $this->text();

        $this->assertStringContains('ExpiresDefault "access plus 0 seconds"', $text);
        $this->assertStringContains('ExpiresByType text/html "access plus 0 seconds"', $text);
    }

    /**
     * /favicon.ico and /llms.txt answered `Cache-Control: max-age=0` on the
     * Apache sim (2026-10-26): no ExpiresByType named either type, so both fell
     * to the zero default and every page view refetched the icon.
     *
     * The .txt rule is held to its SHAPE as well as its presence. It must go by
     * file name, never `ExpiresByType text/plain`: mod_expires acts on PHP's
     * output too, and index.php answers text/plain for robots.txt, /health,
     * /api/metrics and plain-text errors - a type rule would cache a 500 for a
     * day.
     */
    public function testTheFaviconAndStaticTextFilesAreCachedButPhpsTextIsNot(): void
    {
        foreach ($this->shippedHtaccessSources() as $label => $text) {
            foreach (['image/x-icon', 'image/vnd.microsoft.icon'] as $type) {
                $this->assertTrue(
                    preg_match('/^\s*ExpiresByType\s+' . preg_quote($type, '/') . '\s+"access plus [1-9]\d* (day|days|month|months)"/mi', $text) === 1,
                    $label . ' gives ' . $type . ' no expiry, so /favicon.ico falls to max-age=0'
                );
            }

            $this->assertTrue(
                preg_match('/<FilesMatch\s+"\(\?i\)\\\\\.txt\$">\s*ExpiresDefault\s+"access plus 1 day"\s*<\/FilesMatch>/i', $text) === 1,
                $label . ' has no by-name expiry for static .txt files (llms.txt)'
            );
            $this->assertSame(
                0,
                preg_match('/^\s*ExpiresByType\s+text\/plain\b/mi', $text),
                $label . ' caches text/plain by TYPE, which also caches PHP\'s text answers - robots.txt, /health, error pages'
            );
        }
    }

    /**
     * The reference copy is the shipped file plus COMMENTED-OUT blocks, and
     * nothing else.
     *
     * The two drifted for weeks with nothing to notice: the deploy copy kept
     * case-sensitive deny patterns, a 2.2 block that missed half the names and
     * no Authorization pass-through, while carrying active rules the live
     * server never ran (a method block, an `immutable` Cache-Control on every
     * asset). It is also what bin/build-release.php ships if public/.htaccess
     * goes missing, and what the owner pastes from in File Manager - so a
     * difference in an active line is a change to the live site that no test
     * and no curl has ever seen. Comments and blank lines may differ; nothing
     * else may, not even order.
     */
    public function testTheDeployReferenceCopyHasExactlyTheShippedActiveRules(): void
    {
        $sources = $this->shippedHtaccessSources();
        $active  = [];

        foreach ($sources as $label => $text) {
            $lines = [];
            foreach (preg_split('/\R/', $text) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '' && $line[0] !== '#') {
                    $lines[] = $line;
                }
            }
            $active[$label] = $lines;
        }

        $this->assertSame(
            $active['public/.htaccess'],
            $active['deploy/htaccess/public_html.htaccess'],
            'deploy/htaccess/public_html.htaccess has drifted from public/.htaccess - change public/.htaccess, then copy it across'
        );
    }

    /**
     * The belt-and-braces claim, which is the only one that holds when neither
     * authorisation module is present: there is nothing in the web root worth
     * denying.
     */
    public function testNothingSensitiveLivesUnderTheWebRootAtAll(): void
    {
        $found = [];

        foreach ($this->webRootFiles() as $path) {
            $name = basename($path);

            foreach (self::SENSITIVE as $pattern) {
                if (preg_match($pattern, $name) === 1) {
                    $found[] = str_replace(Paths::publicDir() . '/', '', $path);
                }
            }
        }

        /*
         * The two dotfiles that BELONG here, and the reason they are different
         * from everything else on the list.
         *
         *   .htaccess   the server refuses to serve it by its own rule, not by
         *               ours - Apache and LiteSpeed both hard-code that.
         *   .user.ini   the only file-based way to set post_max_size on a host
         *               where php_value is silently ignored, and PHP only reads
         *               it from the directory it applies to. It therefore has
         *               to sit in the web root; there is no elsewhere.
         *
         * Neither contains a secret: they are body limits and deny rules, which
         * is configuration a reader learns nothing from. The `^\.` pattern in
         * public/.htaccess denies both by name anyway, and the `\.ini$` pattern
         * denies .user.ini a second time - which is why this list, not that
         * one, is the thing being relaxed.
         */
        $belongHere = ['.htaccess', '.user.ini'];
        $found      = array_values(array_filter(
            $found,
            static fn (string $f): bool => !in_array($f, $belongHere, true)
        ));

        $this->assertSame(
            [],
            $found,
            'these would be downloadable on a host with no authz module: ' . implode(', ', $found)
        );
    }

    public function testTheOnlyPhpInTheWebRootIsTheFrontControllerAndTheDevRouter(): void
    {
        $php = [];

        foreach ($this->webRootFiles() as $path) {
            if (str_ends_with(strtolower($path), '.php')) {
                $php[] = str_replace(Paths::publicDir() . '/', '', $path);
            }
        }

        sort($php);

        $this->assertSame(
            ['index.php', 'router.php'],
            $php,
            'every other .php in the tree is a sibling of the web root, not inside it'
        );
    }

    public function testConfigAndCodeAreSiblingsOfTheWebRootAndNotInsideIt(): void
    {
        $webRoot = Paths::publicDir() . '/';

        foreach ([Paths::configFile(), Paths::app(), Paths::storage(), Paths::migrations()] as $path) {
            $this->assertFalse(
                str_starts_with(str_replace('\\', '/', $path) . '/', $webRoot),
                $path . ' must not be under the web root'
            );
        }
    }

    public function testPostMaxSizeIsAboveUploadMaxFilesize(): void
    {
        $text = $this->text();

        preg_match_all('/php_value\s+upload_max_filesize\s+(\S+)/i', $text, $uploads);
        preg_match_all('/php_value\s+post_max_size\s+(\S+)/i', $text, $posts);

        $this->assertTrue($uploads[1] !== [], 'the file still sets upload_max_filesize');
        $this->assertSame(count($uploads[1]), count($posts[1]), 'both are set in every block that sets either');

        foreach ($uploads[1] as $i => $upload) {
            $file = Upload::bytes($upload);
            $post = Upload::bytes($posts[1][$i]);

            $this->assertTrue(
                $post > $file,
                'post_max_size (' . $posts[1][$i] . ') must exceed upload_max_filesize (' . $upload . '): '
                . 'an upload at exactly the file limit makes a body slightly larger than it, '
                . 'and PHP then discards the whole form'
            );
            $this->assertTrue($post - $file >= 512 * 1024, 'and by enough to hold the other fields');
        }
    }

    /**
     * The body cap that refuses a giant POST before PHP reaches the app.
     *
     * A 12 MB enquiry at the public contact form has to be refused by the
     * engine, not by the application: every cap the application owns - Caps,
     * the field lengths, the honeypot - is spent AFTER the bytes have arrived,
     * so none of them is a defence against the size itself.
     *
     * The ceiling is asserted as an absolute number rather than "smaller than
     * it was", because the whole point is that it is SMALL: nothing on this
     * site uploads a file any more, the largest honest body is the rates
     * editor's text fields (tens of kilobytes), and 8M/9M was sized for an
     * Excel rate sheet that the owner removed on 2026-09-27.
     */
    public function testTheShippedBodyCapRefusesAGiantPostBeforeThePhpAppSeesIt(): void
    {
        $ceiling = 4 * 1024 * 1024;

        foreach ($this->shippedIniSources() as $label => $text) {
            preg_match_all('/(?:php_value\s+|^\s*)post_max_size\s*(?:=\s*)?(\S+)/im', $text, $posts);

            $this->assertTrue($posts[1] !== [], $label . ' still sets post_max_size');

            foreach ($posts[1] as $value) {
                $bytes = Upload::bytes($value);

                $this->assertTrue($bytes > 0, $label . ' sets post_max_size to ' . $value . ', which is "no limit"');
                $this->assertTrue(
                    $bytes <= $ceiling,
                    $label . ' allows a ' . $value . ' request body. Nothing on this site uploads a file, so a body '
                    . 'that big is only ever an attack or a bug - and it is read and buffered on a one-core shared '
                    . 'plan before the application gets a say'
                );
            }
        }
    }

    /**
     * post_max_size above upload_max_filesize, in every file that sets either.
     *
     * Same rule as testPostMaxSizeIsAboveUploadMaxFilesize, widened to the two
     * files that are pasted onto the host by hand - which is exactly where a
     * pair drifts apart unnoticed, because nothing on the host compares them.
     */
    public function testEveryShippedFileKeepsTheHeadroomBetweenTheTwoLimits(): void
    {
        foreach ($this->shippedIniSources() as $label => $text) {
            preg_match_all('/(?:php_value\s+|^\s*)upload_max_filesize\s*(?:=\s*)?(\S+)/im', $text, $uploads);
            preg_match_all('/(?:php_value\s+|^\s*)post_max_size\s*(?:=\s*)?(\S+)/im', $text, $posts);

            $this->assertSame(
                count($uploads[1]),
                count($posts[1]),
                $label . ' sets one of the pair without the other'
            );

            foreach ($uploads[1] as $i => $upload) {
                $file = Upload::bytes($upload);
                $post = Upload::bytes($posts[1][$i]);

                $this->assertTrue(
                    $post - $file >= 512 * 1024,
                    $label . ': post_max_size (' . $posts[1][$i] . ') must clear upload_max_filesize (' . $upload
                    . ') by at least half a megabyte, or a body at exactly the file limit is discarded whole'
                );
            }
        }
    }

    /**
     * The two anti-framing rules must agree.
     *
     * `X-Frame-Options: SAMEORIGIN` used to sit beside `frame-ancestors 'none'`
     * on the same response. The practical exposure was nil - every current
     * browser reads the CSP - but docs/quality-bar.md §2 asks for DENY, and a
     * header that contradicts the policy next to it is how a reviewer stops
     * believing either. The .htaccess copy matters on its own account: it is
     * the only one a request that dies before PHP sets its headers carries.
     */
    public function testTheAntiFramingHeaderSaysTheSameThingAsTheCsp(): void
    {
        foreach ($this->shippedHtaccessSources() as $label => $text) {
            preg_match_all('/X-Frame-Options\s+"([^"]+)"/i', $text, $found);

            $this->assertTrue($found[1] !== [], $label . ' still sets X-Frame-Options');

            foreach ($found[1] as $value) {
                $this->assertSame(
                    'DENY',
                    strtoupper(trim($value)),
                    $label . ' sends X-Frame-Options: ' . $value . " while the CSP says frame-ancestors 'none'"
                );
            }
        }
    }

    public function testTheFrontControllerRewriteIsStillThere(): void
    {
        $text = $this->text();

        $this->assertStringContains('RewriteEngine On', $text);
        $this->assertStringContains('RewriteRule ^ index.php [L]', $text);
        $this->assertStringContains('Options -Indexes', $text, 'directory listings off');
    }

    // ---------------------------------------------------------------- helpers

    private function text(): string
    {
        $path = Paths::publicDir() . '/.htaccess';

        $text = @file_get_contents($path);
        if ($text === false) {
            $this->fail('public/.htaccess is missing');
        }

        return $text;
    }

    /**
     * Every .htaccess the release puts on the host.
     *
     * public/.htaccess is the one that ships; deploy/htaccess/public_html.htaccess
     * is the annotated copy the owner pastes from through hPanel when a block
     * has to be switched on. A rule fixed in one and not the other is a rule
     * that comes back the first time somebody uses the File Manager.
     *
     * @return array<string,string> label => contents
     */
    private function shippedHtaccessSources(): array
    {
        $root  = dirname(Paths::publicDir());
        $paths = [
            'public/.htaccess'                       => Paths::publicDir() . '/.htaccess',
            'deploy/htaccess/public_html.htaccess'   => $root . '/deploy/htaccess/public_html.htaccess',
        ];

        $out = [];
        foreach ($paths as $label => $path) {
            $text = @file_get_contents($path);
            if ($text === false) {
                $this->fail($label . ' is missing');
            }
            $out[$label] = $text;
        }

        return $out;
    }

    /**
     * Everything that sets a PHP ini value on the host: both .htaccess copies
     * and the .user.ini, which is the only one LiteSpeed actually honours.
     *
     * @return array<string,string> label => contents
     */
    private function shippedIniSources(): array
    {
        $root    = dirname(Paths::publicDir());
        $sources = $this->shippedHtaccessSources();

        $sample = @file_get_contents($root . '/deploy/htaccess/user.ini.sample');
        if ($sample === false) {
            $this->fail('deploy/htaccess/user.ini.sample is missing');
        }
        // Commented-out guidance is documentation, not a setting. Drop it so a
        // sentence explaining an old value cannot be read as the value.
        $sources['deploy/htaccess/user.ini.sample'] = preg_replace('/^\s*;.*$/m', '', $sample) ?? $sample;

        return $sources;
    }

    /** @return array<int,string> line number => trimmed line, comments and blanks dropped */
    private function lines(): array
    {
        $lines = [];

        foreach (explode("\n", $this->text()) as $i => $raw) {
            $line = trim($raw);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $lines[$i + 1] = $line;
        }

        return $lines;
    }

    /**
     * The mod_authz_core block and the mod_access_compat block.
     *
     * @return array{string,string}
     */
    private function twoBlocks(string $text): array
    {
        $modern = '';
        $legacy = '';

        if (preg_match('/<IfModule mod_authz_core\.c>(.*?)\n<\/IfModule>/s', $text, $m) === 1) {
            $modern = $m[1];
        }
        if (preg_match('/<IfModule !mod_authz_core\.c>(.*?)\n<\/IfModule>/s', $text, $m) === 1) {
            $legacy = $m[1];
        }

        $this->assertNotSame('', $modern, 'the mod_authz_core block was not found');
        $this->assertNotSame('', $legacy, 'the mod_access_compat fallback was not found');

        return [$modern, $legacy];
    }

    /** @return list<string> the FilesMatch/Files patterns in a block, sorted */
    private function patternsIn(string $block): array
    {
        preg_match_all('/<Files(?:Match)? "([^"]+)">/', $block, $m);

        $patterns = $m[1];
        sort($patterns);

        return $patterns;
    }

    /**
     * Does any of these <FilesMatch> patterns match this filename?
     *
     * Apache and LiteSpeed both run <FilesMatch> through PCRE, so preg_match is
     * the same engine the host will use - which is the only reason a local test
     * can say anything about these rules at all. The delimiter is `#` and the
     * pattern is NOT quoted, because the pattern is a regex and quoting it would
     * test the literal string instead.
     */
    private function anyPatternMatches(array $patterns, string $name): bool
    {
        foreach ($patterns as $pattern) {
            if (@preg_match('#' . $pattern . '#', $name) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> every file under public/, forward-slashed */
    private function webRootFiles(): array
    {
        $files    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(Paths::publicDir(), \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $entry */
        foreach ($iterator as $entry) {
            if ($entry->isFile()) {
                $files[] = str_replace('\\', '/', $entry->getPathname());
            }
        }

        return $files;
    }
}
