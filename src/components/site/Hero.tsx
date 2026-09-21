/**
 * The home hero's left column: a short headline (two lines at 1366, three
 * on a phone), one line under it and three proof points with an icon
 * each. Everything is server-rendered text; the one entrance is a CSS
 * `rise` on `.hero-copy` inside the reduced-motion guard. No islands, no
 * background, nothing counts up.
 */
import { CardIcons } from "@/components/Icons";
import { Accent } from "@/components/site/Accent";

export interface HeroStat {
  /** The value as printed, e.g. "10", "3 days", "25 kg". */
  text: string;
  label: string;
}

interface Props {
  /** `content.heroTitle`; one `*word*` becomes the italic accent. */
  title: string;
  /** `content.heroSub`. */
  sub: string;
  stats: HeroStat[];
}

/** Proof-point icons by position: countries, days, kilograms (custom `content.stats` lines use the same order). */
const PROOF_ICONS = [CardIcons.globe, CardIcons.plane, CardIcons.box] as const;

export function Hero({ title, sub, stats }: Props) {
  return (
    <div className="hero-copy">
      <h1>
        <Accent text={title} />
      </h1>
      {sub ? <p className="lede">{sub}</p> : null}
      {stats.length ? (
        <ul className="proof">
          {stats.map((s, i) => {
            const Icon = PROOF_ICONS[i % PROOF_ICONS.length];
            return (
              <li key={s.label}>
                <span className="proof-ico" aria-hidden="true">
                  <Icon />
                </span>
                <strong>{s.text}</strong>
                <span>{s.label}</span>
              </li>
            );
          })}
        </ul>
      ) : null}
    </div>
  );
}
