<?php

declare(strict_types=1);

namespace Speedat\Tests\ViewAdmin;

use Speedat\View\Admin\SettingsFields;

/**
 * The add-ons box in Settings teaches all three states in plain words (owner,
 * 2026-10-26: rates show the courier rate only; the pickup charge is shown
 * under the quote, never added). An owner who does not know the word
 * "separate" exists will type "on" and put the pickup charge back into every
 * price on the site, which is the exact thing that was asked to stop.
 */
final class AddonsHintTest extends AdminViewTestCase
{
    public function testTheAddonsHintExplainsOnOffAndSeparate(): void
    {
        $label = null;
        foreach (SettingsFields::groups($this->draft()) as $group) {
            foreach ($group['rows'] as $row) {
                foreach ($row['fields'] as $field) {
                    if ($field->name === 'settings[addons]') {
                        $label = $field->label;
                    }
                }
            }
        }

        $this->assertNotNull($label, 'the add-ons field is missing from Settings');
        $this->assertStringContains('on = added to every price', (string) $label);
        $this->assertStringContains('off = the customer can tick it', (string) $label);
        $this->assertStringContains('separate = never added to any price', (string) $label);
        $this->assertStringContains('Pickup | 1000 | separate', (string) $label);
        $this->assertStringNotContains("\u{2014}", (string) $label);
    }
}
