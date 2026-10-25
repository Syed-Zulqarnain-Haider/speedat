<?php

declare(strict_types=1);

namespace Speedat\Http\Admin;

use Speedat\Core\App;
use Speedat\Core\Config;
use Speedat\Core\Log;
use Speedat\Core\Response;
use Speedat\Core\Str;
use Speedat\Domain\Ops\BearerToken;
use Speedat\Domain\Ops\Cron;
use Speedat\Domain\Ops\Metrics;
use Speedat\Domain\Ops\OpsResponse;

/**
 * The three URLs no human visits: the metrics scrape, the scheduled job's HTTP
 * door, and the OPcache reset a deploy calls.
 *
 * EVERY ONE OF THEM IS A SHARED SECRET COMPARED WITH hash_equals, AND AN UNSET
 * SECRET REFUSES EVERYBODY. `Domain\Ops\BearerToken` is the comparison - one
 * function, constant time, and it answers false for an empty configured token,
 * because an endpoint that opens itself when it is misconfigured is a button
 * anyone on the internet may press. That rule is worth more here than anywhere
 * else on the site: the cron door does housekeeping and sends mail, and the
 * OPcache reset can be used to make a host recompile every file on demand.
 *
 * THE TOKEN COMES FROM `Authorization: Bearer`, AND FOR THE TWO METRICS
 * ENDPOINTS IT COMES FROM NOTHING ELSE. That is a change. `/api/metrics` used
 * to accept `?token=` and, on a default Apache, to accept ONLY that: mod_php
 * builds `$_SERVER` from Apache's `subprocess_env`, `ap_add_common_vars()`
 * withholds `Authorization` from it, and the header therefore never reached
 * PHP - so the endpoint refused every correct bearer token and answered 200 to
 * the same secret typed into a URL. `Core\Request::authorizationHeader()` and
 * the `SetEnvIf` block in `public/.htaccess` carry the measurement and the two
 * halves of that fix.
 *
 * WHY THE QUERY FORM HAD TO GO FROM THAT ONE. A metrics scrape REPEATS - every
 * fifteen seconds, for years - so `?token=` there is not one line in one log.
 * It is the secret written continuously into the host's access log, into the
 * browser history of anybody who tries it by hand, and into the `Referer` of
 * anything that page ever links to. Every scraper ever written can set a
 * header, so there was nothing to trade away. The detailed `/health` answers
 * with the same secret and so takes the same header, for the same reason.
 *
 * WHY `/api/cron` AND `/ops/opcache-reset` STILL TAKE IT. They are one-shot
 * operator calls from two places that genuinely cannot set a header: hPanel's
 * cron field and Hostinger's own tooling. Each use is a single line in one log,
 * under the operator's own hand, and taking it away would remove the no-shell
 * host's only maintenance path to fix a smaller problem than that would cause.
 * Every such call is recorded as `ops_token_in_url`, so the exposure is visible
 * rather than assumed. `bin/cron.php` remains how the hourly job actually runs:
 * CLI only, above public_html, and needing no token at all.
 *
 * NOTHING HERE IS EVER CACHED. `OpsResponse` marks each answer no-store, and a
 * cached health or metrics answer is a lie with a timestamp on it.
 */
final class OpsController
{
    /**
     * GET /api/metrics - the Prometheus scrape. Header only; see the class note.
     */
    public function metrics(App $app): Response
    {
        return $this->send(Metrics::fromConfig()->respond($this->headerOnly($app)));
    }

    /**
     * GET /api/cron - the scheduled job, for a plan that can only fetch a URL.
     *
     * It runs the same `Domain\Ops\Cron::run()` that `bin/cron.php` runs - the
     * stale-rates and long-hold checks, the day's numbers, and the
     * housekeeping, log pruning included - so a host with no shell still gets
     * all of its maintenance. (Until 2026-10-25 this sentence said "exactly what
     * the hourly CLI job does" while the CLI job ran a shorter list of its own;
     * both doors now go through the one method.) Every job inside it is
     * wrapped: one that fails is recorded and the rest still run.
     */
    public function cron(App $app): Response
    {
        return $this->send(Cron::fromConfig()->respond($this->authorization($app, 'cron')));
    }

    /**
     * GET /ops/opcache-reset - drop the compiled bytecode after a deploy.
     *
     * Hostinger keeps OPcache on with a file-timestamp check that a
     * same-second upload can lose, which shows up as half the old release
     * running after a deploy. This is the documented way out
     * (docs/deploy-hostinger.md §14), and it is behind the cron secret.
     */
    public function opcacheReset(App $app): Response
    {
        if (!BearerToken::matches(Config::str('tokens.cron'), $this->authorization($app, 'cron'))) {
            return $this->send(OpsResponse::unauthorized('Bad or missing token'));
        }

        $available = function_exists('opcache_reset');
        $reset     = $available && opcache_reset() === true;

        Log::info('opcache_reset', ['available' => $available, 'reset' => $reset]);

        return $this->send(OpsResponse::json([
            'ok'        => $reset,
            'available' => $available,
            // Said plainly: a host with OPcache switched off is not broken, and
            // a deploy script should not treat it as a failure.
            'note'      => $available
                ? ($reset ? 'the compiled bytecode was dropped' : 'OPcache refused the reset')
                : 'OPcache is not enabled on this host',
        ], $available ? 200 : 501));
    }

    /**
     * The secret this request carries, for an endpoint that will take it from
     * the header and nowhere else.
     *
     * Public, because `app/routes.php` has to ask the same question for the
     * detailed `/health` - which is guarded by the same `tokens.metrics` secret,
     * and would otherwise be a second doorway with the old lock on it.
     *
     * A `?token=` on these paths is not quietly ignored: it is recorded,
     * because the operator or the scraper that sent one has the secret in a log
     * somewhere and needs to hear about it, and because a 401 with no
     * explanation anywhere is how an upgrade becomes an outage nobody can
     * diagnose.
     *
     * IT IS ONLY RECORDED WHEN THE TOKEN IS THE RIGHT ONE, and that is the
     * difference between a useful line and a way to fill the disk. This
     * endpoint is unauthenticated and on the public internet; logging every
     * `?token=anything` would let a stranger write to `storage/logs` as fast as
     * they can send requests, which on a shared plan with a disk quota is the
     * site going down. A caller who has the real secret and is using the old
     * shape is the one case worth a line, and nobody can trigger it without
     * already knowing the secret.
     */
    public static function headerOnly(App $app): string
    {
        $header = $app->request->header('authorization');

        if ($header === '' && self::wasTheRealSecret($app->request->query('token', '', 200))) {
            Log::warn('ops_query_token_refused', [
                'path' => $app->request->path,
                'why'  => 'this endpoint takes Authorization: Bearer only',
            ]);
        }

        return $header;
    }

    /**
     * Is this query parameter one of the two configured operational secrets?
     *
     * Both, because `Domain\Ops\Metrics` falls back from `tokens.metrics` to
     * `tokens.cron` - a deployment that set only the cron secret still gets its
     * metrics, and an operator using that secret in the old shape deserves the
     * same line as anyone else. `Str::secureEquals` answers false for an unset
     * configured value, so a site with no secrets logs nothing at all.
     */
    private static function wasTheRealSecret(string $supplied): bool
    {
        if ($supplied === '') {
            return false;
        }

        return Str::secureEquals(Config::str('tokens.metrics'), $supplied)
            || Str::secureEquals(Config::str('tokens.cron'), $supplied);
    }

    /**
     * The secret this request carries, as a bearer string, for the two
     * operator-driven endpoints that still accept a URL.
     *
     * A real `Authorization` header wins. A `?token=` is turned into one so that
     * `BearerToken` stays the only comparison in the application - one place
     * that is constant-time, one place that refuses an empty configured value.
     */
    private function authorization(App $app, string $which): string
    {
        $header = $app->request->header('authorization');

        if ($header !== '') {
            return $header;
        }

        $query = $app->request->query('token', '', 200);

        if ($query === '') {
            return '';
        }

        Log::info('ops_token_in_url', ['endpoint' => $which]);

        return 'Bearer ' . $query;
    }

    /** An operational answer as an HTTP response, headers and all. */
    private function send(OpsResponse $ops): Response
    {
        $response = Response::text($ops->body, $ops->status);

        foreach ($ops->headers as $name => $value) {
            $response->header($name, $value);
        }

        return $response;
    }
}
