"use client";

import { useEffect, useRef, useState } from "react";
import { STATUS, type Status } from "./roadmap-icons";


/**
 * The filter above a roadmap tree, and the line that fills as the page is scrolled.
 * It only marks the roadmap with the chosen filter and opens the branches; what is hidden is decided by the stylesheet.
 */
export function RoadmapControls({ total }: { total: number }) {
  const [filter, setFilter] = useState<"all" | Status>("all");
  const self = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const tree = self.current?.closest(".cnu-rm")?.querySelector<HTMLElement>(".cnu-rm-tree");
    const bar = tree?.querySelector<HTMLElement>(".cnu-rm-trunk i");
    if (!tree || !bar) return;
    const fill = () => {
      const r = tree.getBoundingClientRect();
      bar.style.height = `${Math.min(Math.max(window.innerHeight * 0.6 - r.top, 0), r.height)}px`;
    };
    fill();
    window.addEventListener("scroll", fill, { passive: true });
    window.addEventListener("resize", fill);
    return () => {
      window.removeEventListener("scroll", fill);
      window.removeEventListener("resize", fill);
    };
  }, [filter]);

  const choose = (f: "all" | Status) => {
    setFilter(f);
    const root = self.current?.closest<HTMLElement>(".cnu-rm");
    if (!root) return;
    root.dataset.filter = f;
    root.querySelectorAll<HTMLDetailsElement>("details.cnu-rm-card").forEach((d) => {
      d.open = f !== "all" && Boolean(d.querySelector(`[data-s="${f}"]`));
    });
  };

  return (
    <div className="cnu-rm-filters" role="group" aria-label="Show by status" ref={self}>
      <button type="button" className="cnu-rm-chip" aria-pressed={filter === "all"} onClick={() => choose("all")}>
        Everything · {total}
      </button>
      {(Object.keys(STATUS) as Status[]).map((k) => (
        <button key={k} type="button" className="cnu-rm-chip" aria-pressed={filter === k} onClick={() => choose(k)}>
          <span className={`cnu-rm-dot ${k}`} />
          {STATUS[k]}
        </button>
      ))}
    </div>
  );
}
