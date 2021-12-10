<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace Hyperf\Scout;

use Closure;
use Hyperf\Database\Model\Collection;
use Hyperf\Database\Model\Collection as BaseCollection;
use Hyperf\ModelListener\Collector\ListenerCollector;
use Hyperf\Scout\Engine\Engine;
use Hyperf\Utils\ApplicationContext;
use Hyperf\Utils\Coroutine;

trait Searchable
{
    /**
     * @var Coroutine\Concurrent
     */
    protected static $scoutRunner;
    /**
     * Additional metadata attributes managed by Scout.
     *
     * @var array
     */
    protected $scoutMetadata = [];

    /**
     * Boot the trait.
     */
    public static function bootSearchable()
    {
        static::addGlobalScope(make(SearchableScope::class));
        ListenerCollector::register(static::class, ModelObserver::class);
        (new static())->registerSearchableMacros();
    }

    /**
     * Register the searchable macros.
     */
    public function registerSearchableMacros()
    {
        $self = $this;
        BaseCollection::macro('searchable', function () use ($self) {
            $self->queueMakeSearchable($this);
        });
        BaseCollection::macro('unsearchable', function () use ($self) {
            $self->queueRemoveFromSearch($this);
        });
    }

    /**
     * Dispatch the coroutine to make the given models searchable.
     */
    public function queueMakeSearchable(Collection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }
        $job = function () use ($models) {
            $models->first()->searchableUsing()->update($models);
        };
        self::dispatchSearchableJob($job);
    }

    /**
     * Dispatch the coroutine to scout the given models.
     */
    protected static function dispatchSearchableJob(callable $job)
    {
        if (!Coroutine::inCoroutine()) {
            $job();
            return;
        }
        if (defined('SCOUT_COMMAND')) {
            if (!(static::$scoutRunner instanceof Coroutine\Concurrent)) {
                static::$scoutRunner = new Coroutine\Concurrent((new static())->syncWithSearchUsingConcurency());
            }
            self::$scoutRunner->create($job);
        } else {
            Coroutine::defer($job);
        }
    }

    /**
     * Get the concurrency that should be used when syncing.
     */
    public function syncWithSearchUsingConcurency(): int
    {
        return (int)config('scout.concurrency', 100);
    }

    /**
     * Dispatch the coroutine to make the given models unsearchable.
     * @param mixed $models
     */
    public function queueRemoveFromSearch($models)
    {
        if ($models->isEmpty()) {
            return;
        }
        $job = function () use ($models) {
            $models->first()->searchableUsing()->delete($models);
        };
        self::dispatchSearchableJob($job);
    }

    /**
     * Perform a search against the model's indexed data.
     */
    public static function search(?string $query = '', ?Closure $callback = null)
    {
        return make(Builder::class, [
            'model' => new static(),
            'query' => $query,
            'callback' => $callback,
            'softDelete' => static::usesSoftDelete() && config('scout.soft_delete', false),
        ]);
    }

    /**
     * Determine if the current class should use soft deletes with searching.
     *
     * @return bool
     */
    protected static function usesSoftDelete()
    {
        return in_array(SoftDeletes::class, class_uses_recursive(get_called_class()));
    }

    /**
     * Make all instances of the model searchable.
     */
    public static function makeAllSearchable(?int $chunk = null, ?string $column = null)
    {
        $self = new static();
        $softDelete = static::usesSoftDelete() && config('scout.soft_delete', false);
        $self->newQuery()
            ->when($softDelete, function ($query) {
                $query->withTrashed();
            })
            ->orderBy($column ?: $self->getKeyName())
            ->searchable($chunk, $column);
    }

    /**
     * Remove all instances of the model from the search index.
     */
    public static function removeAllFromSearch(): void
    {
        $self = new static();
        $self->searchableUsing()->flush($self);
    }

    /**
     * Get the Scout engine for the model.
     *
     * @return Engine
     */
    public function searchableUsing()
    {
        return ApplicationContext::getContainer()->get(Engine::class);
    }

    /**
     * Temporarily disable search syncing for the given callback.
     *
     * @return mixed
     */
    public static function withoutSyncingToSearch(callable $callback)
    {
        static::disableSearchSyncing();
        try {
            return $callback();
        } finally {
            static::enableSearchSyncing();
        }
    }

    /**
     * Disable search syncing for this model.
     */
    public static function disableSearchSyncing(): void
    {
        ModelObserver::disableSyncingFor(get_called_class());
    }

    /**
     * Enable search syncing for this model.
     */
    public static function enableSearchSyncing(): void
    {
        ModelObserver::enableSyncingFor(get_called_class());
    }

    /**
     * Determine if the model should be searchable.
     *
     * @return bool
     */
    public function shouldBeSearchable()
    {
        return true;
    }

    /**
     * Make the given model instance searchable.
     */
    public function searchable(): void
    {
        $this->newCollection([$this])->searchable();
    }

    /**
     * Remove the given model instance from the search index.
     */
    public function unsearchable(): void
    {
        $this->newCollection([$this])->unsearchable();
    }

    /**
     * Get the requested models from an array of object IDs.
     */
    public function getScoutModelsByIds(Builder $builder, array $ids)
    {
        $query = static::usesSoftDelete()
            ? $this->withTrashed() : $this->newQuery();
        if ($builder->queryCallback) {
            call_user_func($builder->queryCallback, $query);
        }

        $modelIdPositions = array_flip($ids);
        return $query->whereIn(
            $this->getScoutKeyName(),
            $ids
        )->get()->sortBy(static function ($model) use ($modelIdPositions) {
            return $modelIdPositions[$model->getScoutKey()];
        })->values();
    }

    /**
     * Get the key name used to index the model.
     *
     * @return mixed
     */
    public function getScoutKeyName()
    {
        return $this->getQualifiedKeyName();
    }

    /**
     * Get the index name for the model.
     *
     * @return string
     */
    public function searchableAs()
    {
        return config('scout.prefix') . $this->getTable();
    }

    /**
     * Get the indexable data array for the model.
     *
     * @return array
     */
    public function toSearchableArray()
    {
        return $this->toArray();
    }

    /**
     * Sync the soft deleted status for this model into the metadata.
     *
     * @return $this
     */
    public function pushSoftDeleteMetadata()
    {
        return $this->withScoutMetadata('__soft_deleted', $this->trashed() ? 1 : 0);
    }

    /**
     * Set a Scout related metadata.
     *
     * @param string $key
     * @param mixed $value
     * @return $this
     */
    public function withScoutMetadata($key, $value)
    {
        $this->scoutMetadata[$key] = $value;
        return $this;
    }

    /**
     * Get all Scout related metadata.
     *
     * @return array
     */
    public function scoutMetadata(): array
    {
        return $this->scoutMetadata;
    }

    /**
     * Get the value used to index the model.
     *
     * @return mixed
     */
    public function getScoutKey()
    {
        return $this->getKey();
    }

    public function searchableStruct(): array
    {
        return [];
    }

    /**
     * 创建索引结构
     */
    public function searchableCreateStruct()
    {
        $this->searchableUsing()->createStruct($this);
    }

    /**
     * 删除索引结构
     */
    public function searchableDropStruct()
    {
        $this->searchableUsing()->dropStruct($this);
    }
}
