# omnilab/earthdata

NASA Earthdata's Common Metadata Repository (CMR) for
[omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): the granules of NASA's Earth
observation collections - the Harmonized Landsat Sentinel-2 (HLS), MODIS, VIIRS, GPM, SMAP,
ICESat-2, the OPERA products... - as products, by collection, point or box, period and cloud
cover, page by page: their footprint, attributes, browse image and files. The collections
themselves are searched by text. Searching needs no account; downloading a file needs an
Earthdata Login token.

```php
$earthdata = (new EarthdataSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$page = $earthdata->products(new ProductQuery(['HLSS30_2.0', 'HLSL30_2.0'], new Point(48.5734, 7.7521),
    new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-14T23:59:59Z'), maxCloudCover: 30));
foreach ($page as $granule) {
    $granule->id;                         // HLS.L30.T32UMU.2026279T102219.v2.0
    $granule->datetime;                   // 2026-10-06T10:22:19Z
    $granule->cloudCover();               // 1.0
    $granule->platform();                 // LANDSAT-8
    $granule->preview;                    // the browse image, open
    $granule->asset('B04')->href;         // https://data.lpdaac.earthdatacloud.nasa.gov/lp-prod-protected/...: needs a token
    $granule->asset('B04')->alternates['s3'];  // s3://lp-prod-protected/...: in AWS us-west-2
}
$earthdata->products($query->with(['cursor' => $page->next]));

$earthdata->datasets(new Query('HLSS30'))->items[1]->id;   // HLSS30_2.0 (DOI 10.5067/HLS/HLSS30.002)

// With a token: a file, streamed.
$earthdata = (new EarthdataSourceFactory($http))->create(['token' => getenv('EARTHDATA_TOKEN')]);
$response = $earthdata->open($granule->asset('B04'));
```

```yaml
omnilab:
    sources:
        earthdata:
            factory: earthdata
            options:
                collections: [HLSS30_2.0, HLSL30_2.0]   # what a query that names none searches
                token: '%env(EARTHDATA_TOKEN)%'         # optional: to download the files
```

## The token

The CMR's search is open. The files sit at NASA's archives (the DAACs: LP DAAC, PO.DAAC, NSIDC,
GES DISC...), which ask for an Earthdata Login (a free account at urs.earthdata.nasa.gov): without
it, a file's https:// address redirects to the login page. A token generated there, given as the
`token` option, is sent as `Authorization: Bearer` by `open()`, and to the CMR (which then shows
the account the restricted collections it may see). It is read from the configuration only,
never written. The s3:// addresses are for a reader in AWS's us-west-2, with the archive's S3
credentials.

## Terms of use

NASA's Earth science data are open, without restriction on use (NASA's Earth Science Data and
Information Policy); cite each collection by its DOI. The CMR publishes no rate for its search;
the source names itself in each call (`Client-Id: omnilab`, a header the CMR takes, and the
User-Agent with the `mailto` address).

See [docs/](docs/index.md) for the calls, the mapping, the CMR's STAC (option `stac: LPCLOUD`: the same granules through the core's STAC reader, one archive at a time, slower), and
what was verified.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by NASA Earthdata. It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
