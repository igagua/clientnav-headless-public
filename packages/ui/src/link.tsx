import type { ReactNode } from "react";
import type { LinkAs } from "./types";

const outside = (href: string) => /^(https?:)?\/\//.test(href);
const special = (href: string) => /^(tel:|mailto:|#)/.test(href);

/** Draws a link with the host's link for addresses on this site and a plain <a> for everything else. */
export function Anchor({ as: As, href, newTab, className, children, onClick, label }: { as?: LinkAs; href: string; newTab?: boolean; className?: string; children?: ReactNode; onClick?: () => void; label?: string }) {
  if (outside(href) || special(href) || !As) {
    const ext = outside(href);
    return (
      <a href={href} className={className} onClick={onClick} aria-label={label} target={newTab || ext ? "_blank" : undefined} rel={newTab || ext ? "noopener" : undefined}>
        {children}
      </a>
    );
  }
  return (
    <As href={href} className={className} onClick={onClick} aria-label={label}>
      {children}
    </As>
  );
}
