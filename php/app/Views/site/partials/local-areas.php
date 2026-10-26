<?php

declare(strict_types=1);

/**
 * "Pickup across Lahore" - the local-SEO section.
 *
 * Why it exists: Speedat's customers search locally ("courier in DHA Lahore",
 * "international courier Johar Town", "send parcel abroad from Model Town"),
 * and a local search is matched against the VISIBLE body text of the page, not
 * only its structured data. So the areas the owner serves are named here, in
 * plain readable prose a customer would recognise - which is also genuinely
 * useful: it tells them Speedat comes to their door.
 *
 * Every area named is real (owner confirmed pickup across all of Lahore,
 * 2026-10-01) and reads from `Domain\Seo\LocalAreas`, the same source the
 * LocalBusiness `areaServed` structured data uses, so the two cannot drift -
 * the rule the whole SEO layer keeps: the markup never claims what the page
 * does not show, and nothing is invented.
 *
 * It is a real section a visitor benefits from, not a hidden keyword block:
 * hiding text for crawlers is exactly what search engines penalise.
 *
 * 2026-10-26 - owner: the pickup charge is shown under the quote, so the site
 * must not also say pickup is free. The sentence is therefore chosen by
 * `LocalAreas::pickupSentence()` from the published add-ons: "Free pickup
 * from anywhere..." while pickup costs nothing, "Pickup from anywhere..."
 * once a pickup line is charged (separate, or on). The calculator sits just
 * above this section, so a "free" here would sit a scroll away from the
 * "Pickup charge: PKR 1,000" the quote prints.
 *
 * @var \Speedat\Core\View     $this
 * @var \Speedat\View\HomeView $view
 */

use Speedat\Domain\Seo\LocalAreas;

$wa = $view->page->doc->waHref();
?>
<section class="local-areas" aria-labelledby="local-areas-title">
  <h2 id="local-areas-title">Courier pickup across Lahore</h2>
  <p class="local-areas-lede">
    We collect your parcel from your door anywhere in Lahore and send it worldwide -
    UK, USA, Canada, Australia, UAE, Germany and more. <?= e(LocalAreas::pickupSentence($view->page->doc->settings())) ?>
  </p>
  <ul class="area-chips" aria-label="Lahore pickup areas">
<?php foreach (LocalAreas::chips() as $area) { ?>
    <li><?= e($area) ?></li>
<?php } ?>
  </ul>
<?php if ($wa !== '') { ?>
  <p class="local-areas-cta">
    Not sure if we reach you? <a href="<?= e($wa) ?>" target="_blank" rel="noopener">Ask on WhatsApp</a> -
    we pick up from every part of the city.
  </p>
<?php } ?>
</section>
