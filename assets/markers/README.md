# Provider map markers

The map uses the supplied transparent PNG artwork without redrawing or
overprinting logos:

- `post.png`: yellow Post pin with the Post emblem.
- `tipax.png`: emerald Tipax pin with the Tipax emblem.
- `other.png`: crimson pin with a blank white circle.

All source images are 1024 × 1536 PNGs with transparent canvas padding. The
original files and compact pin legend are unchanged. Filtered map badges crop
the existing Post/Tipax artwork to its emblem; configured Other-provider logos
use their existing asset or the existing accessible fallback.

Admin dashboard and public maps share one 20 × 20 px badge renderer for
province aggregates, spatial clusters and single records. With all providers,
one circular chart shows Post/Tipax/Other proportions in yellow/green/red;
there are no numeric labels or paired pins. With a provider filter, only that
provider's logo appears in the same circular frame.

Exact counts, composition and provider names remain in keyboard-accessible
tooltips and labels. Badge callouts retain the original geographic anchors;
province groups, cluster membership and click-to-zoom/details stay intact.
