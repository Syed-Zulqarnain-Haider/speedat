<?php

declare(strict_types=1);

/**
 * A section of an inner page: a hairline, 32px, the plain h2, then whatever
 * follows. Port of `src/components/site/pages/SectionHead.tsx` - sections are
 * separated by a rule, never a band.
 *
 * The React component wrapped its children; a PHP template cannot take a block
 * as an argument without a closure, and the two callers (/about's Mission,
 * Vision and Values, /contact's "Or write to us") only ever put the heading
 * inside the wrapper and the body after it. So this prints the wrapper with
 * the heading alone and the caller prints its own body under it, exactly as
 * `<SectionHead />` with no children rendered.
 *
 * @var \Speedat\Core\View $this
 * @var string             $title
 * @var string             $id     for aria-labelledby; "" omits it
 */

$id = isset($id) && is_string($id) ? $id : '';
?>
<div class="page-sec">
  <h2<?= $id !== '' ? ' id="' . e($id) . '"' : '' ?>><?= e($title) ?></h2>
</div>
