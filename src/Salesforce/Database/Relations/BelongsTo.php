<?php

namespace Stratease\Salesforcery\Salesforce\Database\Relations;

use Stratease\Salesforcery\Salesforce\Database\Collection;
use Stratease\Salesforcery\Salesforce\Database\Model;
use Stratease\Salesforcery\Salesforce\Database\Builder;

class BelongsTo extends Relation
{

    /**
     * @var Model
     */
    protected $child;

    /**
     * @var string
     */
    protected $ownerKey;

    public function __construct(Builder $query, Model $child, string $foreignKey, string $ownerKey)
    {
        $this->ownerKey = $ownerKey;

        // In the underlying base relationship class, this variable is referred to as
        // the "parent" since most relationships are not inverted. But, since this
        // one is we will create a "child" variable for much better readability.
        $this->child = $child;

        parent::__construct($query, $child, $foreignKey);
    }

    public function getResults()
    {
        if(is_null($this->child->{$this->foreignKey})) {
            return;
        }

        return $this->query->first();
    }

    public function addConstraints(): void
    {
        $this->query->where($this->ownerKey, '=', $this->child->{$this->foreignKey});
    }

    public function getEagerResults()
    {
        return $this->query->get();
    }

    public function addEagerConstraints(array $models): void
    {
        $this->query->whereIn(
            $this->ownerKey, $this->getKeys($models, $this->foreignKey)
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
}
