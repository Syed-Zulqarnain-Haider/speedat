<?php

declare(strict_types=1);

/**
 * "What customers say" - the reviews the owner has chosen to feature.
 *
 * The reviews are REAL ones the owner picks from his Google Business profile and
 * pastes into the admin; the site never invents one, a rating or a count. Each
 * review carries its own "show on site" switch, so this prints only the ones the
 * owner has left switched on (`Reviews::shown()`, which never includes the
 * seed's "Sample review" placeholders), in the order he arranged them.
 *
 * WHEN THERE IS NOTHING TO SHOW, NOTHING IS PRINTED. A fresh site, or one where
 * the owner has hidden every review, renders no section at all - not an empty
 * heading, not a "no reviews yet" line. A testimonials strip with no
 * testimonials is worse than none.
 *
 * The stars are rendered for the eye AND for a screen reader: the glyphs are
 * decorative (`aria-hidden`), and the whole group carries one honest label -
 * "5 out of 5" - so the rating is announced once as a fact rather than read out
 * as five identical symbols. Every value the owner typed is printed through
 * e(); nothing here comes from the request, so there is nothing else to escape.
 *
 * CLASSES DEFINED HERE (for the Style step - this file does NOT touch site.css):
 *   .reviews                section wrapper
 *   .reviews-title          the h2
 *   .reviews-lede           the one-line intro under the heading
 *   .review-list            the <ul> of cards
 *   .review                 one review card (<li>)
 *   .review-stars           the star group (role="img")
 *   .review-star            one star glyph; .is-on / .is-off for filled / empty
 *   .review-text            the quoted review body
 *   .review-byline          the author + date line
 *   .review-author          the reviewer's name
 *   .review-date            the free-text date, when the owner gave one
 *
 * @var \Speedat\Core\View     $this
 * @var \Speedat\View\HomeView $view
 */

if (!isset($view)) {
    return;
}

$reviews = $view->page->doc->data()->reviews->shown();

// Nothing switched on: no heading, no markup, no empty strip.
if ($reviews === []) {
    return;
}

/** The highest a star rating can be, so the empty stars fill out the row. */
$max = \Speedat\Domain\SiteDoc\Review::RATING_MAX;
?>
<section class="reviews" aria-labelledby="reviews-title">
  <h2 id="reviews-title" class="reviews-title">What customers say</h2>
<?php
/*
 * Neutral on purpose. It used to say "Real reviews from customers", which the
 * page could not vouch for - the seed's placeholders printed under it on a
 * fresh site (found 2026-10-25). The owner's own words go in the cards; the
 * lede claims nothing about them.
 */
?>
  <p class="reviews-lede">A few words from people we have shipped for.</p>
  <ul class="review-list" aria-label="Customer reviews">
<?php foreach ($reviews as $review) { ?>
<?php
    $author = trim($review->author);
    $text   = trim($review->text);
    $date   = trim($review->date);
    $rating = $review->rating;
?>
    <li class="review">
      <span class="review-stars" role="img" aria-label="<?= e($rating) ?> out of <?= e($max) ?>">
<?php for ($star = 1; $star <= $max; $star++) { ?>
        <span class="review-star <?= $star <= $rating ? 'is-on' : 'is-off' ?>" aria-hidden="true"><?= $star <= $rating ? '&#9733;' : '&#9734;' ?></span>
<?php } ?>
      </span>
<?php if ($text !== '') { ?>
      <p class="review-text"><?= e($text) ?></p>
<?php } ?>
<?php if ($author !== '' || $date !== '') { ?>
      <p class="review-byline">
<?php if ($author !== '') { ?>
        <span class="review-author"><?= e($author) ?></span>
<?php } ?>
<?php if ($author !== '' && $date !== '') { ?>
        <span class="review-sep" aria-hidden="true"> &middot; </span>
<?php } ?>
<?php if ($date !== '') { ?>
        <span class="review-date"><?= e($date) ?></span>
<?php } ?>
      </p>
<?php } ?>
    </li>
<?php } ?>
  </ul>
</section>
