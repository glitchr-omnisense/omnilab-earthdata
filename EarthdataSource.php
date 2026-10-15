<?php

namespace Omnilab\Earthdata;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Model\Asset;
use Omnilab\Model\BBox;
use Omnilab\Model\Point;
use Omnilab\Model\Product;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\Page;
use Omnilab\Source\ProductQuery;
use Omnilab\Source\Query;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * NASA Earthdata's Common Metadata Repository (cmr.earthdata.nasa.gov/
 * search): the granules of NASA's Earth observation collections - MODIS,
 * VIIRS, the Harmonized Landsat Sentinel-2 (HLS), GPM, SMAP, ICESat-2,
 * the OPERA products... - as products, by collection, point or box, period
 * and cloud cover, page by page; and the collections themselves, searched
 * by text (datasets()). Searching needs no account.
 *
 * A granule's assets are its files as the CMR lists them: an https://
 * address at its archive (a DAAC), its s3:// one in AWS as the alternate,
 * its browse image (open, the product's preview). Downloading the files
 * needs an Earthdata Login token (a free account): give it as the token
 * option and open() sends it - the search never needs it, though the CMR
 * shows an account the restricted collections it may see.
 */
final class EarthdataSource extends HttpSource
{
    public const BASE_URI = 'https://cmr.earthdata.nasa.gov/search/';

    /** The most granules or collections the CMR answers a page. */
    public const MAX_PER_PAGE = 2000;

    /**
     * @param list<string> $collections what a query that names none searches
     * @param string|null  $token       an Earthdata Login token, sent with open() (and to the CMR, which then shows the account's restricted metadata)
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly array $collections = [],
        #[\SensitiveParameter] private readonly ?string $token = null,
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = 0.0,
    ) {
        parent::__construct($http, $baseUri, $headers + (null !== $token && '' !== $token ? ['Authorization' => 'Bearer '.$token] : []), $throttle);
    }

    public function getName(): string
    {
        return 'earthdata';
    }

    public function capabilities(): array
    {
        return [Capability::PRODUCTS, Capability::AREA, Capability::CLOUD_COVER];
    }

    /**
     * The granules of the collections named (CMR concept ids, C2021957295-
     * LPCLOUD, or entry ids: the short name and version, HLSS30_2.0,
     * MOD09GA_061 - the CMR STAC's collection ids too) over the place and
     * the period, under the cloud cover, newest first.
     */
    public function products(ProductQuery $query): Page
    {
        $collections = $query->collections ?: $this->collections;
        if (!$collections) {
            throw NotSupportedException::operation('earthdata', 'search every collection at once (name one: HLSS30_2.0, MOD09GA_061, C2021957295-LPCLOUD...)');
        }
        $concepts = array_values(array_filter($collections, static fn (string $c) => (bool) preg_match('~^C\d+-[A-Z0-9_]+$~', $c)));
        $entries = array_values(array_diff($collections, $concepts));
        $answer = $this->getJsonWithHeaders('granules.umm_json', [
            'collection_concept_id[]' => $concepts ?: null,
            'entry_id[]' => $entries ?: null,
            'point' => $query->place instanceof Point ? self::number($query->place->longitude).','.self::number($query->place->latitude) : null,
            // West, south, east, north; a box across the antimeridian (west > east) the CMR reads as such.
            'bounding_box' => $query->place instanceof BBox ? implode(',', array_map(self::number(...), $query->place->toArray())) : null,
            'temporal' => null === $query->from && null === $query->to ? null : self::instant($query->from).','.self::instant($query->to),
            'cloud_cover' => null === $query->maxCloudCover ? null : '0,'.self::number($query->maxCloudCover),
            'sort_key' => ProductQuery::OLDEST === $query->sort ? 'start_date' : '-start_date',
            'page_size' => max(1, min(self::MAX_PER_PAGE, $query->limit)),
        ], null === $query->cursor ? [] : ['CMR-Search-After' => $query->cursor]);
        [$data, $headers] = $answer ?? [[], []];
        $single = 1 === \count($collections) ? $collections[0] : null;
        $products = array_values(array_map(fn (array $item) => $this->toGranule($item, $single), array_filter($data['items'] ?? [], static fn (mixed $i) => \is_array($i) && isset($i['umm']))));
        $hits = isset($headers['cmr-hits'][0]) ? (int) $headers['cmr-hits'][0] : (isset($data['hits']) ? (int) $data['hits'] : null);
        $after = $headers['cmr-search-after'][0] ?? null;

        return new Page($products, $products && null !== $after && \count($products) >= max(1, min(self::MAX_PER_PAGE, $query->limit)) ? $after : null, $hits);
    }

    /**
     * The collections that match a text (the CMR's keyword search: titles,
     * abstracts, science keywords, platforms...), the best match first, a
     * page at a time. Outside the contract: the core's search() answers
     * entities, and a collection is a Product (its id the entry id that
     * products() takes).
     *
     * @return Page<Product>
     */
    public function datasets(Query $query): Page
    {
        $answer = $this->getJsonWithHeaders('collections.umm_json', [
            'keyword' => $query->text,
            'page_size' => max(1, min(self::MAX_PER_PAGE, $query->limit)),
        ], null === $query->cursor ? [] : ['CMR-Search-After' => $query->cursor]);
        [$data, $headers] = $answer ?? [[], []];
        $products = array_values(array_map($this->toCollection(...), array_filter($data['items'] ?? [], static fn (mixed $i) => \is_array($i) && isset($i['umm']))));
        $after = $headers['cmr-search-after'][0] ?? null;

        return new Page($products, $products && null !== $after && \count($products) >= max(1, min(self::MAX_PER_PAGE, $query->limit)) ? $after : null, isset($headers['cmr-hits'][0]) ? (int) $headers['cmr-hits'][0] : null);
    }

    /**
     * A file of a granule (an asset, or its address), opened with the
     * Earthdata Login token: the response, streamed, not read - the
     * application reads it (getContent(), or stream()) or saves it. The
     * archive redirects to the file's store; a refusal is the archive's
     * (401, or a redirection to the login page).
     */
    public function open(Asset|string $file): ResponseInterface
    {
        if (null === $this->token || '' === $this->token) {
            throw NotSupportedException::operation('earthdata', 'download a file without a token (option token: an Earthdata Login token)');
        }
        $href = $file instanceof Asset ? ($file->httpsHref() ?? $file->href) : $file;
        if (!str_starts_with($href, 'https://')) {
            throw NotSupportedException::operation('earthdata', 'open an address other than https:// ('.$href.': in AWS, us-west-2, with S3 credentials)');
        }
        try {
            return $this->http->request('GET', $href, ['headers' => ['Authorization' => 'Bearer '.$this->token] + array_diff_key($this->headers, ['Authorization' => true])]);
        } catch (TransportExceptionInterface $e) {
            throw new UnavailableException('earthdata', 'unreachable: '.$e->getMessage(), null, $e);
        }
    }

    /**
     * A granule as a Product.
     *
     * @param array{meta?: array<string, mixed>, umm: array<string, mixed>} $item
     */
    private function toGranule(array $item, ?string $asked): Product
    {
        $umm = $item['umm'];
        $meta = $item['meta'] ?? [];
        $id = (string) ($umm['GranuleUR'] ?? $meta['native-id'] ?? $meta['concept-id'] ?? '');
        $reference = $umm['CollectionReference'] ?? [];
        $collection = isset($reference['ShortName'], $reference['Version'])
            ? $reference['ShortName'].'_'.$reference['Version']
            : ($asked ?? (string) ($meta['collection-concept-id'] ?? ''));

        [$datetime, $start, $end] = self::period($umm['TemporalExtent'] ?? []);
        [$bbox, $geometry] = self::footprint($umm['SpatialExtent']['HorizontalSpatialDomain']['Geometry'] ?? []);

        $attributes = [];
        foreach ($umm['AdditionalAttributes'] ?? [] as $attribute) {
            if (isset($attribute['Name'])) {
                $values = array_values(array_map('strval', (array) ($attribute['Values'] ?? [])));
                $attributes[(string) $attribute['Name']] = 1 === \count($values) ? $values[0] : $values;
            }
        }
        $cloud = $umm['CloudCover'] ?? $attributes['CLOUD_COVERAGE'] ?? $attributes['CLOUD_COVER'] ?? null;
        $platforms = array_values(array_filter(array_map(static fn (mixed $p) => \is_array($p) ? ($p['ShortName'] ?? null) : null, $umm['Platforms'] ?? [])));
        $instruments = [];
        foreach ($umm['Platforms'] ?? [] as $platform) {
            foreach ($platform['Instruments'] ?? [] as $instrument) {
                if (isset($instrument['ShortName'])) {
                    $instruments[] = (string) $instrument['ShortName'];
                }
            }
        }
        $size = null;
        foreach ($umm['DataGranule']['ArchiveAndDistributionInformation'] ?? [] as $file) {
            $bytes = isset($file['SizeInBytes']) ? (float) $file['SizeInBytes'] : (isset($file['Size']) ? (float) $file['Size'] * match (strtoupper((string) ($file['SizeUnit'] ?? 'B'))) {
                'KB' => 1024, 'MB' => 1024 ** 2, 'GB' => 1024 ** 3, 'TB' => 1024 ** 4, default => 1,
            } : null);
            $size = null === $bytes ? $size : ($size ?? 0) + $bytes;
        }
        $properties = array_filter([
            'eo:cloud_cover' => is_numeric($cloud) ? (float) $cloud : null,
            'platform' => $platforms[0] ?? null,
            'platforms' => \count($platforms) > 1 ? $platforms : null,
            'instruments' => $instruments ?: null,
            'day_night' => $umm['DataGranule']['DayNightFlag'] ?? null,
            'production_datetime' => $umm['DataGranule']['ProductionDateTime'] ?? null,
            'size' => null === $size ? null : (int) round($size),
            'attributes' => $attributes ?: null,
            'cmr:concept_id' => $meta['concept-id'] ?? null,
            'cmr:collection_concept_id' => $meta['collection-concept-id'] ?? null,
            'cmr:provider' => $meta['provider-id'] ?? null,
            'cmr:revision_date' => $meta['revision-date'] ?? null,
        ], static fn (mixed $v) => null !== $v && '' !== $v);

        $assets = self::assets($umm['RelatedUrls'] ?? [], $id);
        $preview = null;
        foreach ($assets as $asset) {
            if ($asset->hasRole('thumbnail') && null !== ($preview = $asset->httpsHref())) {
                break;
            }
        }

        return new Product(
            id: $id,
            collection: $collection,
            datetime: $datetime,
            start: $start,
            end: $end,
            bbox: $bbox,
            geometry: $geometry,
            properties: $properties,
            assets: $assets,
            preview: $preview,
            url: isset($meta['concept-id']) ? rtrim($this->baseUri, '/').'/concepts/'.$meta['concept-id'].'.umm_json' : null,
            source: 'earthdata',
        );
    }

    /**
     * A collection as a Product: its entry id (short name and version), its
     * period, its footprint, its description.
     *
     * @param array{meta?: array<string, mixed>, umm: array<string, mixed>} $item
     */
    private function toCollection(array $item): Product
    {
        $umm = $item['umm'];
        $meta = $item['meta'] ?? [];
        $id = isset($umm['ShortName'], $umm['Version']) ? $umm['ShortName'].'_'.$umm['Version'] : (string) ($meta['concept-id'] ?? '');
        $range = $umm['TemporalExtents'][0]['RangeDateTimes'][0] ?? [];
        $rectangle = $umm['SpatialExtent']['HorizontalSpatialDomain']['Geometry']['BoundingRectangles'][0] ?? null;
        $bbox = null;
        if (\is_array($rectangle) && isset($rectangle['WestBoundingCoordinate'], $rectangle['SouthBoundingCoordinate'], $rectangle['EastBoundingCoordinate'], $rectangle['NorthBoundingCoordinate'])) {
            try {
                $bbox = new BBox((float) $rectangle['WestBoundingCoordinate'], (float) $rectangle['SouthBoundingCoordinate'], (float) $rectangle['EastBoundingCoordinate'], (float) $rectangle['NorthBoundingCoordinate']);
            } catch (\InvalidArgumentException) {
            }
        }
        $properties = array_filter([
            'title' => $umm['EntryTitle'] ?? null,
            'description' => $umm['Abstract'] ?? null,
            'short_name' => $umm['ShortName'] ?? null,
            'version' => $umm['Version'] ?? null,
            'sci:doi' => $umm['DOI']['DOI'] ?? null,
            'processing_level' => $umm['ProcessingLevel']['Id'] ?? null,
            'platforms' => array_values(array_filter(array_map(static fn (mixed $p) => \is_array($p) ? ($p['ShortName'] ?? null) : null, $umm['Platforms'] ?? []))) ?: null,
            'ends_at_present' => $umm['TemporalExtents'][0]['EndsAtPresentFlag'] ?? null,
            'cloud_hosted' => isset($umm['DirectDistributionInformation']) ? true : null,
            'cmr:concept_id' => $meta['concept-id'] ?? null,
            'cmr:provider' => $meta['provider-id'] ?? null,
        ], static fn (mixed $v) => null !== $v && '' !== $v);
        $assets = [];
        foreach ($umm['RelatedUrls'] ?? [] as $related) {
            $type = $related['Type'] ?? null;
            $role = match ($type) {
                'DATA SET LANDING PAGE' => 'landing',
                'GET RELATED VISUALIZATION' => 'thumbnail',
                default => null,
            };
            if (null !== $role && \is_string($related['URL'] ?? null)) {
                $name = 'landing' === $role ? 'landing' : 'browse';
                $assets[$name] ??= new Asset($name, $related['URL'], $related['MimeType'] ?? null, [$role], $related['Description'] ?? null);
            }
        }
        $preview = null;
        foreach ($assets as $asset) {
            if ($asset->hasRole('thumbnail') && null !== ($preview = $asset->httpsHref())) {
                break;
            }
        }

        return new Product(
            id: $id,
            collection: $id,
            start: self::time($range['BeginningDateTime'] ?? null),
            end: self::time($range['EndingDateTime'] ?? null),
            bbox: $bbox,
            properties: $properties,
            assets: $assets,
            preview: $preview,
            url: isset($meta['concept-id']) ? rtrim($this->baseUri, '/').'/concepts/'.$meta['concept-id'].'.html' : null,
            source: 'earthdata',
        );
    }

    /**
     * A granule's files: each https:// one an asset named after the part of
     * its file name that follows the granule's ("B05" of
     * HLS.S30.T32ULU.2026283T102941.v2.0.B05.tif), its s3:// twin its "s3"
     * alternate; the browse images ("thumbnail"), the metadata files.
     *
     * @param list<array<string, mixed>> $related
     *
     * @return array<string, Asset>
     */
    private static function assets(array $related, string $granule): array
    {
        $files = [];
        foreach ($related as $url) {
            $href = $url['URL'] ?? null;
            if (!\is_string($href)) {
                continue;
            }
            $role = match ($url['Type'] ?? null) {
                'GET DATA', 'GET DATA VIA DIRECT ACCESS' => 'data',
                'GET RELATED VISUALIZATION' => 'thumbnail',
                'EXTENDED METADATA' => 'metadata',
                'VIEW RELATED INFORMATION' => str_ends_with($href, '.xml') || str_ends_with($href, '.json') ? 'metadata' : null,
                'USE SERVICE API' => 'service',
                default => null,
            };
            if (null === $role) {
                continue;
            }
            $file = basename((string) parse_url($href, \PHP_URL_PATH));
            $extension = '~\.(?:tif|tiff|hdf|h5|he5|nc|nc4|jpg|jpeg|png|xml|json|zip|txt)$~i';
            // HLS.S30.T32ULU.2026283T102941.v2.0.B05.tif: "B05"; ..._stac.json: "stac"; the granule's own file (MOD09GA's .hdf): "data".
            $name = match (true) {
                'thumbnail' === $role => 'browse',
                str_starts_with($file, $granule.'.') || str_starts_with($file, $granule.'_') => (string) preg_replace($extension, '', substr($file, \strlen($granule) + 1)),
                default => (string) preg_replace($extension, '', $file),
            };
            if ('' === $name || preg_match('~^[a-z0-9]{2,4}$~', $name) && 1 === preg_match($extension, '.'.$name)) {
                $name = $role;
            }
            $key = $role.'|'.$name;
            $files[$key] ??= ['name' => $name, 'role' => $role, 'https' => null, 's3' => null, 'other' => null, 'type' => $url['MimeType'] ?? null, 'title' => $url['Description'] ?? null];
            $slot = str_starts_with($href, 'https://') ? 'https' : (str_starts_with($href, 's3://') ? 's3' : 'other');
            $files[$key][$slot] ??= $href;
        }
        $assets = [];
        foreach ($files as $file) {
            $href = $file['https'] ?? $file['other'] ?? $file['s3'];
            $alternates = array_filter(['s3' => $href !== $file['s3'] ? $file['s3'] : null], static fn (?string $h) => null !== $h);
            $name = isset($assets[$file['name']]) ? $file['role'].'_'.$file['name'] : $file['name'];
            $assets[$name] = new Asset($name, (string) $href, $file['type'], [$file['role']], $file['title'], null, $alternates);
        }

        return $assets;
    }

    /**
     * The nominal time, the start and the end of a granule's TemporalExtent.
     *
     * @return array{\DateTimeImmutable|null, \DateTimeImmutable|null, \DateTimeImmutable|null}
     */
    private static function period(array $extent): array
    {
        if (isset($extent['SingleDateTime'])) {
            return [self::time($extent['SingleDateTime']), null, null];
        }
        $start = self::time($extent['RangeDateTime']['BeginningDateTime'] ?? null);
        $end = self::time($extent['RangeDateTime']['EndingDateTime'] ?? null);

        return null !== $end && null !== $start && $end != $start ? [$start, $start, $end] : [$start, null, null];
    }

    /**
     * A granule's footprint: its box and its GeoJSON geometry, from its
     * polygons or its bounding rectangles.
     *
     * @return array{BBox|null, array<string, mixed>|null}
     */
    private static function footprint(array $geometry): array
    {
        $rings = [];
        foreach ($geometry['GPolygons'] ?? [] as $polygon) {
            $ring = array_map(static fn (array $p) => [(float) $p['Longitude'], (float) $p['Latitude']], array_filter($polygon['Boundary']['Points'] ?? [], static fn (mixed $p) => \is_array($p) && isset($p['Longitude'], $p['Latitude'])));
            if (\count($ring) >= 4) {
                $rings[] = array_values($ring);
            }
        }
        foreach ($geometry['BoundingRectangles'] ?? [] as $r) {
            if (isset($r['WestBoundingCoordinate'], $r['SouthBoundingCoordinate'], $r['EastBoundingCoordinate'], $r['NorthBoundingCoordinate'])) {
                [$w, $s, $e, $n] = [(float) $r['WestBoundingCoordinate'], (float) $r['SouthBoundingCoordinate'], (float) $r['EastBoundingCoordinate'], (float) $r['NorthBoundingCoordinate']];
                $rings[] = [[$w, $s], [$e, $s], [$e, $n], [$w, $n], [$w, $s]];
            }
        }
        if (!$rings) {
            return [null, null];
        }
        $points = array_merge(...$rings);
        $longitudes = array_column($points, 0);
        $latitudes = array_column($points, 1);
        try {
            // A footprint that spans the antimeridian is not unrolled here: its box is the widest.
            $bbox = new BBox(max(-180, min($longitudes)), max(-90, min($latitudes)), min(180, max($longitudes)), min(90, max($latitudes)));
        } catch (\InvalidArgumentException) {
            $bbox = null;
        }

        return [$bbox, 1 === \count($rings) ? ['type' => 'Polygon', 'coordinates' => [$rings[0]]] : ['type' => 'MultiPolygon', 'coordinates' => array_map(static fn (array $ring) => [$ring], $rings)]];
    }

    /** An end of the period in RFC 3339, UTC; empty when open. */
    private static function instant(?\DateTimeInterface $time): string
    {
        return null === $time ? '' : \DateTimeImmutable::createFromInterface($time)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(\sprintf('%.6F', $value), '0'), '.');
    }
}
