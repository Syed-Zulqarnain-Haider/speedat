<?php

declare(strict_types=1);

/**
 * The admin frame every signed-in page renders inside. Port of AdminShell.tsx.
 *
 * The sidebar (from 1024px) and the top bar with its scrolling nav (below it)
 * are BOTH always in the markup; admin.css shows one. Nothing branches on the
 * client, so the HTML the server sends is final - which is also why every link
 * and every control here works with JavaScript switched off.
 *
 * The stylesheets and the two scripts come from `admin/partials/head`, shared
 * with the sign-in layout so their order lives in one file. `data-theme` is
 * printed server-side from the cookie, which is what keeps the admin from
 * flashing the wrong theme on load.
 *
 * @var \Speedat\Core\View       $this
 * @var \Speedat\Core\Csrf       $csrf
 * @var string                   $content
 * @var string                   $title
 * @var \Speedat\View\Admin\Shell $shell
 * @var string                   $theme     light|dark, from the theme cookie
 * @var string                   $toast     a one-off message for admin.js, "" for none
 */

$title = isset($title) && is_string($title) && $title !== '' ? $title : 'Rates admin';
$theme = isset($theme) && $theme === 'dark' ? 'dark' : 'light';
$toast = isset($toast) && is_string($toast) ? $toast : '';

/*
 * The theme toggle. There is none in the admin chrome today — it lives only in
 * the public footer — so an admin-only user is stuck in whatever the cookie
 * last held. ui.js already owns the behaviour ([data-theme-toggle]): it writes
 * the cookie and repaints the glyph. The glyph and the label are rendered
 * here, from the server's own `$theme`, so the button is never empty or
 * mislabelled before the script runs — the same approach as site/partials/footer.
 * These are the exact paths ui.js's GLYPH.sun / GLYPH.moon draw.
 */
$themeLabel = $theme === 'dark' ? 'Light mode' : 'Dark mode';
$themeGlyph = $theme === 'dark'
    ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">'
      . '<circle cx="12" cy="12" r="4"></circle>'
      . '<path d="M12 2.5V5M12 19v2.5M2.5 12H5M19 12h2.5M5.3 5.3l1.8 1.8M16.9 16.9l1.8 1.8M5.3 18.7l1.8-1.8M16.9 7.1l1.8-1.8"></path></svg>'
    : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
      . '<path d="M20.5 14.5A8.5 8.5 0 0 1 9.5 3.5a8.5 8.5 0 1 0 11 11z"></path></svg>';
/*
 * `hidden` at rest: the toggle writes the theme cookie through JavaScript and
 * does nothing without it, so — like the Google sign-in and the Copy-CSV button
 * — it starts hidden and admin.js reveals it once it is wired. That keeps the
 * admin's "no dead buttons without a script" contract (AdminTemplatesTest).
 */
$themeToggle = '<button type="button" class="btn outline small theme" data-theme-toggle hidden'
    . ' aria-label="' . e($themeLabel) . '" title="' . e($themeLabel) . '">' . $themeGlyph . '</button>';

/*
 * The brand mark, now the real logo on a brand-yellow plate (docs/brand.md and
 * the admin redesign). The plate is yellow in BOTH themes — it is the one place
 * the brand colour appears as a confident fill, it echoes the public header, and
 * because yellow never inverts it keeps the dark-ink logo legible on the dark
 * sidebar. The logo already carries the wordmark and the "speed against time"
 * tagline, so no text is printed beside it; the "Admin" eyebrow stays.
 */
$brandPlate = '<span class="brand-plate">'
    . '<img class="brand-logo" src="' . e($this->asset('img/brand/speedat-logo.png')) . '"'
    . ' srcset="' . e($this->asset('img/brand/speedat-logo.png')) . ' 1x, ' . e($this->asset('img/brand/speedat-logo@2x.png')) . ' 2x"'
    . ' width="660" height="133" alt="' . e($shell->companyName) . '" decoding="async"></span>';
?>
<!doctype html>
<html lang="en" data-theme="<?= e($theme) ?>">
<head>
<?php /* `js/pricing.js` is the CUSTOMER's engine, and it is loaded here on
         purpose. The rates editor prints, beside the box being typed, what the
         customer will actually pay for that kilogram - tax, rounding and the
         standing charges included. Working that out inside admin.js would be a
         second pricing engine in the building, which is the one thing this
         admin has refused from the start. Loading the real one instead costs
         33 KB, deferred, and the number the owner reads comes from the code
         that 940 vectors check against the server's engine. It must load
         BEFORE admin.js, which calls into it. */ ?>
<?= $this->partial('admin/partials/head', [
    'title'   => $title,
    'theme'   => $theme,
    'scripts' => ['js/ui.js', 'js/pricing.js', 'js/admin.js'],
]) ?>
</head>
<body>
<div class="admin-app">
  <aside class="admin-side">
    <a class="brand-link" href="<?= e($this->url(\Speedat\View\Admin\Routes::RATES)) ?>">
      <?= $brandPlate ?>
      <span class="eyebrow">Admin</span>
    </a>
    <?= $this->partial('admin/partials/nav', ['shell' => $shell, 'variant' => 'side']) ?>
    <div class="admin-user">
      <span class="mono admin-email" title="<?= e($shell->userEmail) ?>"><?= e($shell->userEmail) ?></span>
      <span class="tag"><?= e($shell->userRole) ?></span>
      <div class="admin-user-actions">
        <a class="btn outline small" href="<?= e($this->url(\Speedat\View\Admin\Routes::SITE)) ?>">View website &#8599;</a>
        <?= $themeToggle ?>
        <?= $this->partial('admin/partials/signout') ?>
      </div>
    </div>
  </aside>
  <header class="admin-top">
    <div class="admin-top-row">
      <a class="brand-link" href="<?= e($this->url(\Speedat\View\Admin\Routes::RATES)) ?>" aria-label="<?= e($shell->companyName . ' admin') ?>">
        <?= $brandPlate ?>
        <span class="eyebrow">Admin</span>
      </a>
      <div class="admin-top-user">
        <span class="mono admin-email" title="<?= e($shell->userEmail) ?>"><?= e($shell->userEmail) ?></span>
        <?= $themeToggle ?>
        <?= $this->partial('admin/partials/signout') ?>
      </div>
    </div>
    <?= $this->partial('admin/partials/nav', ['shell' => $shell, 'variant' => 'top']) ?>
  </header>
  <main class="admin-main" id="main">
<?= $content ?>
  </main>
</div>
<?php if ($toast !== '') { ?>
<span data-toast="<?= e($toast) ?>" hidden></span>
<?php } ?>
</body>
</html>
