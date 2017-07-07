<?php

namespace Kevinrob\GuzzleCache\Strategy;

use Kevinrob\GuzzleCache\CacheEntry;
use Kevinrob\GuzzleCache\KeyValueHttpHeader;
use Kevinrob\GuzzleCache\Storage\CacheStorageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * This strategy represents a "greedy" HTTP client.
 *
 * It can be used to cache responses in spite of any cache related response headers,
 * but it SHOULDN'T be used unless absolutely necessary, e.g. when accessing
 * badly designed APIs without Cache control.
 *
 * Obviously, this follows no RFC :(.
 */
class GreedyCacheStrategy extends PrivateCacheStrategy
{
    /**
     * @var int
     */
    protected $ttl;

    /**
     * @var KeyValueHttpHeader
     */
    private $varyHeaders;

    public function __construct(CacheStorageInterface $cache = null, $ttl, KeyValueHttpHeader $varyHeaders = null)
    {
        $this->ttl = $ttl;
        $this->varyHeaders = $varyHeaders;

        parent::__construct($cache);
    }

    protected function getCacheKey(RequestInterface $request, KeyValueHttpHeader $varyHeaders = null)
    {
        if (null === $varyHeaders || $varyHeaders->isEmpty()) {
            return hash(
                'sha256',
                'greedy'.$request->getMethod().$request->getUri()
            );
        }
        $cacheHeaders = [];

        foreach ($varyHeaders as $key => $value) {
            if ($request->hasHeader($key)) {
                $cacheHeaders[$key] = $request->getHeader($key);
            }
        }

        return hash(
            'sha256',
            'greedy'.$request->getMethod().$request->getUri().json_encode($cacheHeaders)
        );
    }

    public function cache(RequestInterface $request, ResponseInterface $response)
    {
        $warningMessage = sprintf('%d - "%s" "%s"',
            299,
            'Cached although the response headers indicate not to do it!',
            (new \DateTime())->format(\DateTime::RFC1123)
        );

        $response = $response->withAddedHeader('Warning', $warningMessage);

        if ($cacheObject = $this->getCacheObject($request, $response)) {
            $varyHeaders = $this->varyHeaders;
            return $this->storage->save(
                $this->getCacheKey($request, $varyHeaders),
                $cacheObject
            );
        }

        return false;
    }

    protected function getCacheObject(RequestInterface $request, ResponseInterface $response)
    {
        if (!array_key_exists($response->getStatusCode(), $this->statusAccepted)) {
            // Don't cache it
            return null;
        }
        $varyHeader = $this->varyHeaders;

        if (null !== $varyHeader && $varyHeader->has('*')) {
            // This will never match with a request
            return;
        }


        return new CacheEntry($request, $response, new \DateTime(sprintf('+%d seconds', $this->ttl)));
    }

    public function fetch(RequestInterface $request)
    {
        $cache = $this->storage->fetch($this->getCacheKey($request, $this->varyHeaders));
        return $cache;
    }
}
