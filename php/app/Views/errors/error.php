<?php

declare(strict_types=1);

/**
 * The only error page. It shows the status, one sentence a visitor can act on,
 * and a way back. It never shows an exception, a query, a file path or a stack
 * trace - all of that is in storage/logs, where it belongs.
 *
 * @var \Speedat\Core\View $this
 * @var int                $status
 * @var string             $message
 * @var string             $title
 */

$this->layout('layouts/base', ['title' => $title . ' - Speedat International']);
?>
<section class="page page--error">
  <p class="error__status"><?= e($status) ?></p>
  <h1 class="error__title"><?= e($title) ?></h1>
  <p class="error__message"><?= e($message) ?></p>
  <p class="error__actions">
    <a class="button" href="<?= e($this->url('/')) ?>">Back to the quote page</a>
  </p>
</section>
