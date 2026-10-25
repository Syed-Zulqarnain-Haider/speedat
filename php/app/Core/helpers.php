<?php

declare(strict_types=1);

/**
 * The three functions templates are allowed to call without a namespace.
 *
 * They exist because `<?= e($lead['name']) ?>` is read correctly at a glance
 * and `<?= $this->e($lead['name']) ?>` is read as noise, and a template nobody
 * reads carefully is where an unescaped variable survives review.
 *
 * Nothing here holds state; they are functions, not globals.
 */

if (!function_exists('e')) {
    /** Escape for HTML text and attribute values. Everything printed in a template goes through this. */
    function e(mixed $value): string
    {
        $string = match (true) {
            $value === null               => '',
            is_bool($value)               => $value ? '1' : '',
            is_scalar($value)             => (string) $value,
            $value instanceof \Stringable => (string) $value,
            default                       => '',
        };

        return htmlspecialchars($string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('ejson')) {
    /**
     * Embed data for a <script> block safely: the encoder escapes the
     * characters that could otherwise close the tag or start a comment, so no
     * value can break out of the script element.
     */
    function ejson(mixed $value): string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return $json === false ? 'null' : $json;
    }
}
