<?php

namespace Stratease\Salesforcery\Salesforce\Database\Relations;

use Illuminate\Support\Traits\ForwardsCalls;
use Stratease\Salesforcery\Salesforce\Database\Collection;
use Stratease\Salesforcery\Salesforce\Database\Model;
use Stratease\Salesforcery\Salesforce\Database\Builder;

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
     * @var string $foreignKey
     */
    protected $foreignKey;

    /**
     * @var Model
     */
    protected $related;

    public function __construct(Builder $builder, Model $parent, string $foreignKey)
    {
        $this->builder = $builder;
        $this->parent = $parent;
        $this->foreignKey = $foreignKey;

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

    public function withoutDefaultConstraint(): void
    {
        unset($this->builder->query->wheres[0]);
    }

    public function getQueryBuilder(): Builder
    {
        return $this->builder;
    }

    public function __call($method, $parameters)
    {
        $result = $this->forwardCallTo($this->builder, $method, $parameters);

        if($result === $this->builder) {
            return $this;
        }

        return $result;
    }

}
