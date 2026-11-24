<?php

declare(strict_types=1);

namespace Speedat\Tests\Http;

use Speedat\Core\Clock;
use Speedat\Core\Config;
use Speedat\Core\Csrf;
use Speedat\Core\Db;
use Speedat\Core\Paths;
use Speedat\Core\Router;
use Speedat\Core\Session;
use Speedat\Domain\Ops\VersionsTable;
use Speedat\Domain\SiteDoc\Hold;
use Speedat\Domain\SiteDoc\Live;
use Speedat\Domain\SiteDoc\PublishArgs;
use Speedat\Domain\SiteDoc\Repo;
use Speedat\Domain\SiteDoc\SiteData;
use Speedat\Http\Admin\Admins;
use Speedat\Http\Admin\AdminUser;
use Speedat\Http\Admin\PendingLogin;
use Speedat\View\Admin\ContentFields;
use Speedat\View\Admin\Routes;
use Speedat\View\Marketing\Tabs;

/**
 * The marketer login: what it may do, which is change words, and everything it
 * may not, which is the rest.
 *
 * THE REFUSALS ARE ASKED OF THE ROUTING TABLE ITSELF, not of a list kept here.
 * A list is what somebody forgets to add the next screen to.
 */
final class MarketerTest extends AdminTestCase
{
    private const MARKETER = 'words@speedat.test';

    /** What is nobody's permission: the sign-ins, the way out, the first run. */
    private const OPEN = [
        Routes::LOGIN, Routes::LOGIN_PASSWORD, Routes::LOGIN_GOOGLE, Routes::LOGIN_CODE, Routes::LOGOUT, '/admin/setup',
        Routes::MARKETING_LOGIN, Routes::MARKETING_LOGIN_GOOGLE, Routes::MARKETING_LOGIN_CODE, Routes::MARKETING_LOGOUT,
    ];

    /* -------------------------------------------------------------- the door */

    public function testEveryAdminRouteRefusesAMarketer(): void
    {
        $this->marketer();
        $checked = 0;

        foreach ($this->routesUnder('/admin') as [$method, $path]) {
            if (in_array($path, self::OPEN, true)) {
                continue;
            }

            $answer = $method === 'GET' ? $this->adminGet($path) : $this->adminPost($path, ['id' => '1', 'on' => '1', 'email' => self::OWNER]);

            if ($method === 'GET' && $path === Routes::RATES) {
                $this->assertSame(Routes::MARKETING, $this->location($answer), 'the admin front page did not send a marketer to theirs');
            } elseif ($method === 'GET' && $path === '/admin/rates') {
                $this->assertSame(Routes::RATES, $this->location($answer));
            } else {
                $this->assertSame(403, $answer->response->status, $method . ' ' . $path . ' let a marketer in');
            }

            $this->assertStringNotContains('<table class="rates', $answer->body(), $path);
            $checked++;
        }

        $this->assertTrue($checked >= 35, 'the routing table was read: ' . $checked . ' routes refused');
        $this->assertSame(1, $this->liveVersion(), 'a refused request published');
        $this->assertFalse(Hold::get()->on, 'a refused request held the prices');
        $this->assertNotNull(Admins::row(self::OWNER), 'a refused request removed the owner');
    }

    public function testEveryMarketingRouteRefusesTheBackOfficeAndEverybodyElse(): void
    {
        $this->admin();
        $this->admin(self::EDITOR, 'editor');
        $checked = 0;

        foreach ($this->routesUnder('/marketing') as [$method, $path]) {
            if (in_array($path, self::OPEN, true)) {
                continue;
            }
            foreach ([self::OWNER, self::EDITOR] as $email) {
                $this->signIn($email);
                $answer = $method === 'GET' ? $this->adminGet($path) : $this->adminPost($path, ['base' => '1', 'content' => ['heroTitle' => 'x']]);
                if ($path === Routes::MARKETING) {
                    $this->assertSame(Routes::RATES, $this->location($answer), $email);
                } else {
                    $this->assertSame(403, $answer->response->status, $email . ' ' . $method . ' ' . $path);
                }
            }

            $this->sid = '';
            $answer = $method === 'GET' ? $this->get($path) : $this->post($path, [Csrf::FIELD => 'x']);
            $this->assertSame(302, $answer->response->status, $path);
            $this->assertSame(Routes::MARKETING_LOGIN, $this->location($answer), $path . ' sent a stranger to the wrong sign-in');
            $checked++;
        }

        $this->assertSame(6, $checked);
        $this->assertSame(1, $this->liveVersion());
        $this->assertSame(Routes::LOGIN, $this->location($this->get(Routes::RATES)), 'the admin lost its own sign-in');
    }

    public function testTheRoleIsAskedBeforeTheTokenSoARefusalNeverDependsOnOne(): void
    {
        $this->marketer();

        $answer = $this->post(Routes::PUBLISH, [Csrf::FIELD => 'wrong'], [], $this->cookies());

        $this->assertSame(403, $answer->response->status);
        $this->assertStringContains('only change the website text', $answer->body());
    }

    public function testAMarketersAreaIsTheirOwn(): void
    {
        $this->marketer();
        $seen = '';

        foreach (array_keys(Tabs::labels()) as $tab) {
            $page = $this->adminGet(Routes::MARKETING, ['page' => $tab]);
            $this->assertSame(200, $page->response->status, $tab);
            $body = $page->body();
            $seen .= $body;

            $this->assertStringContains('Marketing', $body);
            $this->assertStringContains('noindex, nofollow', $body);
            $this->assertSame(1, substr_count($body, '<h1'), $tab);
            foreach (['"/admin', 'admin-app', 'js/admin.js', 'js/pricing.js', self::OWNER] as $foreign) {
                $this->assertStringNotContains($foreign, $body, $tab . ' carries ' . $foreign);
            }
        }

        foreach (['content[address]', 'content[phone2]', 'content[hours]', 'content[mapUrl]', 'settings[', 'company[', 'dest['] as $name) {
            $this->assertStringNotContains('name="' . $name, $seen, $name);
        }
        $this->assertSame(200, $this->adminGet(Routes::MARKETING, ['page' => '../../etc/passwd'])->response->status);
    }

    public function testEveryFieldAMarketerMayWriteIsOnExactlyOnePage(): void
    {
        $onTabs = [];
        foreach (array_keys(Tabs::labels()) as $tab) {
            array_push($onTabs, ...Tabs::names($tab));
        }
        sort($onTabs);
        $may = array_keys(ContentFields::pageFields());
        sort($may);

        $this->assertSame($may, $onTabs);
    }

    public function testTheAccountPageShowsAMarketerNothingAboutAnybodyElse(): void
    {
        $this->marketer();
        $this->adminGet(Routes::MARKETING);

        $body = $this->adminGet(Routes::MARKETING_SECURITY)->body();

        $this->assertStringContains('Two-factor sign-in', $body);
        $this->assertStringContains('action="' . Routes::MARKETING_TOTP_BEGIN . '"', $body);
        foreach ([self::OWNER, 'Deployment checks', 'Recent activity', 'Who can sign in', '"/admin'] as $secret) {
            $this->assertStringNotContains($secret, $body, $secret);
        }

        $begin = $this->adminPost(Routes::MARKETING_TOTP_BEGIN);
        $this->assertStringContains(Routes::MARKETING_SECURITY . '?enrol=1', $this->location($begin));
    }

    public function testAnEditorMayNotPublishWebsiteTextNorSeeTheLogins(): void
    {
        $this->admin(self::EDITOR, 'editor');
        $this->signIn(self::EDITOR);

        $this->assertSame(403, $this->adminGet(Routes::PAGES)->response->status);
        $this->assertSame(403, $this->adminPost(Routes::PAGES_PUBLISH, ['base' => '1', 'content' => ['heroTitle' => 'Editor was here']])->response->status);
        $this->assertSame(403, $this->adminGet(Routes::USERS)->response->status);
        $this->assertSame(403, $this->adminPost(Routes::USERS_ADD, ['email' => 'x@speedat.test'])->response->status);
        $this->assertSame(1, $this->liveVersion());
    }

    public function testAnOwnerStillOpensEverything(): void
    {
        $this->admin();
        $this->signIn();

        foreach ([Routes::RATES, Routes::PAGES, Routes::INBOX, Routes::SHIPMENTS, Routes::ANALYTICS, Routes::USERS, Routes::SECURITY] as $path) {
            $this->assertSame(200, $this->adminGet($path)->response->status, $path);
        }
    }

    public function testARoleNobodyKnowsIsTheLeastThereIs(): void
    {
        foreach (['', null, 'Owner', 'admin', 'root', 1] as $stored) {
            $this->assertSame(AdminUser::MARKETER, AdminUser::role($stored), var_export($stored, true));
        }
        $this->assertSame(AdminUser::OWNER, AdminUser::role('owner'));
        $this->assertSame(AdminUser::EDITOR, AdminUser::role('editor'));
    }

    /* ------------------------------------------------------------ publishing */

    public function testTheWordsGoLiveAndNothingElseDoes(): void
    {
        $before = Repo::getLatestVersion()->data->toArray();
        $this->marketer();

        $answer = $this->publish([
            'heroTitle'       => 'Lahore to the world',
            'seoHomeTitle'    => 'Courier from Lahore | Speedat',
            'seoHomeDesc'     => 'Send a parcel abroad from Lahore.',
            'seoServicesTitle' => 'Air courier services',
            'servicesH1'      => 'Our services',
            // Not on the form, however it got into the post.
            'address'         => 'A stranger\'s house',
            'phone2'          => '0000',
            'live'            => '0',
        ], ['company' => ['whatsapp' => '1'], 'settings' => ['taxPct' => '99'], 'dest' => ['gb' => ['name' => 'Nowhere']], 'live' => '0']);

        $this->assertRedirects(Routes::MARKETING, $answer);
        $this->assertSame(2, $this->liveVersion());

        $after = Repo::getLatestVersion()->data->toArray();
        $this->assertSame('Lahore to the world', $after['content']['heroTitle']);

        foreach (['heroTitle', 'seoHomeTitle', 'seoHomeDesc', 'seoServicesTitle', 'servicesH1'] as $field) {
            unset($before['content'][$field], $after['content'][$field]);
        }
        $this->assertSame($before, $after, 'something besides the words changed');

        $home = $this->get('/')->body();
        $this->assertStringContains('<title>Courier from Lahore | Speedat</title>', $home);
        $this->assertStringContains('Send a parcel abroad from Lahore.', $home);
        $services = $this->get('/services')->body();
        $this->assertStringContains('<h1>Our services</h1>', $services);
        $this->assertStringContains('<title>Air courier services</title>', $services);

        $this->assertSame(1, $this->audited('pages_published'));
        $this->assertSame(self::MARKETER, Db::value('SELECT published_by FROM versions WHERE version = 2'));
        $this->assertSame(PublishArgs::PAGES, Db::value('SELECT source FROM versions WHERE version = 2'));
    }

    public function testBlankIsAutomaticExactlyAsBefore(): void
    {
        $this->marketer();
        $before = $this->headOf('/services');

        $this->publish(['seoServicesTitle' => 'Mine', 'seoServicesDesc' => 'My sentence', 'servicesH1' => 'Mine']);
        $this->assertNotSame($before, $this->headOf('/services'));

        $this->publish(['seoServicesTitle' => '', 'seoServicesDesc' => '', 'servicesH1' => '']);
        $this->assertSame($before, $this->headOf('/services'));
    }

    public function testMarkupInTheWordsIsPrintedAsWords(): void
    {
        $this->marketer();
        $evil = '</title><script>alert(1)</script>';

        $this->publish(['seoHomeTitle' => $evil, 'seoHomeDesc' => '"><script>alert(2)</script>', 'heroTitle' => '<img src=x onerror=alert(3)>', 'faqH1' => $evil]);

        foreach (['/', '/faq'] as $path) {
            foreach (['<script>alert', '<img src=x'] as $bad) {
                $this->assertStringNotContains($bad, $this->get($path)->body(), $path);
            }
        }
    }

    public function testTheOwnersUnpublishedPricesSurviveAndStayUnpublished(): void
    {
        $this->admin();
        $draft = $this->draftPrice(7777.0);
        $this->marketer();

        $this->publish(['heroTitle' => 'New words']);

        $this->assertSame($draft->revision, Repo::getDraft()->revision, 'the draft row was touched');
        $this->assertFalse($this->priceIsLive(7777.0), 'a draft price went on the website');

        // The owner opens the editor: their price is still there, on top of the words.
        $this->signIn();
        $this->assertSame(200, $this->adminGet(Routes::RATES)->response->status);
        $caught = Repo::getDraft();
        $this->assertSame(2, $caught->baseVersion);
        $this->assertSame('New words', $caught->data->content->heroTitle);
        $this->assertSame(7777.0, $this->priceIn($caught->data));

        // And publishing it keeps both.
        $answer = $this->adminPost(Routes::PUBLISH, ['expectedBase' => '2']);
        $this->assertRedirects(Routes::RATES, $answer);
        $this->assertSame('New words', Repo::getLatestVersion()->data->content->heroTitle);
        $this->assertTrue($this->priceIsLive(7777.0));
    }

    public function testAReviewDrawnBeforeTheWordsWentLiveCannotUndoThem(): void
    {
        $this->admin();
        $this->draftPrice(7777.0);
        $this->marketer();
        $this->publish(['heroTitle' => 'New words']);

        $this->signIn();
        $answer = $this->adminPost(Routes::PUBLISH, ['expectedBase' => '1']);

        $this->assertStringContains('conflict=', $this->location($answer));
        $this->assertSame('New words', Repo::getLatestVersion()->data->content->heroTitle);
    }

    public function testWordsDoNotMakeThePricesLookNew(): void
    {
        Db::exec('UPDATE versions SET published_at = ? WHERE version = 1', [Clock::sql(Clock::utcNow()->modify('-20 days'))]);
        Live::bust();
        $old = Repo::getLatestVersion()->publishedAt;
        $this->marketer();

        $this->publish(['heroTitle' => 'One']);
        $this->publish(['heroTitle' => 'Two']);

        $live = Repo::getLatestVersion();
        $this->assertSame(3, $live->version);
        $this->assertSame($old, $live->ratesAt());
        $this->assertNotSame($old, $live->publishedAt);
        $this->assertSame(20, (new VersionsTable())->live()->ageDays(Clock::utcNow()), 'the stale-rates alert was reset');
        Live::forget();
        $this->assertSame($old, Live::site()->ratesAt(), 'from the database');
        Live::forget();
        $this->assertSame($old, Live::site()->ratesAt(), 'from the cache file');
    }

    public function testAHoldStaysOn(): void
    {
        Hold::set(true, self::OWNER, 'Back soon');
        $this->marketer();

        $this->publish(['heroTitle' => 'New words']);

        $this->assertSame(2, $this->liveVersion());
        $this->assertTrue(Hold::get()->on);
    }

    /* -------------------------------------------------------- two at a time */

    public function testTwoPeopleChangingDifferentSentencesBothLand(): void
    {
        $this->marketer();

        $this->publish(['heroTitle' => 'First'], base: 1);
        $answer = $this->publish(['heroTitle' => Repo::getVersion(1)->data->content->heroTitle, 'contactLede' => 'Second'], base: 1);

        $this->assertRedirects(Routes::MARKETING, $answer);
        $content = Repo::getLatestVersion()->data->content;
        $this->assertSame(['First', 'Second'], [$content->heroTitle, $content->contactLede]);
    }

    public function testTheSameSentenceChangedTwiceIsRefusedByNameWithTheTextKept(): void
    {
        $this->marketer();

        $this->publish(['heroTitle' => 'First'], base: 1);
        $answer = $this->publish(['heroTitle' => 'Mine instead'], base: 1);

        $this->assertSame(422, $answer->response->status);
        $this->assertStringContains('changed by somebody else', $answer->body());
        $this->assertStringContains('Mine instead', $answer->body());
        $this->assertSame('First', Repo::getLatestVersion()->data->content->heroTitle);
    }

    public function testNothingChangedPublishesNothing(): void
    {
        $this->marketer();

        $this->assertStringContains('nochange=1', $this->location($this->publish([])));
        $this->assertStringContains('nochange=1', $this->location($this->publish(['heroTitle' => Repo::getLatestVersion()->data->content->heroTitle])));
        $this->assertSame(1, $this->liveVersion());
    }

    public function testTooLongAndUnknownBasesAreRefused(): void
    {
        $this->marketer();

        $long = $this->publish(['seoHomeTitle' => str_repeat('x', 121)]);
        $this->assertSame(422, $long->response->status);
        $this->assertStringContains('121 characters', $long->body());

        foreach ([0, 99] as $base) {
            $this->assertSame(409, $this->publish(['heroTitle' => 'Lost'], base: $base)->response->status);
        }
        $this->assertSame(1, $this->liveVersion());
    }

    public function testAPublishNeedsItsToken(): void
    {
        $this->marketer();

        $answer = $this->post(Routes::MARKETING_PUBLISH, [Csrf::FIELD => 'wrong', 'base' => '1', 'content' => ['heroTitle' => 'x']], [], $this->cookies());

        $this->assertTrue($answer->response->status >= 400);
        $this->assertSame(1, $this->liveVersion());
    }

    /* ---------------------------------------------------------------- logins */

    public function testAnOwnerAddsAndRemovesAMarketer(): void
    {
        $this->admin();
        $this->signIn();

        $this->assertStringContains('useradded=1', $this->location($this->adminPost(Routes::USERS_ADD, ['email' => ' Words@Speedat.test ', 'name' => 'Sana'])));
        $this->assertSame('marketer', Admins::row(self::MARKETER)['role'] ?? null);
        $this->assertNull(Admins::row(self::MARKETER)['password_hash']);
        $this->assertStringContains(self::MARKETER, $this->adminGet(Routes::USERS)->body());

        $owner = $this->sid;
        $theirs = $this->signIn(self::MARKETER);
        $this->assertSame(200, $this->adminGet(Routes::MARKETING)->response->status);

        $this->sid = $owner;
        $this->adminPost(Routes::USERS_REMOVE, ['email' => self::MARKETER]);
        $this->assertNull(Admins::row(self::MARKETER));
        $this->assertFalse($this->sessionExists($theirs), 'they are still signed in');
        $this->assertSame([1, 1], [$this->audited('marketer_added'), $this->audited('marketer_removed')]);
    }

    public function testTheLoginsScreenCannotTouchAnAdminOrAnEditor(): void
    {
        $this->admin();
        $this->admin(self::EDITOR, 'editor');
        $this->signIn();

        foreach ([self::OWNER, self::EDITOR] as $email) {
            $this->adminPost(Routes::USERS_REMOVE, ['email' => $email]);
            $this->adminPost(Routes::USERS_ADD, ['email' => $email, 'role' => 'owner']);
        }
        $this->assertSame(['editor', 'owner'], [Admins::row(self::EDITOR)['role'], Admins::row(self::OWNER)['role']]);

        $this->adminPost(Routes::USERS_ADD, ['email' => 'new@speedat.test', 'role' => 'owner']);
        $this->assertSame('marketer', Admins::row('new@speedat.test')['role'], 'the form chose a role');
        $this->assertStringContains('err=bademail', $this->location($this->adminPost(Routes::USERS_ADD, ['email' => 'nope'])));
    }

    /* ------------------------------------------------------------- two doors */

    public function testTheTwoSignInPagesAreTwoPages(): void
    {
        $this->googleSignInReady();
        $this->admin();

        $admin     = $this->get(Routes::LOGIN)->body();
        $marketing = $this->get(Routes::MARKETING_LOGIN)->body();

        $this->assertStringContains('action="' . Routes::LOGIN_GOOGLE . '"', $admin);
        $this->assertStringContains('type="password"', $admin);
        $this->assertStringContains('action="' . Routes::MARKETING_LOGIN_GOOGLE . '"', $marketing);
        $this->assertStringContains('Sign in to Marketing', $marketing);
        foreach (['type="password"', '"/admin', 'config.php', 'auth.firebase'] as $foreign) {
            $this->assertStringNotContains($foreign, $marketing, $foreign);
        }
        $this->assertStringNotContains('/marketing', $admin);
        $this->assertTrue($this->get(Routes::MARKETING_LOGIN)->response->allowsFirebaseAuth(), 'the page blocks its own Google popup');
    }

    public function testAnUnconfiguredSiteTellsAMarketerToAskAndNothingElse(): void
    {
        $body = $this->get(Routes::MARKETING_LOGIN)->body();

        $this->assertStringContains('ask your Speedat admin', $body);
        foreach (['config', 'setup_token', '/admin', 'seed.php', 'firebase'] as $inside) {
            $this->assertStringNotContains($inside, strip_tags($body), $inside);
        }
    }

    public function testAMarketerSignsInWithGoogleAtTheirOwnDoor(): void
    {
        $this->googleSignInReady();
        Config::set('auth.allow_password_login', false);
        $this->admin();
        $this->admin(self::MARKETER, 'marketer', null);

        $answer = $this->google(self::MARKETER, Routes::MARKETING_LOGIN, Routes::MARKETING_LOGIN_GOOGLE);
        $this->assertSame(303, $answer->response->status, 'a marketer could not sign in');
        $this->assertSame(Routes::MARKETING, $this->location($answer));

        $this->sid = (string) $answer->cookie(Session::COOKIE);
        $this->assertSame(200, $this->adminGet(Routes::MARKETING)->response->status);
        $this->assertSame(403, $this->adminGet(Routes::INBOX)->response->status);
        $this->assertSame(Routes::MARKETING, $this->location($this->adminGet(Routes::LOGIN)), 'signed in, at the admin sign-in');

        $this->assertStringContains(Routes::MARKETING_LOGIN, $this->location($this->adminPost(Routes::MARKETING_LOGOUT)));
        $this->assertSame(302, $this->adminGet(Routes::MARKETING)->response->status);
    }

    public function testTheWrongDoorIsSaidOnlyToSomebodyWhoProvedWhoTheyAreAndStartsNoSession(): void
    {
        $this->googleSignInReady();
        $this->admin();
        $this->admin(self::MARKETER, 'marketer', null);

        foreach ([
            [self::MARKETER, Routes::LOGIN, Routes::LOGIN_GOOGLE, Routes::MARKETING_LOGIN],
            [self::OWNER, Routes::MARKETING_LOGIN, Routes::MARKETING_LOGIN_GOOGLE, Routes::LOGIN],
        ] as [$email, $page, $post, $right]) {
            $answer = $this->google($email, $page, $post);
            $this->assertSame(403, $answer->response->status, $email);
            $this->assertStringContains('signs in at ' . $right, $answer->body());
            $this->assertNull($answer->cookie(Session::COOKIE), $email . ' was signed in at the wrong door');
        }

        // A Google account nobody added hears the same as ever, at both.
        foreach ([[Routes::LOGIN, Routes::LOGIN_GOOGLE], [Routes::MARKETING_LOGIN, Routes::MARKETING_LOGIN_GOOGLE]] as [$page, $post]) {
            $stranger = $this->google('stranger@gmail.com', $page, $post);
            $this->assertSame(401, $stranger->response->status);
            $this->assertStringNotContains('signs in at', $stranger->body());
        }
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM sessions'));
        $this->assertSame([0, 2], [$this->audited('login'), $this->audited('login_wrong_door')]);
    }

    public function testACodeBegunAtOneDoorIsNotTakenAtTheOther(): void
    {
        $this->googleSignInReady();
        $secret = $this->totp()->newSecret();
        $this->admin();
        $this->admin(self::MARKETER, 'marketer', null, $secret);

        $first = $this->google(self::MARKETER, Routes::MARKETING_LOGIN, Routes::MARKETING_LOGIN_GOOGLE);
        $this->assertSame(Routes::MARKETING_LOGIN, $this->location($first));
        $cookies = [PendingLogin::COOKIE => (string) $first->cookie(PendingLogin::COOKIE)];

        $this->assertStringNotContains('name="code"', $this->get(Routes::LOGIN, [], [], $cookies)->body(), 'the admin page asked for a marketer\'s code');
        $step = $this->get(Routes::MARKETING_LOGIN, [], [], $cookies);
        $this->assertStringContains('action="' . Routes::MARKETING_LOGIN_CODE . '"', $step->body());
    }

    public function testARemovedMarketerCannotSignInAgain(): void
    {
        $this->googleSignInReady();
        $this->admin();
        $this->admin(self::MARKETER, 'marketer', null);
        $this->assertTrue(Admins::removeMarketer(self::MARKETER));

        $this->assertSame(401, $this->google(self::MARKETER, Routes::MARKETING_LOGIN, Routes::MARKETING_LOGIN_GOOGLE)->response->status);
    }

    /* ---------------------------------------------------- undoing and drafts */

    public function testUndoingWordsKeepsThePricesTheirDateAndTheOwnersDraft(): void
    {
        Db::exec('UPDATE versions SET published_at = ? WHERE version = 1', [Clock::sql(Clock::utcNow()->modify('-20 days'))]);
        Live::bust();
        $old = Repo::getLatestVersion()->publishedAt;
        $this->admin();
        $this->draftPrice(7777.0);
        $this->marketer();
        $this->publish(['heroTitle' => 'A mistake']);

        Repo::rollbackTo(1, self::OWNER);

        $live = Repo::getLatestVersion();
        $this->assertSame(Repo::getVersion(1)->data->content->heroTitle, $live->data->content->heroTitle);
        $this->assertSame($old, $live->ratesAt(), 'undoing words made the prices look new');
        Repo::catchUpDraft();
        $this->assertSame(7777.0, $this->priceIn(Repo::getDraft()->data), "the owner's unpublished price was thrown away");
    }

    public function testTheRollBackQuestionSaysHowManyTextChangesGoWithIt(): void
    {
        $this->marketer();
        $this->publish(['heroTitle' => 'One']);
        $this->publish(['heroTitle' => 'Two']);
        $this->signIn();

        $this->assertStringContains('undoes 2 website text changes', $this->adminGet(Routes::RATES, ['rollback' => '1'])->body());
    }

    public function testAHalfFinishedCountryInTheDraftDoesNotLeaveItBehind(): void
    {
        $this->admin();
        $draft = Repo::getDraft()->data->toArray();
        $draft['destinations'][0]['name'] = '';
        Repo::saveDraft(SiteData::fromArray($draft), self::OWNER);
        $this->marketer();
        $this->publish(['heroTitle' => 'New words']);

        $this->signIn();
        $this->adminGet(Routes::RATES);

        $this->assertSame(2, Repo::getDraft()->baseVersion, 'the draft was left behind the words');
        $this->assertSame('New words', Repo::getDraft()->data->content->heroTitle);
    }

    public function testADraftThatIsBehindIsNotPublishedOverTheWords(): void
    {
        $this->admin();
        $this->draftPrice(7777.0);
        $this->marketer();
        $this->publish(['heroTitle' => 'New words']);

        $this->signIn();
        // Every claim the form can make about which version it saw, and none.
        foreach ([['expectedBase' => '2'], ['expectedBase' => '1'], []] as $i => $post) {
            Db::exec('UPDATE draft SET base_version = 1');
            $this->adminPost(Routes::PUBLISH, $post);
            $this->assertSame('New words', Repo::getLatestVersion()->data->content->heroTitle, 'case ' . $i);
        }
    }

    public function testABusyDayOfWordsDoesNotPushThePricesOffTheHistory(): void
    {
        $this->marketer();
        for ($i = 0; $i < 25; $i++) {
            $this->publish(['heroTitle' => 'Words ' . $i]);
        }

        $this->assertTrue(in_array(1, array_map(static fn ($m): int => $m->version, Repo::history(20, 20)), true));
    }

    public function testTheGooglePreviewShowsWhatTheSiteWouldPrint(): void
    {
        $this->marketer();
        $body = $this->adminGet(Routes::MARKETING, ['page' => 'services'])->body();
        preg_match('#<title>(.*?)</title>#s', $this->get('/services')->body(), $m);

        $this->assertStringContains('data-serp-title>' . $m[1] . '<', $body);
    }

    /* --------------------------------------------------------------- helpers */

    private function marketer(): void
    {
        if (Admins::row(self::OWNER) === null) {
            $this->admin();
        }
        $this->admin(self::MARKETER, 'marketer', null);
        $this->signIn(self::MARKETER);
    }

    /** A real Google sign-in post, carrying the visitor token the login page would have printed. */
    private function google(string $email, string $door = Routes::LOGIN, string $post = Routes::LOGIN_GOOGLE): Answer
    {
        $page = $this->get($door);
        preg_match('/name="' . preg_quote(Csrf::FIELD, '/') . '" value="([^"]+)"/', $page->body(), $m);

        $cookies = [];
        foreach ([Session::ANON_COOKIE] as $name) {
            $value = $page->cookie($name);
            if ($value !== null) {
                $cookies[$name] = $value;
            }
        }

        return $this->post($post, ['idToken' => $this->googleToken($email), Csrf::FIELD => html_entity_decode($m[1] ?? '')], [], $cookies);
    }

    /**
     * @param array<string,string> $content
     * @param array<string,mixed>  $extra
     */
    private function publish(array $content, array $extra = [], ?int $base = null): Answer
    {
        return $this->adminPost(Routes::MARKETING_PUBLISH, ['base' => (string) ($base ?? $this->liveVersion()), 'content' => $content] + $extra);
    }

    /** @return list<array{0:string,1:string}> every route under an area in app/routes.php, parameters filled in */
    private function routesUnder(string $area): array
    {
        $router = new Router();
        (require Paths::root() . '/app/routes.php')($router);

        $out = [];
        foreach ((new \ReflectionProperty($router, 'routes'))->getValue($router) as $method => $routes) {
            foreach ($routes as $route) {
                // Back from the compiled pattern to an address that matches it.
                $path = stripslashes(str_replace('([^/]+)', 'SH-ABCD2345', substr((string) $route['regex'], 2, -2)));
                if ($path === $area || str_starts_with($path, $area . '/')) {
                    $out[] = [(string) $method, $path];
                }
            }
        }

        return $out;
    }

    private function headOf(string $path): string
    {
        $body = $this->get($path)->body();
        preg_match('#<title>.*?</title>#s', $body, $title);
        preg_match('#<meta name="description"[^>]*>#', $body, $desc);
        preg_match('#<h1>.*?</h1>#s', $body, $h1);

        return ($title[0] ?? '') . ($desc[0] ?? '') . ($h1[0] ?? '');
    }

    private function draftPrice(float $price): \Speedat\Domain\SiteDoc\Draft
    {
        $document = Repo::getDraft()->data->toArray();
        $service  = array_key_first($document['destinations'][0]['rates']);
        $document['destinations'][0]['rates'][$service]['grid']['1'] = $price;

        return Repo::saveDraft(SiteData::fromArray($document), self::OWNER);
    }

    private function priceIn(SiteData $data): ?float
    {
        $document = $data->toArray();
        $service  = array_key_first($document['destinations'][0]['rates']);
        $price    = $document['destinations'][0]['rates'][$service]['grid']['1'] ?? null;

        return $price === null ? null : (float) $price;
    }

    private function priceIsLive(float $price): bool
    {
        return $this->priceIn(Repo::getLatestVersion()->data) === $price;
    }
}
