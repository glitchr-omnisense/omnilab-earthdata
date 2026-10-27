# omnilab/earthdata

NASA Earthdata's Common Metadata Repository (`https://cmr.earthdata.nasa.gov/search/`, and its
STAC by configuration) for [omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): the
granules of NASA's Earth observation collections (HLS, MODIS, VIIRS, GPM...) by place, period and
cloud cover as products, the collections by text as datasets, and their files with an Earthdata
Login token. No account to search.

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

## Credentials

None to search: the CMR's search and STAC APIs are open. Downloading a file (`open()`) needs an
Earthdata Login token, from a free account at urs.earthdata.nasa.gov: give it as `token`; it is
sent as a bearer token (and to the CMR, which then shows the account's restricted metadata).

## Options

The factory's options, with the defaults it applies (`EarthdataSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `collections` | `list<string>` | `[]` | no | what a query that names none searches: entry ids (`HLSS30_2.0`) or concept ids; empty: such a query is refused |
| `token` | `?string` | null | no | an Earthdata Login token, sent as a bearer token with `open()` and to the CMR; null: `open()` refused |
| `stac` | `?string` | null | no | an archive's id (`LPCLOUD`, `POCLOUD`...): its granules read through the CMR's STAC catalogue by the core's STAC reader, rather than the CMR's own search (the default: faster, every archive at once) |
| `mailto` | `?string` | null | no | a contact address, sent in the User-Agent |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `0` | no | seconds left between two calls: the CMR publishes no rate for its search |
| `base_uri` | `string` | `https://cmr.earthdata.nasa.gov/search/` | no | the CMR's search API (or the UAT's, `https://cmr.uat.earthdata.nasa.gov/search/`) |

## Capabilities

`capabilities()` answers `products`, `area`, `cloud_cover`, `datasets`, `open`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | no |  |
| `search` | no |  |
| `series` | no |  |
| `products` | yes | the granules of the named collections over a point or a box and a period, newest or oldest first, 2,000 a page at most (50 through STAC), the next by `CMR-Search-After` (or the STAC cursor), the total from `CMR-Hits` |
| `occurrences` | no |  |
| `datasets` | yes | the CMR's collections by text, the best first, with a total |
| `jobs` | no |  |
| `open` | yes | a granule's file over `https://` with the `token`; an `s3://` address refused (it needs AWS credentials in us-west-2) |
| `structure` | no |  |
| `cross_references` | no | none: the CMR gives no identifier of another base for a granule |
| `forecast` | no |  |
| `historical` | no |  |
| `area` | yes | a `BBox` (across the antimeridian with the CMR's search; refused through STAC) |
| `station` | no |  |
| `cloud_cover` | yes | a cloud cover range (the Query extension through STAC) |

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

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Earthdata\EarthdataSourceFactory;
use Omnilab\Exception\NotSupportedException;
use Omnilab\Model\Point;
use Omnilab\Source\ProductQuery;
use Omnilab\Source\Query;
use Symfony\Component\HttpClient\HttpClient;

$earthdata = (new EarthdataSourceFactory(HttpClient::create()))->create([
    'mailto' => 'you@example.org',
    'token' => getenv('EARTHDATA_TOKEN') ?: null,   // an Earthdata Login token: only to download
]);

$page = $earthdata->products(new ProductQuery(['HLSS30_2.0', 'HLSL30_2.0'], new Point(48.5734, 7.7521), new DateTimeImmutable('2026-10-10'), new DateTimeImmutable('2026-10-26'), maxCloudCover: 40, limit: 3));
printf("%d HLS granules over Strasbourg under 40 %% cloud; the first %d:\n", $page->total, count($page));
foreach ($page as $granule) {
    printf("  %s  %s  %s %% cloud, %d files, B04 %s\n", $granule->datetime->format('Y-m-d H:i'), $granule->id, $granule->cloudCover(), count($granule->assets), $granule->asset('B04') ? 'yes' : 'no');
}

foreach ($earthdata->datasets(new Query('sea surface temperature', limit: 2)) as $collection) {
    echo '  collection ', $collection->id, '  ', $collection->properties['title'] ?? '', "\n";
}

try {
    $earthdata->open($page->first()->asset('B04'));
} catch (NotSupportedException $e) {
    echo $e->getMessage(), "\n";
}
```

```
2 HLS granules over Strasbourg under 40 % cloud; the first 2:
  2026-10-15 10:16  HLS.L30.T32UMU.2026288T101608.v2.0  33 % cloud, 18 files, B04 yes
  2026-10-12 10:37  HLS.S30.T32ULU.2026285T102911.v2.0  5 % cloud, 20 files, B04 yes
  collection OSTIA-UKMO-L4-GLOB-REP-v2.0_2.0  GHRSST Level 4 OSTIA Global Historical Reprocessed Foundation Sea Surface Temperature Analysis produced by the UK Meteorological Office
  collection EO:EUM:DAT:SENTINEL-3:SL_2_WST___NRT_2017-07-05  SLSTR Sea Surface Temperatures (SST) in NRT - Sentinel-3
The "earthdata" source does not download a file without a token (option token: an Earthdata Login token).
```

(as answered on 2026-10-27, without a token: two calls; `open()` refused before any call)

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        earthdata: { factory: earthdata, options: { mailto: '%env(OMNILAB_MAILTO)%', collections: [HLSS30_2.0, HLSL30_2.0], token: '%env(default::EARTHDATA_TOKEN)%' } }
```

## Errors

Every call goes through `HttpSource`: a 404 or a 410 (and a 204) is null - an empty page for a list -; a 429 is a `RateLimitedException` (`retryAfter` from `Retry-After`); a 5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a `ProviderException` with its `status` and what the service said; an answer that is not JSON a `ProviderException`, a web page answered for JSON an `UnavailableException`.

The CMR's errors (`{"errors": [...]}`, a 400 for a bad box or a period backwards) are read into the
`ProviderException`'s message. Before any call (`NotSupportedException`): a query that names no
collection when the option names none; through STAC, a concept id or a box across the
antimeridian; `open()` without a token or of an address that is not `https://`. `open()` maps only
an unreachable host (`UnavailableException`): the response is returned as it is, its status the
caller's to check.

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

Again on 2026-10-27, without a token: the HLS granules over Strasbourg from 10-10 to 10-26 under
40 % cloud (two), the collections found by "sea surface temperature", `open()` refused without a
token (the example above).

**Not verified live (needs a token)**: `open()` with an Earthdata Login token, and the CMR's
answers to an authenticated search (no account was used); the test feeds a token and checks it
is sent.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by NASA. NASA's Earth science data are open, without restriction on use (NASA's Earth
Science Data and Information Policy); cite each collection by its DOI, as the README's
[Terms of use](https://github.com/glitchr-omnisense/omnilab-earthdata#terms-of-use) says. Whoever
runs the package holds the Earthdata Login account it uses and is responsible for any use made of
what it returns: see the README's
[Responsibility](https://github.com/glitchr-omnisense/omnilab-earthdata#responsibility) section.
