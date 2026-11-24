<?php

declare(strict_types=1);

namespace Speedat\View\Marketing;

use Speedat\View\Admin\ContentFields;
use Speedat\View\Admin\Field;
use Speedat\View\Admin\Site;

/**
 * The marketer's screen, one website page at a time.
 *
 * Which fields exist, and which of them a marketer may write, is
 * `ContentFields::pageFields()`. This class only says which page each belongs
 * to, and a test holds the two to the same list.
 */
final class Tabs
{
    public const DEFAULT = 'home';

    /**
     * key => [label, public path, SEO title field, SEO description field, the other fields]
     *
     * @var array<string,array{label:string,path:string,title:string,desc:string,fields:list<string>}>
     */
    private const ALL = [
        'home' => [
            'label' => 'Home', 'path' => '/', 'title' => 'seoHomeTitle', 'desc' => 'seoHomeDesc',
            'fields' => ['heroTitle', 'heroSub', 'promise', 'stats', 'routesTitle', 'routesNote'],
        ],
        'services' => [
            'label' => 'Services', 'path' => '/services', 'title' => 'seoServicesTitle', 'desc' => 'seoServicesDesc',
            'fields' => ['servicesH1', 'servicesLede', 'services'],
        ],
        'about' => [
            'label' => 'About', 'path' => '/about', 'title' => 'seoAboutTitle', 'desc' => 'seoAboutDesc',
            'fields' => ['aboutH1', 'story', 'mission', 'vision', 'values'],
        ],
        'contact' => [
            'label' => 'Contact', 'path' => '/contact', 'title' => 'seoContactTitle', 'desc' => 'seoContactDesc',
            'fields' => ['contactH1', 'contactLede'],
        ],
        'faq' => [
            'label' => 'FAQ', 'path' => '/faq', 'title' => 'seoFaqTitle', 'desc' => 'seoFaqDesc',
            'fields' => ['faqH1', 'faqLede', 'faq'],
        ],
        'shared' => [
            'label' => 'All pages', 'path' => '', 'title' => '', 'desc' => '',
            'fields' => ['ctaTitle', 'ctaSub'],
        ],
    ];

    public static function key(string $asked): string
    {
        return isset(self::ALL[$asked]) ? $asked : self::DEFAULT;
    }

    /** @return array<string,string> key => label */
    public static function labels(): array
    {
        return array_map(static fn (array $tab): string => $tab['label'], self::ALL);
    }

    public static function label(string $key): string
    {
        return self::ALL[self::key($key)]['label'];
    }

    /** The public page this tab edits, or "" for the one that is on several. */
    public static function path(string $key): string
    {
        return self::ALL[self::key($key)]['path'];
    }

    /** The `Domain\Seo\Routes` key of that page, or "". */
    public static function route(string $key): string
    {
        $key = self::key($key);

        return self::ALL[$key]['path'] === '' ? '' : $key;
    }

    /** @return list<string> every field name on a tab */
    public static function names(string $key): array
    {
        $tab = self::ALL[self::key($key)];

        return array_values(array_filter([$tab['title'], $tab['desc'], ...$tab['fields']], static fn (string $n): bool => $n !== ''));
    }

    /** @return list<Field> the search-result fields of a tab */
    public static function seo(string $key, Site $site): array
    {
        $tab = self::ALL[self::key($key)];

        return self::pick([$tab['title'], $tab['desc']], $site);
    }

    /** @return list<Field> the words on the page */
    public static function words(string $key, Site $site): array
    {
        return self::pick(self::ALL[self::key($key)]['fields'], $site);
    }

    /**
     * @param  list<string> $names
     * @return list<Field>
     */
    private static function pick(array $names, Site $site): array
    {
        $fields = ContentFields::pageFields($site);
        $out    = [];

        foreach ($names as $name) {
            if (isset($fields[$name])) {
                $out[] = $fields[$name];
            }
        }

        return $out;
    }
}
