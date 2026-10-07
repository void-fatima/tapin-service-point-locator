# Documentation media

## Deployed public site — 2026-10-07

Captured anonymously from [the deployed public locator](https://iot-core.ir/wordpress_b/?page_id=22).
These files show the site's actual public records and controls at capture time.
The deployed source revision was not independently identified.
The 44-second published clip excludes the initial 17 seconds of page loading; the remaining
UI interactions keep their recorded timing. The recorder script saves the full
session when rerun.

| File | View |
| --- | --- |
| `live-public-locator.png` | Country view, provider filters, province/city controls and public directory |
| `live-province-filter.png` | Public locator filtered to Tehran |
| `live-branch-details.png` | Public branch-details dialog |
| `public-demo.webm` | Country view → Tehran → Tipax → branch details → address-only records → reset |

The recording uses an isolated anonymous Chromium session and normal public UI
actions. No branch records, provider settings or administrative data were changed.
No administrator login, cookies or credentials are included. Public addresses and
contacts visible in the recording are the information published by the site.
Counts describe the live dataset at capture time, not a guaranteed service footprint.

Reproduce with `node scripts/record-demo.cjs` from the repository root after
`npm ci` and `npx playwright install chromium`. Set `TAPIN_DEMO_URL` to select
another installation. The output is WebM/VP8, 1440×1080, without narration or
browser chrome. Open the video with **View raw / Download** when GitHub does
not provide playback. Temporary recorder output stays in ignored `artifacts/`.

## Local administrator and public interface — 2026-09-29

Captured on 2026-09-29 from the running WordPress plugin at source commit
`0b898e4` on a local, isolated installation. These are application captures,
not mockups, generated illustrations or test-pass evidence.

| File | View |
| --- | --- |
| `dashboard.png` | Network totals, coordinate coverage, provider distribution and Iran map |
| `province-filter.png` | Dashboard narrowed to Tehran, with clustered provider markers |
| `service-points.png` | Management table, filters, location status and XLSX export control |
| `file-management.png` | CSV/XLSX upload flow and empty import history |
| `public-locator.png` | Anonymous shortcode view, address-only branch cards and mapped locations |
| `demo.webm` | 15-second recording: dashboard → Tehran filter → service points → file management |

The installation already contained the bundled reference dataset: 231 Tipax
Tehran records and ten Semnan postal records. Counts are specific to that
snapshot, not national coverage or a claim of current branch operation. Visible
branch contacts come from the repository's published source snapshots; no
private customer dataset was used. See [data provenance](../DATA-SOURCES.md).

Captures exclude WordPress account/navigation chrome and browser address bars.
No credentials, session files, local machine paths or configuration files are
included. No branch data was inserted or edited for the recording. The file
manager's empty history is shown as it actually appeared.

The local video uses WebM/VP8 supported by the available recording tools. GIF and
MP4 encoders were not available in the bundled recorder. The main README uses
PNG previews and a direct recording link instead of assuming inline video
playback. Open or download the WebM in a compatible browser/player.

Map and provider attribution remains visible in the captures. See
[third-party notices](../THIRD-PARTY.md). The separately reused
[`tapin-banner.svg`](../images/tapin-banner.svg) is a decorative concept banner,
not a live application view.
