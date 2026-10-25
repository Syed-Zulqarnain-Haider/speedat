<?php

declare(strict_types=1);

namespace Speedat\Tests\Core;

use Speedat\Core\Request;
use Speedat\Core\Upload;
use Speedat\Tests\TestCase;

/**
 * The upload handler's job before anything is read: say, in words, why there
 * is no file.
 *
 * The case worth the whole class is the FIRST one below. A request body over
 * post_max_size is thrown away entire - no $_POST, no $_FILES, no error code -
 * so a handler that only looks at $_FILES reports "no file chosen" to somebody
 * who definitely chose one, or says nothing at all.
 */
final class UploadTest extends TestCase
{
    public function testADiscardedBodyIsExplainedRatherThanLookingLikeAnEmptyForm(): void
    {
        $request = Request::synthetic('POST', '/admin/import', [], [], [
            'content-type'   => 'multipart/form-data; boundary=----x',
            'content-length' => '99000000',
        ]);

        $problem = Upload::problem($request, 'sheet');

        $this->assertNotNull($problem);
        $this->assertStringContains('too large', (string) $problem);
        $this->assertStringContains('nothing was uploaded', (string) $problem, 'it says what did NOT happen');
        $this->assertStringNotContains('post_max_size', (string) $problem, 'and says it without an ini directive');
    }

    public function testNoFileChosenSaysSo(): void
    {
        $request = Request::synthetic('POST', '/admin/import', ['_token' => 'x']);

        $this->assertStringContains('No file was chosen', (string) Upload::problem($request, 'sheet'));
    }

    public function testAGetIsNotAnUpload(): void
    {
        $this->assertStringContains(
            'did not arrive as a form post',
            (string) Upload::problem(Request::synthetic('GET', '/admin/import'), 'sheet')
        );
    }

    public function testEveryUploadErrorCodeGetsItsOwnSentence(): void
    {
        $seen = [];

        foreach ([
            UPLOAD_ERR_INI_SIZE   => 'larger than',
            UPLOAD_ERR_FORM_SIZE  => 'larger than',
            UPLOAD_ERR_PARTIAL    => 'stopped part-way',
            UPLOAD_ERR_NO_FILE    => 'No file was chosen',
            UPLOAD_ERR_NO_TMP_DIR => 'nowhere to put',
            UPLOAD_ERR_CANT_WRITE => 'could not write',
            UPLOAD_ERR_EXTENSION  => 'refused the upload',
            99                    => 'did not explain',
        ] as $code => $expected) {
            $problem = Upload::problem($this->requestWithFile(['error' => $code]), 'sheet');

            $this->assertStringContains($expected, (string) $problem, 'error code ' . $code);
            $seen[] = (string) $problem;
        }

        // Nothing leaks a path or a code number to the person reading it.
        foreach ($seen as $message) {
            $this->assertStringNotContains('/tmp', $message);
            $this->assertStringNotContains('UPLOAD_ERR', $message);
        }
    }

    public function testAFileThatIsNotReallyAnUploadIsRefused(): void
    {
        // is_uploaded_file() is false for anything this process did not receive
        // over HTTP, which is what stops a forged $_FILES entry talking the
        // handler into reading a file off the disk.
        $forged = $this->requestWithFile(['error' => UPLOAD_ERR_OK, 'tmp_name' => __FILE__, 'size' => 1000]);

        $this->assertStringContains('could not be read', (string) Upload::problem($forged, 'sheet'));
    }

    public function testAnEmptyFileIsRefused(): void
    {
        $empty = $this->requestWithFile(['error' => UPLOAD_ERR_OK, 'tmp_name' => '', 'size' => 0]);

        $this->assertNotNull(Upload::problem($empty, 'sheet'));
    }

    public function testTheIniShorthandIsParsedTheWayPhpParsesIt(): void
    {
        $this->assertSame(8 * 1024 * 1024, Upload::bytes('8M'));
        $this->assertSame(8 * 1024 * 1024, Upload::bytes('8m'));
        $this->assertSame(512 * 1024, Upload::bytes('512K'));
        $this->assertSame(1024 * 1024 * 1024, Upload::bytes('1G'));
        $this->assertSame(8388608, Upload::bytes('8388608'));
        $this->assertSame(0, Upload::bytes('0'), 'nought means no limit');
        $this->assertSame(0, Upload::bytes('-1'));
        $this->assertSame(0, Upload::bytes(''));
    }

    public function testBytesAreDescribedTheWayAPersonWouldSayThem(): void
    {
        $this->assertSame('8 MB', Upload::describe(8 * 1024 * 1024));
        $this->assertSame('1.5 MB', Upload::describe((int) (1.5 * 1024 * 1024)));
        $this->assertSame('512 KB', Upload::describe(512 * 1024));
        $this->assertSame('900 bytes', Upload::describe(900));
        $this->assertSame('unlimited', Upload::describe(0));
    }

    /**
     * The trap itself, on whatever PHP is running this.
     *
     * If the local php.ini has the two limits equal, this fails - and it should:
     * the development machine then cannot reproduce an upload that the host
     * would accept, which is how the bug got shipped in the first place.
     */
    public function testThisPhpIsNotConfiguredWithTheTrap(): void
    {
        $this->assertTrue(
            Upload::limitsAreSane(),
            'post_max_size is ' . ini_get('post_max_size') . ' and upload_max_filesize is '
            . ini_get('upload_max_filesize') . '; the first must be larger. See bin/local/README-local.md.'
        );
    }

    /** @param array<string,mixed> $file */
    private function requestWithFile(array $file): Request
    {
        $file += ['name' => 'rates.xlsx', 'type' => 'application/vnd.ms-excel', 'tmp_name' => '/tmp/php-test', 'size' => 1000];

        return Request::synthetic('POST', '/admin/import', ['_token' => 'x'], [], [], [], ['sheet' => $file]);
    }
}
