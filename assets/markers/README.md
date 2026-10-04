# Provider map markers

The map uses the supplied transparent PNG artwork without redrawing or
overprinting logos:

- `post.png`: yellow Post pin with the Post emblem.
- `tipax.png`: emerald Tipax pin with the Tipax emblem.
- `other.png`: crimson pin with a blank white circle.

All source images are 1024 × 1536 PNGs with transparent canvas padding. The
full canvases are retained so each pin shares a 48 × 72 CSS image box and its
tip stays aligned to the geographic coordinate. The compact legend uses the
same images at a proportionally scaled size.

Clusters show each represented category's pin image once. Their tooltip and
accessible label report the total, the Post/Tipax/other composition, and the
provider names. Clicking a cluster zooms toward its points; at the closest
zoom it opens the existing point-details list.
