<?php

declare(strict_types=1);

namespace Speedat\Domain\Tracking;

use Speedat\Core\Http;

/**
 * 17TRACK's Tracking API, v2.4. https://api.17track.net/en/doc
 *
 * HOW 17TRACK WORKS, and why it suits a shop this size:
 *   - POST /register with a number (and a carrier code, or none to detect).
 *     That costs one unit of quota, ONCE per number - checking it again costs
 *     nothing. 17TRACK then polls the carrier itself, every 6-12 hours, until
 *     the parcel is delivered or goes quiet.
 *   - POST /gettrackinfo returns what it has. Free of quota. The cron calls it
 *     hourly; most hours the answer is the same as the last one, and the
 *     checkpoint fingerprints make that a no-op.
 *   - The key goes in a `17token` header. 3 requests a second, 40 numbers a
 *     call. Never in a URL, never in a log line.
 *
 * WHAT IS READ FROM THE ANSWER: each event's UTC time, its city and country,
 * and its stage/sub-status words. The description and the street, state and
 * postcode fields are deliberately ignored - see Stage for why the carrier's
 * prose never reaches the page. The description is read for exactly one
 * thing: a scan 17TRACK filed as plain "in transit" whose text says customs is
 * shown as "At customs", which is what the customer is waiting to hear.
 *
 * The transport is a seam for the tests: a closure taking (url, payload,
 * headers) and returning [status, body, error]. In production it is Http.
 */
final class SeventeenTrack implements TrackingProvider
{
    public const DEFAULT_BASE = 'https://api.17track.net/track/v2.4';

    /** "The tracking number is already registered." Not a failure for us. */
    public const ALREADY_REGISTERED = -18019901;

    /** "The tracking number is not registered." Register it again. */
    public const NOT_REGISTERED = -18019902;

    /** What TrackReport::$error says when the provider has forgotten a number. */
    public const ERROR_NOT_REGISTERED = 'not registered';

    private const BATCH = 40;

    /** @var \Closure(string,array<int,array<string,mixed>>,array<string,string>):array{0:int,1:string,2:string} */
    private readonly \Closure $transport;

    /**
     * @param (\Closure(string,array<int,array<string,mixed>>,array<string,string>):array{0:int,1:string,2:string})|null $transport
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $base = self::DEFAULT_BASE,
        ?\Closure $transport = null,
    ) {
        $this->transport = $transport ?? static function (string $url, array $payload, array $headers): array {
            $r = Http::postJson($url, $payload, $headers, 15);

            return [$r->status, $r->body, $r->error];
        };
    }

    public function name(): string
    {
        return '17track';
    }

    public function batchSize(): int
    {
        return self::BATCH;
    }

    public function register(array $requests): array
    {
        $out = [];
        foreach ($requests as $r) {
            $out[$r->number] = 'no answer from 17TRACK';
        }

        [$data, $error] = $this->call('register', $requests);
        if ($error !== '') {
            return array_map(static fn (): string => $error, $out);
        }

        foreach (self::items($data['accepted'] ?? null) as $item) {
            $number = self::str($item['number'] ?? null);
            if (isset($out[$number])) {
                $out[$number] = '';
            }
        }

        foreach (self::items($data['rejected'] ?? null) as $item) {
            $number = self::str($item['number'] ?? null);
            if (!isset($out[$number])) {
                continue;
            }
            [$code, $message] = self::rejection($item);
            $out[$number] = $code === self::ALREADY_REGISTERED ? '' : $message;
        }

        return $out;
    }

    public function fetch(array $requests): array
    {
        [$data, $error] = $this->call('gettrackinfo', $requests);

        $out = [];
        if ($error !== '') {
            foreach ($requests as $r) {
                $out[$r->number] = TrackReport::failed($error);
            }

            return $out;
        }

        foreach (self::items($data['accepted'] ?? null) as $item) {
            $number = self::str($item['number'] ?? null);
            if ($number !== '') {
                $out[$number] = self::report($item);
            }
        }

        foreach (self::items($data['rejected'] ?? null) as $item) {
            $number = self::str($item['number'] ?? null);
            if ($number === '') {
                continue;
            }
            [$code, $message] = self::rejection($item);
            $out[$number] = TrackReport::failed($code === self::NOT_REGISTERED ? self::ERROR_NOT_REGISTERED : $message);
        }

        return $out;
    }

    /**
     * One 17TRACK sub-status (or stage) word, as one of ours.
     *
     * The sub-status is the finer of the two and is tried first:
     * "InTransit_CustomsProcessing" is customs where the stage would only have
     * said "Arrival". Anything unknown reads as in transit when the parcel is
     * clearly moving, and as nothing when 17TRACK itself has nothing
     * (NotFound, Expired) - an unknown word is never shown as "Delivered".
     */
    public static function stageOf(string $subStatus, string $stage = '', string $text = ''): ?string
    {
        $s = $subStatus !== '' ? $subStatus : $stage;

        $exact = [
            'InTransit_PickedUp'                    => Stage::PICKED_UP,
            'InTransit_CustomsProcessing'           => Stage::CUSTOMS,
            'InTransit_CustomsRequiringInformation' => Stage::CUSTOMS,
            'InTransit_CustomsReleased'             => Stage::IN_TRANSIT,
            'Exception_Returning'                   => Stage::RETURNING,
            'Exception_Returned'                    => Stage::RETURNING,
            // The stage vocabulary.
            'PickedUp'                              => Stage::PICKED_UP,
            'Departure'                             => Stage::IN_TRANSIT,
            'Arrival'                               => Stage::IN_TRANSIT,
            'Returning'                             => Stage::RETURNING,
            'Returned'                              => Stage::RETURNING,
        ];

        $stageWord = $exact[$s] ?? null;

        if ($stageWord === null) {
            $prefix = strtok($s, '_') ?: '';
            $stageWord = match ($prefix) {
                'InfoReceived'       => Stage::INFO_RECEIVED,
                'InTransit'          => Stage::IN_TRANSIT,
                'AvailableForPickup' => Stage::COLLECTION,
                'OutForDelivery'     => Stage::OUT_FOR_DELIVERY,
                'DeliveryFailure'    => Stage::ATTEMPTED,
                'Delivered'          => Stage::DELIVERED,
                'Exception'          => Stage::EXCEPTION,
                default              => null,
            };
        }

        if ($stageWord === Stage::IN_TRANSIT && preg_match('/\bcustoms?\b|clearance/i', $text) === 1
            && preg_match('/\breleased?\b|\bcleared\b/i', $text) !== 1) {
            return Stage::CUSTOMS;
        }

        return $stageWord;
    }

    /* ------------------------------------------------------------ internals */

    /**
     * @param list<TrackRequest> $requests
     * @return array{0:array<array-key,mixed>,1:string} [data, error]
     */
    private function call(string $endpoint, array $requests): array
    {
        if ($this->apiKey === '') {
            return [[], 'no 17TRACK key in config.php'];
        }
        if ($requests === []) {
            return [[], ''];
        }

        $payload = [];
        foreach (array_slice($requests, 0, self::BATCH) as $r) {
            $row = ['number' => $r->number];
            if ($r->carrierCode > 0) {
                $row['carrier'] = $r->carrierCode;
            }
            $payload[] = $row;
        }

        [$status, $body, $error] = ($this->transport)(
            rtrim($this->base, '/') . '/' . $endpoint,
            $payload,
            ['17token' => $this->apiKey],
        );

        if ($error !== '') {
            return [[], 'could not reach 17TRACK: ' . $error];
        }
        if ($status === 429) {
            return [[], '17TRACK is busy (too many requests); will try again'];
        }
        if ($status === 401 || $status === 403) {
            return [[], '17TRACK refused the API key'];
        }
        if ($status < 200 || $status >= 300) {
            return [[], '17TRACK answered HTTP ' . $status];
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            return [[], '17TRACK sent an answer that is not JSON'];
        }

        $code = $json['code'] ?? null;
        if ($code !== 0 && $code !== '0') {
            $message = self::str($json['data']['errors'][0]['message'] ?? null);

            return [[], '17TRACK error ' . self::str($code) . ($message !== '' ? ': ' . $message : '')];
        }

        $data = $json['data'] ?? [];

        return [is_array($data) ? $data : [], ''];
    }

    /** @param array<array-key,mixed> $item */
    private static function report(array $item): TrackReport
    {
        $info   = is_array($item['track_info'] ?? null) ? $item['track_info'] : [];
        $latest = is_array($info['latest_status'] ?? null) ? $info['latest_status'] : [];

        $latestStage = self::stageOf(
            self::str($latest['sub_status'] ?? null),
            self::str($latest['status'] ?? null),
        );

        $checkpoints = [];
        $providers   = $info['tracking']['providers'] ?? [];
        foreach (self::items($providers) as $provider) {
            foreach (self::items($provider['events'] ?? null) as $event) {
                $cp = self::checkpoint($event);
                if ($cp !== null) {
                    $checkpoints[$cp->fingerprint] = $cp;
                }
            }
        }

        $checkpoints = array_values($checkpoints);
        usort($checkpoints, static fn (Checkpoint $a, Checkpoint $b): int => $a->at <=> $b->at);

        // The latest scan says customs where 17TRACK's summary only says "in
        // transit": the scan is the more specific of the two.
        if ($latestStage === Stage::IN_TRANSIT && $checkpoints !== []
            && $checkpoints[count($checkpoints) - 1]->stage === Stage::CUSTOMS) {
            $latestStage = Stage::CUSTOMS;
        }

        $carrier = $item['carrier'] ?? 0;

        return new TrackReport($latestStage, $checkpoints, '', is_int($carrier) ? $carrier : (int) self::str($carrier));
    }

    /** @param array<array-key,mixed> $event */
    private static function checkpoint(array $event): ?Checkpoint
    {
        $text  = self::str($event['description'] ?? null);
        $stage = self::stageOf(self::str($event['sub_status'] ?? null), self::str($event['stage'] ?? null), $text);
        if ($stage === null) {
            // An event 17TRACK could not classify at all is still a scan, and
            // the carrier reported it, so the parcel is moving.
            $stage = Stage::IN_TRANSIT;
        }

        $utc = self::str($event['time_utc'] ?? null);
        $iso = self::str($event['time_iso'] ?? null);
        if ($utc === '' && $iso === '') {
            // An undated scan cannot be placed on a timeline; "now" would be a lie.
            return null;
        }
        try {
            $at = new \DateTimeImmutable($utc !== '' ? $utc : $iso);
        } catch (\Exception) {
            return null;
        }
        $at = $at->setTimezone(new \DateTimeZone('UTC'));

        $address = is_array($event['address'] ?? null) ? $event['address'] : [];
        $city    = Checkpoint::place(self::str($address['city'] ?? null));
        if ($city === '') {
            // Older carriers fill only `location`, often "CITY - COUNTRY".
            $city = Checkpoint::place((string) strtok(self::str($event['location'] ?? null), ',-/|'));
        }

        $fingerprint = sha1($at->format('c') . '|' . $text . '|' . self::str($event['location'] ?? null));

        return new Checkpoint($at, $city, Checkpoint::country(self::str($address['country'] ?? null)), $stage, $fingerprint);
    }

    /**
     * @param array<array-key,mixed> $item
     * @return array{0:int,1:string}
     */
    private static function rejection(array $item): array
    {
        $error   = is_array($item['error'] ?? null) ? $item['error'] : [];
        $code    = (int) self::str($error['code'] ?? null);
        $message = self::str($error['message'] ?? null);

        return [$code, $message !== '' ? $message : 'rejected by 17TRACK (' . $code . ')'];
    }

    /** @return list<array<array-key,mixed>> */
    private static function items(mixed $list): array
    {
        if (!is_array($list)) {
            return [];
        }

        return array_values(array_filter($list, 'is_array'));
    }

    private static function str(mixed $v): string
    {
        return is_scalar($v) ? trim((string) $v) : '';
    }
}
