<?php

declare(strict_types=1);

/**
 * One glyph from the inlined sprite.
 *
 * `<svg class="ico" aria-hidden="true"><use href="#i-wa"></use></svg>` - the
 * exact shape php/docs/assets.md section 4 specifies. The symbol carries its
 * own viewBox, stroke width and caps, so a bare `<use>` renders identically to
 * the React component it came from.
 *
 * `$id` is always a literal from a template, never a value from the document -
 * but it is escaped anyway, because "never" is a property of today's callers
 * and not of the file.
 *
 * @var \Speedat\Core\View $this
 * @var string             $id     the symbol id without the "#": "i-wa", "c-box"
 * @var string             $class  extra classes beside "ico"
 */

$class = isset($class) && is_string($class) && $class !== '' ? 'ico ' . $class : 'ico';
?>
<svg class="<?= e($class) ?>" aria-hidden="true"><use href="#<?= e($id) ?>"></use></svg>
