<?php

declare(strict_types=1);

namespace Speedat\Tests\Seo;

use Speedat\Domain\Seo\Meta;
use Speedat\Domain\Seo\Route;
use Speedat\Domain\Seo\Routes;
use Speedat\Domain\Seo\ShareCard;
use Speedat\Domain\Seo\SiteFacts;
use Speedat\Domain\Seo\Tag;
use Speedat\Domain\Seo\Url;
use Speedat\Domain\SiteDoc\SiteData;
use Speedat\Tests\TestCase;

/**
 * Title, description, canonical, robots and the share tags, asserted against
 * the sample document for every page type.
 */
final class MetaTest extends TestCase
{
    private Url $url;

    private Meta $meta;

    public function setUp(): void
    {
        $this->url  = Url::of('https://speedat.com.pk');
        $this->meta = $this->metaFor(Fixtures::facts());
    }

    private function metaFor(SiteFacts $facts): Meta
    {
        return new Meta($facts, $this->url, new ShareCard($facts, $this->url));
    }

    /** @return array<string,string> tag key (name or property) => content */
    private function map(Route $route, ?Meta $meta = null): array
    {
        $out = [];

        foreach (($meta ?? $this->meta)->tags($route) as $tag) {
            $key = $tag->attributes['name'] ?? $tag->attributes['property'] ?? $tag->attributes['rel'] ?? '';
            $out[$key] = $tag->attributes['content'] ?? $tag->attributes['href'] ?? '';
        }

        return $out;
    }

    public function testEveryPageTitleIsItsOwnLeafPlusTheCompanyName(): void
    {
        $this->assertSame('Get a price - Speedat International Courier', $this->meta->title(Routes::byKey('home')));
        $this->assertSame('Services - Speedat International Courier', $this->meta->title(Routes::byKey('services')));
        $this->assertSame('About us - Speedat International Courier', $this->meta->title(Routes::byKey('about')));
        $this->assertSame('Contact - Speedat International Courier', $this->meta->title(Routes::byKey('contact')));
        $this->assertSame('Questions - Speedat International Courier', $this->meta->title(Routes::byKey('faq')));
    }

    public function testACompanyNameAlreadyInTheTitleIsNotRepeated(): void
    {
        $route = new Route(key: 'about', path: '/about', title: 'About Speedat International Courier');

        $this->assertSame('About Speedat International Courier', $this->meta->title($route));
    }

    public function testTheDescriptionComesFromThePagesOwnContentField(): void
    {
        $content = Fixtures::document()->content;

        $this->assertSame($content->servicesLede, $this->meta->description(Routes::byKey('services')));
        $this->assertSame($content->contactLede, $this->meta->description(Routes::byKey('contact')));
    }

    public function testABlankContentFieldFallsBackToTheGeneratedSiteDescription(): void
    {
        // heroSub is blank in the sample document, and the home page has no
        // lede of its own, so it is the one page that takes the site sentence.
        $expected = Fixtures::facts()->siteDescription();

        $this->assertSame($expected, $this->meta->description(Routes::byKey('home')));
        $this->assertSame(
            // Led by the pickup city and the Lahore neighbourhoods a local
            // search matches on (SiteFacts::siteDescription), not the country.
            'Express and Normal courier prices from Lahore by country and weight. Free pickup across Lahore '
            . '- DHA, Bahria Town, Model Town and Johar Town. Book on WhatsApp.',
            $expected
        );
    }

    /**
     * / AND /faq MAY NOT SHIP THE SAME SENTENCE.
     *
     * `faqLede` is optional and blank in the sample document (and in the
     * seed), and the fallback used to be the site-wide sentence - which the
     * home page also takes, so the two pages went out with one description
     * between them and competed for one result. The FAQ page has content of
     * its own: the questions it prints.
     */
    public function testTheFaqPageDescribesItselfWithItsOwnQuestions(): void
    {
        $faq = $this->meta->description(Routes::byKey('faq'));

        $this->assertNotSame($this->meta->description(Routes::byKey('home')), $faq);
        $this->assertNotSame(Fixtures::facts()->siteDescription(), $faq);

        // Every word of it is a question the page itself prints, in its order.
        $questions = array_column(Fixtures::facts()->faqItems(), 'question');
        $this->assertTrue($questions !== [], 'the fixture document has FAQ pairs');
        $this->assertSame(Meta::clamp(implode(' ', $questions), Meta::DESCRIPTION_LIMIT), $faq);
        $this->assertStringContains($questions[0], $faq, 'the first question leads the description');
        $this->assertTrue(
            mb_strlen($faq) <= Meta::DESCRIPTION_LIMIT,
            'the generated FAQ description is clamped like every other one'
        );
    }

    /** A lede the owner has typed still wins over the generated questions. */
    public function testATypedFaqLedeBeatsTheGeneratedQuestions(): void
    {
        $document                     = Fixtures::array();
        $document['content']['faqLede'] = 'If yours is not here, ask us on WhatsApp.';

        $meta = $this->metaFor(new SiteFacts(SiteData::fromArray($document), 7, '2026-09-20T06:15:00Z'));

        $this->assertSame('If yours is not here, ask us on WhatsApp.', $meta->description(Routes::byKey('faq')));
    }

    /**
     * A document with no FAQ pairs at all has nothing of its own to say, so
     * the page falls back to the site sentence rather than to an empty tag.
     */
    public function testAnEmptyFaqStillGetsTheSiteSentence(): void
    {
        $document                  = Fixtures::array();
        $document['content']['faq'] = '';

        $meta = $this->metaFor(new SiteFacts(SiteData::fromArray($document), 7, '2026-09-20T06:15:00Z'));

        $this->assertSame($meta->description(Routes::byKey('home')), $meta->description(Routes::byKey('faq')));
    }

    public function testATypedHeroSubBeatsTheGeneratedSentence(): void
    {
        $document = Fixtures::array();
        $document['content']['heroSub'] = '1 kg from PKR 5,000 · 3 to 10 days';

        $meta = $this->metaFor(new SiteFacts(SiteData::fromArray($document), 7, '2026-09-20T06:15:00Z'));

        $this->assertSame('1 kg from PKR 5,000 · 3 to 10 days', $meta->description(Routes::byKey('home')));
    }

    public function testALongDescriptionIsCutOnAWordBoundary(): void
    {
        $long = str_repeat('Lahore to Australia and Canada. ', 20);

        $clamped = Meta::clamp($long, Meta::DESCRIPTION_LIMIT);

        $this->assertTrue(mb_strlen($clamped) <= Meta::DESCRIPTION_LIMIT);
        $this->assertFalse(str_ends_with($clamped, ' '), 'no trailing space');
        $this->assertTrue(str_starts_with($long, $clamped), 'the clamp only removes from the end');
        // The cut fell inside a word, so the whole word went.
        $this->assertStringNotContains('Austral ', $clamped . ' ');
    }

    public function testTheClampNeverLeavesHalfAMultibyteCharacter(): void
    {
        // The bytes of "·", "–" and "—" also occur inside other multibyte
        // letters; a byte-wise trim would cut one in half.
        foreach (["Türkiye · Lahore", 'Пример Г', 'Lahore — Karachi', "9 am – 7 pm"] as $text) {
            $clamped = Meta::clamp($text, 200);
            $this->assertSame($text, $clamped, 'nothing to clamp, nothing should change');
            $this->assertNotSame(false, mb_check_encoding($clamped, 'UTF-8') ? $clamped : false);
        }

        $cut = Meta::clamp('Lahore to Türkiye — and Malaysia', 20);
        $this->assertTrue(mb_check_encoding($cut, 'UTF-8'), 'the clamp produced invalid UTF-8');
        $this->assertSame('Lahore to Türkiye', $cut);
    }

    public function testTheDescriptionOfEveryPageIsWithinTheLimit(): void
    {
        foreach (Routes::all() as $route) {
            $this->assertTrue(
                mb_strlen($this->meta->description($route)) <= Meta::DESCRIPTION_LIMIT,
                $route->key . ' description is longer than ' . Meta::DESCRIPTION_LIMIT
            );
        }
    }

    /**
     * The owner removed the document rate from the product on 2026-09-27. The
     * fixture is still a pre-2026-09-27 document - it carries `docMaxKg` and a
     * `doc` price on every rate - so this is the test that the SEO layer reads
     * one of those without ADVERTISING a document rate. "Documents" as a thing
     * the company sends is fine and deliberate; a flat price for them is not.
     */
    public function testNoPagesDescriptionPromisesADocumentRate(): void
    {
        $banned = ['flat rate', 'flat document', 'document rate', 'per envelope', 'documents up to'];

        foreach (Routes::all() as $route) {
            $description = mb_strtolower($this->meta->description($route));

            foreach ($banned as $phrase) {
                $this->assertStringNotContains($phrase, $description, $route->key . ' still sells a document rate');
            }
        }
    }

    public function testTheCanonicalIsAbsoluteAndSlashFreeExceptAtTheRoot(): void
    {
        $this->assertSame('https://speedat.com.pk/', $this->meta->canonical(Routes::byKey('home')));
        $this->assertSame('https://speedat.com.pk/faq', $this->meta->canonical(Routes::byKey('faq')));

        foreach (Routes::all() as $route) {
            $canonical = $this->meta->canonical($route);
            $this->assertTrue(str_starts_with($canonical, 'https://speedat.com.pk'), $route->key . ' is not absolute');
            $this->assertStringNotContains('?', $canonical);
            $this->assertStringNotContains('#', $canonical);
        }
    }

    public function testTheCanonicalAndOgUrlAreTheSameString(): void
    {
        foreach (Routes::all() as $route) {
            $tags = $this->map($route);
            $this->assertSame($tags['canonical'], $tags['og:url'], $route->key . ': og:url disagrees with the canonical');
        }
    }

    public function testThePublicPagesAreIndexableAndAnyAdminPathIsNot(): void
    {
        $this->assertStringContains('index, follow', $this->meta->robots(Routes::byKey('home')));
        $this->assertStringContains('max-image-preview:large', $this->meta->robots(Routes::byKey('home')));

        $hidden = new Route(key: 'thanks', path: '/thanks', title: 'Thanks', indexable: false);
        $this->assertSame('noindex, nofollow', $this->meta->robots($hidden));

        $admin = new Route(key: 'admin', path: '/admin/rates', title: 'Rates');
        $this->assertSame('noindex, nofollow', $this->meta->robots($admin));
    }

    /**
     * A doubled slash is a path LiteSpeed and Apache both hand to the same
     * handler, so `//admin/login` renders the admin login. The route list's
     * own path normaliser used to leave `//admin` intact, which made
     * `isDisallowed()` answer "public" and put `index, follow` on it.
     */
    public function testAnAdminPathReachedThroughADoubledOrDottedSlashIsStillNoindex(): void
    {
        foreach (['//admin', '//admin/login', '/./admin/rates', '/admin//settings', '//api/quote'] as $path) {
            $route = new Route(key: 'admin', path: $path, title: 'Admin');
            $this->assertSame('noindex, nofollow', $this->meta->robots($route), $path . ' must not be indexable');
        }
    }

    /**
     * A `noindex` page that also ships a `canonical` gives a crawler two
     * contradictory instructions, and a 404 whose canonical names a real URL
     * is how the error page gets indexed under that page's name.
     */
    public function testAPageThatIsNotIndexedIssuesNoCanonicalAndNoOgUrl(): void
    {
        $tags = $this->map(Routes::error());

        $this->assertSame('noindex, nofollow', $tags['robots']);
        $this->assertFalse(isset($tags['canonical']), 'a noindex page must not also issue a canonical');
        $this->assertFalse(isset($tags['og:url']), 'a noindex page must not claim a share URL');

        // It still has a head worth having: a title, a description and a card.
        $this->assertStringContains('Speedat International Courier', $tags['og:title']);
        $this->assertNotSame('', $tags['description']);
        $this->assertStringContains(ShareCard::CARD_PNG, $tags['og:image']);
    }

    public function testTheErrorRouteNeverReflectsTheRequestedPath(): void
    {
        $route = Routes::error('Page not found');

        $this->assertSame('/', $route->path);
        $this->assertFalse($route->indexable);
        $this->assertStringContains('Page not found', $this->meta->title($route));

        foreach ($this->meta->tags($route) as $tag) {
            foreach ($tag->attributes as $value) {
                $this->assertStringNotContains('<', $value);
            }
        }
    }

    public function testTheShareTagsCarryTheImageWithItsTypeAndItsSize(): void
    {
        $tags = $this->map(Routes::byKey('home'));

        $this->assertSame('https://speedat.com.pk/' . ShareCard::CARD_PNG, $tags['og:image']);
        $this->assertSame('image/png', $tags['og:image:type']);
        $this->assertSame('1200', $tags['og:image:width']);
        $this->assertSame('630', $tags['og:image:height']);
        $this->assertSame('summary_large_image', $tags['twitter:card']);
        $this->assertSame($tags['og:image'], $tags['twitter:image']);
        $this->assertStringContains('Speedat International Courier', $tags['og:image:alt']);
    }

    public function testEveryPageCarriesTheWholeOpenGraphSet(): void
    {
        foreach (Routes::all() as $route) {
            $tags = $this->map($route);

            foreach (['description', 'robots', 'canonical', 'og:type', 'og:site_name', 'og:title',
                      'og:description', 'og:url', 'og:image', 'twitter:card'] as $required) {
                $this->assertTrue(isset($tags[$required]), $route->key . ' is missing ' . $required);
                $this->assertNotSame('', $tags[$required], $route->key . ': ' . $required . ' is empty');
            }

            $this->assertSame('website', $tags['og:type']);
            $this->assertSame($this->meta->title($route), $tags['og:title']);
            $this->assertSame($this->meta->description($route), $tags['og:description']);
        }
    }

    public function testTagsAreDataAndNeverMarkup(): void
    {
        foreach ($this->meta->tags(Routes::byKey('home')) as $tag) {
            $this->assertTrue($tag instanceof Tag);
            $this->assertTrue($tag->element === 'meta' || $tag->element === 'link');
        }
    }
}
