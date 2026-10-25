<?php

declare(strict_types=1);

/**
 * /contact. Port of `src/app/(site)/contact/page.tsx` and
 * `src/components/site/ContactForm.tsx`.
 *
 * The WhatsApp number as the page - big, green, one tap - then the other
 * numbers, the email, the office, the hours and the pickup facts as plain
 * rows, then the message form under its own h2, then the contact line with
 * the two buttons alone.
 *
 * THE FORM IS A PLAIN POST, not a script. It carries the CSRF token, the
 * signed render token and the honeypot, and it re-renders with what was typed
 * when the handler rejects it - which is why `$view->value()` is read into
 * every field instead of a bare default. The honeypot is positioned off-screen
 * with an inline style, as it was in React; the site's CSP allows inline
 * styles (`style-src 'self' 'unsafe-inline'`) and forbids inline scripts, so
 * this costs nothing.
 *
 * THE ANSWER IS WHERE THE EYES GO, NOT AT THE TOP (fixed 2026-10-25). The form
 * starts about 905px down a 375px phone, and a rejection (the 422 re-render)
 * and a thank-you (the GET after the redirect) both used to open at the top of
 * the page: the customer could not see whether the message had gone, so they
 * sent it again - a second lead - or gave up, and a screen reader heard
 * nothing at all. Now the page FOCUSES the answer, which also scrolls it into
 * view: the thank-you card, the form-level refusal, or the first field that
 * was refused. `autofocus`, not a URL fragment, and on purpose: a browser
 * skips autofocus on a page whose address carries a fragment, so the two
 * would cancel out, and the redirect's Location stays plain "/contact".
 *
 * A refused field is aria-invalid. It needs no aria-describedby: the error is
 * inside the field's own <label>, so it is already part of the name a screen
 * reader reads when the field takes focus ("Your name, Please tell us your
 * name, invalid").
 *
 * @var \Speedat\Core\View        $this
 * @var \Speedat\View\ContactView $view
 */

$this->layout('site/layout', ['page' => $view->page]);

// The one element the page focuses after a rejection: the form-level refusal
// if there is one, else the first refused field in the order they are drawn.
$focus = '';
if ($view->error('_') !== '') {
    $focus = '_';
} else {
    foreach (['name', 'phone', 'destId', 'message'] as $field) {
        if ($view->error($field) !== '') {
            $focus = $field;
            break;
        }
    }
}

/** ` aria-invalid="true"`, plus ` autofocus` on the first, for a refused field; "" otherwise. */
$invalid = static function (string $field) use ($view, $focus): string {
    if ($view->error($field) === '') {
        return '';
    }

    return ' aria-invalid="true"' . ($focus === $field ? ' autofocus' : '');
};
?>
<section class="page">
<?= $this->partial('site/partials/page-head', ['title' => 'Contact', 'lede' => $view->lede]) ?>
  <a class="reach-big" href="<?= e($view->waHref) ?>" target="_blank" rel="noopener"><?= $this->partial('site/partials/icon', ['id' => 'i-wa']) ?><?= e($view->waText) ?></a>
<?php if ($view->facts !== []) { ?>
<?= $this->partial('site/partials/facts', ['facts' => $view->facts]) ?>
<?php } ?>
<?= $this->partial('site/partials/section-head', ['title' => 'Or write to us', 'id' => 'write-title']) ?>
<?php if ($view->sent) { ?>
  <div class="card contact-done" role="status" tabindex="-1" autofocus>
    <h3>Thank you - we have your message</h3>
    <p>We reply during working hours. For anything urgent, WhatsApp is fastest.</p>
    <a class="btn wa big" href="<?= e($view->waHref) ?>" target="_blank" rel="noopener"><?= $this->partial('site/partials/icon', ['id' => 'i-wa']) ?>WhatsApp us</a>
  </div>
<?php } else { ?>
  <form class="panel contact-form" method="post" action="<?= e($this->url('/contact')) ?>" novalidate>
<?php if ($view->error('_') !== '') { ?>
    <div class="contact-form-said" tabindex="-1" autofocus>
      <div class="notice err" role="alert"><?= e($view->error('_')) ?></div>
    </div>
<?php } ?>
    <input type="hidden" name="_token" value="<?= e($view->csrf) ?>">
    <input type="hidden" name="t" value="<?= e($view->formToken) ?>">
    <div style="position:absolute;left:-9999px;top:-9999px" aria-hidden="true">
      <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
    </div>
    <div class="row">
      <label class="field">
        <span>Your name</span>
        <input type="text" name="name" value="<?= e($view->value('name')) ?>" autocomplete="name" required maxlength="120"<?= $invalid('name') ?>>
<?php if ($view->error('name') !== '') { ?>
        <span class="contact-form-err"><?= e($view->error('name')) ?></span>
<?php } ?>
      </label>
      <label class="field">
        <span>Phone or WhatsApp</span>
        <input type="tel" name="phone" value="<?= e($view->value('phone')) ?>" autocomplete="tel" required maxlength="20" placeholder="+92 3xx xxx xxxx"<?= $invalid('phone') ?>>
<?php if ($view->error('phone') !== '') { ?>
        <span class="contact-form-err"><?= e($view->error('phone')) ?></span>
<?php } ?>
      </label>
    </div>
    <div class="row">
      <label class="field">
        <span>Country <span class="hint">(optional)</span></span>
        <select name="destId"<?= $invalid('destId') ?>>
          <option value=""<?= $view->value('destId') === '' ? ' selected' : '' ?>>Not sure yet</option>
<?php foreach ($view->destinations as $dest) { ?>
          <option value="<?= e($dest->id) ?>"<?= $view->value('destId') === $dest->id ? ' selected' : '' ?>><?= e($dest->name) ?></option>
<?php } ?>
        </select>
<?php if ($view->error('destId') !== '') { ?>
        <span class="contact-form-err"><?= e($view->error('destId')) ?></span>
<?php } ?>
      </label>
      <label class="field">
        <span>Weight in kg <span class="hint">(optional)</span></span>
        <input type="number" name="weight" value="<?= e($view->value('weight')) ?>" inputmode="decimal" min="0.1" step="any">
      </label>
    </div>
    <label class="field">
      <span>Message</span>
      <textarea class="long" name="message" required maxlength="2000" placeholder="What are you sending, and when?"<?= $invalid('message') ?>><?= e($view->value('message')) ?></textarea>
<?php if ($view->error('message') !== '') { ?>
      <span class="contact-form-err"><?= e($view->error('message')) ?></span>
<?php } ?>
    </label>
    <div class="contact-form-actions">
      <button class="btn primary big" type="submit">Send message</button>
    </div>
  </form>
<?php } ?>
</section>
<?= $this->partial('site/partials/reach', ['reach' => $view->reach]) ?>
