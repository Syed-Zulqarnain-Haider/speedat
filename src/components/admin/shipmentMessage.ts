/**
 * The WhatsApp update a customer receives about a shipment. Pure and
 * client-safe: the status list and customer sentences mirror
 * src/lib/shipments.ts (a server-only module).
 *
 * The message is greeting, the canned sentence for the current status, the
 * note the admin wrote with that status change ("Handed to Emirates flight
 * EK623"), then the tracking line. The note is read from the latest timeline
 * event, so what the customer gets is exactly what the timeline records.
 */
export const STATUSES = ["booked", "picked_up", "in_transit", "customs", "out_for_delivery", "delivered", "exception"] as const;
export type Status = (typeof STATUSES)[number];

export const CUSTOMER_TEXT: Record<Status, string> = {
  booked: "Your shipment is booked. We will collect it and hand it to the airline.",
  picked_up: "We have collected your shipment and it is being prepared for the flight.",
  in_transit: "Your shipment is on its way to the destination country.",
  customs: "Your shipment is with customs at the destination. This can take a day or two.",
  out_for_delivery: "Your shipment is out for delivery today.",
  delivered: "Your shipment has been delivered.",
  exception: "There is a hold-up with your shipment. We are on it and will update you shortly.",
};

/** A stored status narrowed to one we know; anything else reads as booked. */
export function currentStatus(status: string): Status {
  return (STATUSES as readonly string[]).includes(status) ? (status as Status) : "booked";
}

export interface MessageShipment {
  id: string;
  customerName: string;
  destination: string;
  status: string;
  trackingNo: string;
  carrier: string;
}

export interface MessageEvent {
  status: string;
  note: string;
}

/**
 * Build the customer's update. `events` is the timeline oldest first; the
 * note of its last entry is included when that entry is the current status
 * change, so a note written for an earlier status never travels under a
 * newer headline.
 */
export function customerMessage(companyName: string, shipment: MessageShipment, events: readonly MessageEvent[]): string {
  const status = currentStatus(shipment.status);
  const latest = events[events.length - 1];
  const note = latest && currentStatus(latest.status) === status ? latest.note.trim() : "";
  return [
    `Hi${shipment.customerName ? ` ${shipment.customerName}` : ""}, an update from ${companyName} on your shipment ${shipment.id} to ${shipment.destination}:`,
    CUSTOMER_TEXT[status],
    note,
    shipment.trackingNo ? `Tracking number: ${shipment.trackingNo}${shipment.carrier ? ` (${shipment.carrier})` : ""}` : "",
  ]
    .filter(Boolean)
    .join("\n");
}
