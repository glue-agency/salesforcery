<?php

namespace Stratease\Salesforcery\Salesforce\Database\Relations;

use Stratease\Salesforcery\Salesforce\Database\Collection;

abstract class HasOneOrMany extends Relation
{

    public function addEagerConstraints(array $models): void
    {
        $this->query->whereIn(
            $this->foreignKey, $this->getKeys($models, $this->parent->primaryKey)
        );
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

    protected function buildDictionary(Collection $results)
    {
        $dictionary = [];

        foreach($results as $result) {

            $key = $result->{$this->foreignKey};

            if(! isset($dictionary[$key])) {
                $dictionary[$key] = [];
            }

            $dictionary[$key][] = $result;
        }

        return new Collection($dictionary);
    }

    protected function getRelationValue($dictionary, $key)
    {
        $value = $dictionary[$key];

        return new Collection($value);
    }
}
