<?php

namespace Stratease\Salesforcery\Salesforce\Database\Relations;

use Closure;
use Stratease\Salesforcery\Salesforce\Database\Builder;
use Stratease\Salesforcery\Salesforce\Database\Collection;

class HasOne extends HasOneOrMany
{

    // !! THIS IS STILL A WIP !!

    public function getResults()
    {
        if(is_null($this->localKey)) {
            return;
        }

        return $this->builder->first();
    }

    public function addConstraints(): void
    {
        $this->builder->where($this->foreignKey, $this->parent->{$this->localKey});
    }

    public function getEagerResults()
    {
        return $this->builder->get();
    }

    public function initRelation(array $models, $relation)
    {
        foreach($models as $model) {
            $model->setRelation($relation, new $model);
        }

        return $models;
    }

    public function match(array $models, Collection $results, $name)
    {
        $dictionary = $this->buildDictionary($results);

        foreach($models as $model) {
            if(isset($dictionary[$key = $model->{$this->localKey}])) {
                $model->setRelation(
                    $name,
                    $this->getRelationValue($dictionary, $key),
                );
            }
        }

        return $models;
    }

    protected function getRelationValue($dictionary, $key)
    {
        $value = $dictionary[$key];

        return reset($value);
    }

    public function getRelationExistenceQuery(Relation $relation, Builder $parentBuilder, Closure $callback)
    {
        // TODO: Implement getRelationExistenceQuery() method.
    }
}
