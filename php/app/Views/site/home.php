<?php

declare(strict_types=1);

/**
 * Home - "Get a price". Port of `src/app/(site)/page.tsx`.
 *
 * One section. The headline names the route and the lowest 1 kg price, the
 * calculator is the page, the rates board is the reference under it - on a
 * phone in that order, from 1024 the headline and the board share the left
 * column beside the calculator. Every word is server-rendered; nothing
 * reveals, counts or scrolls by itself.
 *
 * On hold the calculator is replaced by the message and the WhatsApp form, and
 * the board prints days without prices. There is no third state.
 *
 * @var \Speedat\Core\View     $this
 * @var \Speedat\View\HomeView $view
 */

$this->layout('site/layout', ['page' => $view->page]);
?>
<div class="home">
  <section class="calc" aria-label="Get your price">
    <div class="hero-copy">
      <h1><?= e($view->title) ?></h1>
<?php if ($view->sub !== '') { ?>
      <p class="lede"><?= e($view->sub) ?></p>
<?php } ?>
<?php if ($view->stats !== []) { ?>
      <p class="stats"><?= e(implode(' · ', $view->stats)) ?></p>
<?php } ?>
    </div>
    <div id="quote-instrument" class="instrument">
<?php if ($view->hold !== null) { ?>
<?= $this->partial('site/partials/hold-panel', ['view' => $view]) ?>
<?php } else { ?>
<?= $this->partial('site/partials/calculator', ['view' => $view]) ?>
<?php } ?>
<?php if ($view->promise !== '') { ?>
      <p class="promise"><?= e($view->promise) ?></p>
<?php } ?>
    </div>
<?= $this->partial('site/partials/rates-board', ['board' => $view->board]) ?>
  </section>
<?php
/*
 * The local-SEO section: who we pick up from, across Lahore. It sits under the
 * price tool - the price is the hero, this is the "and we come to you" that
 * matches a "courier in <your area> Lahore" search. Real, visible content,
 * driven by Domain\Seo\LocalAreas (see the partial).
 */
?>
<?= $this->partial('site/partials/local-areas', ['view' => $view]) ?>
<?php
/*
 * The carriers we ship with. Static, owner-confirmed content (DHL, FedEx,
 * TeamGlobal, UPS, DPT, Skynet); it sits under the Lahore pickup section as
 * the "and here is who carries it onward" that follows "we come to your door".
 */
?>
<?= $this->partial('site/partials/partners') ?>
<?php
/*
 * What customers say. The owner-chosen reviews, under the partners strip as the
 * "and here is who trusts us" that follows "who we carry with". It renders
 * nothing at all unless the owner has switched at least one review on.
 */
?>
<?= $this->partial('site/partials/reviews', ['view' => $view]) ?>
</div>
