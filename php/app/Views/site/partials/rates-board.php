<?php

declare(strict_types=1);

/**
 * The rates board: every live destination as one row - flag, name, the 1 kg
 * price and days per service - like a departures board. Port of
 * `src/components/site/home/RatesBoard.tsx`, without its Documents column: the
 * flat document rate left the product on 2026-09-27 (owner), and papers are
 * priced by weight in the columns that are already here.
 *
 * A reference, not a control: nothing here is tappable, and there is no
 * script behind it. One header row at every width; on a phone the row header
 * takes a line of its own and the cells line up under the service names
 * (site.css HOME), so no label is repeated per cell.
 *
 * UNDER HOLD every `price` is null, so only the days print. The caption says so.
 * Nothing here hides a price with CSS - there is no price to hide, because the
 * view model never asked the engine for one.
 *
 * @var \Speedat\Core\View      $this
 * @var \Speedat\View\BoardView $board
 */

use Speedat\Core\Money;

if ($board->isEmpty()) {
    return;
}
?>
<table class="rates">
  <caption><strong><?= e($board->title) ?></strong> <span><?= e($board->note) ?></span></caption>
  <thead>
    <tr>
      <th scope="col">Country</th>
<?php foreach ($board->services as $service) { ?>
      <th scope="col"><?= e($service->name) ?></th>
<?php } ?>
    </tr>
  </thead>
  <tbody>
<?php foreach ($board->rows as $row) { ?>
    <tr>
      <th scope="row"><?= $this->partial('site/partials/flag', ['flag' => $row->flag]) ?><span><?= e($row->name) ?></span></th>
<?php foreach ($row->cells as $cell) { ?>
      <td><?php if ($cell->price !== null) { ?><b><?= e(Money::fmtMoney($cell->price, $board->currency)) ?></b><?php } ?><?php if ($cell->days !== null) { ?><span><?= e($cell->days) ?> days</span><?php } ?></td>
<?php } ?>
    </tr>
<?php } ?>
  </tbody>
</table>
