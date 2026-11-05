<?php

declare(strict_types=1);

namespace Speedat\Tests\Tracking;

use Speedat\Domain\Tracking\SeventeenTrack;
use Speedat\Domain\Tracking\Stage;
use Speedat\Domain\Tracking\TrackRequest;
use Speedat\Tests\TestCase;

/**
 * The 17TRACK adapter against recorded answers, with no network: what is sent
 * (endpoint, header, carrier code), what is read back, and above all what is
 * NOT read back - the carrier's prose, the street, a phone number in the text.
 */
final class SeventeenTrackTest extends TestCase
{
    /** @var list<array{url:string,payload:array<int,array<string,mixed>>,headers:array<string,string>}> */
    private array $calls = [];

    public function testRegisterSendsTheKeyInAHeaderAndTheCarrierCodeOnlyWhenKnown(): void
    {
        $api = $this->api(200, (string) json_encode([
            'code' => 0,
            'data' => ['accepted' => [['number' => 'A1'], ['number' => 'B2']], 'rejected' => []],
        ]));

        $answers = $api->register([new TrackRequest('A1', 100001), new TrackRequest('B2', 0)]);

        $this->assertSame(['A1' => '', 'B2' => ''], $answers);
        $this->assertCount(1, $this->calls);
        $this->assertSame('https://api.example.test/track/v2.4/register', $this->calls[0]['url']);
        $this->assertSame(['17token' => 'secret-key'], $this->calls[0]['headers']);
        $this->assertSame([['number' => 'A1', 'carrier' => 100001], ['number' => 'B2']], $this->calls[0]['payload'],
            'carrier 0 means "detect", so it is left out rather than sent as 0');
        $this->assertStringNotContains('secret-key', $this->calls[0]['url'], 'the key is never in a URL');
    }

    public function testAlreadyRegisteredIsNotAFailureButAnyOtherRejectionIs(): void
    {
        $api = $this->api(200, (string) json_encode([
            'code' => 0,
            'data' => [
                'accepted' => [],
                'rejected' => [
                    ['number' => 'OLD', 'error' => ['code' => SeventeenTrack::ALREADY_REGISTERED, 'message' => 'already registered']],
                    ['number' => 'BAD', 'error' => ['code' => -18019903, 'message' => 'Invalid carrier']],
                ],
            ],
        ]));

        $this->assertSame(['OLD' => '', 'BAD' => 'Invalid carrier'], $api->register([new TrackRequest('OLD', 0), new TrackRequest('BAD', 0)]));
    }

    public function testATransportFailureIsAReasonForEveryNumberAndNeverAnException(): void
    {
        $cases = [
            [0, '', 'timed out', 'could not reach 17TRACK'],
            [429, '', '', 'busy'],
            [401, '', '', 'refused the API key'],
            [500, '', '', 'HTTP 500'],
            [200, 'not json', '', 'not JSON'],
            [200, '{"code":-18010002,"data":{"errors":[{"code":-18010002,"message":"Invalid token"}]}}', '', 'Invalid token'],
        ];

        foreach ($cases as [$status, $body, $error, $expect]) {
            $api = $this->api($status, $body, $error);

            $answers = $api->register([new TrackRequest('A1', 0)]);
            $this->assertStringContains($expect, $answers['A1'], 'register, HTTP ' . $status);

            $reports = $api->fetch([new TrackRequest('A1', 0)]);
            $this->assertStringContains($expect, $reports['A1']->error, 'fetch, HTTP ' . $status);
            $this->assertNull($reports['A1']->latest);
        }
    }

    public function testNoKeyMeansNoCall(): void
    {
        $api = new SeventeenTrack('', 'https://api.example.test/track/v2.4', function (): array {
            $this->calls[] = ['url' => '', 'payload' => [], 'headers' => []];

            return [200, '{}', ''];
        });

        $this->assertStringContains('no 17TRACK key', $api->register([new TrackRequest('A1', 0)])['A1']);
        $this->assertCount(0, $this->calls);
    }

    public function testATrackInfoAnswerBecomesSafeCheckpointsOldestFirst(): void
    {
        $api     = $this->api(200, $this->fixture());
        $reports = $api->fetch([new TrackRequest('1234567890', 100001)]);

        $report = $reports['1234567890'];
        $this->assertSame('', $report->error);
        $this->assertSame(100001, $report->carrierCode);
        $this->assertCount(3, $report->checkpoints, 'the undated event is dropped, not dated "now"');

        [$first, $second, $third] = $report->checkpoints;

        $this->assertSame(Stage::PICKED_UP, $first->stage);
        $this->assertSame('Lahore', $first->city, 'the postcode after the city is cut off');
        $this->assertSame('PK', $first->country);
        $this->assertSame('2026-10-25 10:00:00', $first->at->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $first->at->getTimezone()->getName());

        $this->assertSame(Stage::IN_TRANSIT, $second->stage);
        $this->assertSame('', $second->city, 'no address city: the free-text location is never used');
        $this->assertSame('PK', $second->country);

        $this->assertSame(Stage::CUSTOMS, $third->stage, 'a scan whose text says customs reads as customs');
        $this->assertSame('Dubai', $third->city);
        $this->assertSame('AE', $third->country);

        $this->assertSame(Stage::CUSTOMS, $report->latest, 'the newest scan sharpens 17TRACK\'s "in transit"');

        // Nothing the carrier wrote in prose, and no street, survives.
        $dump = var_export($report, true);
        foreach (['AHMED', 'KHAN', '971', 'SARA', 'Airport Free Zone', 'Mall Road', 'Jumeirah', 'departed'] as $secret) {
            $this->assertStringNotContains($secret, $dump, $secret . ' must not reach a checkpoint');
        }
    }

    public function testRejectedNumbersInAFetch(): void
    {
        $reports = $this->api(200, $this->fixture())->fetch([
            new TrackRequest('FORGOTTEN1', 0),
            new TrackRequest('BAD1', 0),
        ]);

        $this->assertSame(SeventeenTrack::ERROR_NOT_REGISTERED, $reports['FORGOTTEN1']->error);
        $this->assertSame('Carrier Code 100001 is incorrect.', $reports['BAD1']->error);
    }

    public function testTheSameScanReportedTwiceHasTheSameFingerprint(): void
    {
        $a = $this->api(200, $this->fixture())->fetch([new TrackRequest('1234567890', 0)])['1234567890'];
        $b = $this->api(200, $this->fixture())->fetch([new TrackRequest('1234567890', 0)])['1234567890'];

        $this->assertSame(
            array_map(static fn ($c): string => $c->fingerprint, $a->checkpoints),
            array_map(static fn ($c): string => $c->fingerprint, $b->checkpoints),
        );
        $this->assertSame(40, strlen($a->checkpoints[0]->fingerprint));
    }

    public function testStageWords(): void
    {
        $cases = [
            ['InfoReceived', '', '', Stage::INFO_RECEIVED],
            ['InTransit_PickedUp', '', '', Stage::PICKED_UP],
            ['InTransit_Other', '', '', Stage::IN_TRANSIT],
            ['InTransit_CustomsProcessing', '', '', Stage::CUSTOMS],
            ['InTransit_CustomsReleased', '', 'Customs released', Stage::IN_TRANSIT],
            ['InTransit_Other', '', 'Clearance event', Stage::CUSTOMS],
            ['InTransit_Other', '', 'Cleared by customs', Stage::IN_TRANSIT],
            ['OutForDelivery_Other', '', '', Stage::OUT_FOR_DELIVERY],
            ['AvailableForPickup_Other', '', '', Stage::COLLECTION],
            ['DeliveryFailure_NoBody', '', '', Stage::ATTEMPTED],
            ['Delivered_Other', '', '', Stage::DELIVERED],
            ['Exception_Returning', '', '', Stage::RETURNING],
            ['Exception_Other', '', '', Stage::EXCEPTION],
            ['Exception_Security', '', '', Stage::CUSTOMS],
            ['DeliveryFailure_Security', '', '', Stage::CUSTOMS],
            ['InTransit_Departure', '', '', Stage::IN_TRANSIT],
            ['NotFound_InvalidCode', '', '', null],
            ['', 'Departure', '', Stage::IN_TRANSIT],
            ['', 'Returned', '', Stage::RETURNING],
            ['NotFound_Other', '', '', null],
            ['Expired_Other', '', '', null],
            ['SomethingNew', '', '', null],
        ];

        foreach ($cases as [$sub, $stage, $text, $expected]) {
            $this->assertSame($expected, SeventeenTrack::stageOf($sub, $stage, $text), $sub . '/' . $stage . '/' . $text);
        }
    }

    public function testOneCallCarriesAtMostOneBatch(): void
    {
        $api = $this->api(200, '{"code":0,"data":{"accepted":[],"rejected":[]}}');

        $requests = [];
        for ($i = 0; $i < 45; $i++) {
            $requests[] = new TrackRequest('N' . $i, 0);
        }
        $api->register($requests);

        $this->assertSame(40, $api->batchSize());
        $this->assertCount(40, $this->calls[0]['payload']);
    }

    public function testARequestLevelErrorWithCodeZeroIsStillAnError(): void
    {
        // The v2.4 doc's own example of a rejected request.
        $api = $this->api(200, '{"code":0,"data":{"errors":[{"code":-18010013,"message":"Submitted data is invalid."}]}}');

        $this->assertStringContains('Submitted data is invalid', $api->register([new TrackRequest('A1', 0)])['A1']);
        $this->assertStringContains('-18010013', $api->fetch([new TrackRequest('A1', 0)])['A1']->error);
    }

    public function testANumberAnsweredForSeveralCarriersKeepsTheOneAskedFor(): void
    {
        $item = static fn (int $carrier, int $events): array => [
            'number'     => 'MULTI',
            'carrier'    => $carrier,
            'track_info' => [
                'latest_status' => ['status' => 'InTransit', 'sub_status' => 'InTransit_Other'],
                'tracking'      => ['providers' => [['events' => array_map(
                    static fn (int $i): array => ['time_utc' => '2026-10-2' . $i . 'T10:00:00Z', 'description' => 'scan ' . $i, 'location' => 'X', 'sub_status' => 'InTransit_Other', 'address' => []],
                    range(1, $events),
                )]]],
            ],
        ];
        $body = (string) json_encode(['code' => 0, 'data' => [
            'accepted' => [$item(100007, 1), $item(100010, 3)],
            'rejected' => [['number' => 'MULTI', 'carrier' => '100071', 'error' => ['code' => -18019909, 'message' => 'No tracking information at this time.']]],
        ]]);

        $detected = $this->api(200, $body)->fetch([new TrackRequest('MULTI', 0)])['MULTI'];
        $this->assertSame(100010, $detected->carrierCode, 'asked without a carrier: the answer with the most scans');
        $this->assertSame('', $detected->error, 'a rejection for a third carrier does not undo an answer');

        $asked = $this->api(200, $body)->fetch([new TrackRequest('MULTI', 100007)])['MULTI'];
        $this->assertSame(100007, $asked->carrierCode, 'asked for a carrier: that carrier\'s answer');
    }

    /* ------------------------------------------------------------- helpers */

    private function api(int $status, string $body, string $error = ''): SeventeenTrack
    {
        $this->calls = [];

        return new SeventeenTrack(
            'secret-key',
            'https://api.example.test/track/v2.4/',
            function (string $url, array $payload, array $headers) use ($status, $body, $error): array {
                $this->calls[] = ['url' => $url, 'payload' => $payload, 'headers' => $headers];

                return [$status, $body, $error];
            },
        );
    }

    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/17track-gettrackinfo.json');
    }
}
