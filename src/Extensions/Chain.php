<?php

namespace LaravelEnso\CacheChain\Extensions;

use Illuminate\Cache\RetrievesMultipleKeys;
use Illuminate\Cache\TaggableStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\InteractsWithTime;
use LaravelEnso\CacheChain\Exceptions\Chain as Exception;

class Chain extends TaggableStore implements LockProvider
{
    use InteractsWithTime, RetrievesMultipleKeys;

    private Collection $adapters;
    private ?int $ttl;

    public function __construct()
    {
        $this->adapters(Config::get('cache.stores.chain.adapters'));

        $this->ttl = Config::get('cache.stores.chain.defaultTTL');
    }

    public function get($key)
    {
        return $this->cacheGet($key);
    }

    public function put($key, $value, $seconds)
    {
        return $this->handle('put', ...func_get_args());
    }

    public function increment($key, $value = 1)
    {
        $current = $this->cacheGet($key);
        $new = $current + $value;

        return $this->handleWithSync('increment', $key, $value, $new);
    }

    public function decrement($key, $value = 1)
    {
        $current = $this->cacheGet($key);
        $new = $current - $value;

        return $this->handleWithSync('decrement', $key, $value, $new);
    }

    public function forever($key, $value)
    {
        return $this->handle('forever', ...func_get_args());
    }

    public function forget($key)
    {
        return $this->handle('forget', ...func_get_args());
    }

    public function flush()
    {
        return $this->handle('flush', ...func_get_args());
    }

    public function getPrefix()
    {
        return '';
    }

    public function adapters(array $adapters)
    {
        throw_if(empty($adapters), Exception::adapters());

        $this->adapters = Collection::wrap($adapters)
            ->map(fn ($provider) => $this->store($provider));
    }

    public function restoreLock($name, $owner)
    {
        return $this->lockAdapter()->restoreLock($name, $owner);
    }

    public function lock($name, $seconds = 0, $owner = null)
    {
        return $this->lockAdapter()->lock($name, $seconds, $owner);
    }

    private function handleWithSync($method, $key, $value, $new)
    {
        return $this->adapters
            ->map(fn ($adapter) => $adapter->has($key)
                ? $adapter->{$method}($key, $value)
                : $adapter->{$method}($key, $new))
            ->last();
    }

    private function handle($method, ...$args)
    {
        return $this->adapters
            ->map(fn ($adapter) => $adapter->{$method}(...$args))
            ->last();
    }

    private function cacheGet($key, int $layer = 0)
    {
        if ($layer >= $this->adapters->count()) {
            return null;
        }

        $cachedValue = $this->adapters->get($layer)->get($key);

        if ($cachedValue !== null) {
            return $cachedValue;
        }

        if ($cachedValue = $this->cacheGet($key, $layer + 1)) {
            if ($this->ttl > 0) {
                $this->adapters->get($layer)->put($key, $cachedValue, $this->ttl);
            } else {
                $this->adapters->get($layer)->forever($key, $cachedValue);
            }
        }

        return $cachedValue;
    }

    private function store($provider)
    {
        return $provider instanceof Repository
            ? $provider
            : Cache::store($provider);
    }

    private function lockAdapter(): Repository
    {
        return $this->adapters
            ->reverse()
            ->filter(fn ($adapter) => $adapter->getStore() instanceof LockProvider)
            ->whenEmpty(function () {
                throw Exception::lockAdapter();
            })->first();
    }
}
