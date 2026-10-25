<?php

declare(strict_types=1);

namespace Speedat\Tests\Auth;

use Speedat\Domain\Auth\FirebaseTokenError;
use Speedat\Domain\Auth\GoogleCertificates;
use Speedat\Tests\TestCase;

/**
 * Where the certificate cache may live, after `www` stopped being refused by
 * name on /var/www installs (review, 2026-10-23). The staging layout is
 * /var/www/<site>/storage/cache, and refusing it meant every Google sign-in on
 * staging said "refused" with nothing in the page to say why.
 */
final class B7CertCacheWebRootTest extends TestCase
{
    private string $root = '';

    /** @var mixed */
    private $docRoot = null;

    public function setUp(): void
    {
        $this->root    = sys_get_temp_dir() . '/speedat-b7-' . bin2hex(random_bytes(6));
        $this->docRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
        mkdir($this->root . '/site/served', 0o750, true);
    }

    public function tearDown(): void
    {
        if ($this->docRoot === null) {
            unset($_SERVER['DOCUMENT_ROOT']);
        } else {
            $_SERVER['DOCUMENT_ROOT'] = $this->docRoot;
        }

        @rmdir($this->root . '/site/served');
        @rmdir($this->root . '/site');
        @rmdir($this->root);
    }

    public function testAVarWwwInstallIsAccepted(): void
    {
        unset($_SERVER['DOCUMENT_ROOT']);

        new GoogleCertificates('/var/www/speedat/storage/cache/google-certs');
        new GoogleCertificates('/var/www/html/../storage/cache/google-certs');
        $this->assertTrue(true, '/var/www is the parent of site trees, not a web root');
    }

    public function testWwwAnywhereElseIsStillRefusedByName(): void
    {
        foreach (['C:\\inetpub\\www\\cache', '/srv/www/cache', '/home/u1/WWW/x', '/var/www/public/cache'] as $bad) {
            $this->assertThrows(FirebaseTokenError::class, static fn () => new GoogleCertificates($bad), $bad);
        }
    }

    public function testADirectoryInsideTheLiveDocumentRootIsRefusedWhateverItIsCalled(): void
    {
        $_SERVER['DOCUMENT_ROOT'] = $this->root . '/site/served';

        $this->assertThrows(
            FirebaseTokenError::class,
            fn () => new GoogleCertificates($this->root . '/site/served/cache/google-certs'),
            'a cache that does not exist yet, under the document root',
        );

        // Beside it is fine, and so is a sibling whose name merely starts the same.
        new GoogleCertificates($this->root . '/site/storage/cache');
        new GoogleCertificates($this->root . '/site/served-not/cache');
        $this->assertTrue(true);
    }

    public function testANamedWebRootIsRefusedToo(): void
    {
        unset($_SERVER['DOCUMENT_ROOT']);

        $this->assertThrows(
            FirebaseTokenError::class,
            fn () => new GoogleCertificates($this->root . '/site/served/x', webRoots: [$this->root . '/site/served']),
            'inside a root the caller named',
        );

        new GoogleCertificates($this->root . '/site/x', webRoots: [$this->root . '/site/served']);
        $this->assertTrue(true);
    }
}
