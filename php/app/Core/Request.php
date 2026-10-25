<?php

declare(strict_types=1);

namespace Speedat\Core;

/**
 * One immutable snapshot of the incoming request. Nothing else in the
 * application reads $_GET, $_POST, $_SERVER or $_COOKIE - if it did, there
 * would be no single place to add a cap, a trim or a trust decision.
 *
 * The trust decision that matters is the client IP. X-Forwarded-For and
 * CF-Connecting-IP are headers, which means anybody can send one; believing one
 * without a proxy in front would let a single attacker spend everybody else's
 * rate limit. TWO things must therefore be true before either is read:
 *
 *   1. `app.trust_proxy` is explicitly on, and
 *   2. the machine that opened this TCP connection - REMOTE_ADDR, the one thing
 *      the handshake proves - is in `app.proxy_ips`.
 *
 * Condition 2 is the one this class used to be missing, and it is the one that
 * matters. Behind Cloudflare the origin's own address is discoverable, and a
 * request sent straight to it carries whatever `CF-Connecting-IP` the attacker
 * typed - so every per-IP limit became per-attacker-chosen-string, i.e. no limit
 * at all, on the setting whose whole purpose was to make the limits correct.
 * An empty `app.proxy_ips` means "believe nobody", which is what
 * `trust_proxy = false` already meant: turning the flag on without the list
 * changes nothing.
 */
final class Request
{
    /** Longest query string, form field and header we will look at. */
    private const MAX_FIELD = 20000;

    /**
     * Longest forwarding chain we will walk.
     *
     * An X-Forwarded-For is client-supplied on its left-hand side, so its length
     * is an attacker's choice; parsing ten thousand entries to find the one we
     * were going to read anyway is work somebody else asked us to do.
     */
    private const MAX_CHAIN = 20;

    /**
     * @param array<string,mixed>  $query
     * @param array<string,mixed>  $post
     * @param array<string,string> $headers lower-cased names
     * @param array<string,string> $cookies
     * @param array<string,mixed>  $files
     */
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $basePath,
        public readonly array $query,
        public readonly array $post,
        public readonly array $headers,
        public readonly array $cookies,
        public readonly array $files,
        public readonly string $remoteAddr,
        public readonly bool $https,
    ) {
    }

    public static function fromGlobals(): self
    {
        /** @var array<string,mixed> $server */
        $server = $_SERVER;

        $method = strtoupper(is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : 'GET');
        if (preg_match('/^[A-Z]{3,10}$/', $method) !== 1) {
            $method = 'GET';
        }

        $uri = is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/';
        $path = self::pathOf($uri);

        // A subdirectory install (a staging copy under /php/public) has a base
        // path; on Hostinger public_html is the document root and this is "".
        $script   = is_string($server['SCRIPT_NAME'] ?? null) ? $server['SCRIPT_NAME'] : '';
        $basePath = rtrim(str_replace('\\', '/', dirname($script)), '/');
        if ($basePath !== '' && $basePath !== '/' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        $headers = [];
        foreach ($server as $k => $v) {
            if (!is_string($k) || !is_string($v)) {
                continue;
            }
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = self::headerValue($v);
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $k => $name) {
            if (isset($server[$k]) && is_string($server[$k])) {
                $headers[$name] = self::headerValue($server[$k]);
            }
        }

        if (($headers['authorization'] ?? '') === '') {
            $recovered = self::authorizationHeader($server);

            if ($recovered !== '') {
                $headers['authorization'] = self::headerValue($recovered);
            }
        }

        $remote = is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : '';

        // X-Forwarded-Proto is a header like any other: it is only believed from
        // a peer on the proxy list. Believing it from anybody would let a
        // visitor on plain http claim to be on https, and everything that keys
        // off $https - Secure cookies, the origin used for the CSRF check, every
        // absolute URL - would agree with them.
        $https = (isset($server['HTTPS']) && is_string($server['HTTPS']) && strtolower($server['HTTPS']) !== 'off' && $server['HTTPS'] !== '')
            || (isset($server['SERVER_PORT']) && (string) $server['SERVER_PORT'] === '443')
            || (self::believesForwardedHeaders($remote) && strtolower($headers['x-forwarded-proto'] ?? '') === 'https');

        /** @var array<string,mixed> $query */
        $query = $_GET;
        /** @var array<string,mixed> $post */
        $post = $_POST;
        /** @var array<string,string> $cookies */
        $cookies = array_filter($_COOKIE, 'is_string');
        /** @var array<string,mixed> $files */
        $files = $_FILES;

        return new self($method, $path, $basePath, $query, $post, $headers, $cookies, $files, $remote, $https);
    }

    /**
     * The path part of REQUEST_URI, percent-decoded.
     *
     * NOT parse_url(), WHICH READS A DOUBLED SLASH AS A HOST. A REQUEST_URI is
     * a path plus a query, never a whole URL, but parse_url() does not know that:
     * `parse_url('//faq', PHP_URL_PATH)` takes `faq` for the host and returns
     * null, so `https://speedat.co//faq` - one badly joined link away - served
     * the HOME PAGE with a 200 and canonical "/", instead of reaching
     * Http\Kernel::canonicalRedirect() and its promised 301 to `/faq`. Cutting at
     * the first `?` or `#` is all the parsing a request target needs, and it is
     * the same cut Seo\Url::normalise() makes.
     *
     * The one form that is a whole URL - an absolute-form target,
     * `GET http://host/path`, which a server may pass through to a proxy-style
     * request - does not start with a slash, and is the one place parse_url()
     * is still the right tool.
     */
    private static function pathOf(string $uri): string
    {
        if ($uri !== '' && $uri[0] === '/') {
            $raw = substr($uri, 0, strcspn($uri, '?#'));
        } else {
            $raw = parse_url($uri, PHP_URL_PATH);
        }

        return is_string($raw) && $raw !== '' ? rawurldecode($raw) : '/';
    }

    /**
     * One header value: capped, then made well-formed UTF-8. In that order.
     *
     * WHY HEADERS NEED THE SAME SCRUB AS THE BODY. clean() has made every form
     * field readable since audit round 1, finding 4; headers were only cut to
     * length, and two of them go straight into utf8mb4 columns under
     * STRICT_TRANS_TABLES: the User-Agent into sessions.user_agent and
     * audit_log.user_agent. One raw Latin-1 byte (`User-Agent: caf\xE9`) was
     * then a 1366 out of PDO on both writes. Core\Audit swallows its own failure
     * by design, so `login_refused` and everything else that client did never
     * reached the audit screen - a way to guess passwords, or use a stolen
     * cookie, without leaving a line. Core\Session rethrows, so sign-in from
     * that browser was a 500 every time.
     *
     * Capped FIRST, unlike clean(), because the cap here is a byte budget on
     * work rather than a character count anybody sees: scrubbing 20,000 bytes
     * is cheap, scrubbing whatever length a client chose is not. A cut through
     * the middle of a multi-byte character leaves a broken tail, which the
     * scrub then substitutes, so the order costs at most one `?`.
     *
     * Nothing here changes a well-formed header, so an Authorization token or a
     * Content-Type arrives byte for byte as it was sent.
     */
    private static function headerValue(string $value): string
    {
        return mb_scrub(substr($value, 0, self::MAX_FIELD), 'UTF-8');
    }

    /**
     * Build a request by hand. Tests and the CLI entry points.
     *
     * @param array<string,mixed>  $post
     * @param array<string,mixed>  $query
     * @param array<string,string> $headers lower-cased names, as fromGlobals() produces them
     * @param array<string,string> $cookies
     * @param array<string,mixed>  $files   $_FILES entries
     */
    public static function synthetic(
        string $method,
        string $path,
        array $post = [],
        array $query = [],
        array $headers = [],
        array $cookies = [],
        array $files = [],
    ): self {
        return new self(strtoupper($method), $path, '', $query, $post, $headers, $cookies, $files, '127.0.0.1', false);
    }

    /**
     * The `Authorization` header, dug out of the places a server hides it.
     *
     * THE HEADER DOES NOT ARRIVE IN `$_SERVER` ON A DEFAULT APACHE, AND THAT IS
     * NOT A BUG IN THE CALLER. Measured on the staging host (Apache 2.4.68,
     * mod_php 8.2.34, 2026-09-29) with a probe that printed every `$_SERVER` key
     * containing "auth":
     *
     *     $ curl -H "Authorization: Bearer SEEKRET" .../zz-probe.php?auth=1
     *     apache_fn=yes
     *     AR:Authorization=Bearer SEEKRET
     *     END
     *
     * `apache_request_headers()` had it. `$_SERVER` had NOTHING - not
     * `HTTP_AUTHORIZATION`, not anything. mod_php builds `$_SERVER` from
     * Apache's `subprocess_env`, and `ap_add_common_vars()` deliberately leaves
     * `Authorization` and `Proxy-Authorization` out of it unless `CGIPassAuth`
     * is on, so that a CGI script cannot read the credentials of a request it
     * was not authenticating. The consequence for this application was that
     * `/api/metrics` answered **401 to a correct bearer token** and 200 to the
     * same secret in a query string, which is the finding this method exists to
     * close: the query form was not preferred, it was the only one that worked.
     *
     * THREE PLACES ARE READ, in the order they are trustworthy:
     *
     *   1. `REDIRECT_HTTP_AUTHORIZATION`. When `public/.htaccess` puts the
     *      header back with `SetEnvIf`, an internal rewrite to `index.php`
     *      re-exposes it under this name. This is the normal path on a host that
     *      honours the file.
     *   2. `apache_request_headers()`, which reads Apache's own header table
     *      rather than the CGI environment, and therefore has the value even
     *      when nothing in `.htaccess` applied. It exists under mod_php,
     *      LiteSpeed's LSAPI and PHP-FPM (where it is `getallheaders()` by
     *      another name), and not under CLI - hence the `function_exists`.
     *   3. `PHP_AUTH_USER`/`PHP_AUTH_PW`, which is what a SAPI leaves behind
     *      when it has parsed a Basic header for itself and dropped the
     *      original. Rebuilt rather than guessed at.
     *
     * NOTHING HERE IS A DECISION ABOUT WHO MAY GET IN. It recovers a header a
     * server chose not to pass on; every comparison is still
     * `Domain\Ops\BearerToken` against a configured secret, in constant time,
     * refusing an unset one.
     *
     * @param array<string,mixed> $server
     */
    private static function authorizationHeader(array $server): string
    {
        $redirected = $server['REDIRECT_HTTP_AUTHORIZATION'] ?? null;

        if (is_string($redirected) && $redirected !== '') {
            return $redirected;
        }

        if (function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $name => $value) {
                if (is_string($name) && is_string($value) && strcasecmp($name, 'authorization') === 0 && $value !== '') {
                    return $value;
                }
            }
        }

        $user = $server['PHP_AUTH_USER'] ?? null;

        if (is_string($user) && $user !== '') {
            $password = $server['PHP_AUTH_PW'] ?? '';

            return 'Basic ' . base64_encode($user . ':' . (is_string($password) ? $password : ''));
        }

        return '';
    }

    public function isGet(): bool
    {
        return $this->method === 'GET' || $this->method === 'HEAD';
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /** HEAD gets every header the GET would have produced, and no body. */
    public function isHead(): bool
    {
        return $this->method === 'HEAD';
    }

    public function header(string $name, string $default = ''): string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function userAgent(): string
    {
        return Str::limit($this->header('user-agent'), 300);
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    /**
     * The client's address, as well as it can be known.
     *
     * Default, and whenever the immediate peer is not on `app.proxy_ips`: the
     * peer address, which the TCP handshake proves. Nothing else is even read.
     *
     * Behind a configured proxy, in this order:
     *
     *   1. `CF-Connecting-IP`, which Cloudflare sets to the client and which is
     *      the whole client. Preferred over X-Forwarded-For because Cloudflare
     *      APPENDS to XFF, so an XFF arriving from Cloudflare still has the
     *      visitor's own bytes in its left-hand side.
     *   2. The forwarding chain, read FROM THE RIGHT - see clientFromChain().
     *   3. The peer address, if neither says anything usable. A junk header is
     *      not a reason to have no address.
     */
    public function ip(): string
    {
        $peer = $this->remoteAddr !== '' ? $this->remoteAddr : 'unknown';

        if (!self::believesForwardedHeaders($this->remoteAddr)) {
            return $peer;
        }

        $cf = self::bareAddress($this->header('cf-connecting-ip'));
        if ($cf !== null) {
            return $cf;
        }

        return self::clientFromChain($this->header('x-forwarded-for')) ?? $peer;
    }

    /** A query-string value as a trimmed, length-capped, well-formed string. */
    public function query(string $key, string $default = '', int $max = 500): string
    {
        $v = $this->query[$key] ?? null;

        return is_scalar($v) ? self::clean((string) $v, $max) : $default;
    }

    /** A form field as a trimmed, length-capped, well-formed string. */
    public function post(string $key, string $default = '', int $max = 5000): string
    {
        $v = $this->post[$key] ?? null;

        return is_scalar($v) ? self::clean((string) $v, $max) : $default;
    }

    public function postInt(string $key, int $default = 0): int
    {
        $v = $this->post[$key] ?? null;

        return is_numeric($v) ? (int) $v : $default;
    }

    /** An HTML checkbox: present means on, absent means off. */
    public function postBool(string $key): bool
    {
        $v = $this->post[$key] ?? null;

        return $v !== null && $v !== '' && $v !== '0' && $v !== 'off' && $v !== 'false';
    }

    /** @return list<string> */
    public function postList(string $key, int $maxItems = 200): array
    {
        $v = $this->post[$key] ?? null;
        if (!is_array($v)) {
            return [];
        }

        $out = [];
        foreach ($v as $item) {
            if (is_scalar($item)) {
                $out[] = self::clean((string) $item, 500);
            }
            if (count($out) >= $maxItems) {
                break;
            }
        }

        return $out;
    }

    /**
     * Trim, make well-formed UTF-8, then cap. In that order.
     *
     * WHY THE ENCODING STEP IS HERE. utf8mb4 plus STRICT_TRANS_TABLES makes a
     * malformed byte an ERROR out of PDO, which is a 500 page. Twelve bytes of
     * `curl --data-binary` at the public contact form did it:
     * `"Ali\xC3\x28 Khan"` went through post(), through Str::limit(), through
     * Caps::problems() - which reported no problem - and arrived as
     * `SQLSTATE[22007]: 1366 Incorrect string value` (audit round 1, finding 4).
     *
     * This is not a refusal. A broken byte is a broken client, not a person to
     * argue with, so the field is made readable and whatever is left still faces
     * its own validation: an empty name is still refused as an empty name, by the
     * rule that was always going to refuse it. Core\Caps checks the encoding
     * again for anything that reaches a write path from somewhere other than a
     * request, and THAT one refuses with a sentence.
     *
     * mb_convert_encoding from UTF-8 to UTF-8 is the documented way to substitute
     * the malformed sequences; it is not a no-op.
     *
     * The order matters twice. Scrubbing before capping means the cap counts
     * characters that exist, and capping a scrubbed string cannot cut a multi-byte
     * character in half - which would put a malformed byte back at the end and
     * undo the whole exercise.
     */
    private static function clean(string $value, int $max): string
    {
        $value = trim($value);

        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = (string) mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        return Str::limit($value, $max);
    }

    /**
     * One entry of $_FILES, or null.
     *
     * @return array<string,mixed>|null
     */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        // A multiple-file input gives an array for every member; this site has
        // no such input, and treating one as a single file would read the
        // wrong tmp_name.
        if (!is_array($file) || is_array($file['name'] ?? null)) {
            return null;
        }

        /** @var array<string,mixed> $file */
        return $file;
    }

    /**
     * True when PHP threw this POST's body away before the application saw it.
     *
     * A body larger than `post_max_size` is discarded WHOLE: $_POST and $_FILES
     * both arrive empty and there is no upload error code to report, so a
     * handler that only looks at $_FILES sees an empty form and says nothing
     * at all. The one piece of evidence left is the Content-Length the client
     * announced, which is what this looks at. It is deliberately narrow - a
     * form content type AND a declared body - so a JSON route, whose $_POST is
     * legitimately empty, never trips it.
     *
     * Core\Upload::problem() turns this into a sentence; public/.htaccess keeps
     * post_max_size above upload_max_filesize so it should never fire.
     */
    public function postWasDiscarded(): bool
    {
        if ($this->method !== 'POST' || $this->post !== [] || $this->files !== []) {
            return false;
        }

        $type = strtolower($this->header('content-type'));
        $form = str_contains($type, 'multipart/form-data')
            || str_contains($type, 'application/x-www-form-urlencoded');

        return $form && (int) $this->header('content-length') > 0;
    }

    public function wantsJson(): bool
    {
        return str_contains($this->header('accept'), 'application/json')
            || str_contains($this->header('content-type'), 'application/json');
    }

    /**
     * Raw request body, read once and capped. php://input cannot be re-read
     * after php://input has been consumed by the form parser, so JSON routes
     * are the only ones that call this.
     */
    public function body(int $maxBytes = 1048576): string
    {
        $stream = fopen('php://input', 'rb');
        if ($stream === false) {
            return '';
        }

        $body = stream_get_contents($stream, $maxBytes);
        fclose($stream);

        return $body === false ? '' : $body;
    }

    /** @return array<array-key,mixed> */
    public function json(int $maxBytes = 1048576): array
    {
        $decoded = json_decode($this->body($maxBytes), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** Absolute URL of this request's origin, for building links and checking Origin headers. */
    public function origin(): string
    {
        $host = $this->header('host');

        return ($this->https ? 'https://' : 'http://') . ($host !== '' ? $host : 'localhost');
    }

    private static function trustProxy(): bool
    {
        return Config::loaded() && Config::bool('app.trust_proxy', false);
    }

    /**
     * Do we believe this peer's forwarding headers?
     *
     * Both halves, and the second half is the fix: `app.trust_proxy` says "there
     * is a proxy in front", `app.proxy_ips` says WHICH machines that is, and a
     * header is only read when the connection actually came from one of them.
     *
     * `app.proxy_ips` holds addresses or CIDR blocks - Cloudflare's published
     * ranges, or the load balancer. An empty list believes nobody, so a
     * half-finished configuration is a safe one.
     */
    private static function believesForwardedHeaders(string $peer): bool
    {
        return $peer !== '' && self::trustProxy() && self::isTrustedProxy($peer);
    }

    private static function isTrustedProxy(string $ip): bool
    {
        if (!Config::loaded()) {
            return false;
        }

        foreach (Config::arr('app.proxy_ips') as $range) {
            if (is_string($range) && trim($range) !== '' && self::inRange($ip, trim($range))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The client address in an X-Forwarded-For, read FROM THE RIGHT.
     *
     * WHY RIGHT AND NOT LEFT. Every proxy APPENDS the address it received the
     * request from, so the right-most entry is the only one written by our own
     * nearest proxy; everything to its left was written by whoever was further
     * out, and the left-most entry was written by the client itself. Reading the
     * left-most entry - which is what this used to do - means reading a string
     * the visitor chose, behind a proxy or not. Cloudflare appends too, which is
     * why `CF-Connecting-IP` is preferred above and why this is the fallback.
     *
     * Our own proxies are then skipped from the right, so a chain that passes
     * through two of them still resolves to the visitor rather than to the
     * second hop. The first entry that is not one of ours is the client.
     *
     * Junk anywhere in the part we walk returns null rather than a guess: a
     * chain we cannot read is a chain we do not use, and ip() falls back to the
     * peer address, which is always true.
     */
    private static function clientFromChain(string $forwarded): ?string
    {
        if ($forwarded === '') {
            return null;
        }

        $entries = explode(',', $forwarded);
        if (count($entries) > self::MAX_CHAIN) {
            // Keep the right-hand end: that is the end our proxies wrote.
            $entries = array_slice($entries, -self::MAX_CHAIN);
        }

        for ($i = count($entries) - 1; $i >= 0; $i--) {
            $candidate = self::bareAddress($entries[$i]);

            if ($candidate === null) {
                return null;
            }

            if (!self::isTrustedProxy($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * One forwarding-chain entry as a bare IP address, or null.
     *
     * An entry may legitimately carry a port - `1.2.3.4:51000`, and an IPv6
     * address is bracketed when it does: `[2001:db8::1]:51000`. Without the
     * bracket handling every IPv6 entry failed FILTER_VALIDATE_IP and the chain
     * was silently unreadable, which is the quiet half of this finding - an
     * IPv6 visitor behind the proxy shared one counter with every other.
     */
    private static function bareAddress(string $entry): ?string
    {
        $value = trim($entry);
        if ($value === '') {
            return null;
        }

        if ($value[0] === '[') {
            $close = strpos($value, ']');
            $value = $close === false ? '' : substr($value, 1, $close - 1);
        } elseif (substr_count($value, ':') === 1) {
            // Exactly one colon is host:port. Two or more is a bare IPv6.
            $value = substr($value, 0, (int) strpos($value, ':'));
        }

        return filter_var($value, FILTER_VALIDATE_IP) !== false ? $value : null;
    }

    /**
     * Is $ip inside $range, where $range is an address or a CIDR block?
     *
     * Compared as packed bytes so IPv4 and IPv6 use the same code and neither
     * needs string arithmetic. A v4 address is never inside a v6 block and vice
     * versa: the lengths differ and the answer is no.
     */
    private static function inRange(string $ip, string $range): bool
    {
        $slash   = strpos($range, '/');
        $network = $slash === false ? $range : substr($range, 0, $slash);

        $address = self::packed($ip);
        $subject = self::packed($network);

        if ($address === null || $subject === null || strlen($address) !== strlen($subject)) {
            return false;
        }

        $widest = strlen($address) * 8;
        $bits   = $slash === false ? $widest : (int) substr($range, $slash + 1);

        if ($bits < 0 || $bits > $widest) {
            return false;
        }

        $whole = intdiv($bits, 8);
        if ($whole > 0 && substr($address, 0, $whole) !== substr($subject, 0, $whole)) {
            return false;
        }

        $spare = $bits % 8;
        if ($spare === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $spare)) & 0xFF;

        return (ord($address[$whole]) & $mask) === (ord($subject[$whole]) & $mask);
    }

    /**
     * An address as bytes: 4 for IPv4, 16 for IPv6, null for anything else.
     *
     * An IPv4-mapped IPv6 address (`::ffff:127.0.0.1`) is folded back to its
     * four bytes. A dual-stack listener reports REMOTE_ADDR in that form, and a
     * `app.proxy_ips` written in ordinary IPv4 - which is how every provider
     * publishes its ranges - would otherwise never match it.
     */
    private static function packed(string $ip): ?string
    {
        $packed = @inet_pton($ip);
        if (!is_string($packed)) {
            return null;
        }

        if (strlen($packed) === 16 && str_starts_with($packed, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xFF\xFF")) {
            return substr($packed, 12);
        }

        return $packed;
    }
}
