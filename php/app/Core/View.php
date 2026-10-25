<?php

declare(strict_types=1);

namespace Speedat\Core;

/**
 * Plain PHP templates. No template language, because the one thing a template
 * language buys - escaping by default - is bought here instead by the rule
 * that every value printed in a template goes through e().
 *
 * A template is a .php file under app/Views. It may pick a layout with
 * $this->layout('layouts/base'); whatever it printed then arrives in the
 * layout as $content.
 *
 * The CSP nonce lives here because it is the only per-request value an inline
 * <script> or <style> tag needs: <script <?= $this->nonceAttr() ?>>.
 */
final class View
{
    /** @var array<string,mixed> values available to every template */
    private array $shared = [];

    private ?string $layout = null;

    /** @var array<string,mixed> */
    private array $layoutData = [];

    public function __construct(
        private readonly string $viewsDir,
        private readonly string $nonce,
    ) {
    }

    /** @param array<string,mixed> $values */
    public function share(array $values): void
    {
        $this->shared = $values + $this->shared;
    }

    public function nonce(): string
    {
        return $this->nonce;
    }

    /** `nonce="..."` for an inline script or style tag. */
    public function nonceAttr(): string
    {
        return 'nonce="' . htmlspecialchars($this->nonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
    }

    /** Escape for HTML text and attribute values. The default for everything. */
    public function e(mixed $value): string
    {
        return htmlspecialchars(self::stringify($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * A URL under the application root, correct whether the site is at the
     * domain root (Hostinger) or in a subdirectory (a staging copy).
     */
    public function url(string $path = '/'): string
    {
        $base = (string) ($this->shared['basePath'] ?? '');

        return $base . '/' . ltrim($path, '/');
    }

    /**
     * The asset file on disk that the BROWSER actually downloads.
     *
     * This reads the web root the server is serving (`DOCUMENT_ROOT`), NOT
     * `Paths::publicDir()`. On a host where the two are the same folder - the
     * local dev server, a subdirectory staging copy - it makes no difference.
     * On Hostinger they are DIFFERENT physical folders: the web root is
     * `public_html`, and `Paths::publicDir()` is the app's own bundled copy at
     * `speedat_main/php/public`. Only `public_html` is served, and only
     * `public_html` gets updated on a deploy. Versioning and existence read
     * from the served copy so that updating it is enough - a deploy no longer
     * has to touch a second, hidden mirror, and the failure that once stamped a
     * stale `?v=` onto fresh CSS (leaving returning visitors on the old cache
     * forever, and new files like the popup script unreferenced) cannot happen.
     *
     * `DOCUMENT_ROOT` is set by the web server, not by the client, so it is
     * safe to build a path from; the asset name is the app's own constant, not
     * user input, so there is no traversal. When it is unset (CLI, cron, the
     * test suite) or does not carry the asset, this falls back to the bundled
     * copy, which is exactly the old behaviour.
     */
    private function assetPath(string $relative): string
    {
        $docRoot = str_replace('\\', '/', (string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));

        if ($docRoot !== '') {
            $served = rtrim($docRoot, '/') . '/' . $relative;
            if (is_file($served)) {
                return $served;
            }
        }

        return Paths::publicDir() . '/' . $relative;
    }

    /**
     * A static asset URL with a cache-busting suffix taken from the file's
     * modification time, so a CSS change is visible immediately while the
     * .htaccess still caches assets for a week. The mtime is read from the
     * served copy (see `assetPath()`), so bumping the file the browser loads is
     * what bumps the version.
     */
    public function asset(string $path): string
    {
        $relative = 'assets/' . ltrim($path, '/');
        $file     = $this->assetPath($relative);
        $version  = is_file($file) ? (string) filemtime($file) : '0';

        return $this->url($relative) . '?v=' . $version;
    }

    /**
     * Whether a static asset is actually present, checked against the copy the
     * browser downloads (see `assetPath()`): the layout only links what exists,
     * so a page collects no 404s for files that are not there - and a new file
     * added to the served web root is picked up without touching anything else.
     */
    public function assetExists(string $path): bool
    {
        return is_file($this->assetPath('assets/' . ltrim($path, '/')));
    }

    /** Called from inside a template to wrap its output. @param array<string,mixed> $data */
    public function layout(string $template, array $data = []): void
    {
        $this->layout     = $template;
        $this->layoutData = $data;
    }

    /**
     * Render a template to a string.
     *
     * @param  array<string,mixed> $data
     * @throws \RuntimeException   when the template does not exist - a typo in a
     *                             template name must be loud, not a blank page.
     */
    public function render(string $template, array $data = []): string
    {
        $file = $this->resolve($template);

        $this->layout     = null;
        $this->layoutData = [];

        $content = $this->capture($file, $data + $this->shared);

        if ($this->layout !== null) {
            $layout           = $this->layout;
            $layoutData       = $this->layoutData;
            $this->layout     = null;
            $this->layoutData = [];

            $content = $this->capture(
                $this->resolve($layout),
                ['content' => $content] + $layoutData + $data + $this->shared
            );
        }

        return $content;
    }

    /** Render a partial from inside a template, without touching the layout. @param array<string,mixed> $data */
    public function partial(string $template, array $data = []): string
    {
        return $this->capture($this->resolve($template), $data + $this->shared);
    }

    /** @param array<string,mixed> $data */
    private function capture(string $file, array $data): string
    {
        $level = ob_get_level();
        ob_start();

        try {
            (function () use ($file, $data): void {
                // Templates address the view as $this; data arrives as locals.
                extract($data, EXTR_SKIP);
                require $file;
            })->call($this);

            $out = ob_get_clean();

            return $out === false ? '' : $out;
        } catch (\Throwable $e) {
            // Discard the half-rendered page: the front controller will render
            // an error page instead, and a partial page plus an error page
            // concatenated is worse than either.
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $e;
        }
    }

    private function resolve(string $template): string
    {
        if (preg_match('#^[A-Za-z0-9_][A-Za-z0-9_\-/]*$#', $template) !== 1 || str_contains($template, '..')) {
            throw new \RuntimeException('Illegal template name.');
        }

        $file = $this->viewsDir . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('Template not found: ' . $template);
        }

        return $file;
    }

    private static function stringify(mixed $value): string
    {
        return match (true) {
            $value === null            => '',
            is_bool($value)            => $value ? '1' : '',
            is_scalar($value)          => (string) $value,
            $value instanceof \Stringable => (string) $value,
            default                    => '',
        };
    }
}
