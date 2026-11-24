<?php

declare(strict_types=1);

namespace Speedat\Http\Admin;

use Speedat\Core\App;
use Speedat\Core\Audit;
use Speedat\Core\Log;
use Speedat\Core\RateLimit;
use Speedat\Domain\SiteDoc\Diff;
use Speedat\Domain\SiteDoc\PublishArgs;
use Speedat\Domain\SiteDoc\PublishConflict;
use Speedat\Domain\SiteDoc\PublishedVersion;
use Speedat\Domain\SiteDoc\Repo;
use Speedat\Domain\SiteDoc\Validate;

/**
 * Publish website text: the one implementation, behind the marketers' editor
 * and the admin's Website text screen.
 *
 * WHAT GOES LIVE IS THE LIVE DOCUMENT WITH WORDS CHANGED IN IT. Not the draft:
 * the draft is where the owner's unpublished prices are, so publishing it would
 * put them on the website for a spelling mistake. The document is taken from
 * the published version, `PagesForm` changes only the fields on its list, and
 * nothing else in it can differ - the prices, the company details, the popup,
 * the reviews and the "rates are live" tick are the same objects. The draft row
 * is not touched (`PublishArgs::$keepDraft`) and the price hold is not lifted.
 *
 * IT IS A VERSION LIKE ANY OTHER, so it is in the history with the writer's
 * address on it and an owner can roll it back.
 */
final class PagesPublisher
{
    /** Publishes per person per hour. Each one is a whole document in `versions`. */
    private const PUBLISH_LIMIT = 60;

    public static function live(): PublishedVersion
    {
        return Repo::getLatestVersion() ?? PublishedVersion::sample();
    }

    public static function publish(App $app, AdminUser $admin): PagesResult
    {
        RateLimit::enforceKey('pages_publish', $admin->email, self::PUBLISH_LIMIT, 3600);

        $live = self::live();
        $form = PagesForm::of($live, $app->request);

        if ($live->version < 1 || $form->base === null) {
            return new PagesResult($live, $form, refused: Notice::text('pagesbase'), status: 409);
        }

        if ($form->problems !== []) {
            return new PagesResult($live, $form, refused: implode(' ', $form->problems), status: 422);
        }

        if ($form->changed === []) {
            return new PagesResult($live, $form);
        }

        $document = $live->data->withContent($form->content);
        $diff     = Diff::of($live->data, $document);

        // Only what the words got wrong: the rest is live already.
        if (array_diff(Validate::errors($document), Validate::errors($live->data)) !== []) {
            return new PagesResult($live, $form, refused: Notice::text('publish'), status: 422);
        }

        try {
            $published = Repo::publishVersion(new PublishArgs(
                data: $document,
                by: $admin->email,
                source: PublishArgs::PAGES,
                summary: 'Website text: ' . Diff::summarise($diff),
                changeCount: $diff->count,
                // The version this document was built from has to still be the
                // live one, or the prices in it may already be old.
                expectedBase: $live->version,
                keepDraft: true,
            ));
        } catch (PublishConflict $e) {
            Log::warn('pages_publish_conflict', ['live' => $e->live, 'built' => $live->version]);

            return new PagesResult(self::live(), $form, refused: Notice::text('pagesraced'), status: 409);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            Log::exception('pages_publish_failed', $e, ['version' => $live->version + 1]);

            return new PagesResult($live, $form, refused: Notice::text('publish'), status: 503);
        }

        Audit::log($admin->email, 'pages_published', [
            'version' => $published->version,
            'role'    => $admin->role,
            'fields'  => $form->changed,
            'changes' => array_map(static fn ($line): array => $line->toArray(), array_slice($diff->lines, 0, 60)),
        ], $app->request);

        return new PagesResult($published, $form, $published->version);
    }
}
