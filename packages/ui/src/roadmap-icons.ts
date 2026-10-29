/** How far a roadmap item is, and how that is worded. Kept outside the client-side control so the server can read it too. */
export const STATUS = { live: "Live", build: "Building", next: "Next", plan: "Planned" } as const;
export type Status = keyof typeof STATUS;

/** The line icons a roadmap branch can carry (24 by 24, drawn with strokes). */
export const ROADMAP_ICONS: Record<string, string> = {
  shield: '<path d="M12 3l7 3v5c0 4.500-3 8.500-7 10-4-1.500-7-5.500-7-10V6z"/><path d="M9 12l2 2 4-4"/>',
  net: '<circle cx="12" cy="5" r="2"/><circle cx="5" cy="19" r="2"/><circle cx="19" cy="19" r="2"/><path d="M12 7v5M12 12l-6 5M12 12l6 5"/>',
  cloud: '<path d="M7 18a4 4 0 01-.5-7.970A6 6 0 0118 9.500a4.200 4.200 0 01.500 8.500z"/>',
  life: '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3.500"/><path d="M5.600 5.600l3.900 3.900M14.500 14.500l3.900 3.900M18.400 5.600l-3.900 3.900M9.500 14.500l-3.900 3.900"/>',
  call: '<path d="M5 4h4l2 5-2.500 1.500a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2z"/>',
  chart: '<path d="M4 20V4M4 20h16M8 16v-4M12 16V8M16 16v-6"/>',
  globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.200 3 14.800 0 18M12 3c-3 3.200-3 14.800 0 18"/>',
  desk: '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
  hub: '<circle cx="12" cy="12" r="3"/><path d="M12 3v6M12 15v6M3 12h6M15 12h6"/>',
  spark: '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/><path d="M19 16l.7 2 2 .7-2 .7-.7 2-.7-2-2-.7 2-.7z"/>',
  people: '<circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.700 6 6"/><circle cx="17.500" cy="9" r="2.400"/><path d="M16.500 14.200c2.600.4 4.500 2.600 4.500 5.300"/>',
  layout: '<rect x="3" y="4" width="18" height="16" rx="2.500"/><path d="M3 9h18M9 9v11"/>',
  key: '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M17 6l3 3M14 9l2 2"/>',
  board: '<rect x="3" y="4" width="5" height="14" rx="1.300"/><rect x="9.500" y="4" width="5" height="9" rx="1.300"/><rect x="16" y="4" width="5" height="12" rx="1.300"/>',
  server: '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.500h.01M7 16.500h.01"/>',
  plug: '<path d="M9 3v5M15 3v5M6 8h12v3a6 6 0 01-12 0zM12 17v4"/>',
  book: '<path d="M4 5.500A2.500 2.500 0 016.500 3H20v15H6.500A2.500 2.500 0 004 20.500z"/><path d="M4 20.500A2.500 2.500 0 016.500 18H20v3H6.500"/>',
  phone: '<rect x="7" y="2.500" width="10" height="19" rx="2.500"/><path d="M11 18h2"/>',
};
