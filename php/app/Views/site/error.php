<?php

declare(strict_types=1);

/**
 * The 404 and the 500, in the site's own layout. Port of
 * `src/components/site/pages/NotFoundBody.tsx`, `src/app/not-found.tsx` and
 * `src/app/(site)/error.tsx`.
 *
 * The heading, one line, two big buttons - the calculator and WhatsApp - and
 * nothing to decode: no code figure, no accent, nothing centred. The same
 * header, nav, footer and JSON-LD as every customer page, so a visitor who
 * mistyped a URL is not dropped onto a bare document.
 *
 * It says NOTHING about what went wrong. On the 500 the detail is in
 * storage/logs; a visitor never sees a stack trace, a path, a query or a
 * driver message. The caller is responsible for the status code - this
 * template returning 200 would be an SEO bug, not a cosmetic one.
 *
 * @var \Speedat\Core\View      $this
 * @var \Speedat\View\ErrorView $view
 */

$this->layout('site/layout', ['page' => $view->page]);
?>
<section class="page nf">
<?= $this->partial('site/partials/page-head', ['title' => $view->title, 'lede' => $view->line]) ?>
  <div class="nf-actions">
    <a class="btn primary big" href="<?= e($this->url('/')) ?>">Get a price</a>
<?php if ($view->waHref !== '') { ?>
    <a class="btn wa big" href="<?= e($view->waHref) ?>" target="_blank" rel="noopener"><?= $this->partial('site/partials/icon', ['id' => 'i-wa']) ?>WhatsApp us</a>
<?php } ?>
  </div>
</section>
