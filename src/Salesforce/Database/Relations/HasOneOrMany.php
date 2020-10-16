<?php

namespace Stratease\Salesforcery\Salesforce\Database\Relations;

use Stratease\Salesforcery\Salesforce\Database\Builder;
use Stratease\Salesforcery\Salesforce\Database\Collection;
use Stratease\Salesforcery\Salesforce\Database\Model;

abstract class HasOneOrMany extends Relation
{

    /**
     * @var string $foreignKey
     */
    protected $foreignKey;

    /**
     * @var string $localKey
     */
    protected $localKey;

    public function __construct(Builder $builder, Model $parent, string $foreignKey, string $localKey)
    {
        $this->foreignKey = $foreignKey;
        $this->localKey = $localKey;

        parent::__construct($builder, $parent);
    }

    public function addEagerConstraints(array $models): void
    {
        $this->builder->whereIn(
            $this->foreignKey, $this->getKeys($models, $this->localKey)
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

    public function getRelationExistenceQuery(Relation $relation, Builder $parentBuilder, \Closure $callback = null)
    {
        $query = $relation->getRelated()->newQuery();
        $query->select($this->foreignKey);

        if($callback) {
            $callback($query);
        }

        $parentBuilder->query->whereIn($this->localKey, $query);
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

    public function getForeignKey()
    {
        return $this->foreignKey;
    }

    public function getLocalKey()
    {
        return $this->localKey;
    }
}
