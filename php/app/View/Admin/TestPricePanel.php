<?php

declare(strict_types=1);

namespace Speedat\View\Admin;

use Speedat\Core\Money;
use Speedat\Domain\Pricing\CivilDate;
use Speedat\Domain\Pricing\Dates;
use Speedat\Domain\Pricing\Format;
use Speedat\Domain\Pricing\PriceResult;
use Speedat\Domain\Pricing\QuoteText;

/**
 * "Test a price": price a parcel against the DRAFT, unpublished changes
 * included, so a rate can be checked before it goes live. Port of
 * TestPrice.tsx.
 *
 * The React panel priced in the browser. This one posts and the server prices
 * with `Speedat\Domain\Pricing\Engine`, deliberately: the point of the panel
 * is to prove that THE SERVER charges what the owner expects. A browser
 * engine agreeing with itself proves nothing.
 */
final class TestPricePanel
{
    /**
     * @param list<array{id:string,name:string}>                                                 $destinations active, A-Z
     * @param list<array{serviceId:string,name:string,days:string,total:string,hint:string,eta:string,offered:bool}> $cards
     */
    private function __construct(
        public readonly array $destinations,
        public readonly string $destId,
        public readonly string $kg,
        public readonly string $pieces,
        public readonly string $length,
        public readonly string $width,
        public readonly string $height,
        /** "" until something has been priced. */
        public readonly string $weightSentence,
        /** The line shown instead of prices: over the cargo threshold, or the prompt. */
        public readonly string $notice,
        public readonly array $cards = [],
        public readonly bool $priced = false,
    ) {
    }

    /** The empty panel, as the editor first renders it. */
    public static function blank(Site $site): self
    {
        return new self(
            destinations: self::options($site),
            destId: '',
            kg: '',
            pieces: '1',
            length: '',
            width: '',
            height: '',
            weightSentence: '',
            notice: 'Enter a destination and weight.',
        );
    }

    /**
     * The panel after a test, with whatever the engine answered.
     *
     * @param array<string,string> $input the posted form, already validated by the caller
     */
    public static function result(Site $site, array $input, PriceResult $result, ?CivilDate $today = null, int $nowHour = 0): self
    {
        $settings = $site->settings();

        $notice   = '';
        $sentence = '';
        $cards    = [];

        if (!$result->ok) {
            $notice = $result->reason === PriceResult::REASON_OVERMAX && $result->weights !== null
                ? 'Over the cargo threshold (' . Money::numberToString($settings->maxKg) . ' kg): the site asks the customer to message you. '
                  . QuoteText::weightSentence($result->weights, $settings)
                : 'Enter a destination and weight.';
        } else {
            $sentence = $result->weights === null ? '' : QuoteText::weightSentence($result->weights, $settings);

            foreach ($site->services() as $service) {
                $price = $result->price($service->id);

                if ($price === null) {
                    $cards[] = [
                        'serviceId' => $service->id,
                        'name'      => $service->name,
                        'days'      => '',
                        'total'     => "-",
                        'hint'      => 'not offered',
                        'eta'       => '',
                        'offered'   => false,
                    ];
                    continue;
                }

                // Grid mode names the kilogram box the price came out of, which
                // is the one thing the owner wants confirmed: "did it charge the
                // 3 kg box or the 4 kg one?". Slab mode shows the sum instead.
                $working = $price->gridKg !== null
                    ? $price->gridKg . ' kg box ' . Format::fmtNum($price->base)
                    : Format::fmtNum($price->first ?? 0.0)
                      . ($price->steps > 0 ? ' + ' . $price->steps . ' x ' . Format::fmtNum($price->addl ?? 0.0) : '');

                $eta = '';
                if ($settings->showEta && $price->days !== '' && $today !== null) {
                    $estimate = Dates::estimateFor($settings, $price->days, null, $today, $nowHour);
                    $eta      = $estimate === null ? '' : 'delivered ' . Format::fmtRange($estimate);
                }

                $cards[] = [
                    'serviceId' => $service->id,
                    'name'      => $service->name,
                    'days'      => $price->days,
                    'total'     => Format::fmtMoney($price->total, $settings->currency),
                    'hint'      => $working . ($price->tax > 0.0 ? ' + tax' : ''),
                    'eta'       => $eta,
                    'offered'   => true,
                ];
            }
        }

        return new self(
            destinations: self::options($site),
            destId: $input['destId'] ?? '',
            kg: $input['kg'] ?? '',
            pieces: $input['pieces'] ?? '1',
            length: $input['L'] ?? '',
            width: $input['W'] ?? '',
            height: $input['H'] ?? '',
            weightSentence: $sentence,
            notice: $notice,
            cards: $cards,
            priced: $result->ok,
        );
    }

    /*
     * There is no types() any more. The panel used to offer "Packages" or
     * "Documents"; the owner removed the document rate from the product on
     * 2026-09-27, so a test price has one kind of shipment - a parcel of some
     * weight - and a select with one option is a control that asks a question
     * with no second answer.
     */

    /** @return list<array{id:string,name:string}> */
    private static function options(Site $site): array
    {
        $out = [];
        foreach ($site->activeAlpha() as $dest) {
            $out[] = ['id' => $dest->id, 'name' => $dest->name];
        }

        return $out;
    }
}
