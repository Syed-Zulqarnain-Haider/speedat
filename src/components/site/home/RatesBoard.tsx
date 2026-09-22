import { Flag } from "@/components/site/Flag";
import { fmtMoney } from "@/lib/pricing/format";
import { boardNote } from "@/lib/site/copy";
import { flagCode } from "@/lib/site/countries";
import type { PublishedVersion, SiteData } from "@/lib/site/types";
import { boardRows } from "./routes";

interface Props {
  site: SiteData | PublishedVersion;
  holdOn: boolean;
}

/**
 * The rates board (brief v3 §3): every live destination as one row — flag,
 * name, the 1 kg price and days per service, the flat document rate — like
 * a departures board. A reference, not a control: nothing here is tappable.
 * Server component; rendered once on the home page (third child of
 * `section.calc`) and once on /services. Under hold the prices are null,
 * only the days print and the generated caption says so (`boardNote`), so
 * no pickup charge or limit leaks. Every number is `boardRows`' — the calculator's own
 * 1 kg call with the default add-ons — so the board and the tiles agree to
 * the rupee. One header row at every width: on a phone the row header
 * (flag and name) takes a line of its own and the cells line up under the
 * service names (site.css HOME), so no label is repeated per cell.
 */
export function RatesBoard({ site, holdOn }: Props) {
  const rows = boardRows(site, holdOn);
  if (!rows.length) return null;
  const cur = site.settings.currency;
  const hasDoc = rows.some((r) => r.doc != null);
  const title = site.content.routesTitle.trim() || "Rates";
  const note = site.content.routesNote.trim() || boardNote(site, holdOn);
  return (
    <table className="rates">
      <caption>
        <strong>{title}</strong> <span>{note}</span>
      </caption>
      <thead>
        <tr>
          <th scope="col">Country</th>
          {site.services.map((s) => (
            <th key={s.id} scope="col">
              {s.name}
            </th>
          ))}
          {hasDoc ? <th scope="col">Documents</th> : null}
        </tr>
      </thead>
      <tbody>
        {rows.map((r) => (
          <tr key={r.id}>
            <th scope="row">
              <Flag code={flagCode(r.name, r.id)} name={r.name} size={24} lazy />
              <span>{r.name}</span>
            </th>
            {r.cells.map((c) => (
              <td key={c.serviceId}>
                {c.price != null ? <b>{fmtMoney(c.price, cur)}</b> : null}
                {c.days ? <span>{c.days} days</span> : null}
              </td>
            ))}
            {hasDoc ? <td>{r.doc != null ? <b>{fmtMoney(r.doc, cur)}</b> : null}</td> : null}
          </tr>
        ))}
      </tbody>
    </table>
  );
}
