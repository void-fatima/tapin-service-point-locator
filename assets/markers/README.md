# Complete provider marker images

`post.png` comes from the supplied **Amber map pin with blank medallion-1.png**;
`tipax.png` comes from **Emerald map pin with blank medallion-2.png**.
They contain the complete original artwork, without a logo overlay or redrawing.

Only transparent canvas padding was changed, using an unscaled pixel copy.
Every nontransparent pixel was verified to retain its exact RGBA value.
No nontransparent pixels were cropped or resampled.
The opaque body bounds (alpha > 240) determine the common visible height of
56 CSS pixels. Both use the same 48 × 72 CSS image size and the same 8-pixel
vertical offset, preserving the original soft shadow below the coordinate tip.
The Leaflet marker box is 48 × 72, anchored at [24, 72]. Rounding canvas sizes
to whole source pixels introduces less than 0.1 CSS pixel of difference.

| Image | Original size | Body bounds (inclusive) | Output canvas | Pixel offset |
| --- | --- | --- | --- | --- |
| Post | 1024 × 1536 | 119,133–902,1284 | 987 × 1481 | -17,+32 |
| Tipax | 1024 × 1536 | 103,131–915,1323 | 1023 × 1534 | +2,+39 |

The admin provider badges still use the separate official logo assets in
`assets/brand`. Clusters use a separate count circle, never a provider pin.
