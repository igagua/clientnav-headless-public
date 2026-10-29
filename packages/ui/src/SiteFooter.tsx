import type { ReactNode } from "react";
import { Anchor } from "./link";
import type { Brand, LinkAs, MenuItem } from "./types";

export type SiteFooterProps = {
  brand: Brand;
  /** Lines under the brand: a short statement, then the address. */
  statement?: string[];
  address?: string[];
  /** Top entries are the column titles, their children the links. */
  columns: MenuItem[];
  legal?: MenuItem[];
  copyright: string;
  /** Something above the columns, for example a newsletter form. */
  lead?: ReactNode;
  linkAs?: LinkAs;
};

const lines = (items: string[]) =>
  items.map((line, i) => (
    <span key={i}>
      {line}
      {i < items.length - 1 ? <br /> : null}
    </span>
  ));

export function SiteFooter({ brand, statement = [], address = [], columns, legal = [], copyright, lead, linkAs }: SiteFooterProps) {
  return (
    <footer className="cnu-footer">
      {lead ? <div className="cnu-wrap cnu-footer-lead">{lead}</div> : null}
      <div className="cnu-wrap cnu-footer-grid">
        <div className="cnu-footer-brand">
          <Anchor as={linkAs} href={brand.href} className="cnu-brand" label={`${brand.small} ${brand.name}`.trim()}>
            {brand.mark ? <span className="cnu-brand-mark">{brand.mark}</span> : null}
            <span className="cnu-brand-text">
              <span className="cnu-brand-small">{brand.small}</span>
              <span className="cnu-brand-name">
                {brand.name}
                <i>.</i>
              </span>
            </span>
          </Anchor>
          {statement.length ? <p className="cnu-footer-statement">{lines(statement)}</p> : null}
          {address.length ? <p className="cnu-footer-address">{lines(address)}</p> : null}
        </div>
        {columns.map((col) => (
          <nav key={col.id} aria-label={col.title}>
            <h3>{col.title}</h3>
            {col.children.map((l) => (
              <Anchor key={l.id} as={linkAs} href={l.href} newTab={l.newTab}>
                {l.title}
              </Anchor>
            ))}
          </nav>
        ))}
      </div>
      <div className="cnu-wrap cnu-footer-bottom">
        <p>{copyright}</p>
        <nav aria-label="Legal">
          {legal.map((l) => (
            <Anchor key={l.id} as={linkAs} href={l.href} newTab={l.newTab}>
              {l.title}
            </Anchor>
          ))}
          <a href="#top">Back to top ↑</a>
        </nav>
      </div>
    </footer>
  );
}
