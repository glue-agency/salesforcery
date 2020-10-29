<?php

namespace Stratease\Salesforcery\Salesforce\Database\Relations;

use Closure;
use Stratease\Salesforcery\Salesforce\Database\Builder;
use Illuminate\Support\Collection;
use Stratease\Salesforcery\Salesforce\Database\Model;

class BelongsToMany extends Relation
{

    /**
     * The intermediate model for the relation.
     *
     * @var string
     */
    protected $intermediate;

    /**
     * The foreign key of the parent model.
     *
     * @var string
     */
    protected $foreignPivotKey;

    /**
     * The associated key of the relation.
     *
     * @var string
     */
    protected $relatedPivotKey;

    /**
     * The key name of the parent model.
     *
     * @var string
     */
    protected $parentKey;

    /**
     * The key name of the related model.
     *
     * @var string
     */
    protected $relatedKey;

    /**
     * The "name" of the relationship.
     *
     * @var string
     */
    protected $relationName;

    /**
     * The pivot data for the relationship.
     *
     * @var array
     */
    protected $pivot = [];

    /**
     * Create a new belongs to many relationship instance.
     *
     * @param Builder     $builder
     * @param Model       $parent
     * @param string      $intermediate
     * @param string      $foreignPivotKey
     * @param string      $relatedPivotKey
     * @param string      $parentKey
     * @param string      $relatedKey
     * @param string|null $relationName
     *
     * @return void
     */
    public function __construct(Builder $builder, Model $parent, $intermediate, $foreignPivotKey,
                                $relatedPivotKey, $parentKey, $relatedKey, $relationName = null)
    {
        $this->intermediate = $intermediate;
        $this->foreignPivotKey = $foreignPivotKey;
        $this->relatedPivotKey = $relatedPivotKey;
        $this->parentKey = $parentKey;
        $this->relatedKey = $relatedKey;
        $this->relationName = $relationName;

        parent::__construct($builder, $parent);
    }

    public function getResults()
    {
        return $this->builder->get();
    }

    public function addConstraints(): void
    {
        // @todo find an implementation that does not require an extra query
        // $this->loadPivot([$this->parent->{$this->parentKey}]);

        $this->builder->whereIn($this->parentKey, $this->getPivotKeys($this->relatedPivotKey));
    }

    public function addEagerConstraints(array $models): void
    {
        $this->loadPivot($this->getKeys($models, $this->parentKey));

        $this->builder->whereIn($this->parentKey, $this->getPivotKeys($this->relatedPivotKey));
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
            if(isset($dictionary[$key = $model->{$this->parentKey}])) {
                $model->setRelation(
                    $name,
                    $this->getRelationValue($dictionary, $key),
                );
            }
        }

        return $models;
    }

    protected function buildDictionary(Collection $results)
    {
        $dictionary = [];

        // @todo improve this dictionary build
        foreach($results as $result) {
            foreach($this->pivot as $pivot) {
                if($result->{$this->relatedKey} == $pivot[$this->relatedPivotKey]) {
                    $dictionary[$pivot[$this->foreignPivotKey]][] = $result;
                }
            }
        }

        return $dictionary;
    }

    protected function loadPivot(array $keys)
    {
        $builder = $this->builder->newBuilder();
        $builder->select($this->relatedPivotKey, $this->foreignPivotKey)
            ->from($this->intermediate)
            ->whereIn($this->foreignPivotKey, $keys);

        $this->pivot = $builder->getRaw();
    }

    protected function getPivotKeys($key)
    {
        $keys = [];

        foreach($this->pivot as $pivot) {
            $keys[] = $pivot[$key];
        }

        $keys = array_unique($keys);

        return $keys;
    }

    protected function getRelationValue($dictionary, $key)
    {
        $value = $dictionary[$key];

        return new Collection($value);
    }
}
