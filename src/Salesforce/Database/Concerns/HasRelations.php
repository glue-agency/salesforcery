<?php

namespace Stratease\Salesforcery\Salesforce\Database\Concerns;


use LogicException;
use Stratease\Salesforcery\Salesforce\Database\Model;
use Stratease\Salesforcery\Salesforce\Database\QueryBuilder;
use Stratease\Salesforcery\Salesforce\Database\Relations\BelongsTo;
use Stratease\Salesforcery\Salesforce\Database\Relations\HasMany;
use Stratease\Salesforcery\Salesforce\Database\Relations\HasOne;
use Stratease\Salesforcery\Salesforce\Database\Relations\Relation;

trait HasRelations
{

    protected $relations = [];

    public function hasOne($related, $foreignKey = null)
    {
        $instance = $this->newRelatedInstance($related);

        $foreignKey = $foreignKey ?: $this->resolveObjectName();

        return $this->newHasOne(
            $instance->newQuery(), $this, $foreignKey
        );
    }

    public function belongsTo($related, $foreignKey = null, $ownerKey = null)
    {
        $instance = $this->newRelatedInstance($related);

        $foreignKey = $foreignKey ?: $instance->resolveObjectName();

        $ownerKey = $ownerKey ?: $instance->primaryKey;

        return $this->newBelongsTo(
            $instance->newQuery(), $this, $foreignKey, $ownerKey
        );
    }

    public function hasMany($related, $foreignKey = null)
    {
        $instance = $this->newRelatedInstance($related);

        $foreignKey = $foreignKey ?: $this->resolveObjectName();

        return $this->newHasMany(
            $instance->newQuery(), $this, $foreignKey
        );
    }

    protected function newHasONe(QueryBuilder $query, Model $parent, $foreignKey)
    {
        return new HasOne($query, $parent, $foreignKey);
    }

    protected function newBelongsTo(QueryBuilder $query, Model $child, $foreignKey, $ownerKey)
    {
        return new BelongsTo($query, $child, $foreignKey, $ownerKey);
    }

    protected function newHasMany(QueryBuilder $query, Model $parent, $foreignKey)
    {
        return new HasMany($query, $parent, $foreignKey);
    }

    protected function newRelatedInstance($class)
    {
        return tap(new $class, function($instance) {
            if(! self::$connection) {
                $instance->registerConnection($this->connection);
            }
        });
    }

    public function getRelationValue($relation)
    {
        if($this->isRelationLoaded($relation)) {
            return $this->relations[$relation];
        }

        return $this->getRelationshipFromMethod($relation);
    }

    public function isRelationLoaded($relation): bool
    {
        return array_key_exists($relation, $this->relations);
    }

    public function getRelationshipFromMethod($method)
    {
        $relation = $this->$method();

        if(! $relation instanceof Relation) {
            throw new LogicException(sprintf(
                '%s::%s must return a relationship instance.', static::class, $method
            ));
        }

        return tap($relation->getResults(), function($results) use ($method) {
            $this->setRelation($method, $results);
        });
    }

    public function setRelation($name, $value): self
    {
        $this->relations[$name] = $value;

        return $this;
    }
}
