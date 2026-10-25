<?php

declare(strict_types=1);

/**
 * Phase-1 placeholder for the quote page.
 *
 * It exists to prove the whole path works: front controller -> router ->
 * container -> view -> layout -> security headers. Phase 4 replaces the body
 * with the calculator; the surrounding machinery stays.
 *
 * @var \Speedat\Core\View $this
 * @var string             $phpVersion
 * @var bool               $dbOk
 */

$this->layout('layouts/base', [
    'title'       => 'Speedat International Courier - Lahore',
    'description' => 'International courier from Lahore. Live rates, honest delivery estimates.',
]);
?>
<section class="page page--home">
  <h1>Hello from the PHP port</h1>
  <p>
    The front controller, router, container, templates and security headers are in place.
    The pricing engine, the site document and the admin arrive in the phases after this one.
  </p>
  <ul class="facts">
    <li>PHP <?= e($phpVersion) ?></li>
    <li>Database: <?= $dbOk ? 'connected' : 'not reachable' ?></li>
  </ul>
</section>
