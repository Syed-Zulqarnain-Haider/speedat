import { Accent } from "./Accent";

interface Props {
  /** The h1; accepts one `*word*` for the italic accent. */
  title: string;
  lede?: string;
  /** Kept for callers from the first round; no eyebrow line renders any more. */
  no?: string;
  /** Kept for callers from the first round; ignored. */
  name?: string;
}

/** The shared page header: a big h1 and an optional lede, nothing to decode. Server component. */
export function PageHead({ title, lede }: Props) {
  return (
    <header className="page-head">
      <h1>
        <Accent text={title} />
      </h1>
      {lede ? <p className="lede">{lede}</p> : null}
    </header>
  );
}
