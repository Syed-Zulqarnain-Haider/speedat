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
 * @var \Speedat\Core\View        $this
 * @var \Speedat\View\ContactView $view
 */

$this->layout('site/layout', ['page' => $view->page]);
?>
<section class="page">
<?= $this->partial('site/partials/page-head', ['title' => 'Contact', 'lede' => $view->lede]) ?>
  <a class="reach-big" href="<?= e($view->waHref) ?>" target="_blank" rel="noopener"><?= $this->partial('site/partials/icon', ['id' => 'i-wa']) ?><?= e($view->waText) ?></a>
<?php if ($view->facts !== []) { ?>
<?= $this->partial('site/partials/facts', ['facts' => $view->facts]) ?>
<?php } ?>
<?= $this->partial('site/partials/section-head', ['title' => 'Or write to us', 'id' => 'write-title']) ?>
<?php if ($view->sent) { ?>
  <div class="card contact-done" role="status">
    <h3>Thank you - we have your message</h3>
    <p>We reply during working hours. For anything urgent, WhatsApp is fastest.</p>
    <a class="btn wa big" href="<?= e($view->waHref) ?>" target="_blank" rel="noopener"><?= $this->partial('site/partials/icon', ['id' => 'i-wa']) ?>WhatsApp us</a>
  </div>
<?php } else { ?>
  <form class="panel contact-form" method="post" action="<?= e($this->url('/contact')) ?>" novalidate>
<?php if ($view->error('_') !== '') { ?>
    <div class="notice err" role="alert"><?= e($view->error('_')) ?></div>
<?php } ?>
    <input type="hidden" name="_token" value="<?= e($view->csrf) ?>">
    <input type="hidden" name="t" value="<?= e($view->formToken) ?>">
    <div style="position:absolute;left:-9999px;top:-9999px" aria-hidden="true">
      <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
    </div>
    <div class="row">
      <label class="field">
        <span>Your name</span>
        <input type="text" name="name" value="<?= e($view->value('name')) ?>" autocomplete="name" required maxlength="120">
<?php if ($view->error('name') !== '') { ?>
        <span class="contact-form-err"><?= e($view->error('name')) ?></span>
<?php } ?>
      </label>
      <label class="field">
        <span>Phone or WhatsApp</span>
        <input type="tel" name="phone" value="<?= e($view->value('phone')) ?>" autocomplete="tel" required maxlength="20" placeholder="+92 3xx xxx xxxx">
<?php if ($view->error('phone') !== '') { ?>
        <span class="contact-form-err"><?= e($view->error('phone')) ?></span>
<?php } ?>
      </label>
    </div>
    <div class="row">
      <label class="field">
        <span>Country <span class="hint">(optional)</span></span>
        <select name="destId">
          <option value=""<?= $view->value('destId') === '' ? ' selected' : '' ?>>Not sure yet</option>
<?php foreach ($view->destinations as $dest) { ?>
          <option value="<?= e($dest->id) ?>"<?= $view->value('destId') === $dest->id ? ' selected' : '' ?>><?= e($dest->name) ?></option>
<?php } ?>
        </select>
      </label>
      <label class="field">
        <span>Weight in kg <span class="hint">(optional)</span></span>
        <input type="number" name="weight" value="<?= e($view->value('weight')) ?>" inputmode="decimal" min="0.1" step="any">
      </label>
    </div>
    <label class="field">
      <span>Message</span>
      <textarea class="long" name="message" required maxlength="2000" placeholder="What are you sending, and when?"><?= e($view->value('message')) ?></textarea>
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
