<?php

declare(strict_types=1);

/**
 * The `dl.facts` list on /about and /contact. Four shapes, named by
 * `FactRow::$kind` so this file is four short branches and no decisions:
 * plain text, a link, an address with its "· Google Maps" link, and a run of
 * phone segments (the "Also" row, where the owner's own words stay as typed
 * and every number in them becomes a `tel:` link).
 *
 * @var \Speedat\Core\View       $this
 * @var list<\Speedat\View\FactRow> $facts
 */

use Speedat\View\FactRow;
?>
<dl class="facts">
<?php foreach ($facts as $fact) { ?>
  <div>
    <dt><?= e($fact->label) ?></dt>
    <dd><?php
        if ($fact->kind === FactRow::LINK) {
            echo '<a href="' . e($fact->href) . '"' . ($fact->external ? ' target="_blank" rel="noopener"' : '') . '>' . e($fact->text) . '</a>';
        } elseif ($fact->kind === FactRow::ADDRESS) {
            echo e($fact->text);
            if ($fact->mapHref !== '') {
                echo ' · <a href="' . e($fact->mapHref) . '" target="_blank" rel="noopener">Google Maps</a>';
            }
        } elseif ($fact->kind === FactRow::SEGMENTS) {
            foreach ($fact->segments as $segment) {
                echo $segment->isPhone()
                    ? '<a href="' . e($segment->tel) . '">' . e($segment->text) . '</a>'
                    : e($segment->text);
            }
        } else {
            echo e($fact->text);
        }
    ?></dd>
  </div>
<?php } ?>
</dl>
