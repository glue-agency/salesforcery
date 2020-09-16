<?php

namespace Stratease\Salesforcery\Salesforce\Database\Relations;

use Stratease\Salesforcery\Salesforce\Database\Collection;

class HasOne extends HasOneOrMany
{

    // !! THIS IS STILL A WIP !!

    public function getResults()
    {
        if(is_null($this->parent->primaryKey)) {
            return;
        }

        return $this->query->first();
    }

    public function addConstraints(): void
    {
        $this->query->where($this->foreignKey, $this->parent->{$this->parent->primaryKey});
    }

    public function getEagerResults()
    {
        return $this->query->get();
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
            if(isset($dictionary[$key = $model->{$this->parent->primaryKey}])) {
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
}
