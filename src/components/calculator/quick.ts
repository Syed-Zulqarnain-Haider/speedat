/**
 * The quick view's weight buttons. A button is offered only when the engine
 * prices that weight for at least one service to at least one live
 * destination: the old filter compared against the list of weights the
 * settings allow, so a grid with no box at or above a weight, or a slab
 * with no price for a weight, offered a button that could only answer
 * "no price".
 */
import { priceAll } from "@/lib/pricing/engine";
import type { RateCard } from "@/lib/pricing/types";

/** The weight buttons on the quick view, in kilograms, before the rate card is asked which of them it prices. */
export const KG_BUTTONS = [1, 2, 3, 5, 10, 15, 20, 25];

/** True when a 1-piece parcel of `kg` gets a price from some service to some active destination. */
export function pricesAt(card: RateCard, kg: number): boolean {
  return card.destinations.some((d) => {
    if (!d.active) return false;
    const r = priceAll(card, { destId: d.id, type: "pkg", rows: [{ kg, qty: 1 }] });
    return r.ok && card.services.some((s) => r.prices[s.id] != null);
  });
}

/** The buttons to show, in order. */
export function quickKgs(card: RateCard, buttons: readonly number[] = KG_BUTTONS): number[] {
  return buttons.filter((k) => pricesAt(card, k));
}
