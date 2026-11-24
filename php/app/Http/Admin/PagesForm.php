<?php

declare(strict_types=1);

namespace Speedat\Http\Admin;

use Speedat\Core\Request;
use Speedat\Core\Str;
use Speedat\Domain\SiteDoc\Content;
use Speedat\Domain\SiteDoc\PublishedVersion;
use Speedat\Domain\SiteDoc\Repo;
use Speedat\Domain\SiteDoc\Schema;
use Speedat\View\Admin\ContentFields;
use Speedat\View\Admin\Field;

/**
 * The website-text form, read against the version it was opened at.
 *
 * ONLY THE FIELDS ON `ContentFields::pageFields()` CAN BE WRITTEN, whatever was
 * posted. That list is the permission: the office's address, hours, phone and
 * map are in the same record and are not on it, and nothing outside `content`
 * is read at all.
 *
 * A BOX NOBODY TOUCHED IS NOT A CHANGE. Each posted value is compared with the
 * version the page was drawn from, so two people editing different sentences
 * both land, and a stored text that is longer than its box is never shortened
 * by somebody saving a different field. When the same sentence has been changed
 * by somebody else in the meantime it is refused by name, with what was typed
 * still in the box.
 */
final class PagesForm
{
    /**
     * @param array<string,string> $typed    what was typed, by field name, for redrawing a refusal
     * @param list<string>         $changed  the field names that will change
     * @param list<string>         $problems sentences; not empty means nothing may be published
     */
    private function __construct(
        public readonly ?int $base,
        public readonly Content $content,
        public readonly array $typed,
        public readonly array $changed,
        public readonly array $problems,
    ) {
    }

    public static function of(PublishedVersion $live, Request $request): self
    {
        $asked  = $request->postInt('base', 0);
        $opened = $asked > 0 ? ($asked === $live->version ? $live : Repo::getVersion($asked)) : null;
        $posted = is_array($request->post['content'] ?? null) ? $request->post['content'] : [];

        $content  = $live->data->content;
        $typed    = [];
        $changed  = [];
        $problems = [];

        foreach (ContentFields::pageFields() as $name => $field) {
            $value = $posted[$name] ?? null;

            if (!is_string($value)) {
                continue;
            }

            $value        = self::tidy($value, $field);
            $typed[$name] = $value;

            if ($opened === null) {
                continue;
            }

            $was = self::tidy($opened->data->content->get($name), $field);

            if ($value === $was) {
                continue;
            }

            $max = Schema::contentCap($name);
            if (mb_strlen($value) > $max) {
                $problems[] = '"' . $field->label . '" is ' . mb_strlen($value) . ' characters; the most it can hold is ' . $max . '.';
                continue;
            }

            $now = self::tidy($live->data->content->get($name), $field);
            if ($now !== $was && $now !== $value) {
                $problems[] = '"' . $field->label . '" was changed by somebody else while this page was open. '
                    . 'Copy your text, reload the page and make the change again.';
                continue;
            }

            if ($now !== $value) {
                $content   = $content->with($name, $value);
                $changed[] = $name;
            }
        }

        return new self($opened === null ? null : $asked, $content, $typed, $changed, $problems);
    }

    /** One value as the document stores it: lines in an area, one line in a box. */
    private static function tidy(string $value, Field $field): string
    {
        $value = mb_scrub($value, 'UTF-8');

        return $field->type === Field::TEXTAREA
            ? trim(str_replace(["\r\n", "\r"], "\n", $value))
            : Str::squish($value);
    }
}
