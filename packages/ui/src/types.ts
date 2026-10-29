import type { ComponentType, ReactNode } from "react";

/** A menu entry as the content door sends it. */
export type MenuItem = {
  id: number;
  title: string;
  href: string;
  description: string;
  /** The entry's "title attribute" in WordPress: on a top entry, the label of its "see all" link. */
  note: string;
  classes: string[];
  newTab: boolean;
  children: MenuItem[];
};

export type Picture = { src: string; width: number; height: number; alt: string; srcset: string };

export type TermRef = { id: number; taxonomy: string; name: string; slug: string; path: string; count: number };

/** The short form of an entry. */
export type Card = {
  id: number;
  type: string;
  slug: string;
  path: string;
  title: string;
  excerpt: string;
  date: string;
  modified: string;
  author: string;
  category: TermRef | null;
  image: Picture | null;
  minutes: number;
};

export type Block = { name: string; attrs: Record<string, unknown>; html: string; innerBlocks: Block[] };

/**
 * The link the host uses: a plain <a> by default, the framework's own link on the front end,
 * so the library stays usable inside the WordPress editor.
 */
export type LinkAs = ComponentType<{ href: string; className?: string; children?: ReactNode; target?: string; rel?: string; "aria-label"?: string; onClick?: () => void }>;

export type Brand = { small: string; name: string; href: string; mark?: ReactNode };
