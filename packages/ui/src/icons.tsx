const base = { viewBox: "0 0 24 24", fill: "none", stroke: "currentColor", "aria-hidden": true } as const;

export const ArrowUpRight = () => (
  <svg {...base} strokeWidth={1.7}>
    <path d="M7 17 17 7M7 7h10v10" />
  </svg>
);
export const ChevronDown = () => (
  <svg {...base} strokeWidth={1.7}>
    <path d="m7 10 5 5 5-5" />
  </svg>
);
export const Bars = () => (
  <svg {...base} strokeWidth={2}>
    <path d="M4 6h16M4 12h16M4 18h16" />
  </svg>
);
export const Close = () => (
  <svg {...base} strokeWidth={1.7}>
    <path d="M6 6l12 12M18 6 6 18" />
  </svg>
);
export const Moon = () => (
  <svg {...base} strokeWidth={1.6} className="cnu-moon">
    <path d="M20.9 13A9 9 0 0 1 11 3.1 9 9 0 1 0 20.9 13Z" />
  </svg>
);
export const Sun = () => (
  <svg {...base} strokeWidth={1.6} className="cnu-sun">
    <circle cx="12" cy="12" r="4" />
    <path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5" />
  </svg>
);
export const ArrowDown = () => (
  <svg {...base} strokeWidth={1.7}>
    <path d="M12 5v14m-6-6 6 6 6-6" />
  </svg>
);
