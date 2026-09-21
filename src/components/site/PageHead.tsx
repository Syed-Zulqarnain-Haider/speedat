import { Accent } from "./Accent";

interface Props {
  /** Section number in the nav order, e.g. "02". */
  no: string;
  /** Section name after the number, e.g. "Services". */
  name: string;
  /** The h1; accepts one `*word*` for the italic accent. */
  title: string;
  lede?: string;
}

/** The shared page header: numbered mono eyebrow, editorial rule, h1 and an optional lede. Server component. */
export function PageHead({ no, name, title, lede }: Props) {
  return (
    <header className="page-head">
      <p className="eyebrow">
        {no} — {name}
      </p>
      <span className="rule" aria-hidden="true" />
      <h1>
        <Accent text={title} />
      </h1>
      {lede ? <p className="lede">{lede}</p> : null}
    </header>
  );
}
