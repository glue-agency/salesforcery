<?php

namespace Stratease\Salesforcery\Salesforce\Database\Relations;

use Closure;
use Illuminate\Support\Traits\ForwardsCalls;
use Stratease\Salesforcery\Salesforce\Database\Builder;
use Illuminate\Support\Collection;
use Stratease\Salesforcery\Salesforce\Database\Model;

abstract class Relation
{

    use ForwardsCalls;

    /**
     * @var Builder
     */
    protected $builder;

    /**
     * @var Model
     */
    protected $parent;

    /**
     * @var Model
     */
    protected $related;

    public function __construct(Builder $builder, Model $parent)
    {
        $this->builder = $builder;
        $this->parent = $parent;

        $this->related = $builder->model;

        $this->addConstraints();
    }

    abstract public function getResults();

    abstract public function addConstraints(): void;

    public function getEagerResults()
    {
        return $this->getResults();
    }

    abstract public function addEagerConstraints(array $models): void;

    abstract public function initRelation(array $models, $relation);

    abstract public function match(array $models, Collection $results, $name);

    public function getRelationExistenceQuery(Relation $relation, Builder $parentBuilder, Closure $callback = null)
    {
        $query = $relation->getRelated()->newQuery();
        $query->select($parentBuilder->model->primaryKey);

        if($callback) {
            $callback($query);
        }

        $parentBuilder->query->whereIn($relation->getParent()->primaryKey, $query);
    }

    public function withoutDefaultConstraint(): void
    {
        unset($this->builder->query->wheres[0]);
    }

    public function getQueryBuilder(): Builder
    {
        return $this->builder;
    }

    protected function getKeys(array $models, $key): array
    {
        $keys = [];

        foreach($models as $model) {
            $keys[] = $model->{$key};
        }

        $keys = array_unique($keys);

        return $keys;
    }

    public function getRelated(): Model
    {
        return $this->related;
    }

    public function __call($method, $parameters)
    {
        $result = $this->forwardCallTo($this->builder, $method, $parameters);

        if($result === $this->builder) {
            return $this;
        }

        return $result;
    }

    public function getParent()
    {
        return $this->parent;
    }
}
