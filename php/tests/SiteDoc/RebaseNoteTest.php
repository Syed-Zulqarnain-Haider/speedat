<?php

declare(strict_types=1);

namespace Speedat\Tests\SiteDoc;

use Speedat\Domain\SiteDoc\RebaseNote;
use Speedat\Tests\TestCase;

/** The words the editor shows after a catch-up, and the stored row it is read back from. */
final class RebaseNoteTest extends TestCase
{
    public function testAPriceClashIsWordedTheWayTheEditorNamesTheField(): void
    {
        $note = $this->note([[
            'path' => 'destinations > United Kingdom > rates > saver > grid > 14',
            'keys' => ['destinations', '#gb', 'rates', 'saver', 'grid', '14'],
            'live' => 29000, 'draft' => 2900, 'liveMissing' => false, 'draftMissing' => false,
        ]]);

        $this->assertSame(
            ["United Kingdom \u{00b7} Express Saver 14 kg: kept PKR 29,000 from version 18 - your draft had PKR 2,900"],
            $note->openLines(),
        );
    }

    public function testARemovedCountryIsSaidToBeRemovedNotBlank(): void
    {
        $note = $this->note([[
            'path' => 'destinations > Pakistan', 'keys' => ['destinations', '#pk'],
            'live' => null, 'draft' => ['id' => 'pk', 'name' => 'Pakistan', 'active' => true],
            'liveMissing' => true, 'draftMissing' => false,
        ]]);

        $line = $note->openLines()[0];
        $this->assertStringContains('version 18 removed it', $line);
        $this->assertStringContains('name: Pakistan', $line, 'what the draft held is not shown, so it cannot be retyped');
        $this->assertStringNotContains('(a list)', $line);
    }

    public function testTheLeadSentenceNamesTheVersionAndSaysEveryEditIsKept(): void
    {
        $s = $this->note([], 18)->sentence(0);

        $this->assertStringContains('(version 18)', $s);
        $this->assertStringContains('All your own edits are still here', $s);
        $this->assertStringNotContains('version 18: 18', $s);
    }

    public function testOpenClashesAreCountedAndTheBoxNeverSaysEverythingWasKept(): void
    {
        $note = $this->note([['path' => 'settings > addons', 'keys' => ['settings', 'addons'], 'live' => 'a', 'draft' => 'b', 'liveMissing' => false, 'draftMissing' => false]], 0);

        $open = $note->sentence(1);
        $this->assertStringContains('1 of your edits was replaced', $open);
        $this->assertStringNotContains('All your own edits', $open);

        $this->assertStringContains('You have dealt with every item that clashed', $note->sentence(0));
        $this->assertFalse($note->isEmpty());
        $this->assertTrue($this->note([], 0)->isEmpty());
    }

    public function testTheDateIsWhenTheWebsiteChangedNotWhenTheDraftWasCaughtUp(): void
    {
        $note = new RebaseNote(17, 18, 1, [], '2026-10-26T08:00:00.000Z', 'PKR', [], '2026-10-11T08:41:29.000Z');

        $this->assertStringContains('11 Oct', $note->sentence(0));
        $this->assertStringNotContains('26 Oct', $note->sentence(0));
    }

    public function testADamagedRowReadsAsNothingRatherThanAnError(): void
    {
        foreach ([null, 'x', [], ['from' => 'a', 'to' => 2], ['from' => 1, 'to' => 2, 'conflicts' => [['path' => ['x']]], 'at' => ['y']]] as $raw) {
            $note = RebaseNote::fromArray($raw);
            if ($note !== null) {
                $this->assertSame([], $note->conflicts);
                $this->assertSame('', $note->at);
            }
        }
        $this->assertNull(RebaseNote::fromArray(['from' => 'a', 'to' => 2]));
    }

    public function testAStoredNoteRoundTrips(): void
    {
        $note = $this->note([['path' => 'settings > addons', 'keys' => ['settings', 'addons'], 'live' => 'a', 'draft' => 'b', 'liveMissing' => false, 'draftMissing' => false]]);

        $this->assertSame($note->toArray(), RebaseNote::fromArray($note->toArray())->toArray());
    }

    /** @param list<array<string,mixed>> $conflicts */
    private function note(array $conflicts, int $carried = 18): RebaseNote
    {
        return new RebaseNote(17, 18, $carried, $conflicts, '', 'PKR', ['express' => 'Express', 'normal' => 'Normal', 'saver' => 'Express Saver']);
    }
}
