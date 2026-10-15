<?php

namespace Omnilab\Earthdata;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * NASA Earthdata's Common Metadata Repository: the granules of NASA's
 * Earth observation collections by place, period and cloud cover, and the
 * collections by text - no account needed to search.
 *
 *   options:
 *     collections: []                  # what a query that names none searches: entry ids (HLSS30_2.0) or concept ids
 *     token: ~                         # an Earthdata Login token (urs.earthdata.nasa.gov, a free account), for open(): downloading the files
 *     mailto: 'support@glitchr.io'     # in the User-Agent
 *     throttle: 0                      # seconds between two calls
 *     base_uri: 'https://cmr.earthdata.nasa.gov/search/'   # or the UAT's: https://cmr.uat.earthdata.nasa.gov/search/
 */
final class EarthdataSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'earthdata',
            'omnilab.required_options' => [],
            'collections' => [],
            'token' => null,
            'throttle' => 0.0,
            'base_uri' => EarthdataSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new EarthdataSource(
            $this->http ?? HttpClient::create(),
            array_values(array_map('strval', (array) $c->get('collections'))),
            $c->get('token'),
            (string) $c->get('base_uri', EarthdataSource::BASE_URI),
            ['User-Agent' => self::userAgent($c), 'Client-Id' => 'omnilab'],
            (float) $c->get('throttle'),
        );
    }
}
