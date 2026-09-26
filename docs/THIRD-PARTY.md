# Third-party assets

Assets are bundled locally; installation needs no npm or Composer step.

| Asset | Version / source | License / attribution |
| --- | --- | --- |
| Leaflet | 1.9.4, https://leafletjs.com | BSD-2-Clause, `assets/vendor/LEAFLET-LICENSE` |
| Vazirmatn | 33.0.3, https://github.com/rastikerdar/vazirmatn | SIL OFL 1.1, `assets/fonts/OFL.txt` |
| Iran administrative geometry | geoBoundaries IRN ADM1, revision `9469f09`, boundary ID `IRN-ADM1-17685810` | OpenStreetMap / Wambacher, ODbL 1.0; https://www.openstreetmap.org/copyright |
| Tapin logo | https://www.tapin.ir/wp-content/themes/tapin/image/logo.png | Official brand asset; trademark rights remain with Tapin |
| Iran Post logo | https://tracking.post.ir/Content/Image/arm.png | Official brand asset; trademark rights remain with Iran Post |
| Tipax logo | https://tipaxco.com/UI/Styles/Default/images/logo.svg | Official brand asset; trademark rights remain with Tipax |

Logos were downloaded from official sites on 2026-09-26 and are unmodified. The official Post asset is blue and the Tipax header asset is white; no substitute marks or recoloring were generated. Provider colors consistently identify points in controls, markers, and charts. Users may replace logo URLs with their own authorized brand assets in provider management.

Boundary download: https://media.githubusercontent.com/media/wmgeolab/geoBoundaries/9469f09/releaseData/gbOpen/IRN/ADM1/geoBoundaries-IRN-ADM1_simplified.geojson

Boundary metadata: https://www.geoboundaries.org/api/current/gbOpen/IRN/ADM1/

The bundled geometry represents the source's 2017 administrative data (published 2023), unmodified, including its separate geometry features. It is contextual cartography, not authoritative current administrative data; provider/province filters use stored service-point text, never inferred geography. An attribution link is displayed on the map. The ODbL license is available at https://opendatacommons.org/licenses/odbl/1-0/ and bundled in `assets/ODbL-1.0.txt`.

Default background tiles use https://tile.openstreetmap.org with visible attribution, ordinary browser caching, no prefetch or offline download. Browser requests disclose the visitor IP and referrer to the tile service. Its usage policy applies: https://operations.osmfoundation.org/policies/tiles/ . Configure a suitable tile provider for production traffic with `tapin_tile_url` and `tapin_tile_attribution`; the URL is intentionally public browser configuration and must not contain secret server credentials. Local boundaries and branch lists remain available during tile outages.
