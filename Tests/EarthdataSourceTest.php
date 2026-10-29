<?php

namespace Omnilab\Earthdata\Tests;

use Omnilab\Earthdata\EarthdataSource;
use Omnilab\Earthdata\EarthdataSourceFactory;
use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Exception\RateLimitedException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Model\BBox;
use Omnilab\Model\Point;
use Omnilab\Source\Capability;
use Omnilab\Source\ProductQuery;
use Omnilab\Source\Query;
use Omnilab\Source\SeriesQuery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from cmr.earthdata.nasa.gov/search on 2026-10-15,
 * without an account: the HLS granules (HLSS30 and HLSL30 v2.0) over
 * Strasbourg (48.5734 N 7.7521 E) from 2026-10-01 to 10-14 under 30 % cloud
 * cover, newest first, two a page (hls-page1.json, then hls-page2.json by
 * the CMR-Search-After of the first; four in all); the MOD09GA v061
 * granules there from 10-01 to 10-07 (three of seven); the MOD09GA
 * granules of 2026-10-01 in a box across the antimeridian (170 E to 170 W,
 * 25 S to 10 S: thirteen); the collections found by "HLSS30" (three of
 * four, cut to the fields read); a collection the CMR does not have (no
 * hits); the 400 of a period that ends before it starts. The answers'
 * CMR-Hits and CMR-Search-After headers are given back as recorded. From
 * cmr.earthdata.nasa.gov/stac/LPCLOUD that evening: the HLSS30 granules
 * over Strasbourg from 10-01 to 10-14 under 30 % cloud, one a page, the
 * first page and the second by its next link's cursor (two in all).
 */
final class EarthdataSourceTest extends TestCase
{
    private const AFTER = '[1791282139684,4348586473]';

    /** @var list<array{string, list<string>, list<string>}> */
    private array $calls = [];

    private function source(array $options = [], ?MockResponse $response = null): EarthdataSource
    {
        $http = new MockHttpClient(function (string $method, string $url, array $request) use ($response): MockResponse {
            $headers = $request['normalized_headers'] ?? [];
            $this->calls[] = [urldecode($url), $headers['cmr-search-after'] ?? [], $headers['authorization'] ?? []];
            if ($response) {
                return $response;
            }
            [$fixture, $hits, $after] = match (true) {
                str_contains($url, '/stac/LPCLOUD/search') => [str_contains($url, 'cursor=') ? 'stac-hls-page2.json' : 'stac-hls-page1.json', null, null],
                str_contains($url, 'collections.umm_json') => ['collections-hlss30.json', 4, '[0.6,0.0,6000.0,1792048940832,"2 - geophys. variables, sensor coordinates","HLSL30_VI","2.0",3246892554,42]'],
                str_contains($url, 'NOPE_1') => ['unknown.json', 0, null],
                str_contains($url, 'bounding_box') => ['modis-antimeridian.json', 13, '[1790812800000,4343305885]'],
                str_contains($url, 'MOD09GA') => ['modis.json', 7, '[1791158400000,4346362668]'],
                [] !== ($headers['cmr-search-after'] ?? []) => ['hls-page2.json', 4, '[1791196651171,4345923571]'],
                default => ['hls-page1.json', 4, self::AFTER],
            };

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture), ['response_headers' => array_filter([null === $hits ? null : 'CMR-Hits: '.$hits, null === $after ? null : 'CMR-Search-After: '.$after])]);
        });
        $source = (new EarthdataSourceFactory($http))->create($options);
        self::assertInstanceOf(EarthdataSource::class, $source);

        return $source;
    }

    private static function hls(): ProductQuery
    {
        return new ProductQuery(['HLSS30_2.0', 'HLSL30_2.0'], new Point(48.5734, 7.7521), new \DateTimeImmutable('2026-10-01T00:00:00Z'), new \DateTimeImmutable('2026-10-14T23:59:59Z'), maxCloudCover: 30, limit: 2);
    }

    public function testTheHlsGranulesOverStrasbourgUnderThirtyPercentCloud(): void
    {
        $page = $this->source()->products(self::hls());

        self::assertSame([2, 4, self::AFTER], [\count($page), $page->total, $page->next]);
        self::assertStringContainsString('granules.umm_json?entry_id[]=HLSS30_2.0&entry_id[]=HLSL30_2.0&point=7.7521,48.5734&temporal=2026-10-01T00:00:00Z,2026-10-14T23:59:59Z&cloud_cover=0,30&sort_key=-start_date&page_size=2', $this->calls[0][0]);
        $granule = $page->items[0];
        self::assertSame('HLS.L30.T32UMU.2026279T102219.v2.0', $granule->id);
        self::assertSame('C2021957657-LPCLOUD', $granule->collection, 'HLS names its collection by its title alone: its concept id, two being asked');
        self::assertSame('2026-10-06T10:22:19+00:00', $granule->datetime->format(\DATE_ATOM));
        self::assertSame([1.0, 'LANDSAT-8'], [$granule->cloudCover(), $granule->platform()]);
        self::assertSame('32UMU', $granule->properties['attributes']['MGRS_TILE_ID']);
        self::assertSame(['G4348581957-LPCLOUD', 'LPCLOUD'], [$granule->properties['cmr:concept_id'], $granule->properties['cmr:provider']]);
        self::assertTrue($granule->bbox->contains(new Point(48.5734, 7.7521)));
        self::assertSame('Polygon', $granule->geometry['type']);
        self::assertSame('https://cmr.earthdata.nasa.gov/search/concepts/G4348581957-LPCLOUD.umm_json', $granule->url);

        $red = $granule->asset('B04');
        self::assertSame('https://data.lpdaac.earthdatacloud.nasa.gov/lp-prod-protected/HLSL30.020/HLS.L30.T32UMU.2026279T102219.v2.0/HLS.L30.T32UMU.2026279T102219.v2.0.B04.tif', $red->href);
        self::assertSame('s3://lp-prod-protected/HLSL30.020/HLS.L30.T32UMU.2026279T102219.v2.0/HLS.L30.T32UMU.2026279T102219.v2.0.B04.tif', $red->alternates['s3']);
        self::assertSame(['data'], $red->roles);
        self::assertCount(15, $granule->assets('data'), 'its ten bands, Fmask and the four angles');
        self::assertSame('https://data.lpdaac.earthdatacloud.nasa.gov/lp-prod-public/HLSL30.020/HLS.L30.T32UMU.2026279T102219.v2.0/HLS.L30.T32UMU.2026279T102219.v2.0.jpg', $granule->preview, 'the browse image, open');
        self::assertSame(['metadata'], $granule->asset('stac')->roles);
    }

    public function testTheNextPageByTheSearchAfterHeader(): void
    {
        $source = $this->source();
        $first = $source->products(self::hls());
        $second = $source->products(self::hls()->with(['cursor' => $first->next]));

        self::assertSame(['CMR-Search-After: '.self::AFTER], $this->calls[1][1]);
        self::assertSame(['HLS.S30.T32ULU.2026278T102859.v2.0', 'HLS.S30.T32UMU.2026278T102859.v2.0'], array_map(static fn ($p) => $p->id, $second->items));
        self::assertSame('Sentinel-2B', $second->items[0]->platform());
    }

    public function testModisDailyGranules(): void
    {
        $page = $this->source()->products(new ProductQuery(['MOD09GA_061'], new Point(48.5734, 7.7521), new \DateTimeImmutable('2026-10-01T00:00:00Z'), new \DateTimeImmutable('2026-10-07T23:59:59Z'), limit: 3));

        self::assertSame(7, $page->total);
        $day = $page->first();
        self::assertSame(['MOD09GA.A2026280.h18v04.061.2026282031822', 'MOD09GA_061'], [$day->id, $day->collection], 'MODIS names its collection by short name and version');
        self::assertSame(['2026-10-07T00:00:00+00:00', '2026-10-07T23:59:59+00:00'], [$day->start->format(\DATE_ATOM), $day->end->format(\DATE_ATOM)]);
        self::assertSame($day->start, $day->datetime);
        self::assertNull($day->cloudCover());
        self::assertSame(['data', 'cmr', 'browse'], array_keys($day->assets));
        self::assertStringEndsWith('.hdf', $day->asset('data')->href);
        self::assertSame(102398460, $day->properties['size'], '97.65 MB');
        self::assertSame('h18v04', 'h'.str_pad($day->properties['attributes']['HORIZONTALTILENUMBER'], 2, '0', \STR_PAD_LEFT).'v'.str_pad($day->properties['attributes']['VERTICALTILENUMBER'], 2, '0', \STR_PAD_LEFT));
    }

    public function testABoxAcrossTheAntimeridian(): void
    {
        $page = $this->source()->products(new ProductQuery(['MOD09GA_061'], new BBox(170, -25, -170, -10), new \DateTimeImmutable('2026-10-01T00:00:00Z'), new \DateTimeImmutable('2026-10-01T23:59:59Z'), limit: 20));

        self::assertStringContainsString('bounding_box=170,-25,-170,-10', $this->calls[0][0]);
        self::assertSame([13, 13, null], [\count($page), $page->total, $page->next], 'both sides, one page');
    }

    public function testTheCollectionsByText(): void
    {
        $page = $this->source()->datasets(new Query('HLSS30', limit: 3));

        self::assertStringContainsString('collections.umm_json?keyword=HLSS30&page_size=3', $this->calls[0][0]);
        self::assertSame(['HLSL30_2.0', 'HLSS30_2.0', 'HLSL30_VI_2.0'], array_map(static fn ($p) => $p->id, $page->items));
        self::assertSame(4, $page->total);
        self::assertNotNull($page->next);
        $s30 = $page->items[1];
        self::assertSame('HLS Sentinel-2 Multi-spectral Instrument Surface Reflectance Daily Global 30m v2.0', $s30->properties['title']);
        self::assertSame(['10.5067/HLS/HLSS30.002', 'C2021957295-LPCLOUD', true], [$s30->properties['sci:doi'], $s30->properties['cmr:concept_id'], $s30->properties['ends_at_present']]);
        self::assertSame(['2015-11-28', null], [$s30->start->format('Y-m-d'), $s30->end]);
        self::assertSame([-180.0, -90.0, 180.0, 90.0], $s30->bbox->toArray());
        self::assertSame('https://cmr.earthdata.nasa.gov/search/concepts/C2021957295-LPCLOUD.html', $s30->url);
    }

    public function testAnUnknownCollectionHasNoGranule(): void
    {
        $page = $this->source()->products(new ProductQuery(['NOPE_1'], limit: 1));

        self::assertSame([0, 0, null], [\count($page), $page->total, $page->next]);
    }

    public function testTheTokenIsSentToTheCmrAndToOpen(): void
    {
        $source = $this->source(['token' => 'a-token']);
        $source->products(self::hls());
        self::assertSame(['Authorization: Bearer a-token'], $this->calls[0][2]);

        $file = $source->products(self::hls())->first()->asset('B04');
        $response = $source->open($file);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($file->href, $this->calls[2][0]);
        self::assertSame(['Authorization: Bearer a-token'], $this->calls[2][2]);
    }

    public function testTheCmrsStacCatalogueThroughTheCoresReader(): void
    {
        $source = $this->source(['stac' => 'LPCLOUD']);
        $query = new ProductQuery(['HLSS30_2.0'], new Point(48.5734, 7.7521), new \DateTimeImmutable('2026-10-01T00:00:00Z'), new \DateTimeImmutable('2026-10-14T23:59:59Z'), maxCloudCover: 30, limit: 1);
        $first = $source->products($query);

        self::assertSame('https://cmr.earthdata.nasa.gov/stac/LPCLOUD/search?collections=HLSS30_2.0&intersects={"type":"Point","coordinates":[7.7521,48.5734]}&datetime=2026-10-01T00:00:00Z/2026-10-14T23:59:59Z&limit=1&sortby=-datetime&query[eo:cloud_cover][lte]=30', $this->calls[0][0]);
        self::assertSame([2, 'eyJqc29uIjoiWzE3OTExOTY2NTUzNTcsNDM0NTkzNjc0MF0iLCJ1bW0iOiJbMTc5MTE5NjY1NTM1Nyw0MzQ1OTM2NzQwXSJ9'], [$first->total, $first->next]);
        $granule = $first->first();
        self::assertSame(['HLS.S30.T32ULU.2026278T102859.v2.0', 'HLSS30_2.0', 'earthdata', 12.0], [$granule->id, $granule->collection, $granule->source, $granule->cloudCover()]);
        self::assertStringStartsWith('https://data.lpdaac.earthdatacloud.nasa.gov/', $granule->asset('B04')->href);
        self::assertStringStartsWith('s3://lp-prod-protected/', $granule->asset('B04')->alternates['s3'], 'the s3_ twin folded in');
        self::assertArrayNotHasKey('s3_B04', $granule->assets);

        $second = $source->products($query->with(['cursor' => $first->next]));
        self::assertStringEndsWith('&cursor='.$first->next, $this->calls[1][0]);
        self::assertSame(['HLS.S30.T32UMU.2026278T102859.v2.0', 23.0], [$second->first()?->id, $second->first()?->cloudCover()]);

        foreach ([
            static fn () => $source->products(new ProductQuery(['C2021957295-LPCLOUD'])),
            static fn () => $source->products(new ProductQuery(['MOD09GA_061'], new BBox(170, -25, -170, -10))),
        ] as $refusal) {
            try {
                $refusal();
                self::fail('refused');
            } catch (NotSupportedException) {
            }
        }
        self::assertCount(2, $this->calls);
    }

    public function testRefusedBeforeAnyCall(): void
    {
        $source = $this->source();
        $keyed = $this->source(['token' => 't']);
        $refusals = [
            static fn () => $source->products(new ProductQuery()),
            static fn () => $source->open('https://data.lpdaac.earthdatacloud.nasa.gov/lp-prod-protected/x.tif'),
            static fn () => $keyed->open('s3://lp-prod-protected/x.tif'),
            static fn () => $source->entity('C2021957295-LPCLOUD'),
            static fn () => $source->search(new Query('HLS')),
            static fn () => $source->series(new SeriesQuery(['B04'], new Point(48.5734, 7.7521))),
        ];
        foreach ($refusals as $i => $refusal) {
            try {
                $refusal();
                self::fail("refusal $i answered");
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testDefaultCollectionsAndCapabilities(): void
    {
        $this->source(['collections' => ['MOD09GA_061']])->products(new ProductQuery(limit: 3));
        self::assertStringContainsString('entry_id[]=MOD09GA_061', $this->calls[0][0]);
        self::assertSame('earthdata', (new EarthdataSourceFactory())->getName());
        self::assertSame([Capability::PRODUCTS, Capability::AREA, Capability::CLOUD_COVER, Capability::DATASETS, Capability::OPEN], $this->source()->capabilities());

        $this->source()->products(new ProductQuery(['C2021957295-LPCLOUD'], limit: 1));
        self::assertStringContainsString('collection_concept_id[]=C2021957295-LPCLOUD', $this->calls[1][0]);
        self::assertStringNotContainsString('entry_id', $this->calls[1][0]);
    }

    public function testErrors(): void
    {
        try {
            $this->source([], new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/error-temporal.json'), ['http_code' => 400]))->products(self::hls());
            self::fail('a 400 answered');
        } catch (ProviderException $e) {
            self::assertStringContainsString('must be before end_date', $e->getMessage());
        }
        try {
            $this->source([], new MockResponse('', ['http_code' => 503]))->products(self::hls());
            self::fail('a 503 answered');
        } catch (UnavailableException) {
        }
        $this->expectException(RateLimitedException::class);
        $this->source([], new MockResponse('', ['http_code' => 429]))->datasets(new Query('HLS'));
    }

    /**
     * A file not reached: nothing of the token anywhere in what a log of the whole chain writes - the
     * messages, the previous exception (a copy of the HTTP client's, its class kept), the traces'
     * arguments (the request's headers) when zend.exception_ignore_args is off.
     */
    public function testAnUnreachableFileHoldsNoToken(): void
    {
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        $http = new \Symfony\Component\HttpClient\MockHttpClient(static function (string $method, string $url): never {
            throw new \Symfony\Component\HttpClient\Exception\TransportException(\sprintf('Could not resolve host: data.example for "%s".', $url));
        });
        try {
            (new \Omnilab\Earthdata\EarthdataSourceFactory($http))->create(['token' => 'earthdata-token-1234567'])->open('https://data.example/file.nc');
            self::fail('no exception');
        } catch (\Omnilab\Exception\UnavailableException $e) {
            self::assertInstanceOf(\Symfony\Component\HttpClient\Exception\TransportException::class, $e->getPrevious());
            for ($one = $e; null !== $one; $one = $one->getPrevious()) {
                self::assertFalse(str_contains($one->getMessage(), 'earthdata-token-1234567'), $one->getMessage());
                self::assertFalse(str_contains(print_r($one->getTrace(), true), 'earthdata-token-1234567'), 'a trace holds the token');
            }
        } finally {
            ini_set('zend.exception_ignore_args', false !== $ignoreArgs ? $ignoreArgs : '1');
        }
    }
}
