<?php

namespace Guzzle\Common\Cache;

/**
 * Abstract cache adapter
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
abstract class AbstractCacheAdapter implements CacheAdapterInterface
{
    protected $cache;

    /**
     * {@inheritdoc}
     */
    public function getCacheObject()
    {
        return $this->cache;
    }
}