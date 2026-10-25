<?php

declare(strict_types=1);

namespace Speedat\Domain\Seo;

/**
 * /robots.txt - everything open except the back office and the machine
 * endpoints, with the sitemap pointed at.
 *
 * The disallow list is `Routes::DISALLOWED`, the same constant the sitemap
 * filters on, so the two files cannot drift: if a prefix is hidden from
 * crawlers it is also absent from the sitemap, and `RobotsTest` and
 * `SitemapTest` assert both halves.
 *
 * robots.txt IS NOT A SECURITY CONTROL and this file is not treated as one.
 * It is a published list of the paths worth attacking, which is exactly why
 * the only two entries are the two prefixes that are already obvious from any
 * link on the site, and why the admin is protected by a server-side session
 * check on every route rather than by this. The `Disallow` lines are here to
 * keep an admin login form out of the index, not to keep anyone out of it.
 */
final class Robots
{
    public const CONTENT_TYPE = 'text/plain; charset=UTF-8';

    /**
     * The AI assistants and answer engines this site names EXPLICITLY so it can
     * be read and cited when someone asks one of them for a courier from
     * Pakistan or the cheapest price to a country. The owner wants to be found
     * from an assistant, not withheld from one, so every agent here is ALLOWED
     * the public site (below), never blocked.
     *
     * - OpenAI: GPTBot (crawls for training), OAI-SearchBot (powers ChatGPT
     *   search results), ChatGPT-User (fetches a page a user asked about).
     * - Anthropic: ClaudeBot (crawls), Claude-User (fetches on a user's
     *   request), Claude-SearchBot (search index).
     * - Perplexity: PerplexityBot (index), Perplexity-User (user-driven fetch).
     * - Google-Extended and Applebot-Extended: the opt-in tokens that let
     *   Google's and Apple's assistants use the site's content; naming them here
     *   with an Allow is how a site says yes.
     *
     * A group may list many `User-agent` lines before one shared rule block,
     * and a crawler obeys the MOST SPECIFIC group that names it - so each agent
     * here uses this group, not the `*` group, which is why the same two
     * machine/admin prefixes are repeated for it.
     */
    public const AI_AGENTS = [
        'GPTBot',
        'OAI-SearchBot',
        'ChatGPT-User',
        'ClaudeBot',
        'Claude-User',
        'Claude-SearchBot',
        'PerplexityBot',
        'Perplexity-User',
        'Google-Extended',
        'Applebot-Extended',
    ];

    public function __construct(
        private readonly Url $url,
    ) {
    }

    public function document(): TextDocument
    {
        return new TextDocument($this->text(), self::CONTENT_TYPE);
    }

    public function text(): string
    {
        $lines = [
            'User-agent: *',
            'Allow: /',
        ];

        foreach (Routes::DISALLOWED as $prefix) {
            $lines[] = 'Disallow: ' . $prefix;
        }

        // The named AI crawlers, welcomed onto the public site and kept out of
        // the same two prefixes as everyone else. robots.txt is not a security
        // control (the admin is guarded server-side on every route); these
        // Disallow lines only keep a login form and the machine endpoints out
        // of an assistant's index, they do not keep anyone out of them.
        $lines[] = '';
        foreach (self::AI_AGENTS as $agent) {
            $lines[] = 'User-agent: ' . $agent;
        }
        $lines[] = 'Allow: /';
        foreach (Routes::DISALLOWED as $prefix) {
            $lines[] = 'Disallow: ' . $prefix;
        }

        $lines[] = '';
        $lines[] = 'Sitemap: ' . $this->url->absolute('/sitemap.xml');
        $lines[] = '';

        return implode("\n", $lines);
    }
}
