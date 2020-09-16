<?php

namespace Stratease\Salesforcery\Salesforce\Database\Relations;

use Stratease\Salesforcery\Salesforce\Database\Collection;
use Stratease\Salesforcery\Salesforce\Database\Model;
use Stratease\Salesforcery\Salesforce\Database\Builder;

abstract class Relation
{

    /**
     * @var Builder
     */
    protected $query;

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

    public function __construct(Builder $query, Model $parent, string $foreignKey)
    {
        $this->query = $query;
        $this->parent = $parent;
        $this->foreignKey = $foreignKey;

        $this->related = $query->model;

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
        unset($this->query->wheres[0]);
    }

    public function getQueryBuilder(): Builder
    {
        return $this->query;
    }
}
