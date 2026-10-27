# Speedat admin guide

For the people who run the office. Everything is at **`https://your-domain/admin`**.

Nothing in here needs a computer person. If a step does, it says so and points at
[`runbook.md`](./runbook.md).

There are two kinds of account. An **owner** can publish and can put prices back
on the website. An **editor** can prepare changes but not publish them; an owner
presses the button. Ask an owner to add you if you cannot get in.

---

## 1. Signing in

Open `/admin`. Two ways in, and both end the same way:

- **Continue with Google** — the account that was added as an admin. Use a
  normal Chrome or Safari window, not the browser inside WhatsApp, Instagram
  or Facebook: Google refuses sign-in there.
- **Email and password** — only while the owner has this switched on.

If you have turned on two-factor under *Security*, it then asks for the
**6-digit code** from the authenticator app on your phone.
The code changes every thirty seconds; type the one showing now. A code only
works once, so if you mistype it, wait for the next one.

**The code is refused although it looks right.** Almost always the phone's clock
has drifted. Turn on automatic date and time on the phone and try again.

**You have lost the phone.** An owner clears your second factor for you (it is
one step in the runbook), then you set it up again under *Security*.

**Google says the domain is not authorised.** The site is fine; one setting in
Firebase is missing. That is section 1 step 3 of
[`firebase-setup.md`](./firebase-setup.md).

**Google sign-in does not work at all and there is no password form.** The
owner follows section 7.7 of [`security.md`](./security.md) ("Google sign-in
is broken"). It works even for an account that never had a password.

You are signed out after eight hours of doing nothing, and after five days
whatever happens. Signing out on purpose is the button at the bottom of the menu.

---

## 2. The four screens

| Screen | What it is for |
|---|---|
| **01 — Rates** | prices, settings, the words on the website, and the publish button |
| **02 — Inbox** | every customer who asked for something |
| **03 — Shipments** | every parcel and where it has got to |
| **04 — Security** | your 6-digit code, who else can sign in, and what everyone did |

*Rates* is one long page in six parts: **Rates**, **Bulk adjust**, **Test a
price**, **Settings**, **Website pages**, **History**. The tabs at the top jump
between them.

---

## 3. Keying in prices

*Rates* has one line per country. On each line, for each service, you see the
**days** (the transit time customers are shown, such as `3-5`) and a summary of
the prices like `4,500 – 21,000 (12/25)` — the cheapest and the dearest box, and
that 12 of the 25 boxes have a price in them. Once every box is priced the
`(12/25)` part goes away, so a bare `4,500 – 21,000` means the line is finished.

**There is no document price.** Everything is priced by weight; papers are
quoted like any other parcel. If somebody is sending something unusual they ask
on WhatsApp.

Press **Prices** on the line to open the boxes. There is **one box for every
whole kilogram, 1 kg to 25 kg**. Type what you charge for each.

Three rules worth knowing, because they are how customers get quoted:

1. **A parcel is charged at the next whole kilogram up.** 1.2 kg pays the 2 kg
   price.
2. **A blank box means that weight is not offered.** A customer whose parcel
   lands on a blank box is told there is no rate for that weight and asked to
   message on WhatsApp — the site never borrows the next heavier box's price
   (owner, 2026-09-28; it used to, and charged numbers nobody had typed). So
   leave a box blank only when you really do not carry that weight, and fill
   every weight you do. **`0` means the same as blank**, not free: the site
   never shows a price of 0.
3. **A service with no boxes at all is not offered to that country.**

### The keyboard, so you never reach for the mouse

Twenty-five boxes is the job. Once your hand is in the first box it does not
have to leave:

| key | what it does |
|---|---|
| **Enter** or **↓** | next kilogram |
| **Shift+Enter** or **↑** | back one kilogram |
| **Tab** / **Shift+Tab** | across the row, then on to the next service |
| **Esc** | puts *this* box back the way you found it |

**Esc undoes one box, not the line.** If you have made a mess of a whole
country, use **Undo** on the line instead.

The same keys are printed under the table, so you do not have to remember them.

### Paste a column straight from a spreadsheet

This is the fastest way in, and the one worth learning first.

Copy a column of numbers out of the carrier's rate sheet — Excel, Sheets, a
mail, anything — click the kilogram you want it to **start** at, and paste.
The numbers go down the ladder from there.

- It fills **from the box you clicked downwards**, so you can paste a correction
  for 10–25 kg without touching 1–9 kg.
- Anything past 25 kg is ignored, and it tells you how many it dropped.
- Something that is not a number is skipped and counted, not silently stored.
- A blank cell in your column **clears** that box.

You get one line back — "24 prices pasted from 1 kg, 1 skipped (not a number)" —
so you can see it did what you meant.

### Fill from a base price

When there is no sheet to paste and the prices are a straight ladder, enter the
**1 kg price** and **how much each extra kilogram adds**. The sum is printed
**before** anything is written, so you can see where 4,200 + 700 lands at 25 kg
without filling the boxes to find out. Then:

- **Fill blanks** — writes into the empty boxes only, and leaves everything you
  already typed exactly as it is.
- **Fill all** — overwrites every box on that line, including the ones you typed.

Then correct the odd box by hand. This is a starting point, not a decision.

### Copy one country's prices to another

Under each service, **Copy these prices to** — choose a country, or **Every
other country**. It tells you what it is about to do ("25 prices from UAE would
replace Oman's Express prices") before you press **Copy**, and it only touches
the service you are standing in.

Nothing is published by this. It writes into the boxes exactly as if you had
typed them, so it is a draft edit like any other, and **Undo** on a line still
puts that line back.

### The running check — what the customer will actually pay

Beside the boxes, as you type, one line says what the customer is charged for
that kilogram: **the box price with tax, rounding and the standing charges
added**. That is the number on the website, not the number in the box.

It reads the values in *Settings* on this same page, so it can never be
describing settings you have since changed. It also answers the question a blank
box raises — at 7 kg with 7 kg blank it says that weight is not offered and the
customer is sent to WhatsApp, not a price borrowed from 8 kg.

### When something is wrong, it says so in place

Never a pop-up. A sentence appears under the ladder and the box itself is
marked:

- **Orange — a price lower than the kilogram below it.** "9 kg is priced below
  the kilogram underneath, so a heavier parcel costs less." This is almost
  always a typo and it is the one you must fix.
- **A gap — a blank box (or a `0`) between priced kilograms.** Those weights
  are not offered: a customer at 4 kg with 4 kg blank is told there is no rate
  and sent to WhatsApp. Sometimes that is exactly what you want; more often it
  is a box you meant to fill, so read the sentence and decide.
- **Orange — not a number.** The box is left alone and the stored price is kept.

The counter beside each service — "18 of 25 priced", or "all 25 priced" — is how
you tell at a glance that a line is finished.

### One weight, every country

A rate change usually arrives as "10 kg is going up". Reading that out of the
folds means opening every country one at a time.

**Compare one weight**, above the table, puts one kilogram of one service down
every country in a single column, and you can type straight into it. The boxes
there are the same boxes — edit either and both agree.

### Saved, and unsaved

The bar at the bottom says **"Unsaved edits…"**, then **"Saving…"**, then
**"Draft saved"** with the time. It saves itself about a second after you stop
typing; you do not have to press anything. If you try to leave with something
unsaved, the browser asks first.

If a save ever fails it says **"Save failed — retrying on next edit"** and keeps
your typing — it is not lost, it just has not landed yet.

### If JavaScript is off

Everything above still works, by pressing a button instead: **Fill blanks** and
**Fill all** fill on the server, **Save draft** saves, and **Tab** still walks
the boxes in kilogram order. What you lose is the paste, the previews, the copy
control and the one-weight column — and **Download as CSV** gives you the same
comparison on paper.

### Adding and removing a country

- **Add country** puts a new line at the bottom. Give it a name and fill its
  boxes. It is not on the website until you publish.
- **On site** — untick it to take a country off the website while **keeping its
  prices**. This is what you want nine times out of ten: the prices are still
  there when the route comes back.
- **Remove** takes the line out altogether. It only really disappears when you
  publish, and the old version is always in *History*.
- **Undo** on a changed line puts that one line back the way it was.
- **Download as CSV** gives you a spreadsheet of every box, for your own records
  or to send to somebody. It only goes that way — nothing is ever read *in* from
  a file.

### Two helpers beside the table

- **Bulk adjust** raises or lowers many prices at once, by a percentage or by an
  amount, rounded the way *Settings* says.
- **Test a price** prices any shipment you describe against **what is in the
  editor**, including changes you have not published. Use it before you publish,
  not after.

---

## 4. The review screen, and publishing

Nothing you type is on the website yet. The editor keeps a **draft**; the site
serves the last thing that was **published**. The bar at the bottom says how many
unpublished changes are waiting.

Press **Review and publish**. The card lists every change, old price struck
through and new price in bold, and separates two very different things:

- **Problems that block publishing** (a country with no name, a setting outside
  its sensible range). The button is not offered until they are fixed.
- **Warnings**, which never block. "Canada Express 1 kg is 38% above the live
  price" is a warning, because only you can tell a typo from a decision you made
  on purpose. **Read them.** A warning is the last thing standing between a
  mis-keyed box and a customer being quoted it.

Then **Publish**. The website changes within seconds. The publish is recorded
with your name, the time and a one-line summary.

**Discard** throws away every unpublished change and puts the editor back to what
is live. There is no undo for discard, so use *History* instead if you might want
the work back.

### "The live website was updated after you started this draft"

You may see this box at the top of the editor. It means a new version went live
while your draft was open (for example, somebody changed the database directly,
as happened on 11 October). The admin has already **moved your draft onto that
new version for you**: your own edits are still there, and everything the new
version changed is kept too, so publishing will not undo it.

If the box also lists lines under **"Changed in your draft and in version N"**,
those are fields you and the new version both changed. The new version's value
was kept. If yours was the right one, type it back in and publish. A line
disappears from the list once you have typed your value back.

The box goes away after your next publish (or Discard, or Restore into editor).
You never need to discard a draft because of it.

### "Rates are live"

Until this is ticked once, the website carries a notice saying the prices shown
are samples. It appears on the review card **only while that notice is still
there**. When the first set of real prices is ready:

> **Rates are live** — remove the sample notice from the website

Tick it, publish, and the notice is gone for good. Do not tick it while the boxes
still hold made-up numbers.

---

## 5. Holding prices while you re-key them

New airline rates have arrived and the prices on the website are now wrong. You
need them off the site **now**, not in twenty minutes when you have finished
typing.

Press **Hold prices** at the top of *Rates*, check the message customers will
see (you can edit it; keep it short), and confirm. Within seconds:

- the calculator is **gone** from the website — not hidden, gone;
- customers see your message and a short WhatsApp form, so they can still send
  you their destination and weight and **no enquiry is lost**;
- no price is shown anywhere, and no quote can be logged.

**Nothing in the editor changes.** Key in the new prices exactly as usual. When
you press *Review and publish*, the card carries one extra tick:

> **Show prices again after publishing (lifts the hold)**

Leave it ticked and the new prices and the working calculator arrive in the same
moment. Untick it to publish quietly and keep holding — useful when you have one
country left to do.

**Resume showing prices** on the hold banner lifts the hold with the prices that
are already live, for when it turned out nothing needed changing.

Any admin can put prices on hold. Only an owner resumes them or publishes — the
safe direction is the easy one. A hold that nobody lifts for twelve hours sends
the office an email, so a hold you forgot does not quietly stop quotes for a day.
Every hold and every resume is in *Security*.

---

## 6. Inbox

Every customer who tapped **Book** on a price, and every message from the contact
form, arrives here. Work the tabs left to right: **New → Contacted → Booked**,
or **Lost**.

- Each card shows what they asked for: country, weight, service, the price they
  were quoted, their name and number, and anything they typed.
- **Notes** save when you click out of the box.
- **Open in WhatsApp** starts a chat with their number.
- **Create shipment** turns the enquiry into a shipment with the name, number,
  destination and message already filled in. Do not retype the phone number —
  a retyped number is a wrong number.

If outgoing email is not working, this page says so at the top. Enquiries are
still saved; it is the notification that is not arriving.

---

## 7. Shipments

A shipment has the customer, the receiver, the destination, the service, the
carrier, the airline tracking number, and a place it has got to:

**Booked → Picked up → In transit → At customs → Out for delivery → Delivered**,
plus **Needs attention** for anything that has gone wrong.

**Add update** records where it is now, with a note. The moves allowed are the
ones that happen in the real world: forward along the chain, back from customs
onto a plane, a failed delivery back out tomorrow, and *Needs attention* from
anywhere. You cannot mark something Delivered that was never collected. You can
always record the same status again — "still at customs, day three" is a real
update and the right way to keep a customer informed.

**Send WhatsApp update** opens WhatsApp with the message already written: who it
is from, the shipment number, the destination, the sentence for the current
status, your note, and the tracking number. Read it before you send it. **There
is no unsend.** The note only travels while it belongs to the status showing now,
so last week's "handed to the airline" can never go out under this week's status.

The list has tabs for open shipments and for the ones needing attention, and the
heading tells you how many need attention.

### Customer tracking (speedat.co/track)

The customer types their shipment number (SH-…) at **speedat.co/track**
and sees the status, the destination country, the carrier's name and a
timeline of dates, cities and statuses. They never see a name, a phone
number, an address, your notes or the carrier's tracking number. The WhatsApp
update now ends with the tracking link, so customers usually just tap it.

**Automatic updates.** Write the carrier's name in the *Carrier* box (DHL,
FedEx, UPS, DPD, Aramex, SkyNet, TCS, Leopards, M&P or Trax) and paste its
tracking number. Once an hour the site asks 17TRACK where the parcel is, adds
the new scans to the customer's timeline, and moves the status forward for you
(each move shows "tracking" as who made it). It never moves a shipment
backwards and never clears **Needs attention**: that stays your decision. It
sends nothing to the customer; WhatsApp updates are still your button.

**By hand.** TeamGlobal, and any carrier not in the list above, is not
tracked automatically. Update those as before. The *Customer tracking* box on
each shipment says which kind it is, when it was last checked, and if 17TRACK
could not follow the number (usually a typo in the number or the carrier).

Automatic tracking is off until the 17TRACK key is in `config.php`
(`tracking.api_key`). Without it, /track still works from the updates you add.

---

## 8. Settings and the words on the website

Both live inside the same editor, so a settings change and a price change are
one save and **one published version**.

**Settings** is in four parts:

- **Company** — name, tagline, WhatsApp number (country code, digits only, e.g.
  `923001234567`), phone and email as shown on the site, where you ship from, the
  pickup cities, and the line printed under the prices.
- **Pricing** — currency, how volume is turned into kilograms, the first weight
  and the step, the document weight limit, tax, rounding, and the weight above
  which the site asks the customer for a cargo quote.
- **Delivery estimates** — whether to show days at all, the same-day pickup
  cut-off hour, which days you work, and **holidays** (one date per line). A
  holiday is skipped when the site works out a delivery date, so entering the
  year's holidays once stops the site promising Eid.
- **Under the prices** — the optional charges and the small print.

**Website pages** holds the sentences on the public site, grouped by where they
appear: Home, the rates board, Services, the contact line on inner pages, About,
Contact, FAQ. Two things make it safe to edit:

- Fields marked **blank = automatic** write themselves from the rates. Left
  blank, the line under the headline says the real lowest 1 kg price and the real
  day span, and it can never say a number your prices do not. Type your own and
  you own it — including keeping it true.
- Where a field takes a list, the hint says how: **one item per line**, and parts
  of a line separated by `|`.

The FAQ you type is the FAQ Google is shown. They cannot drift apart.

---

## 9. History — when something goes wrong

*History* lists every publish: the version number, when, who, and a one-line
summary such as "12 prices, 1 country added". The live one is marked.

**Restore into editor** on any version copies its prices, settings and words back
into the editor. **It does not publish them.** You then review and publish as
usual — which means you get to look at what you are about to put back before
customers see it.

So the answer to "we published the wrong prices" is: *History* → the last good
version → **Restore into editor** → **Review and publish**. It takes under a
minute, and every customer who was quoted in the meantime is kept with the
version that priced them, so any argument can be settled from the record.

If you need the prices off the site while you think, **Hold prices** first
(section 5). That is instant, and it is the right first move.

---

## 10. Security

- **Your second factor.** Turn on two-factor sign-in: scan the square code with
  any authenticator app (Google Authenticator, Authy, 1Password), type the 6
  digits it shows to prove it worked. From then on sign-in asks for a code.
- **Who can sign in**, their role, and whether they have a second factor turned
  on. Everyone should.
- **Deployment checks** — a short list of green and red lights about the site
  itself, for example whether outgoing email is configured. A red light here
  explains why something is not arriving.
- **Recent activity** — the last forty things anybody did: sign-ins, publishes,
  holds, price changes, shipment updates. It is written as it happens and cannot
  be edited or deleted, by you or by anyone.

---

## 11. Things that are meant to be this way

- **The editor is not the website.** Typing is not publishing.
- **A blank price box means "not offered", never "free".**
- **Only an owner publishes or resumes prices.**
- **Restoring an old version does not publish it.**
- **A sent WhatsApp message cannot be recalled.** Read it first.
- **The website's prices, days, document limit and pickup charge all come from
  what you published.** If one of them looks wrong on the site, it is wrong in
  the editor; nothing is written anywhere else.
