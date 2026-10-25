<?php

declare(strict_types=1);

/**
 * One header row: the owner's logo, the page links (hidden below 1024 by
 * site.css), then Call and WhatsApp. Port of the header in
 * `src/app/(site)/layout.tsx`, re-branded on 2026-09-28.
 *
 * THE MARK IS THE OWNER'S OWN LOGO FILE, not the CSS stripes and not a
 * wordmark beside it - php/docs/brand.md. The stripe `<span class="mark">` and
 * the `.brand-name` text both went: the artwork already carries the company
 * name AND the tagline ("speed against time"), so printing either again says
 * it twice. `.mark` still exists in site.css for the admin shell and the admin
 * login, which are not this file's to change. (docs/assets.md section 4 still
 * describes the CSS mark as the header's; brand.md is the later decision and
 * wins here.)
 *
 * The 2x file is in `srcset` because the 1x is visibly soft on a phone, and
 * `width`/`height` are the file's real pixels so the row cannot jump while the
 * image loads - the CSS sets the rendered height (36px phone, 40px from 1024)
 * and leaves the width to the 4.96:1 ratio.
 *
 * The alt is the company name, not "logo": it replaces the wordmark. The link
 * keeps its own aria-label, so a screen reader hears the name and "home" once
 * rather than the name twice.
 *
 * @var \Speedat\Core\View     $this
 * @var \Speedat\View\PageView $page
 */

use Speedat\Domain\Pricing\Format;

$doc   = $page->doc;
$tel   = $doc->telHref();
$phone = $doc->company()->phone;
$name  = $doc->company()->name;

// The WhatsApp number, shown ON the button. Read from the document's `whatsapp`
// field (never hard-coded) and formatted by the same helper the footer uses, so
// "923337667076" reads as "+92 333 766 7076" and the two can never disagree. It
// is blank when the owner left the field blank, in which case the button keeps
// its word alone rather than printing a lone "+".
$waNumber = $doc->company()->whatsapp !== '' ? Format::fmtPhone($doc->company()->whatsapp) : '';
?>
<header class="site-head">
  <div class="head-row">
    <a class="brand-link" href="<?= e($this->url('/')) ?>" aria-label="<?= e($name . ' - home') ?>">
      <img class="brand-logo"
           src="<?= e($this->asset('img/brand/speedat-logo.png')) ?>"
           srcset="<?= e($this->asset('img/brand/speedat-logo.png')) ?> 1x, <?= e($this->asset('img/brand/speedat-logo@2x.png')) ?> 2x"
           width="660" height="133" alt="<?= e($name) ?>" fetchpriority="high" decoding="async">
    </a>
    <?= $this->partial('site/partials/nav', ['page' => $page, 'label' => 'Site']) ?>
    <div class="head-actions">
<?php if ($tel !== '') { ?>
      <a class="btn outline call" href="<?= e($tel) ?>" aria-label="<?= e('Call ' . $phone) ?>">
        <?= $this->partial('site/partials/icon', ['id' => 'i-phone']) ?>
        <span class="call-word">Call<span class="call-num"> <?= e($phone) ?></span></span>
      </a>
<?php } ?>
      <a class="btn wa" href="<?= e($doc->waHref()) ?>" target="_blank" rel="noopener" aria-label="<?= e($waNumber !== '' ? 'WhatsApp us on ' . $waNumber : 'WhatsApp us') ?>">
        <?= $this->partial('site/partials/icon', ['id' => 'i-wa']) ?>
        <span class="wa-word">WhatsApp<?php if ($waNumber !== '') { ?><span class="wa-num"> <?= e($waNumber) ?></span><?php } ?></span>
      </a>
    </div>
<?php
/*
 * THE PHONE MENU. Below 1024 the header's inline nav is hidden (site.css
 * `.head-row > .nav { display:none }`) and until now a phone had no top menu at
 * all - only the footer's "Pages" list. This is that menu: the same five links
 * behind a hamburger.
 *
 * It is a NATIVE <details>/<summary>, so it opens and closes with NO JavaScript
 * and is keyboard-operable for free (summary is a button; Enter/Space toggle;
 * <details> conveys its own expanded state to assistive tech). The summary is
 * the hamburger, labelled "Menu"; the glyph is inline so it inherits the bar's
 * ink via currentColor - nothing white on the yellow (docs/brand.md). site.css
 * hides this whole element at >=1024, where the inline nav takes over, so the
 * two are never both on screen. A tapped link is a full navigation, which
 * closes the disclosure on its own on the next page.
 */
?>
    <details class="menu">
      <summary class="menu-btn" aria-label="Menu">
        <svg class="menu-ico" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <line x1="4" y1="7" x2="20" y2="7"></line>
          <line x1="4" y1="12" x2="20" y2="12"></line>
          <line x1="4" y1="17" x2="20" y2="17"></line>
        </svg>
      </summary>
      <nav class="menu-panel" aria-label="Menu">
<?php foreach ($page->navLinks() as $link) { ?>
        <a href="<?= e($this->url($link['href'])) ?>"<?= $link['current'] ? ' aria-current="page"' : '' ?>><?= e($link['label']) ?></a>
<?php } ?>
      </nav>
    </details>
  </div>
</header>
