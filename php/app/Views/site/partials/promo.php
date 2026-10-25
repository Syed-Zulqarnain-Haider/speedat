<?php

declare(strict_types=1);

/**
 * The discount / offer popup, rendered ONLY when the owner has turned it on.
 *
 * It is a small corner card, never a full-screen modal: the price is the hero
 * of every page and this must not cover it, least of all on a phone. The card
 * ships with `hidden` and `promo.js` reveals it after a beat, so the price
 * paints first and a returning visitor who has already dismissed the offer
 * never sees it flash in. With JavaScript off the card stays hidden - a promo
 * is an enhancement, not part of pricing a parcel, so gating it on script is
 * the reasonable line the brief draws.
 *
 * CONTENT HONESTY. Nothing here is invented: the title, the message and the
 * button are exactly what the owner typed, each printed through e(). When the
 * owner enabled the popup but has not typed anything yet, there is nothing to
 * say and nothing renders.
 *
 * The button's href is checked against a small scheme allow-list before it is
 * printed, because e() stops an attribute break-out but not a `javascript:` URL
 * that would still run on click. Only http(s), a root-relative path, mailto,
 * tel or a fragment is allowed; anything else drops the button rather than the
 * whole card. A bare domain (`wa.me/...`, `www.example.com`) is read as https.
 *
 * @var \Speedat\Core\View     $this
 * @var \Speedat\View\PageView $page
 */

if (!isset($page)) {
    return;
}

$promo = $page->doc->data()->promo;

// Off is the default and the common case: no popup, no markup, no script.
if (!$promo->enabled) {
    return;
}

$title   = trim($promo->title);
$message = trim($promo->message);
$cta     = trim($promo->ctaLabel);
$rawHref = trim($promo->ctaHref);

// A popup with nothing in it is not shown: the owner ticked the box but has not
// written the offer yet.
if ($title === '' && $message === '' && $cta === '') {
    return;
}

// A bare domain gets https:// in front before the allow-list sees it. The owner
// types links the way people say them - `wa.me/92300...`, `www.example.com` -
// and the allow-list alone dropped the button for those with no warning, so the
// offer went live with nothing to tap (found in review, 2026-10-25). Only a
// value that STARTS with a dotted host name is touched: no scheme can match it
// (a scheme ends in ':', which the pattern does not allow), so this can widen
// the allow-list to nothing but https.
if (preg_match('~^[a-z0-9-]+(?:\.[a-z0-9-]+)+(?:[/?#]|$)~i', $rawHref) === 1) {
    $rawHref = 'https://' . $rawHref;
}

$safeHref = preg_match('~^(https?://|/|mailto:|tel:|#)~i', $rawHref) === 1 ? $rawHref : '';

// A short fingerprint of THIS offer. promo.js remembers a dismissal against it,
// so when the owner changes the wording the popup returns for everyone; an
// unchanged offer stays dismissed.
$sig = substr(hash('sha256', $title . "\x1f" . $message . "\x1f" . $cta . "\x1f" . $safeHref), 0, 16);
?>
<aside class="promo-pop" data-promo data-promo-sig="<?= e($sig) ?>" aria-label="Offer" hidden>
  <button type="button" class="promo-close" data-promo-close aria-label="Dismiss offer">
    <span class="promo-x" aria-hidden="true">&#215;</span>
  </button>
  <div class="promo-body">
<?php if ($title !== '') { ?>
    <p class="promo-title"><?= e($title) ?></p>
<?php } ?>
<?php if ($message !== '') { ?>
    <p class="promo-msg"><?= e($message) ?></p>
<?php } ?>
<?php if ($cta !== '' && $safeHref !== '') { ?>
    <a class="promo-cta" href="<?= e($safeHref) ?>"><?= e($cta) ?></a>
<?php } ?>
  </div>
</aside>
<script defer src="<?= e($this->asset('js/promo.js')) ?>"></script>
