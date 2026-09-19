import { beforeAll, describe, expect, it } from "vitest";
import { checkFormToken, issueFormToken } from "./form-token";

describe("form token", () => {
  beforeAll(() => {
    process.env.APP_SECRET = "test-secret";
  });
  it("accepts a token older than 3 s and younger than a day", () => {
    const t0 = 1_800_000_000_000;
    const tok = issueFormToken(t0);
    expect(checkFormToken(tok, t0 + 1000)).toBe("too_fast");
    expect(checkFormToken(tok, t0 + 5000)).toBe("ok");
    expect(checkFormToken(tok, t0 + 25 * 3600_000)).toBe("expired");
  });
  it("rejects tampering and garbage", () => {
    const tok = issueFormToken(1_800_000_000_000);
    const [ts, sig] = tok.split(".");
    expect(checkFormToken(`${Number(ts) - 60_000}.${sig}`, 1_800_000_000_000 + 5000)).toBe("invalid");
    expect(checkFormToken("nope", Date.now())).toBe("invalid");
    expect(checkFormToken("", Date.now())).toBe("invalid");
  });
});
