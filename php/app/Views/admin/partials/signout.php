<?php

declare(strict_types=1);

/**
 * Sign out. A POST, not a link: signing out deletes the session row, and
 * anything that changes state on the server must not be reachable by a GET
 * that a prefetcher, a crawler or an <img src> can fire.
 *
 * @var \Speedat\Core\View $this
 * @var \Speedat\Core\Csrf $csrf
 */
?>
<form method="post" action="<?= e($this->url(\Speedat\View\Admin\Routes::LOGOUT)) ?>">
  <?= $csrf->field() ?>
  <button class="btn small" type="submit">Sign out</button>
</form>
