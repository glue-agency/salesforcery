<?php

namespace Stratease\Salesforcery\Salesforce\Database\Relations;

use Closure;
use Stratease\Salesforcery\Salesforce\Database\Builder;
use Stratease\Salesforcery\Salesforce\Database\Collection;
use Stratease\Salesforcery\Salesforce\Database\Model;

class BelongsTo extends Relation
{

    /**
     * @var Model
     */
    protected $child;

    /**
     * @var string $foreignKey
     */
    protected $foreignKey;

    /**
     * @var string
     */
    protected $ownerKey;

    public function __construct(Builder $builder, Model $child, string $foreignKey, string $ownerKey)
    {
        $this->foreignKey = $foreignKey;
        $this->ownerKey = $ownerKey;

        // In the underlying base relationship class, this variable is referred to as
        // the "parent" since most relationships are not inverted. But, since this
        // one is we will create a "child" variable for much better readability.
        $this->child = $child;

        parent::__construct($builder, $child);
    }

    public function getResults()
    {
        if(is_null($this->child->{$this->foreignKey})) {
            return;
        }

        return $this->builder->first();
    }

    public function addConstraints(): void
    {
        $this->builder->where($this->ownerKey, '=', $this->child->{$this->foreignKey});
    }

    public function getEagerResults()
    {
        return $this->builder->get();
    }

    public function addEagerConstraints(array $models): void
    {
        $this->builder->whereIn(
            $this->ownerKey, $this->getKeys($models, $this->foreignKey)
        );
    }

    public function initRelation(array $models, $relation)
    {
        foreach($models as $model) {
            $model->setRelation($relation, null);
        }

        return $models;
    }

    public function match(array $models, Collection $results, $relation)
    {
        $dictionary = [];

        foreach($results as $result) {
            $dictionary[$result->{$this->ownerKey}] = $result;
        }

        foreach($models as $model) {
            if(isset($dictionary[$model->{$this->foreignKey}])) {
                $model->setRelation($relation, $dictionary[$model->{$this->foreignKey}]);
            }
        }

        return $models;
    }

    public function getRelationExistenceQuery(Relation $relation, Builder $parentBuilder, Closure $callback)
    {
        $query = $relation->getRelated()->newQuery();

        $query->select($parentBuilder->model->primaryKey);
        $callback($query);

        $parentBuilder->query->whereIn($relation->getForeignKey(), $query);
    }

    public function getForeignKey()
    {
        return $this->foreignKey;
    }
}
