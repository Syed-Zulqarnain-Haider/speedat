# Parity harness: the TypeScript spec vs. the PHP port

The TypeScript in `/d/speedat/src` is the specification for this rewrite. The
pricing engine is held to it by 940 golden vectors. This is the same idea for the
published-document layer: run the **real** `src/lib/site/*.ts` over a battery of
fixtures, keep both the inputs and the outputs, then feed the identical inputs
through `Speedat\Domain\SiteDoc\*` and compare character for character.

It is a developer tool, not part of `bin/test.php`. Generating the golden file
needs node and the Next.js tree, and the Hostinger host has neither. Everything
it proved is pinned as ordinary assertions in the suite one directory up; this is
how those expectations were obtained, and how to obtain them again when the
TypeScript changes.

## Running it

```sh
# 1. Generate the golden file from the TypeScript specification (needs node).
cd /d/speedat
node_modules/.bin/tsx --tsconfig ./tsconfig.json \
  php/tests/SiteDoc/parity/generate-golden.ts /tmp/golden.json

# 2. Replay the same inputs through the PHP port.
cd /d/speedat/php
/d/php/php.exe tests/SiteDoc/parity/compare.php /tmp/golden.json
```

The comparator exits non-zero and prints, for every mismatch, the character
offset of the first difference with both sides around it.

`generate-golden.ts` only ever imports from `src/`; it does not modify it.

## What it covers

270 cases: the seed and grid seed documents, `migrate()` over 20 stored shapes
(every retired default, the `""`/null cutoff, a missing `docMaxKg`, an unknown
pricing mode), `diffSite` over 35 document pairs including the text-windowing and
multi-line-span paths, `fieldChange`, `validateSite` and `warnSite` over 11
broken documents (every message, in order), the Zod schema over 48 invalid
documents, `normalizeCountry`/`flagCode` over 46 names, and
`cleanHoldMessage` over 30 raw inputs.

## What it found

Three real defects in the port, all now fixed:

1. **Every schema message was zod 3's wording.** The project runs zod **4.6.5**,
   which rewrote all of them: "Too big: expected string to have <=200
   characters", not "String must contain at most 200 character(s)". The admin
   prints these and `publishVersion` puts the first one in its refusal, so the
   two halves of one product were giving the owner different reasons for
   refusing the same edit. (See `SchemaTest`.)
2. **The price hold's cap counted the wrong unit.** `cleanHoldMessage` is capped
   by a plain JavaScript `slice(0, 300)`, which counts UTF-16 code units, so an
   emoji costs two. `mb_substr` counted code points and kept 200 emoji where the
   TypeScript keeps 150. (Note this is NOT the rule the schema caps follow - zod
   4 counts code points there. The two rules are genuinely different, and both
   are now implemented as they are.) (See `HoldTest`.)
3. **A missing field was silently accepted.** The absence of a key was
   represented by a magic *string* sentinel, and `is_string()` accepted it, so a
   document with a whole text field missing passed the schema and could be
   published. The sentinel is an object now.

It also settled two ordering questions that were guessed wrong, by running zod
rather than reasoning about it: an array reports its elements' problems *before*
its own ceiling, and a record is reported entry by entry in **JavaScript's** key
order (canonical integer keys hoisted to the front, ascending), not
values-then-keys and not PHP's insertion order.

## The one accepted divergence

`migrate()` carries an unknown key through in the TypeScript (`{...SEED.company,
...stored}` keeps whatever the stored document had); the PHP builds typed value
objects and drops it. Nothing renders, diffs or validates differently - zod does
not reject unknown keys either, so the junk is invisible in both apps - and
dropping it is the safer of the two. The `unknownKeys` case is therefore expected
to be the only mismatch the comparator reports.
