# Speedat admin guide

For the people who run the office. Sign in at `/admin` with the Google account or password
that was added as an admin. Owners can publish; editors can prepare changes for an owner.

## The daily rate update

**If the airline emails the sheet to the intake address**, you normally do nothing:

- A known sheet layout is read and applied automatically. If every price moved less than the
  auto-publish tolerance, it is published and the site updates. You get no email.
- If something moved more than the tolerance, or a check failed, the sheet is applied to the
  editor but **not published**, and you get an email saying why. Open *Rates*, read the
  review, and publish.
- If the layout is new, you get an email; open *Import from Excel* → *Map columns*, tell it
  once which column is which, and apply. Next time that layout is automatic.

**If you have the file yourself:** *Rates → Import from Excel → Rate sheet file*. The mapper
opens pre-filled. Check:

- *Header row number* highlights the row with the column names.
- Each field points at the right column. Sheets with one column per kilogram (1 kg, 2 kg …)
  are recognised and map to the price boxes; a sheet with "first" and "additional" columns
  fills the boxes from those slabs.
- Tick *Prices in this file are carrier costs — add my margin* if the sheet is what the
  airline charges you; set your margin % and rounding.
- The preview lists every destination with old → new prices; rows that will be skipped are
  listed with the reason.

*Apply to editor*, then *Review and publish*.

## Review and publish

The bottom bar shows how many unpublished changes exist. *Review and publish* lists every
change against what customers currently see; jumps of 25 % or more are flagged. Blocking
problems (empty names, missing prices, bad settings) must be fixed first; warnings (Express
cheaper than Normal, document price above the first slab) are advice.

*Publish* makes the change live within seconds. Every publish is kept under *Version history*;
*Restore into editor* brings an old version back for review — it is not live until you publish it.

*Discard* throws away all unpublished changes.

## Editing rates by hand

The *Rates* table has one row per destination. Per service it shows **Docs** (flat price for
documents up to the weight in Settings) and **Days** (transit time such as 3–5); the prices
themselves sit behind **Prices ▾**, which opens a box for every whole kilogram from 1 kg up
to the cargo threshold (25 kg by default). Type the price for each kilogram; a parcel is
charged at the next whole kilogram up (1.2 kg pays the 2 kg price). A blank box means that
kilogram is not offered — the customer is told to ask on WhatsApp — and a service with no
boxes at all is not offered for that destination.

*Quick fill* saves typing a run of boxes: enter the 1 kg price and the amount per extra
kilogram, then *Fill blanks* (only empty boxes) or *Fill all* (overwrite the lot), and adjust
the odd box by hand. *Add country* appends a destination; give it a name and fill its boxes.
Untick *On site* to hide a destination without deleting its prices. Changed rows are marked;
*Undo* restores that row. *Download as CSV* exports every box for a spreadsheet.

(Settings → *Pricing model* can switch back to the older "first slab + each additional step"
model; per-kilogram is the default.)

## Holding prices while rates change

When new airline rates arrive and the old prices must stop showing at once, press **Hold
prices** at the top of *Rates* and confirm. Within seconds the website replaces the
calculator with your message (editable in the confirmation box) and a short WhatsApp form —
customers can still send you their destination and weight, so no enquiry is lost, but no
price is shown or sent to their browser, and no quote can be logged.

Nothing in the editor changes: key in the new rates as usual (by hand or from a sheet),
then *Review and publish*. The review shows a ticked box *Show prices again after
publishing*; publishing with it ticked puts the new prices on the site in the same
moment. Untick it to publish quietly and keep holding. **Resume showing prices** on the hold
banner lifts the hold with the current live rates, for when nothing needed changing after all.

Any admin can hold; only an owner resumes or publishes. A hold that has lasted twelve hours
raises an alert email (and shows in the `/api/metrics` gauge `speedat_prices_held`) so a
forgotten hold does not quietly stop quotes for a day. Every hold and resume is in the audit log.

*Bulk adjust* raises or lowers many prices at once (by percent or amount, rounded).
*Test a price* prices any shipment against the editor, including unpublished changes.

## Settings and website text

*Settings* holds the company details, currency, weight slabs, tax, rounding, cargo threshold,
document weight limit, pickup cutoff hour, working days, **holidays** (one date per line,
skipped by delivery estimates), optional charges and the small print.

*Website pages* holds every sentence on the public site: headline, services, story, mission,
vision, values, contact details, FAQ. Formats are one item per line, parts separated by `|`.
Publishing rates publishes text too (they are one version).

## Inbox

Every customer who tapped *Book* on a quote, and every contact-form message, appears under
*Inbox* as a lead. Work them: **new → contacted → booked** (or **lost**). Notes save when you
leave the box. *Open in WhatsApp* starts a chat with the customer's number. *Create shipment*
turns the lead into a shipment.

## Shipments

A shipment has a customer, receiver, destination, service, carrier and tracking number, and a
status timeline: Booked → Picked up → In transit → At customs → Out for delivery → Delivered,
plus *Needs attention* for problems. *Add update* records a status with a note the customer
can see. *Send WhatsApp update* opens a prefilled message with the status and the tracking link.

Customers track at `/track` with the shipment id (SH-…), their quote id (SP-…) or the airline
tracking number.

## Security

*Security* shows the deployment checks, the admins, and recent activity. Turn on two-factor
sign-in with any authenticator app (Google Authenticator, Authy, 1Password): scan the code,
type the 6 digits. From then on sign-in asks for a code. If you lose the phone, an owner
resets it for you.

## Email intake settings (under Import from Excel)

- **Auto-publish tolerance**: 0 = never publish without a human; 5 = publish automatically
  when no price moves more than 5 %.
- **Allowed senders**: only these addresses/domains may deliver sheets by email.
- **Expect a sheet by hour**: you get one email a day when nothing arrived by then.
