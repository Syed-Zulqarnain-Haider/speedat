import { describe, expect, it } from "vitest";
import { accentWords, parseAccent } from "./accent";

describe("parseAccent", () => {
  it("returns the text untouched when there is no asterisk", () => {
    expect(parseAccent("Send anything abroad")).toEqual({ before: "Send anything abroad", accent: "", after: "" });
  });

  it("prints a lone asterisk literally", () => {
    expect(parseAccent("Send anything *abroad")).toEqual({ before: "Send anything *abroad", accent: "", after: "" });
  });

  it("prints an empty pair literally", () => {
    expect(parseAccent("Send ** abroad")).toEqual({ before: "Send ** abroad", accent: "", after: "" });
    expect(parseAccent("Send * * abroad")).toEqual({ before: "Send * * abroad", accent: "", after: "" });
  });

  it("splits a normal pair", () => {
    expect(parseAccent("Send anything *abroad* from Lahore.")).toEqual({ before: "Send anything ", accent: "abroad", after: " from Lahore." });
  });

  it("keeps a third asterisk literal in the tail", () => {
    expect(parseAccent("Send *anything* abroad*")).toEqual({ before: "Send ", accent: "anything", after: " abroad*" });
  });

  it("refuses a pair that spans a line break", () => {
    const t = "Send *any\nthing* abroad";
    expect(parseAccent(t)).toEqual({ before: t, accent: "", after: "" });
  });
});

describe("accentWords", () => {
  it("flags nothing without a pair", () => {
    expect(accentWords("Send anything abroad")).toEqual([
      { text: "Send", accent: false },
      { text: "anything", accent: false },
      { text: "abroad", accent: false },
    ]);
  });

  it("flags the accent word", () => {
    expect(accentWords("Send anything *abroad* from Lahore.")).toEqual([
      { text: "Send", accent: false },
      { text: "anything", accent: false },
      { text: "abroad", accent: true },
      { text: "from", accent: false },
      { text: "Lahore.", accent: false },
    ]);
  });

  it("glues a trailing comma to the accent word as its tail", () => {
    expect(accentWords("Send it *abroad*, fast")).toEqual([
      { text: "Send", accent: false },
      { text: "it", accent: false },
      { text: "abroad", accent: true, tail: "," },
      { text: "fast", accent: false },
    ]);
  });

  it("glues an opening bracket to the accent word as its head", () => {
    expect(accentWords("Priced (*in seconds*)")).toEqual([
      { text: "Priced", accent: false },
      { text: "in", accent: true, head: "(" },
      { text: "seconds", accent: true, tail: ")" },
    ]);
  });

  it("prints a lone asterisk as part of its word", () => {
    expect(accentWords("Send *abroad")).toEqual([
      { text: "Send", accent: false },
      { text: "*abroad", accent: false },
    ]);
  });
});
