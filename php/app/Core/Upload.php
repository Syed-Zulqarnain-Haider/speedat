<?php

declare(strict_types=1);

namespace Speedat\Core;

/**
 * What arrived on a file input, and - when nothing usable did - a sentence the
 * person who pressed the button can act on.
 *
 * THE CASE THIS CLASS EXISTS FOR. `post_max_size` caps the whole request body,
 * `upload_max_filesize` caps one file inside it. When a body is larger than
 * `post_max_size`, PHP does not half-parse it and does not set an upload error
 * code: it discards the lot. $_POST is empty, $_FILES is empty, and the
 * handler sees a POST that looks like an empty form. With the two limits set
 * to the same number - which is how this project shipped until the sizes in
 * public/.htaccess were separated - a file exactly at the documented limit
 * lands in precisely that hole, because the multipart envelope and the other
 * fields push the body past it. The admin uploads a rate sheet, the page comes
 * back, and nothing has happened or been said.
 *
 * So: detect it (an empty $_POST on a form POST that declared a body), and say
 * what happened. `problem()` returns a sentence or null, never a code, and it
 * is the FIRST thing an upload route calls.
 *
 * It only reads the request and the ini. Storing, validating and parsing the
 * file belong to the route and to Domain\Import.
 */
final class Upload
{
    /**
     * Why there is no usable file on $field, in words - or null when there is
     * one.
     *
     * The order is the order things go wrong in: the body that never arrived
     * first, because in that case there is nothing else to look at.
     */
    public static function problem(Request $request, string $field): ?string
    {
        if (!$request->isPost()) {
            return 'That upload did not arrive as a form post. Please use the upload button on the page.';
        }

        if ($request->postWasDiscarded()) {
            return 'That file is too large to send in one request, so the server discarded the whole form - '
                . 'nothing was uploaded and nothing was changed. The limit for the entire form is '
                . self::describe(self::postLimit()) . ', which has to hold the file and the other fields together. '
                . 'Please send a file under ' . self::describe(self::fileLimit()) . '.';
        }

        $file = $request->file($field);

        if ($file === null) {
            return 'No file was chosen. Please pick the rate sheet and try again.';
        }

        $error = is_int($file['error'] ?? null) ? $file['error'] : UPLOAD_ERR_NO_FILE;

        return match ($error) {
            UPLOAD_ERR_OK => self::sizeProblem($file),
            // INI_SIZE is upload_max_filesize; FORM_SIZE is the form's own
            // MAX_FILE_SIZE field, which this site does not send, but a proxy
            // or a browser extension can.
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is larger than the '
                . self::describe(self::fileLimit()) . ' this server accepts. Please send a smaller sheet.',
            UPLOAD_ERR_PARTIAL   => 'The upload stopped part-way through, so the file is incomplete. Please try again.',
            UPLOAD_ERR_NO_FILE   => 'No file was chosen. Please pick the rate sheet and try again.',
            // The next three are the server's fault, not the person's, and
            // they are the ones worth waking somebody for.
            UPLOAD_ERR_NO_TMP_DIR => 'The server has nowhere to put the file (no temporary directory). Please tell whoever looks after the hosting.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the file to disk. Please tell whoever looks after the hosting.',
            UPLOAD_ERR_EXTENSION  => 'The server refused the upload. Please tell whoever looks after the hosting.',
            default               => 'The upload failed for a reason the server did not explain. Please try again.',
        };
    }

    /**
     * A file that PHP accepted but that is empty or not really an upload.
     *
     * is_uploaded_file() is the check that stops a handler being talked into
     * reading /etc/passwd by a forged $_FILES entry. It is false for anything
     * this process did not receive over HTTP.
     *
     * @param array<string,mixed> $file
     */
    private static function sizeProblem(array $file): ?string
    {
        $tmp  = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
        $size = is_int($file['size'] ?? null) ? $file['size'] : 0;

        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return 'That upload could not be read. Please try again.';
        }

        if ($size <= 0) {
            return 'That file is empty. Please check you picked the right one.';
        }

        return null;
    }

    /** `post_max_size` in bytes; 0 when the host has switched the limit off. */
    public static function postLimit(): int
    {
        return self::bytes((string) ini_get('post_max_size'));
    }

    /** `upload_max_filesize` in bytes; 0 when the host has switched the limit off. */
    public static function fileLimit(): int
    {
        return self::bytes((string) ini_get('upload_max_filesize'));
    }

    /**
     * True when the host has been configured with the trap this class exists
     * for: a whole-body limit that is not bigger than the single-file limit.
     *
     * bin/local/doctor.php reports it, because it is invisible until the day a
     * full-size file is uploaded.
     */
    public static function limitsAreSane(): bool
    {
        $post = self::postLimit();
        $file = self::fileLimit();

        // 0 means "no limit" for post_max_size, which is sane by definition.
        return $post === 0 || $post > $file;
    }

    /** An ini shorthand value ("8M", "512K", "1G", "8388608") in bytes. */
    public static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '0' || $value === '-1') {
            return 0;
        }

        $unit   = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g'     => $number * 1024 * 1024 * 1024,
            'm'     => $number * 1024 * 1024,
            'k'     => $number * 1024,
            default => $number,
        };
    }

    /** Bytes as a person would say them: "8 MB", not "8388608". */
    public static function describe(int $bytes): string
    {
        if ($bytes <= 0) {
            return 'unlimited';
        }
        if ($bytes >= 1024 * 1024) {
            return rtrim(rtrim(number_format($bytes / (1024 * 1024), 1, '.', ''), '0'), '.') . ' MB';
        }
        if ($bytes >= 1024) {
            return rtrim(rtrim(number_format($bytes / 1024, 1, '.', ''), '0'), '.') . ' KB';
        }

        return $bytes . ' bytes';
    }
}
