/**
 * The home page's first words: a headline that names the route ("Lahore to
 * Australia, Canada, France and Germany.") and one line with the lowest
 * 1 kg price and the day span — both generated from the rate document
 * unless the owner typed his own. When `content.stats` is set it prints as
 * one plain muted line; nothing else. Server-rendered text, painted
 * finished, no icons, no entrance.
 */
export interface HeroStat {
  /** The value as printed, e.g. "12". */
  text: string;
  label: string;
}

interface Props {
  /** The h1, printed exactly as given. */
  title: string;
  /** The line under it. */
  sub: string;
  /** Optional owner-typed points (`content.stats`); empty = no line. */
  stats: HeroStat[];
}

export function Hero({ title, sub, stats }: Props) {
  return (
    <div className="hero-copy">
      <h1>{title}</h1>
      {sub ? <p className="lede">{sub}</p> : null}
      {stats.length ? <p className="stats">{stats.map((s) => `${s.text} ${s.label}`.trim()).join(" · ")}</p> : null}
    </div>
  );
}
