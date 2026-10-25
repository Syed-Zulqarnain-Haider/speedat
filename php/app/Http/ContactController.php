<?php

declare(strict_types=1);

namespace Speedat\Http;

use Speedat\Core\App;
use Speedat\Core\Audit;
use Speedat\Core\Config;
use Speedat\Core\Csrf;
use Speedat\Core\Ip;
use Speedat\Core\Log;
use Speedat\Core\RateLimit;
use Speedat\Core\Response;
use Speedat\Core\Str;
use Speedat\Domain\Leads\LeadRepository;
use Speedat\Domain\Notify\Notifier;
use Speedat\Domain\Notify\OfficeMessages;

/**
 * POST /contact - the message form. Port of
 * `src/app/(site)/contact/actions.ts`.
 *
 * ==========================================================================
 * THE GUARDS, IN THE ORDER THEY RUN, AND WHY THAT ORDER
 * ==========================================================================
 *
 *  1. THE BODY ARRIVED AT ALL. A form larger than `post_max_size` is thrown
 *     away whole by PHP before this code runs, and $_POST arrives empty; without
 *     this check that presents as "please tell us your name" on a message the
 *     visitor definitely typed.
 *
 *  2. CSRF. Before the honeypot, deliberately: the honeypot answers "sent" to a
 *     bot, and a security control that can be skipped by filling a hidden field
 *     is not a control. `Core\Csrf` checks the Origin and the signed token.
 *
 *  3. THE HONEYPOT. Bots fill the hidden "website" field; humans never see it.
 *     The answer is a thank-you and nothing is stored - telling a bot it was
 *     caught only teaches whoever wrote it which field to leave alone.
 *
 *  4. THE RENDER TOKEN. `FormToken` proves when the page was drawn: faster than
 *     three seconds was not typed, older than a day is a stale tab.
 *
 *  5. THE FIELDS. Every one validated against an explicit shape and a cap, and
 *     rejected with a sentence - never a 500, never a bare "invalid".
 *
 *  6. THE PER-IP LIMIT. Five messages per quarter of an hour, as
 *     `actions.ts` sets it.
 *
 * Only then is anything written. The order is the TypeScript's, with CSRF added
 * in front of it, because Next.js server actions carry their own origin check
 * and a plain `<form method="post">` does not.
 *
 * ==========================================================================
 * WHY THE SUCCESS PATH REDIRECTS
 * ==========================================================================
 *
 * The React version returned a state object and the page re-rendered in place;
 * a PHP form that answers a POST with HTML leaves the visitor on a URL that
 * re-sends the message every time they reload or press back. So: store, set a
 * one-shot flash, 303 to /contact, and let the GET draw the thank-you card
 * (`SiteController::contact()`). One message per submit, whatever the browser
 * does afterwards.
 *
 * A REJECTED post does NOT redirect - it re-renders with what was typed still
 * in the fields and the reason above them, because carrying a half-filled form
 * through a redirect means putting a customer's message in a cookie.
 */
final class ContactController
{
    /**
     * Five messages per quarter hour per IP. The numbers in actions.ts.
     *
     * NOT `config('limits.contact_per_hour')`: that key names a different
     * window (an hour), and silently reinterpreting "5 per 15 minutes" as
     * "10 per hour" would change a published behaviour to match a name. The two
     * want reconciling deliberately, by whoever owns the limits table.
     */
    private const LIMIT        = 5;
    private const LIMIT_WINDOW = 900;

    /**
     * Input caps.
     *
     * Each is deliberately WIDER than the rule it feeds, so that "too long" is
     * something the visitor is told rather than something that happens to their
     * text silently. `Request::post()` truncates at the cap; a name cut at 120
     * would validate as a legal name that is not the one they typed.
     */
    private const CAP_NAME    = 200;
    private const CAP_PHONE   = 60;
    private const CAP_MESSAGE = 4000;
    private const CAP_DEST    = 80;
    private const CAP_WEIGHT  = 20;

    /** The field rules, from the `Fields` schema in actions.ts. */
    private const MAX_NAME    = 120;
    private const MIN_NAME    = 2;
    private const MAX_MESSAGE = 2000;
    private const MIN_MESSAGE = 5;
    private const MAX_PHONE   = 20;
    private const MAX_DEST_ID = 60;

    /** Every sentence a visitor can be shown, in one place so none is invented twice. */
    private const SAID = [
        'discarded' => 'That message was too large to send in one request, so nothing was received. '
            . 'Please shorten it and try again, or WhatsApp us.',
        'expired'   => 'This form expired. Please reload the page and try again.',
        'stale'     => 'This page has been open for a while. Reload it and send again.',
        'too_fast'  => 'That was quick - please check your message and send it again.',
        'name_min'  => 'Please tell us your name',
        'name_max'  => 'That name is too long',
        'phone'     => 'Enter a phone number we can call or WhatsApp',
        'msg_min'   => 'Tell us what you would like to send',
        'msg_max'   => 'Please keep the message under 2000 characters',
    ];

    public function __construct(
        private readonly SiteController $site = new SiteController(),
        private readonly LeadRepository $leads = new LeadRepository(),
    ) {
    }

    public function submit(App $app): Response
    {
        $request = $app->request;

        // What the visitor typed, echoed back into every rejection so a refused
        // submit never empties the page.
        $values = [
            'name'    => $request->post('name', '', self::CAP_NAME),
            'phone'   => $request->post('phone', '', self::CAP_PHONE),
            'message' => $request->post('message', '', self::CAP_MESSAGE),
            'destId'  => $request->post('destId', '', self::CAP_DEST),
            'weight'  => $request->post('weight', '', self::CAP_WEIGHT),
        ];

        /* 1 - the body arrived at all --------------------------------------- */
        //
        // 400, NOT 413, and the quality bar is the reason it is the narrower of
        // the two: "input caps make a giant payload a 400, not a memory
        // exhaustion". PHP discarded the body before this file ran, so what the
        // application actually knows is not "your message was too long" - it is
        // that a form post arrived of which not one field can be read. That is a
        // bad request. One status for every unreadable body is also one thing to
        // test and one thing for the edge to agree with: `LimitRequestBody` in
        // the shipped .htaccess should be refusing these before PHP is started
        // at all, and this branch is the floor under that rather than the plan.
        if ($request->postWasDiscarded()) {
            Log::warn('contact_body_discarded', ['length' => Str::limit($request->header('content-length'), 20)]);

            return $this->reject($app, [], self::SAID['discarded'], 400);
        }

        /* 2 - CSRF ---------------------------------------------------------- */
        //
        // 403, AND IT USED TO BE 419. The go-live rehearsal measured this exact
        // branch answering 500 Internal Server Error on the installed copy - the
        // page below was right, the sentence on it was right, and only the
        // status line lied, because Apache has no reason phrase for 419 and
        // falls back to the 500 slot. Every expired contact form on the live
        // site would have been counted as an outage. The measurement and the
        // choice of 403 are written out once, in
        // `Core\HttpError::invalidToken()`; this branch answers the same thing
        // that does, so the site has ONE story about a rejected token.
        $csrf = $app->csrf();
        if (!$csrf->originOk($request) || !$csrf->check($request->post(Csrf::FIELD, '', 200))) {
            Log::warn('contact_csrf_rejected', [
                'origin' => Str::limit($request->header('origin'), 100),
                'ip'     => Ip::hash($request->ip()),
            ]);

            return $this->reject($app, $values, self::SAID['expired'], 403);
        }

        /* 3 - the honeypot -------------------------------------------------- */
        if (trim($request->post('website', '', 200)) !== '') {
            Audit::log('anonymous', 'contact_honeypot', [], $request);

            // Pretend success. Nothing is stored and nothing is sent.
            return $this->site->contactPretendSent($app);
        }

        /* 4 - the render token ---------------------------------------------- */
        $token = FormToken::check($request->post('t', '', 200));
        if ($token === FormToken::TOO_FAST) {
            return $this->reject($app, $values, self::SAID['too_fast'], 422);
        }
        if ($token !== FormToken::OK) {
            return $this->reject($app, $values, self::SAID['stale'], 422);
        }

        /* 5 - the fields ---------------------------------------------------- */
        $clean  = array_map(static fn (string $v): string => Str::squish($v), $values);
        $clean['message'] = trim($values['message']);

        // From here on the render token has passed, so the page that comes
        // back carries one the visitor can send with straight away
        // (`SiteController::contactRejected()` says why).
        $errors = self::validate($clean);
        if ($errors !== []) {
            return $this->site->contactRejected($app, $values, $errors, 422, tokenPassed: true);
        }

        /* 6 - the per-IP limit ---------------------------------------------- */
        $ip    = $request->ip();
        $limit = RateLimit::hit('contact', $ip, self::LIMIT, self::LIMIT_WINDOW);
        if (!$limit->ok) {
            Log::warn('contact_rate_limited', ['ip' => Ip::hash($ip), 'count' => $limit->count]);

            return $this->reject(
                $app,
                $values,
                'Too many messages from this connection. Try again in '
                    . (int) ceil($limit->retryAfter / 60) . ' minutes or WhatsApp us.',
                429,
                $limit->retryAfter,
                tokenPassed: true,
            );
        }

        /* the lead ---------------------------------------------------------- */
        $dest    = Page::doc()->card()->activeDestination($clean['destId']);
        $weightG = self::weightGrams($clean['weight']);

        $lead = $this->leads->createMessageLead(
            name: $clean['name'],
            phone: $clean['phone'],
            email: '',
            message: $clean['message'],
            destId: $dest?->id,
            weightG: $weightG,
            ipHash: Ip::hash($ip),
        );

        Audit::log('anonymous', 'contact_message', [
            'leadId' => $lead->id,
            'dest'   => $dest?->name,
        ], $request);

        self::tellTheOffice($clean, $dest?->name, $weightG, $lead->id);

        // Post/redirect/get: the thank-you is a GET, so a reload cannot resend.
        Flash::put($app, 'contact_sent');

        return Response::redirect($app->request->basePath . '/contact');
    }

    /* ------------------------------------------------------------- rejection */

    /**
     * A form-level rejection: the page again, with one sentence above the
     * fields.
     *
     * @param array<string,string> $values
     */
    private function reject(
        App $app,
        array $values,
        string $said,
        int $status,
        int $retryAfter = 0,
        bool $tokenPassed = false,
    ): Response {
        $response = $this->site->contactRejected($app, $values, ['_' => $said], $status, $tokenPassed);

        if ($retryAfter > 0) {
            $response->header('Retry-After', (string) $retryAfter);
        }

        return $response;
    }

    /* ------------------------------------------------------------ validation */

    /**
     * The `Fields` schema from actions.ts, message for message.
     *
     * One error per field, the first that applies, exactly as the TypeScript
     * takes the first zod issue per path.
     *
     * @param  array<string,string> $v already trimmed
     * @return array<string,string> field name => sentence; empty means valid
     */
    private static function validate(array $v): array
    {
        $errors = [];

        $nameLength = mb_strlen($v['name'], 'UTF-8');
        if ($nameLength < self::MIN_NAME) {
            $errors['name'] = self::SAID['name_min'];
        } elseif ($nameLength > self::MAX_NAME) {
            $errors['name'] = self::SAID['name_max'];
        }

        // The same expression actions.ts uses. Its `.max(20)` can only fire on a
        // string this already accepted (the pattern allows a leading "+" plus 20
        // digits), so the length check shares the pattern's sentence rather than
        // inventing a second one for a 21-character phone number.
        if (preg_match('/^\+?[0-9 ()\-]{7,20}$/', $v['phone']) !== 1
            || mb_strlen($v['phone'], 'UTF-8') > self::MAX_PHONE) {
            $errors['phone'] = self::SAID['phone'];
        }

        $messageLength = mb_strlen($v['message'], 'UTF-8');
        if ($messageLength < self::MIN_MESSAGE) {
            $errors['message'] = self::SAID['msg_min'];
        } elseif ($messageLength > self::MAX_MESSAGE) {
            $errors['message'] = self::SAID['msg_max'];
        }

        // destId and weight are optional and are looked up rather than trusted:
        // an unknown id becomes "no destination", which is what the schema's
        // `.optional().default("")` plus the `find()` below it amounts to. An
        // over-long one cannot match anything, so it needs no message of its
        // own - but it must not reach the database either.
        if (mb_strlen($v['destId'], 'UTF-8') > self::MAX_DEST_ID) {
            $errors['destId'] = 'Choose a country from the list.';
        }

        return $errors;
    }

    /**
     * The weight in grams, or null.
     *
     * `is_numeric()` first, because PHP's cast would read "12kg" as 12 while
     * `Number("12kg")` in the TypeScript is NaN - and a weight nobody typed is
     * worse than no weight. Out of range is "no weight given", never a clamped
     * number: the office would read a clamp as a fact.
     */
    private static function weightGrams(string $weight): ?int
    {
        if ($weight === '' || !is_numeric($weight)) {
            return null;
        }

        $kg = (float) $weight;

        if (!is_finite($kg) || $kg <= 0 || $kg >= 100_000) {
            return null;
        }

        return (int) round($kg * 1000);
    }

    /**
     * Tell the office. Never lets a notification failure lose a lead: the
     * message is already stored, and `Notifier::office()` is documented never to
     * throw - this catches anyway, because `fromConfig()` reads configuration
     * and a lead must survive a broken mailbox setting too.
     *
     * @param array<string,string> $v
     */
    private static function tellTheOffice(array $v, ?string $destName, ?int $weightG, int $leadId): void
    {
        try {
            Notifier::fromConfig()->office(OfficeMessages::newLead(
                name: $v['name'],
                phone: $v['phone'],
                destName: $destName,
                weightG: $weightG,
                message: $v['message'],
                leadId: $leadId,
                baseUrl: rtrim(Config::str('app.url'), '/'),
            ));
        } catch (\Throwable $e) {
            Log::exception('contact_notify_failed', $e, ['leadId' => $leadId]);
        }
    }
}
