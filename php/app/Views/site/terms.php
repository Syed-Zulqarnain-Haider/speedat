<?php

declare(strict_types=1);

/**
 * /terms - the terms and conditions.
 *
 * STANDARD international-courier terms, drafted for the owner to review (the
 * draft notice at the top says so in plain words). The legal copy is static and
 * written here; the only live values are the contact details, which come off
 * the published document the footer already reads, so they can never disagree
 * with the rest of the site. Every printed value goes through e().
 *
 * House style: no em dashes anywhere a reader can see; " - " or a reworded
 * sentence instead.
 *
 * @var \Speedat\Core\View     $this
 * @var \Speedat\View\PageView $page
 */

$this->layout('site/layout', ['page' => $page]);

$doc     = $page->doc;
$company = $doc->company()->name !== '' ? $doc->company()->name : 'Speedat International Courier';
$email   = $doc->company()->email;
$phone   = $doc->company()->phone;
$tel     = $doc->telHref();
$waHref  = $doc->waHref();
$waHas   = $doc->company()->whatsapp !== '';
?>
<section class="page terms">
<?= $this->partial('site/partials/page-head', ['title' => 'Terms and Conditions']) ?>

  <div class="notice" role="note">
    <strong>Draft for review.</strong> These terms are a working draft prepared
    for <?= e($company) ?> to review and approve. Please read them, change
    anything that does not match how you work, and confirm before they go live.
  </div>

  <div class="prose terms-body">
    <h2>1. About these terms</h2>
    <p>
      These terms apply when you ask <?= e($company) ?> ("we", "us", "our") to
      collect, carry and deliver a shipment. By booking a shipment with us, in
      person, by phone or on WhatsApp, you ("the sender") agree to them. If
      anything here is unclear, please ask us before you book.
    </p>

    <h2>2. Prices and quotes</h2>
    <p>
      Any price we show on this website or quote to you in advance is an
      estimate. The final price is based on the actual weight and size of your
      shipment, which is confirmed when we weigh and measure it at pickup. Where
      the volumetric (dimensional) weight is greater than the actual weight, the
      price is based on the greater of the two, which is standard across the
      courier industry.
    </p>
    <p>
      Prices are quoted in Pakistani Rupees unless we agree otherwise in
      writing, and do not include any duties or taxes charged at the
      destination (see section 6).
    </p>

    <h2>3. Pickup</h2>
    <p>
      We offer free pickup within Lahore. Please have the shipment ready and
      properly packed, and have the receiver's full name, complete address and
      phone number to hand. If a pickup cannot go ahead because the shipment is
      not ready, is not packed, or the details are missing, we may need to
      rearrange it.
    </p>

    <h2>4. What you can and cannot send</h2>
    <p>
      Some goods cannot be carried by air, or cannot be sent to certain
      countries, by law or by the rules of the airlines and carriers we use.
      You must not ask us to carry prohibited or restricted items. These
      include, but are not limited to:
    </p>
    <ul>
      <li>Batteries and devices containing them, where restricted for air transport</li>
      <li>Liquids, aerosols, paints and other items that can leak or are pressurised</li>
      <li>Perishable food and other items that can spoil in transit</li>
      <li>Flammable, explosive, corrosive or otherwise dangerous goods</li>
      <li>Illegal drugs, weapons, counterfeit goods and anything unlawful to send or receive</li>
      <li>Cash, jewellery, precious metals and stones, and other items of exceptional value</li>
    </ul>
    <p>
      If you are not sure whether an item can be sent, ask us first. We may
      refuse or return any shipment that contains a prohibited or restricted
      item, and you remain responsible for any fine, penalty or cost that
      results from sending one.
    </p>

    <h2>5. Declaring your shipment honestly</h2>
    <p>
      You must describe the contents and value of your shipment truthfully and
      completely on the paperwork. Customs authorities rely on this
      declaration. A wrong, incomplete or dishonest declaration can lead to
      delay, seizure, fines or return of the shipment, and the sender is
      responsible for the consequences. We may ask to see the contents before
      we accept a shipment.
    </p>

    <h2>6. Duties, taxes and customs</h2>
    <p>
      International shipments may be subject to customs inspection and to
      duties, taxes and other charges in the destination country. Unless we
      have agreed otherwise in writing, these charges are the responsibility of
      the receiver and are paid in addition to our price. We are not
      responsible for delays caused by customs, and the time a shipment spends
      in customs does not count toward any delivery estimate.
    </p>

    <h2>7. Delivery times</h2>
    <p>
      Any delivery time we give is an estimate based on normal conditions and
      the service you choose. It is not a guarantee. Delivery can be affected by
      customs, weather, flight schedules, the carrier's own network and other
      events outside our control. A longer transit time on its own is not a
      reason for a refund.
    </p>

    <h2>8. Our liability</h2>
    <p>
      We take care of your shipment, but our liability for loss of or damage to
      a shipment is limited. Because your shipment travels with international
      carriers, our liability follows the limits set by the carrier we use and
      by the international conventions that apply to air carriage, calculated by
      the weight of the shipment. We are not liable for indirect or
      consequential loss, such as lost profit or missed opportunity, and we are
      not liable for loss or damage caused by poor packing, a wrong or
      dishonest declaration, a prohibited item, or any cause outside our
      reasonable control. If you are sending something of high value, tell us
      before you book so we can discuss cover.
    </p>

    <h2>9. Making a claim</h2>
    <p>
      If something goes wrong, contact us as soon as possible and in any case
      within seven days of the expected delivery date. Keep the shipment, its
      packaging and your receipt, as we may need to see them. Tell us the
      tracking reference, what was sent and what happened, and we will look into
      it with the carrier.
    </p>

    <h2>10. Contact us</h2>
    <p>
      The fastest way to reach us is WhatsApp. You can also call or email.
    </p>
    <ul class="terms-contact">
<?php if ($waHas) { ?>
      <li>WhatsApp: <a href="<?= e($waHref) ?>" target="_blank" rel="noopener">message us</a></li>
<?php } ?>
<?php if ($tel !== '' && $phone !== '') { ?>
      <li>Phone: <a href="<?= e($tel) ?>"><?= e($phone) ?></a></li>
<?php } ?>
<?php if ($email !== '') { ?>
      <li>Email: <a href="<?= e('mailto:' . $email) ?>"><?= e($email) ?></a></li>
<?php } ?>
    </ul>
  </div>
</section>
