<?php

declare(strict_types=1);

/**
 * The discount popup, in the rates editor. A NEW admin partial (creating one is
 * allowed; editing the existing sections is not).
 *
 * Its fields belong to `form="editor"` - the same form Settings and Website
 * pages post through - so turning the popup on and typing an offer is saved,
 * reviewed and published with everything else, in one version. The controls are
 * plain HTML with a `form` attribute rather than being nested inside the form,
 * exactly as the Settings and Website sections are, because HTML forms cannot
 * nest and other sections sit between them.
 *
 * The checkbox carries a hidden "0" companion of the same name: a browser posts
 * nothing for an unticked box, and without the companion the server could not
 * tell "turned the popup off" from "that field was not on this form".
 *
 * CONTENT HONESTY. Nothing is prefilled and nothing is invented: the fields show
 * exactly what the owner has stored, and the public popup shows exactly what is
 * typed here. Off is the default, so a site that never touches this stays quiet.
 *
 * @var \Speedat\Core\View            $this
 * @var \Speedat\View\Admin\RatesPage $page
 */

$draft    = $page->draft;
$readOnly = $page->readOnly;
$disabled = $readOnly ? ' disabled' : '';
?>
<section class="block" id="sec-promo">
  <p class="eyebrow"><?= e(\Speedat\View\Admin\Sections::no('promo')) ?> - Popup</p>
  <h2>Discount popup</h2>
  <p class="desc">A small popup on the public site showing an offer. It is <strong>off</strong> until you turn it on, and it shows exactly what you type here - it never makes up a discount. Publishing rates publishes this too.</p>

  <label class="chk">
    <input type="hidden" name="promo[enabled]" value="0" form="editor">
    <input type="checkbox" name="promo[enabled]" value="1"<?= $draft->promoEnabled() ? ' checked' : '' ?> form="editor"<?= $disabled ?>>
    Show the popup on the site
  </label>

  <label class="field">
    <span>Title</span>
    <input type="text" name="promo[title]" value="<?= e($draft->promo('title')) ?>" maxlength="120" form="editor"<?= $disabled ?>>
    <span class="hint">A short headline, e.g. &#8220;Ramzan offer&#8221;.</span>
  </label>

  <label class="field">
    <span>Message</span>
    <input type="text" name="promo[message]" value="<?= e($draft->promo('message')) ?>" maxlength="300" form="editor"<?= $disabled ?>>
    <span class="hint">One line. Type the real offer - a percentage or amount only if it is true.</span>
  </label>

  <label class="field">
    <span>Button text</span>
    <input type="text" name="promo[ctaLabel]" value="<?= e($draft->promo('ctaLabel')) ?>" maxlength="60" form="editor"<?= $disabled ?>>
    <span class="hint">Leave blank for no button, e.g. &#8220;Get a price&#8221;.</span>
  </label>

  <label class="field">
    <span>Button link</span>
    <input type="text" name="promo[ctaHref]" value="<?= e($draft->promo('ctaHref')) ?>" maxlength="300" form="editor"<?= $disabled ?>>
    <span class="hint">Where the button goes: a full link like https://wa.me/923001234567, a page like /contact, or a mailto: link.</span>
  </label>

  <div class="btn-row">
    <button class="btn primary" type="submit" form="editor" data-save-submit<?= $disabled ?>>Save settings and pages</button>
  </div>
</section>
