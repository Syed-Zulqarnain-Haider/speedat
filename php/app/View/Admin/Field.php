<?php

declare(strict_types=1);

namespace Speedat\View\Admin;

/**
 * One labelled control in an admin form: the PHP answer to
 * `src/components/admin/fields.tsx`.
 *
 * The Next.js fields were uncontrolled on purpose - a number box keeps a
 * half-typed "4." while the draft holds 4, and the caret never jumps. Here
 * every field IS the browser's own input, so that property comes free; what
 * this class carries is the label, the value the server last stored, and the
 * hint under it.
 *
 * NOTHING HERE IS ESCAPED. Escaping happens where the value is printed, in
 * the template, exactly once - a pre-escaped model is double-escaped by e()
 * and the owner reads &amp;amp; in his own company name.
 */
final class Field
{
    public const TEXT     = 'text';
    public const NUMBER   = 'number';
    public const TEL      = 'tel';
    public const EMAIL    = 'email';
    public const TEXTAREA = 'textarea';
    public const SELECT   = 'select';
    public const CHECKBOX = 'checkbox';

    /** @param array<string,string> $options value => label, for a select */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $value = '',
        public readonly string $type = self::TEXT,
        public readonly string $placeholder = '',
        public readonly string $hint = '',
        public readonly string $step = '',
        public readonly int $maxLength = 0,
        public readonly array $options = [],
        public readonly bool $checked = false,
        /** A textarea for pasted lists: `.paste` rather than `.long`. */
        public readonly bool $mono = false,
        public readonly bool $disabled = false,
        /**
         * One extra class on the control itself, for the two admin.css styles
         * by name: `track` makes a tracking number monospace and tabular,
         * `code` makes a six-digit box look like one. It is always a class
         * name written in a stylesheet, never a value a person typed.
         */
        public readonly string $cssClass = '',
    ) {
    }

    public static function text(string $name, string $label, string $value, string $placeholder = '', string $hint = '', int $maxLength = 0, bool $disabled = false, string $cssClass = ''): self
    {
        return new self($name, $label, $value, self::TEXT, $placeholder, $hint, '', $maxLength, [], false, false, $disabled, $cssClass);
    }

    public static function tel(string $name, string $label, string $value, string $placeholder = '', string $hint = '', bool $disabled = false): self
    {
        return new self($name, $label, $value, self::TEL, $placeholder, $hint, '', 30, [], false, false, $disabled);
    }

    public static function email(string $name, string $label, string $value, string $placeholder = '', bool $disabled = false): self
    {
        return new self($name, $label, $value, self::EMAIL, $placeholder, '', '', 190, [], false, false, $disabled);
    }

    /**
     * A number box. The value arrives as a string because that is what the
     * owner typed and what the server stored; formatting it here would turn
     * a blank ("not offered") into a 0 ("free").
     */
    public static function number(string $name, string $label, string $value, string $step = 'any', string $placeholder = '', string $hint = '', bool $disabled = false): self
    {
        return new self($name, $label, $value, self::NUMBER, $placeholder, $hint, $step, 0, [], false, false, $disabled);
    }

    public static function area(string $name, string $label, string $value, string $placeholder = '', string $hint = '', bool $mono = false, int $maxLength = 0, bool $disabled = false): self
    {
        return new self($name, $label, $value, self::TEXTAREA, $placeholder, $hint, '', $maxLength, [], false, $mono, $disabled);
    }

    /** @param array<string,string> $options */
    public static function select(string $name, string $label, string $value, array $options, string $hint = '', bool $disabled = false): self
    {
        return new self($name, $label, $value, self::SELECT, '', $hint, '', 0, $options, false, false, $disabled);
    }

    public static function check(string $name, string $label, bool $checked, string $value = '1', bool $disabled = false): self
    {
        return new self($name, $label, $value, self::CHECKBOX, '', '', '', 0, [], $checked, false, $disabled);
    }
}
