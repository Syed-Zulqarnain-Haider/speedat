/**
 * 01 — the home hero: the route line, the headline whose words rise in from
 * first paint (CSS only, see home/HeroTitle), the lede, the manifest strip
 * whose numbers count up once in view, and the phone-only jump to the
 * instrument. Hairline threads drift behind it (fx/LivingBackground) on
 * machines that may run them; everyone else keeps the inline SVG.
 *
 * Everything renders as real text on the server. The only client islands are
 * the background and the manifest numbers (home/StatCounter), and those keep
 * the real figure in the DOM until their count starts.
 */
import { LivingBackground } from "@/components/site/fx/LivingBackground";
import { HeroTitle } from "@/components/site/home/HeroTitle";
import { StatCounter } from "@/components/site/home/StatCounter";

export interface HeroStat {
  /** Numeric part animates; `null` renders `text` as-is. */
  value: number | null;
  /** The whole value as text, used only when `value` is null. */
  text: string;
  /** Printed after the number (e.g. "+ days"); never part of `text`'s replacement. */
  suffix?: string;
  label: string;
}

interface Props {
  /** The mono route line above the headline (home/eyebrow.ts). */
  eyebrow: string;
  /** `content.heroTitle`; one `*word*` becomes the italic accent. */
  title: string;
  /** `content.heroSub`. */
  sub: string;
  stats: HeroStat[];
}

/** Splits "+ days" into the symbol that stays with the number ("+") and the word set smaller as a unit ("days"). */
function splitSuffix(suffix: string): { sym: string; unit: string } {
  const m = /^([^\p{L}\p{N}]*)(.*)$/u.exec(suffix);
  return { sym: (m?.[1] ?? "").trim(), unit: (m?.[2] ?? "").trim() };
}

function StatValue({ stat }: { stat: HeroStat }) {
  if (stat.value == null) return <>{stat.text}</>;
  const { sym, unit } = splitSuffix(stat.suffix ?? "");
  return (
    <>
      <StatCounter key={stat.value} to={stat.value} />
      {sym}
      {unit ? <span className="unit"> {unit}</span> : null}
    </>
  );
}

export function Hero({ eyebrow, title, sub, stats }: Props) {
  return (
    <div className="hero">
      <LivingBackground />
      <div className="hero-fg">
        {eyebrow ? <p className="eyebrow hero-eyebrow">{eyebrow}</p> : null}
        <HeroTitle text={title} />
        {sub ? <p className="lede">{sub}</p> : null}
        {stats.length ? (
          <ul className="stats manifest">
            {stats.map((s) => (
              <li key={s.label}>
                <strong>
                  <StatValue stat={s} />
                </strong>
                <span>{s.label}</span>
              </li>
            ))}
          </ul>
        ) : null}
        <a className="jump btn outline" href="#quote-instrument">
          Check your rate
          <span className="jump-arrow" aria-hidden="true">
            ↓
          </span>
        </a>
      </div>
    </div>
  );
}
