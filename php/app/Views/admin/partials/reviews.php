<?php

declare(strict_types=1);

/**
 * The customer reviews, in the rates editor. A NEW admin partial (creating one
 * is allowed; editing the existing sections is not).
 *
 * Its fields belong to `form="editor"` - the same form Settings, Website pages
 * and the Popup post through - so adding, editing, hiding and removing reviews
 * is saved, reviewed and published with everything else, in one version. The
 * controls carry the `form` attribute rather than being nested, exactly as the
 * other editor sections are, because HTML forms cannot nest and other sections
 * sit between them.
 *
 * REVIEWS ARE A LIST, so this is not the fixed grid of boxes Settings is. Each
 * stored review gets a row of its own; below them sit a few BLANK rows for
 * adding new ones. The server REBUILDS the list from these rows
 * (`Http\Admin\DraftForm::reviews`), so three kinds of row simply vanish on
 * save: a blank row nobody filled in, a row the owner cleared, and a row whose
 * "Remove this review" box is ticked. Nothing here needs JavaScript - every row
 * is a plain set of inputs and the plain Save button posts them all.
 *
 * CONTENT HONESTY. Nothing is prefilled and nothing is invented: the rows show
 * exactly the reviews the owner has stored, and the public strip shows exactly
 * the ones he leaves switched on. The owner pastes these from his Google
 * Business profile; the site never writes a review, a name or a star for him.
 *
 * CLASSES DEFINED HERE (for the Style step - this file does NOT touch admin.css;
 * `.block .field .chk .hint .btn .eyebrow .desc` are the shared editor classes):
 *   .review-rows        the <ol> of review editors
 *   .review-edit        one review's row of controls (an <li>)
 *   .review-edit-head   the small "Review N" / "New review" label on a row
 *   .review-edit-new    modifier on a blank add row
 *
 * @var \Speedat\Core\View            $this
 * @var \Speedat\View\Admin\RatesPage $page
 */

use Speedat\View\Admin\Field;
use Speedat\View\Admin\Sections;

$draft    = $page->draft;
$readOnly = $page->readOnly;

/** @var list<array{author:string,rating:int,text:string,date:string,show:bool}> $reviews */
$reviews = $draft->reviews();
$count   = count($reviews);

// A few blank slots to add new reviews, never more than the document's own
// ceiling leaves room for. With the ceiling already reached, none are offered.
$blanks = max(0, min(3, \Speedat\Domain\SiteDoc\Schema::MAX_REVIEWS - $count));

/** The star choices, highest first, the way the owner thinks of them. */
$stars = ['5' => '5 stars', '4' => '4 stars', '3' => '3 stars', '2' => '2 stars', '1' => '1 star'];

/**
 * Render one review's controls for index $i. $review is null for a blank add
 * row, which starts five-star and shown so a filled-in row appears by default.
 *
 * @param array{author:string,rating:int,text:string,date:string,show:bool}|null $review
 */
$row = function (int $i, ?array $review) use ($stars, $readOnly): void {
    $author = $review['author'] ?? '';
    $rating = (string) ($review['rating'] ?? 5);
    $text   = $review['text'] ?? '';
    $date   = $review['date'] ?? '';
    $show   = $review['show'] ?? true;

    $fields = [
        Field::text("reviews[$i][author]", 'Name', $author, 'e.g. Ayesha K.', 'The reviewer\'s name, as it appears on Google.', 120, $readOnly),
        Field::select("reviews[$i][rating]", 'Stars', $rating, $stars, '', $readOnly),
        Field::area("reviews[$i][text]", 'Review', $text, 'Paste the review here.', 'The words of the review, as written.', false, 1000, $readOnly),
        Field::text("reviews[$i][date]", 'Date', $date, 'e.g. March 2026', 'Free text - whatever reads well. Leave blank for none.', 60, $readOnly),
        Field::check("reviews[$i][show]", 'Show on site', $show, '1', $readOnly),
    ];

    // Only a STORED review offers Remove; a blank add row has nothing to remove,
    // and is dropped on save anyway if left empty.
    if ($review !== null) {
        $fields[] = Field::check("reviews[$i][remove]", 'Remove this review', false, '1', $readOnly);
    }

    foreach ($fields as $field) {
        echo $this->partial('admin/partials/field', ['field' => $field, 'formId' => 'editor']);
    }
};
?>
<section class="block" id="sec-reviews">
  <p class="eyebrow"><?= e(Sections::no('reviews')) ?> - Reviews</p>
  <h2>Customer reviews</h2>
  <p class="desc">The reviews shown on the home page. Paste in the ones you pick from your Google Business profile, untick <strong>Show on site</strong> to hide one without deleting it, and tick <strong>Remove this review</strong> to delete it. With no review shown, the website prints no reviews section at all. Publishing rates publishes this too.</p>

  <ol class="review-rows">
<?php foreach ($reviews as $i => $review) { ?>
    <li class="review-edit">
      <p class="review-edit-head">Review <?= e($i + 1) ?></p>
<?php $row($i, $review); ?>
    </li>
<?php } ?>
<?php for ($b = 0; $b < $blanks; $b++) { ?>
<?php $i = $count + $b; ?>
    <li class="review-edit review-edit-new">
      <p class="review-edit-head">New review</p>
<?php $row($i, null); ?>
    </li>
<?php } ?>
  </ol>

  <div class="btn-row">
    <button class="btn primary" type="submit" form="editor" data-save-submit<?= $readOnly ? ' disabled' : '' ?>>Save settings and pages</button>
  </div>
</section>
