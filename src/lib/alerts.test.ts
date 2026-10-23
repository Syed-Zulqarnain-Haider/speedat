import { describe, expect, it, vi } from "vitest";

// alerts.ts opens the database client at import; sendDeduped never touches it.
vi.mock("@/lib/db", () => ({ db: {}, schema: {} }));
vi.mock("@/lib/notify", () => ({ notifyOffice: vi.fn() }));

const { sendDeduped } = await import("./alerts");

function steps(claimed: boolean, send: () => Promise<boolean>) {
  return { claim: vi.fn(async () => claimed), send: vi.fn(send), release: vi.fn(async () => {}) };
}

describe("sendDeduped", () => {
  it("does nothing when another caller holds today's claim", async () => {
    const s = steps(false, async () => true);
    expect(await sendDeduped(s)).toBe(false);
    expect(s.send).not.toHaveBeenCalled();
    expect(s.release).not.toHaveBeenCalled();
  });
  it("keeps the claim after a successful send", async () => {
    const s = steps(true, async () => true);
    expect(await sendDeduped(s)).toBe(true);
    expect(s.release).not.toHaveBeenCalled();
  });
  it("releases the claim when the provider refused, so the next run retries", async () => {
    const s = steps(true, async () => false);
    expect(await sendDeduped(s)).toBe(false);
    expect(s.release).toHaveBeenCalledOnce();
  });
  it("releases the claim when sending throws", async () => {
    const s = steps(true, async () => {
      throw new Error("boom");
    });
    await expect(sendDeduped(s)).rejects.toThrow("boom");
    expect(s.release).toHaveBeenCalledOnce();
  });
  it("lets exactly one of several concurrent callers send", async () => {
    let taken = false;
    const send = vi.fn(async () => true);
    const claim = async () => (taken ? false : (taken = true));
    const results = await Promise.all([0, 1, 2].map(() => sendDeduped({ claim, send, release: async () => {} })));
    expect(results.filter(Boolean)).toHaveLength(1);
    expect(send).toHaveBeenCalledOnce();
  });
});
