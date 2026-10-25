<?php

declare(strict_types=1);

/**
 * The icon sprite, printed once inside <body> before anything that uses it.
 *
 * It is INLINED, not linked. Chrome dropped external `<use href="file.svg#id">`
 * entirely, and where it does work `currentColor` resolves against the external
 * document - black icons on the green WhatsApp button. Inlined, every symbol
 * inherits the colour of whatever it sits in, exactly as the React components
 * did (php/docs/assets.md section 4).
 *
 * The file's own XML prolog and comments are dropped: an `<?xml ... ?>` inside
 * an HTML body is a bogus comment to every HTML parser. Everything from the
 * root `<svg xmlns=...>` on is printed raw, which is safe because this is a
 * file WE ship, read from disk, never a value from the database or a request.
 *
 * Comments are stripped BEFORE the root element is located, and that order is
 * load-bearing: the sprite's own documentation comment shows a usage example
 * containing the text `<svg class="ico">`, so searching the raw file for the
 * first `<svg ` landed inside the comment. Every page then printed the second
 * half of that comment as visible text above the header, with one giant
 * orphaned glyph - which is exactly what it did until this was fixed.
 *
 * @var \Speedat\Core\View $this
 */

use Speedat\Core\Paths;

$file = Paths::publicDir() . '/assets/img/icons.svg';

if (!is_file($file)) {
    return;
}

$svg = file_get_contents($file);
if ($svg === false) {
    return;
}

// Prolog first, then comments, then find the real root element.
$svg = (string) preg_replace('/<\?xml\b.*?\?>/s', '', $svg);
$svg = (string) preg_replace('/<!--.*?-->/s', '', $svg);

// The root carries the namespace; nothing else in the file does.
if (preg_match('/<svg\b[^>]*\bxmlns=/i', $svg, $m, PREG_OFFSET_CAPTURE) !== 1) {
    return;
}

echo trim(substr($svg, (int) $m[0][1]));
