<?php

namespace Stratease\Salesforcery\Salesforce\Database\Relations;

use Stratease\Salesforcery\Salesforce\Database\Collection;

class HasMany extends HasOneOrMany
{

    public function getResults()
    {
        return $this->query->get();
    }

    public function addConstraints(): void
    {
        $this->query->where($this->foreignKey, $this->parent->{$this->parent->primaryKey});
    }

    public function initRelation(array $models, $relation)
    {
        foreach($models as $model) {
            $model->setRelation($relation, new Collection());
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
}
