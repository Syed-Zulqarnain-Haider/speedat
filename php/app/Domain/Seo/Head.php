<?php

declare(strict_types=1);

namespace Speedat\Domain\Seo;

/**
 * Everything one page puts in its head, assembled once.
 *
 * This is the only class a page controller needs:
 *
 *     $seo = Head::forRoute($facts, Url::fromConfig($origin), Routes::byKey('faq'));
 *     return $app->page('pages/faq', ['seo' => $seo, ...]);
 *
 * and the layout prints it with one line:
 *
 *     <?= $this->partial('partials/seo', ['seo' => $seo]) ?>
 *
 * Nothing here renders HTML. `tags()` returns data that the partial escapes,
 * and `jsonLd()` returns a JSON string that is already safe for a script
 * element (see `JsonLd::encode()`). Keeping the assembly here rather than in
 * the template is what makes the whole head assertable in a unit test without
 * a browser or a request.
 */
final class Head
{
    private function __construct(
        public readonly Route $route,
        private readonly Meta $meta,
        private readonly JsonLd $jsonLd,
        private readonly ShareCard $card,
    ) {
    }

    public static function forRoute(SiteFacts $facts, Url $url, Route $route): self
    {
        $card = new ShareCard($facts, $url);

        return new self($route, new Meta($facts, $url, $card), new JsonLd($facts, $url, $card), $card);
    }

    /** Convenience for a page that knows its key rather than its Route. */
    public static function forKey(SiteFacts $facts, Url $url, string $key): self
    {
        return self::forRoute($facts, $url, Routes::byKey($key) ?? Routes::home());
    }

    public function title(): string
    {
        return $this->meta->title($this->route);
    }

    public function description(): string
    {
        return $this->meta->description($this->route);
    }

    public function canonical(): string
    {
        return $this->meta->canonical($this->route);
    }

    /** @return list<Tag> */
    public function tags(): array
    {
        return $this->meta->tags($this->route);
    }

    /** The JSON-LD graph, encoded and safe to print inside a script element. */
    public function jsonLd(): string
    {
        return $this->jsonLd->render($this->route);
    }

    public function shareCard(): ShareCard
    {
        return $this->card;
    }
}
