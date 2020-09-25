<?php

namespace LaravelEnso\CacheChain\Extensions;

use Illuminate\Cache\RetrievesMultipleKeys;
use Illuminate\Cache\TaggableStore;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\InteractsWithTime;

class Chain extends TaggableStore
{
    use InteractsWithTime, RetrievesMultipleKeys;

    private Collection $chains;

    public function __construct($chains = [])
    {
        $this->chains = (new Collection($chains))
            ->map(fn ($provider) => $provider instanceof Store ? $provider : Cache::store($provider));
    }

    public function get($key)
    {
        return $this->getAndPut($key);
    }

    public function put($key, $value, $seconds)
    {
        return $this->each('put', ...func_get_args());
    }

    public function increment($key, $value = 1)
    {
        return $this->each('increment', ...func_get_args());
    }

    public function decrement($key, $value = 1)
    {
        return $this->each('decrement', ...func_get_args());
    }

    public function forever($key, $value)
    {
        return $this->each('forever', ...func_get_args());
    }

    public function forget($key)
    {
        return $this->each('forget', ...func_get_args());
    }

    public function flush()
    {
        return $this->each('flush', ...func_get_args());
    }

    public function getPrefix()
    {
        return '';
    }

    private function each($method, ...$args)
    {
        return $this->chains->each->$method(...$args);
    }

    private function getAndPut($key, $index = 0)
    {
        if ($index >= $this->chains->count()) {
            return null;
        }

        if ($result = $this->chains->get($index)->get($key)) {
            return $result;
        }

        if ($result = $this->getAndPut($key, $index + 1)) {
            config('cache.stores.chain.defaultTTL') !== null
                ? $this->chains->get($index)->put($key, $result, config('cache.stores.chain.defaultTTL'))
                : $this->chains->get($index)->forever($key, $result);
        }

        return $result;
    }
}
