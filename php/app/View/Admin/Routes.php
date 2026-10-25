<?php

declare(strict_types=1);

namespace Speedat\View\Admin;

/**
 * Every URL the admin templates point at, in one place.
 *
 * A template that writes "/admin/rates/save" inline is a URL nobody can
 * rename, and a typo in one is a 404 the owner meets at the worst moment.
 * The router registers these same paths; this class is the list both sides
 * agree on.
 *
 * NOTHING HERE DESCRIBES A SPREADSHEET. The rate sheet import was removed
 * from the product on 2026-09-27, so there is no upload, mapping, preview or
 * intake URL - the owner keys prices into the editor and publishes. The CSV
 * route below is an EXPORT (a GET that hands the owner a file); nothing ever
 * travels the other way.
 */
final class Routes
{
    public const RATES = '/admin';

    /** The autosaving draft form - also the no-JS "Save draft" submit. */
    public const SAVE = '/admin/rates/save';

    public const ADD_DESTINATION    = '/admin/rates/destination/add';
    public const REMOVE_DESTINATION = '/admin/rates/destination/remove';
    public const SORT_DESTINATIONS  = '/admin/rates/sort';
    public const UNDO_ROW           = '/admin/rates/row/undo';

    /** Quick fill without JavaScript: the server writes start + i x step into the blanks. */
    public const FILL = '/admin/rates/fill';

    /** Export only. A GET that streams text/csv; there is no inbound counterpart. */
    public const EXPORT_CSV = '/admin/rates/export.csv';

    public const REVIEW  = '/admin/rates/review';
    public const PUBLISH = '/admin/rates/publish';
    public const DISCARD = '/admin/rates/discard';
    public const RESTORE = '/admin/rates/restore';

    /**
     * Put an already-published version back on the WEBSITE, in one action.
     *
     * RESTORE puts an old version in the editor; this publishes it, which is
     * why it is the owner's alone. Two posts and not one: the first press
     * writes nothing and asks the question, the second carries `confirm=1`.
     * See `Http\Admin\RatesController::rollback()`.
     */
    public const ROLLBACK = '/admin/rates/rollback';

    public const BULK = '/admin/rates/bulk';
    public const TEST = '/admin/rates/test';

    public const HOLD = '/admin/hold';

    public const INBOX        = '/admin/inbox';
    public const INBOX_UPDATE = '/admin/inbox/update';

    public const SHIPMENTS        = '/admin/shipments';
    public const SHIPMENT_NEW     = '/admin/shipments/new';
    public const SHIPMENT_CREATE  = '/admin/shipments/create';
    public const SHIPMENT_UPDATE  = '/admin/shipments/update';
    public const SHIPMENT_EVENT   = '/admin/shipments/event';

    /** The owner's numbers: landings, countries, weight bands, services, WhatsApp. */
    public const ANALYTICS = '/admin/analytics';

    /**
     * The shortcuts above the date boxes, in days.
     *
     * Thirty leads because it is what the screen opens on - a month is the
     * window the owner talks about rates in. Seven is "this week so far", and
     * ninety is the longest range whose daily bars can still be told apart at
     * 375px.
     *
     * @var list<int>
     */
    public const ANALYTICS_PRESETS = [7, 30, 90];

    public const SECURITY      = '/admin/security';
    public const TOTP_BEGIN    = '/admin/security/totp/begin';
    public const TOTP_CONFIRM  = '/admin/security/totp/confirm';
    public const TOTP_DISABLE  = '/admin/security/totp/disable';

    public const LOGIN          = '/admin/login';
    public const LOGIN_PASSWORD = '/admin/login/password';
    public const LOGIN_GOOGLE   = '/admin/login/google';
    public const LOGIN_CODE     = '/admin/login/code';
    public const LOGOUT         = '/admin/logout';

    public const SITE = '/';

    /** The inbox filtered by one status tab. */
    public static function inbox(string $status): string
    {
        return self::INBOX . '?status=' . rawurlencode($status);
    }

    /** The shipments list filtered by one status tab. */
    public static function shipments(string $status): string
    {
        return self::SHIPMENTS . '?status=' . rawurlencode($status);
    }

    /**
     * The analytics screen over the last N days, ending today.
     *
     * `?days=` and not a pair of dates: a preset link the owner can bookmark
     * should mean "the last thirty days" whenever it is opened, not "the thirty
     * days that ended the afternoon I copied the link".
     */
    public static function analytics(int $days): string
    {
        return self::ANALYTICS . '?days=' . $days;
    }

    /** One shipment's desk. The id is a slug the server minted (SH-XXXXXXXX). */
    public static function shipment(string $id): string
    {
        return self::SHIPMENTS . '/' . rawurlencode($id);
    }

    /** "New shipment", carrying the lead it was raised from. */
    public static function shipmentFromLead(int $leadId): string
    {
        return self::SHIPMENT_NEW . '?lead=' . $leadId;
    }

    /**
     * The rates editor with one country's per-kilogram boxes already open.
     * This is the no-JS path for the "Prices" expander: with JavaScript the
     * button folds the row open in place, without it the link reloads the
     * page with the row rendered open.
     */
    public static function openFold(string $destId): string
    {
        return self::RATES . '?open=' . rawurlencode($destId) . '#fold-' . rawurlencode($destId);
    }

    /** The rates editor with the hold dialog rendered open (the no-JS path). */
    public static function holdDialog(): string
    {
        return self::RATES . '?hold=1#hold';
    }

    /**
     * The editor with the roll-back question on it, for one version.
     *
     * The question is a GET the owner can leave and come back to; the act is
     * the confirming POST. Nothing is written by asking.
     */
    public static function rollbackAsk(int $version): string
    {
        return self::RATES . '?rollback=' . $version . '#sec-history';
    }
}
