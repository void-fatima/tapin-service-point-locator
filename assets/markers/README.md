# Provider map markers

The map uses the supplied transparent PNG artwork without redrawing or
overprinting logos:

- `post.png`: yellow Post pin with the Post emblem.
- `tipax.png`: emerald Tipax pin with the Tipax emblem.
- `other.png`: crimson pin with a blank white circle.

All source images are 1024 × 1536 PNGs with transparent canvas padding. The
original files and compact pin legend are unchanged. Individual branch pins
retain the full supplied artwork, with its visible tip anchored to the stored
latitude/longitude. Post/Tipax use their logo pins; other providers use the red pin.

Admin dashboard and public maps share one presentation policy. Country/province
aggregates and unresolved spatial clusters use a 20 × 20 px circular chart of
the actual Post/Tipax/Other proportions in yellow/green/red, including after
provider filtering. No numeric labels or paired pins are rendered. As zoom
separates records, each single branch uses a 40 × 60 px location pin. Zooming
out restores charts; coincident branches remain a chart with the existing list action.

Exact counts, composition and provider names remain in keyboard-accessible
tooltips and labels. Badge callouts retain the original geographic anchors;
province groups, cluster membership and click-to-zoom/details stay intact.
