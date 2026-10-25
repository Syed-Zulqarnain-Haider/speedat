<?php

declare(strict_types=1);

namespace Speedat\Http\Admin;

use Speedat\Core\App;
use Speedat\Core\Audit;
use Speedat\Core\HttpError;
use Speedat\Core\Log;
use Speedat\Core\Response;
use Speedat\Core\Str;
use Speedat\Domain\SiteDoc\Hold;
use Speedat\Domain\SiteDoc\HoldState;
use Speedat\View\Admin\Routes;

/**
 * The price hold: one switch that takes every price off the website at once,
 * without publishing anything.
 *
 * THE TWO DIRECTIONS ARE NOT THE SAME TRUST. Any admin may switch it ON - that
 * is the safe direction, and the one somebody needs at 9pm when a carrier's
 * rates change under them. Only an owner may resume prices, which is the same
 * trust as publishing, so the button is not printed for anybody else AND the
 * role is re-checked here. One POST route, two rules, because the form is one
 * form with `on=1` or `on=0` in it.
 *
 * THE CUSTOMER SEES IT IMMEDIATELY. `Domain\SiteDoc\Hold::set()` busts the live
 * cache itself, and nothing here wraps it in a transaction - a cache busted
 * inside an open transaction would be refilled with the old answer by any request
 * that arrived before the commit. That is the whole reason this route does not do
 * anything clever: it calls one domain method and redirects.
 *
 * THE DRAFT IS NOT TOUCHED, in either direction. Holding prices must never push
 * a half-keyed draft, and resuming them must never need a diff.
 */
final class HoldController
{
    /**
     * The longest posted message this will look at.
     *
     * `HoldState::cleanMessage()` collapses and cuts to 300 UTF-16 units; four
     * times that is the same allowance the TypeScript action made before
     * refusing outright, and it exists so that a paste of an entire email is
     * told to shorten itself rather than silently losing its end.
     */
    private const MESSAGE_POSTED_MAX = HoldState::MESSAGE_MAX * 4;

    /** POST /admin/hold */
    public function set(App $app, AdminUser $admin): Response
    {
        $on = $app->request->postBool('on');

        if (!$on && !$admin->isOwner()) {
            Log::warn('hold_resume_refused', ['email' => Str::maskEmail($admin->email)]);

            throw HttpError::forbidden('Only an owner can show prices again.');
        }

        $posted = $app->request->post('message', '', self::MESSAGE_POSTED_MAX + 1);

        if (mb_strlen($posted) > self::MESSAGE_POSTED_MAX) {
            return $this->back($app, ['err' => 'toolong']);
        }

        $before = Hold::get();

        // A null message keeps the sentence already stored: resuming prices must
        // not wipe the words the owner wrote for the next time.
        $hold = Hold::set($on, $admin->email, $posted === '' ? null : $posted);

        Audit::log($admin->email, $on ? 'hold_on' : 'hold_off', [
            'message' => $hold->message,
            'was'     => $before->on,
        ], $app->request);

        return $this->back($app, $on ? ['held' => true] : ['resumedprices' => true]);
    }

    /** @param array<string,int|string|bool> $codes */
    private function back(App $app, array $codes): Response
    {
        return Response::redirect($app->request->basePath . Notice::url(Routes::RATES, $codes), 303)->noStore();
    }
}
