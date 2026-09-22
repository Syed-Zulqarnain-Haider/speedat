import { describe, expect, it } from "vitest";
import { CUSTOMER_TEXT, STATUSES, currentStatus, customerMessage } from "./shipmentMessage";

const COMPANY = "Speedat International Courier";
const SHIPMENT = {
  id: "SH-GJAVFXDR",
  customerName: "Ayesha Khan",
  destination: "Canada",
  status: "customs",
  trackingNo: "1234567890",
  carrier: "DHL",
};

describe("customerMessage", () => {
  it("carries the note written with the current status change, between the status sentence and the tracking line", () => {
    const msg = customerMessage(COMPANY, SHIPMENT, [
      { status: "booked", note: "" },
      { status: "in_transit", note: "Handed to Emirates flight EK623" },
      { status: "customs", note: "Cleared by Canada Border Services, releasing tomorrow" },
    ]);
    expect(msg.split("\n")).toEqual([
      "Hi Ayesha Khan, an update from Speedat International Courier on your shipment SH-GJAVFXDR to Canada:",
      CUSTOMER_TEXT.customs,
      "Cleared by Canada Border Services, releasing tomorrow",
      "Tracking number: 1234567890 (DHL)",
    ]);
  });

  it("does not carry an earlier status's note under a newer status", () => {
    const msg = customerMessage(COMPANY, SHIPMENT, [
      { status: "in_transit", note: "Handed to Emirates flight EK623" },
      { status: "customs", note: "" },
    ]);
    expect(msg).not.toContain("EK623");
    expect(msg.split("\n")).toEqual([
      "Hi Ayesha Khan, an update from Speedat International Courier on your shipment SH-GJAVFXDR to Canada:",
      CUSTOMER_TEXT.customs,
      "Tracking number: 1234567890 (DHL)",
    ]);
  });

  it("ignores a note whose event is not the current status", () => {
    const msg = customerMessage(COMPANY, { ...SHIPMENT, status: "delivered" }, [{ status: "customs", note: "Cleared" }]);
    expect(msg).not.toContain("Cleared");
    expect(msg).toContain(CUSTOMER_TEXT.delivered);
  });

  it("leaves no blank line when the note is empty or whitespace and no tracking number is known", () => {
    const msg = customerMessage(COMPANY, { ...SHIPMENT, trackingNo: "", carrier: "" }, [{ status: "customs", note: "   " }]);
    expect(msg.split("\n")).toEqual([
      "Hi Ayesha Khan, an update from Speedat International Courier on your shipment SH-GJAVFXDR to Canada:",
      CUSTOMER_TEXT.customs,
    ]);
  });

  it("greets without a name and omits the carrier when they are blank, and reads an unknown status as booked", () => {
    const msg = customerMessage(COMPANY, { ...SHIPMENT, customerName: "", carrier: "", status: "weird" }, []);
    expect(msg.split("\n")).toEqual([
      "Hi, an update from Speedat International Courier on your shipment SH-GJAVFXDR to Canada:",
      CUSTOMER_TEXT.booked,
      "Tracking number: 1234567890",
    ]);
  });

  it("has a customer sentence for every status", () => {
    for (const st of STATUSES) expect(CUSTOMER_TEXT[st].length).toBeGreaterThan(10);
  });
});

describe("currentStatus", () => {
  it("keeps a known status and falls back to booked otherwise", () => {
    expect(currentStatus("out_for_delivery")).toBe("out_for_delivery");
    expect(currentStatus("")).toBe("booked");
    expect(currentStatus("cancelled")).toBe("booked");
  });
});
