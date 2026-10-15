# omnilab/earthdata

## Installation

```sh
composer require omnilab/earthdata
```

```php
use Omnilab\Earthdata\EarthdataSourceFactory;

$earthdata = (new EarthdataSourceFactory($httpClient))->create([
    'collections' => ['HLSS30_2.0'],         // optional: what a query that names none searches
    'token' => getenv('EARTHDATA_TOKEN'),    // optional: an Earthdata Login token, to download files
    'mailto' => 'support@glitchr.io',        // optional: in the User-Agent
]);
```

With the Symfony bundle, the factory is registered as `earthdata` when the bundle knows it;
otherwise register `EarthdataSourceFactory` as a service (it is autoconfigured as a
`SourceFactoryInterface`).

## Which API: the CMR's search, and its STAC by configuration

NASA's Common Metadata Repository answers in two ways, both open:

- its **search API** (`https://cmr.earthdata.nasa.gov/search/granules.umm_json`,
  `collections.umm_json`): every archive (DAAC) at once, a collection named by its concept id or its
  entry id, a point, a box (across the antimeridian too), a period, a cloud cover range, a sort;
  the count of matches in the `CMR-Hits` header, the next page by the `CMR-Search-After` header;
  2,000 a page at most;
- its **STAC API** (`https://cmr.earthdata.nasa.gov/stac/{provider}/search`), a STAC API over the
  same metadata - the shape `omnilab/copernicus` reads, items whose assets are named like here
  (`B04`, with an `s3_B04` twin).

The search API is read by default. The STAC is read when the option `stac` names an archive
(`stac: LPCLOUD`), through the core's STAC reader (`Omnilab\Source\Stac`, shared with
omnilab/copernicus): `collections`, `intersects` or `bbox`, `datetime`, `limit` (50 at most),
`sortby=-datetime` (or `datetime`), the cloud cover by its Query extension
(`query[eo:cloud_cover][lte]=30`), the next page by the next link's `cursor`, the total from
`context.matched`; each `s3_` asset folded into its twin as its `s3` alternate. Compared on
2026-10-15 with the same question (the HLS S30 granules over Strasbourg, 2026-10-01 to 10-14, at
most 30 % cloud): both answer the same two granules; the STAC needs the archive in its path, so a
search across archives is one call per archive; it knows entry ids only (a concept id matches
nothing: refused), refuses a box across the antimeridian (400: refused before the call), and was
slower - 4 to 16.6 s against 3.5 to 6.5 s, three times each -, answering 502 to a page of 100 or
250 (50 answered) and once a 500 to a page of 10. A cloud filter written as a JSON object in a GET
(`query={"eo:cloud_cover":...}`) is ignored; in the bracketed form, or in a POST body, it narrows.

## Calls

| Method | Reads | CMR |
|---|---|---|
| `products(ProductQuery)` | `collections`: entry ids (`HLSS30_2.0`, `MOD09GA_061`: short name `_` version, the CMR STAC's collection ids too) or concept ids (`C2021957295-LPCLOUD`), several at once; the option's when empty (none: refused); a `Point` or a `BBox`; `from`, `to` (an open end left open); `maxCloudCover`; `sort` newest (the default) or oldest by start time; `limit` (2,000 at most); `cursor` | `granules.umm_json` with `entry_id[]`, `collection_concept_id[]`, `point=lon,lat`, `bounding_box=W,S,E,N`, `temporal=from,to`, `cloud_cover=0,max`, `sort_key=-start_date`, `page_size`; the cursor sent as the `CMR-Search-After` header |
| `datasets(Query)` | the collections that match a text, the CMR's relevance order; `limit`, `cursor` | `collections.umm_json?keyword=&page_size=` |
| `open(Asset\|string)` | a file's https:// address, with the token: the response, not read | GET with `Authorization: Bearer` |

`datasets()` and `open()` are the contract's (`Capability::DATASETS`, `Capability::OPEN`, since
the core named them on 2026-10-15; their signatures did not change). `Page::$total` is `CMR-Hits`; `next` is
the answer's `CMR-Search-After` when the page is full (the CMR gives the header on a last page
too). Refused before any call: no collection at all, `open()` without a token or on an s3://
address; `entity()`, `search()`, `series()`, `occurrences()`.

## What a product holds

A **granule**: `id` its granule UR (`HLS.L30.T32UMU.2026279T102219.v2.0`); `collection` its entry
id when the granule names its collection by short name and version (MODIS: `MOD09GA_061`), else
the collection asked when one was, else its collection's concept id (HLS names only its
collection's title); `datetime` its start (or its single time), `start` and `end` when it covers
an interval (a MODIS day: 00:00:00 to 23:59:59); `bbox` and `geometry` from its polygons or
rectangles (a footprint across the antimeridian is not unrolled: its box is the widest);
`properties`: `eo:cloud_cover` (the CMR's `CloudCover`, else the attribute `CLOUD_COVERAGE` or
`CLOUD_COVER`), `platform` (and `platforms` when several), `instruments`, `day_night`,
`production_datetime`, `size` in bytes (summed over its files), `attributes` (every additional
attribute by name: HLS's `MGRS_TILE_ID`, `SPATIAL_COVERAGE`, `SENSING_TIME`; MODIS's tile numbers
and DOI), `cmr:concept_id`, `cmr:collection_concept_id`, `cmr:provider`, `cmr:revision_date`;
`assets`: each file, named by what follows the granule's name in its file name (`B04`, `Fmask`,
`SZA`, `stac`, `cmr`), `data` for the granule's own file (MOD09GA's `.hdf`), `browse` for its
image; its https:// address as `href`, its s3:// twin as the `s3` alternate; roles `data`,
`thumbnail`, `metadata`, `service` (OPeNDAP and the like); `preview` the browse image's https://
address (open); `url` its CMR record (`concepts/G....umm_json`).

A **collection** (`datasets()`): `id` and `collection` its entry id, `start` and `end` its
temporal extent (`end` null when it goes on: `ends_at_present`), `bbox` its bounding rectangle,
`properties`: `title`, `description` (its abstract), `short_name`, `version`, `sci:doi`,
`processing_level`, `platforms`, `cloud_hosted`, `cmr:concept_id`, `cmr:provider`; `assets` its
landing page and browse image; `url` its CMR page (`concepts/C....html`).

## Downloading

The archives ask for an Earthdata Login: on 2026-10-15, a MOD09GA file's https:// address
answered 302 to `urs.earthdata.nasa.gov/oauth/authorize` without one; its browse image
(`lp-prod-public`) answered 200. `open()` sends the `token` option as a bearer token, which the
archives accept in place of the login's redirections (NASA's documented way for scripts);
Symfony's client follows the archive's redirection to the file's store and does not carry the
token to another host.

## The Collector

Nothing to follow: granules and collections are products, and the CMR gives no identifier of
another base for them (a collection's DOI would need a scheme the core does not have).

## Verified, and not

Against the real CMR on 2026-10-15, without an account: the HLS granules (S30 and L30 v2.0) over
Strasbourg from 2026-10-01 to 10-14 under 30 % cloud - four, two a page, the second page by the
first's `CMR-Search-After` (Landsat 8 on 10-06, two tiles at 1 % cloud; Sentinel-2B on 10-05),
each with its ten bands, Fmask and four angles at LP DAAC and in S3, its STAC and CMR metadata and
its browse image; the MOD09GA v061 days there from 10-01 to 10-07 (seven, h18v04, about 100 MB
each); the MOD09GA granules of 2026-10-01 in a box across the antimeridian (13, both sides); the
collections found by "HLSS30" (four: HLSL30 and HLSS30 v2.0 with their DOIs, the vegetation
indices); a collection the CMR does not have (no hits); its 400 for a period that ends before it
starts; a protected file without a token (302 to the login page) and an open browse image (200);
the CMR's STAC for the HLS S30 question (six granules in 2.7 s without a cloud filter; with the
`stac` option, two under 30 %, one a page, the second page by the cursor, as the search API's).

**Not verified live (needs a token)**: `open()` with an Earthdata Login token, and the CMR's
answers to an authenticated search (no account was used); the test feeds a token and checks it
is sent.
