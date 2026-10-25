<?php

declare(strict_types=1);

namespace Speedat\Core;

use Speedat\Domain\Analytics\PageViews;

/**
 * Starting the application, for the web and for the command line.
 *
 * Everything that has to be true before the first line of application code
 * runs is here: error reporting that cannot print to the browser, a time zone
 * that is set rather than assumed, paths, configuration, and handlers that
 * turn any error - including the fatals PHP will not let you catch - into a
 * logged line and a plain page.
 */
final class Bootstrap
{
    private static bool $handlersRegistered = false;

    /** Root of the php/ tree, remembered for the shutdown handler. */
    private static string $root = '';

    /**
     * Web entry. Returns the container; public/index.php calls run() with it.
     */
    public static function web(string $root): App
    {
        self::common($root);

        $request = Request::fromGlobals();

        // Start the log line for this request before anything can fail.
        Log::info('request', [
            'method' => $request->method,
            'path'   => $request->path,
            'ip'     => Ip::hash($request->ip()),
        ]);

        /*
         * COUNT THE LANDING - on the way out, once the answer is known.
         *
         * It used to happen right here, before routing, on the request alone,
         * and so it counted things that were not pages: the 301 from
         * `/services/` and then `/services` itself (one visit, two landings),
         * every 404 a scanner with a browser User-Agent asked for, and so on
         * (PageViews::countable() has the whole list, 2026-10-25). Only the
         * response can say "this was a page somebody read", and the response
         * is built by `Http\Kernel::run()`, which public/index.php calls
         * after this method returns - so the count is a shutdown function that
         * reads back what was actually sent: the status PHP reports and the
         * Content-Type that went out. That covers Kernel and the older
         * Bootstrap::run() alike, with no second call site to forget.
         *
         * Registered AFTER common(), so it runs after the fatal-error net:
         * a fatal has already turned the status into a 500 by the time this
         * asks, and a 500 is not counted.
         *
         * Every other decision is PageViews': never an asset, never /admin,
         * never an ops endpoint, never a bot, never a click from one of the
         * site's own pages - and never an exception either. It logs its own
         * failures and swallows them, the way Core\Audit does. A visitor came
         * here to read about courier rates, and must not meet an error page
         * because a number could not be incremented.
         */
        register_shutdown_function(static function () use ($request): void {
            self::countLanding($request);
        });

        return new App($request);
    }

    /**
     * The landing counter's half of a finished web request; see web().
     *
     * http_response_code() is the status Response::send() set (it passes the
     * code as header()'s third argument precisely so this reads back true),
     * and headers_list() is what went out. Both are empty under the CLI, where
     * nothing is counted, which is the right answer there too.
     *
     * The site's own hosts are `app.url`'s and the one this request arrived on:
     * the first is the address the owner publishes, the second is the www/apex
     * or staging name a misconfigured `app.url` would otherwise miss - the same
     * pair Core\Csrf::originOk() accepts, for the same reason.
     */
    private static function countLanding(Request $request): void
    {
        try {
            $status = http_response_code();
            if (!is_int($status)) {
                return;
            }

            $type = '';
            foreach (headers_list() as $line) {
                if (stripos($line, 'content-type:') === 0) {
                    $type = trim(substr($line, strlen('content-type:')));
                }
            }

            if (!PageViews::countable($request->method, $status, $type)) {
                return;
            }

            (new PageViews())->record(
                $request->path,
                $request->userAgent(),
                $request->header('referer'),
                [Config::str('app.url'), $request->origin()],
            );
        } catch (\Throwable $e) {
            // record() already swallows its own failures; this is for anything
            // around it, because an exception thrown out of a shutdown function
            // has nowhere left to go but a blank line in the server log.
            Log::exception('page_view_count_failed', $e, ['path' => $request->path]);
        }
    }

    /**
     * CLI entry for bin/*.php. No request, no session, no headers - and it
     * refuses to run under a web server, because a migration runner reachable
     * over HTTP is a migration runner somebody else can run.
     */
    public static function cli(string $root): void
    {
        if (PHP_SAPI !== 'cli') {
            http_response_code(404);
            exit(1);
        }

        self::common($root);
    }

    /**
     * Route the request, run the handler, send the response. Every failure
     * path ends in a response - there is no way out of this method that leaves
     * the visitor with a blank page.
     */
    public static function run(App $app, Router $router): void
    {
        try {
            $match    = $router->match($app->request);
            $handler  = $match['handler'];
            $response = $handler($app, $match['params']);

            if (!$response instanceof Response) {
                throw new \RuntimeException('Route handler did not return a Response.');
            }
        } catch (HttpError $e) {
            $response = self::errorPage($app, $e->status, $e->getMessage(), $e);
        } catch (\Throwable $e) {
            Log::exception('unhandled_error', $e, ['path' => $app->request->path]);
            $response = self::errorPage($app, 500, 'Something went wrong at our end.', null);
        }

        $app->send($response);
    }

    /**
     * The page a visitor sees when something fails. It never contains a stack
     * trace, a query or a file path: those went to storage/logs, which is
     * where they are useful and where nobody outside can read them.
     */
    public static function errorPage(App $app, int $status, string $message, ?HttpError $error): Response
    {
        if ($error !== null && $error->detail !== []) {
            $level = $status >= 500 ? 'error' : 'warn';
            Log::{$level}('http_error', ['status' => $status, 'path' => $app->request->path] + $error->detail);
        }

        if ($app->request->wantsJson()) {
            $response = Response::json(['error' => ['status' => $status, 'message' => $message]], $status);
        } else {
            try {
                $response = $app->page('errors/error', [
                    'status'  => $status,
                    'message' => $message,
                    'title'   => self::statusTitle($status),
                ], $status);
            } catch (\Throwable $e) {
                // The error page itself is broken. Say so in the log and give
                // the visitor something rather than a white screen.
                Log::exception('error_page_failed', $e);
                $response = Response::text($message . "\n", $status);
            }
        }

        $response->noStore();

        if ($status === 405 && $error !== null && isset($error->detail['allow']) && is_string($error->detail['allow'])) {
            $response->header('Allow', $error->detail['allow']);
        }

        if ($status === 429 && $error !== null && $error->retryAfter() > 0) {
            $response->header('Retry-After', (string) $error->retryAfter());
        }

        return $response;
    }

    /** Settings, paths, configuration and handlers - shared by web and CLI. */
    private static function common(string $root): void
    {
        self::$root = $root;

        // Errors are always reported to the log and never to the output. A
        // notice printed into a JSON response or an HTML head is a bug report
        // published to the internet.
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        ini_set('log_errors', '1');

        // No magic session cookies from PHP; Session owns that (see Session).
        ini_set('session.use_cookies', '0');

        Paths::init($root);
        self::registerHandlers();

        require_once $root . '/app/Core/helpers.php';

        Config::load(Paths::configFile());

        $timezone = Config::str('app.timezone', 'Asia/Karachi');
        date_default_timezone_set($timezone !== '' ? $timezone : 'Asia/Karachi');

        // Debug output is a local-only affordance and is refused outright when
        // the config says production, whatever else it says.
        if (Config::isDebug()) {
            ini_set('display_errors', '1');
        }
    }

    private static function registerHandlers(): void
    {
        if (self::$handlersRegistered) {
            return;
        }
        self::$handlersRegistered = true;

        // Turn warnings and notices into exceptions so they cannot be ignored
        // - an unnoticed warning is how a wrong price ships.
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(static function (\Throwable $e): void {
            Log::exception('uncaught_exception', $e);
            self::lastResort();
        });

        // Fatals (memory, time limit, parse errors in an included file) never
        // reach the exception handler, so they are caught on the way out.
        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            Log::error('fatal_error', [
                'message' => $error['message'],
                'file'    => $error['file'] . ':' . $error['line'],
            ]);

            self::lastResort();
        });
    }

    /**
     * No container, no view, possibly no config: the last thing this process
     * says before it stops.
     *
     * THE TWO SAPIS NEED OPPOSITE THINGS.
     *
     * A browser needs a page and a 500. A cron job needs a NON-ZERO EXIT CODE
     * and a line on stderr: hPanel, `&&` in a shell wrapper and every
     * monitoring tool ever written read the exit status and nothing else.
     * Before this split, an uncaught fatal in bin/cron.php printed an HTML
     * error page onto stdout and exited 0 - a job that had done none of its
     * work reported success, night after night, and the only evidence was a
     * mailed-out page of HTML that nobody reads.
     *
     * exit() from inside a shutdown function does set the process exit code,
     * which is why this can be called from one.
     *
     * tests/Core/BootstrapCliTest.php pins both halves against the real binary,
     * because an exit code cannot be read from inside the process that has it:
     * it runs tests/Core/bootstrap-child.php four ways under `php` (healthy,
     * uncaught exception, warning, memory-exhaustion fatal) and once under
     * `php-cgi`, which reports a SAPI of `cgi-fcgi` and so takes the web branch.
     * The contrast is the thing: under CGI the same uncaught exception yields a
     * 500 and a page and an exit code of ZERO, which is exactly why the CLI
     * branch needs an exit(1) of its own rather than sharing that path.
     */
    private static function lastResort(): void
    {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "speedat: the command failed - see storage/logs/app-*.log for the reason.\n");

            exit(1);
        }

        if (headers_sent()) {
            return;
        }

        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\"><title>Speedat</title></head>"
            . "<body><h1>Something went wrong</h1>"
            . "<p>Please try again in a moment, or call the office.</p></body></html>";
    }

    /**
     * 419 has gone from this list because nothing answers 419 any more: it is
     * unregistered, and Apache puts an unregistered status on the wire as a 500
     * (see `Core\HttpError::invalidToken()`). A rejected form token answers 403,
     * whose title here - "Not allowed" - is the right words for it; the
     * "this form expired" sentence a visitor actually reads is the error's own
     * message and is printed under the title by the template.
     */
    private static function statusTitle(int $status): string
    {
        return match ($status) {
            400     => 'Bad request',
            401     => 'Please sign in',
            403     => 'Not allowed',
            404     => 'Page not found',
            405     => 'Method not allowed',
            429     => 'Too many requests',
            503     => 'Temporarily unavailable',
            default => 'Something went wrong',
        };
    }
}
