<?php

declare(strict_types=1);

/**
 * The three hidden fields every form that writes the draft carries.
 *
 * They are one partial and not three copies because there are now many such
 * forms - one per country, one for the table's own buttons, one for Settings
 * and Website pages - and a form that is missing one of these fails in a way
 * nobody sees until it matters:
 *
 *   CSRF      the token, without which the write is refused with 403.
 *   revision  the fingerprint of the draft row THIS SCREEN WAS DRAWN FROM.
 *             `Repo::saveDraft()` refuses when the stored row no longer has
 *             it, which is what stops two admins sharing one draft from
 *             taking turns overwriting each other in silence. admin.js
 *             rewrites every one of these after an autosave, because the
 *             fingerprint changes with each write and a page that kept
 *             posting the one it was rendered with would have its second
 *             autosave refused by its own first.
 *   expectedBase  the published version the editor was OPENED at, carried
 *             unchanged through every save and every redirect so that
 *             publishing on top of somebody else's newer version is actually
 *             refused. A form that prints the CURRENT live version instead
 *             always agrees with it, and the refusal can never fire.
 *
 * @var \Speedat\Core\View $this
 * @var \Speedat\Core\Csrf $csrf
 * @var string             $revision
 * @var int                $baseVersion
 */

$revision    = isset($revision) && is_string($revision) ? $revision : '';
$baseVersion = isset($baseVersion) && is_int($baseVersion) ? $baseVersion : 0;
?>
<?= $csrf->field() ?>
<input type="hidden" name="revision" value="<?= e($revision) ?>" data-revision>
<input type="hidden" name="expectedBase" value="<?= e($baseVersion) ?>">
