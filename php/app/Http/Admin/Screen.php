<?php

declare(strict_types=1);

namespace Speedat\Http\Admin;

use Speedat\Core\App;
use Speedat\Domain\Ops\Counts;
use Speedat\Domain\SiteDoc\Live;
use Speedat\View\Admin\Shell;

/**
 * The frame every signed-in admin screen renders inside: who is signed in, which
 * of the five links is current, and the two numbers on the badges.
 *
 * THE NUMBERS COME FROM `Domain\Ops\Counts` AND NOWHERE ELSE. That class exists
 * because the Next.js admin counted the same things three different ways - one
 * badge counted the first fifteen rows and called it the total - and it is the
 * class the metrics endpoint and the cron read too. A badge that disagrees with
 * the page it links to is worse than no badge, so this file asks the same
 * question the same way and every screen gets the answer from here.
 *
 * WHICH NUMBER EACH BADGE SHOWS, and why they are different questions:
 *
 *   Inbox      leads still marked "new" - work waiting for somebody.
 *   Shipments  shipments needing attention, NOT the open ones. Open is the
 *              normal state of a courier's ledger and a permanent badge showing
 *              "14" teaches the office to ignore it; `exception` is the count
 *              that means somebody has to do something today. The open count is
 *              printed as text on the page beside it.
 *
 * Counts is memoised per request (one query per number, not one per reader), and
 * a fresh instance per request rather than a shared one, because a long-lived
 * one would go stale in silence.
 */
final class Screen
{
    private static ?Counts $counts = null;

    /** The admin frame for one page. */
    public static function shell(AdminUser $admin, string $current, bool $sections = false): Shell
    {
        $counts = self::counts();

        return new Shell(
            companyName: self::companyName(),
            userEmail: $admin->email,
            userRole: $admin->role,
            current: $current,
            badges: [
                'inbox'     => $counts->leadsNew(),
                'shipments' => $counts->shipmentsException(),
            ],
            showSections: $sections,
        );
    }

    /** The numbers, asked once per request. */
    public static function counts(): Counts
    {
        return self::$counts ??= Counts::now();
    }

    /** Drop the memo. Tests and long-running CLI jobs only. */
    public static function forget(): void
    {
        self::$counts = null;
    }

    /**
     * The company's name as the published document spells it, with the product's
     * own name as the floor - an admin header that says nothing at all is worse
     * than one that says the default.
     */
    public static function companyName(): string
    {
        $name = Live::site()->data->company->name;

        return $name !== '' ? $name : 'Speedat International Courier';
    }

    /** The theme the owner last chose, read from the cookie on the server. */
    public static function theme(App $app): string
    {
        return $app->request->cookie('theme') === 'dark' ? 'dark' : 'light';
    }
}
