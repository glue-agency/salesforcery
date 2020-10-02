<?php

namespace Stratease\Salesforcery\Salesforce\Database\Concerns;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\ForwardsCalls;
use LogicException;
use Stratease\Salesforcery\Salesforce\Database\Builder;
use Stratease\Salesforcery\Salesforce\Database\Model;
use Stratease\Salesforcery\Salesforce\Database\Relations\BelongsTo;
use Stratease\Salesforcery\Salesforce\Database\Relations\BelongsToMany;
use Stratease\Salesforcery\Salesforce\Database\Relations\HasMany;
use Stratease\Salesforcery\Salesforce\Database\Relations\HasOne;
use Stratease\Salesforcery\Salesforce\Database\Relations\Relation;

trait HasRelations
{

    use ForwardsCalls;

    protected $relations = [];

    public static $manyMethods = [
        'belongsToMany', 'morphToMany', 'morphedByMany',
    ];


    public function hasOne($related, $foreignKey = null, $localKey = null)
    {
        $instance = $this->newRelatedInstance($related);

        $foreignKey = $foreignKey ?: $this->resolveObjectName();
        $localKey = $localKey ?: $this->primaryKey;

        return $this->newHasOne(
            $instance->newQuery(), $this, $foreignKey, $localKey
        );
    }

    protected function newHasONe(Builder $query, Model $parent, $foreignKey, $localKey)
    {
        return new HasOne($query, $parent, $foreignKey, $localKey);
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

    protected function newBelongsTo(Builder $query, Model $child, $foreignKey, $ownerKey)
    {
        return new BelongsTo($query, $child, $foreignKey, $ownerKey);
    }

    public function hasMany($related, $foreignKey = null, $localKey = null)
    {
        $instance = $this->newRelatedInstance($related);

        $foreignKey = $foreignKey ?: $this->resolveObjectName();
        $localKey = $localKey ?: $this->primaryKey;

        return $this->newHasMany(
            $instance->newQuery(), $this, $foreignKey, $localKey
        );
    }

    protected function newHasMany(Builder $query, Model $parent, $foreignKey, $localKey)
    {
        return new HasMany($query, $parent, $foreignKey, $localKey);
    }

    public function belongsToMany($related, $intermediate = null, $foreignPivotKey = null, $relatedPivotKey = null,
                                  $parentKey = null, $relatedKey = null, $relation = null)
    {
        // If no relationship name was passed, we will pull backtraces to get the
        // name of the calling function. We will use that function name as the
        // title of this relation since that is a great convention to apply.
        if(is_null($relation)) {
            $relation = $this->guessBelongsToManyRelation();
        }

        // First, we'll need to determine the foreign key and "other key" for the
        // relationship. Once we have determined the keys we'll make the query
        // instances as well as the relationship instances we need for this.
        $instance = $this->newRelatedInstance($related);
        $foreignPivotKey = $foreignPivotKey ?: $this->resolveObjectName();
        $relatedPivotKey = $relatedPivotKey ?: $instance->resolveObjectName();

        if(is_null($intermediate)) {
            $intermediate = $this->joiningObjectName($foreignPivotKey, $relatedPivotKey);
        }

        return $this->newBelongsToMany(
            $instance->newQuery(),
            $this,
            $intermediate,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey ?: $this->primaryKey,
            $relatedKey ?: $instance->primaryKey,
            $relation
        );
    }

    protected function newBelongsToMany(Builder $query, Model $parent, $intermediate, $foreignPivotKey, $relatedPivotKey,
                                        $parentKey, $relatedKey, $relationName = null)
    {
        return new BelongsToMany($query, $parent, $intermediate, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey, $relationName);
    }

    /**
     * Create a new model instance for a related model.
     *
     * @param  string  $class
     * @return mixed
     */
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

    /**
     * Get the relationship name of the belongsToMany relationship.
     *
     * @return string|null
     */
    protected function guessBelongsToManyRelation()
    {
        $caller = Arr::first(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), function ($trace) {
            return ! in_array(
                $trace['function'],
                array_merge(static::$manyMethods, ['guessBelongsToManyRelation'])
            );
        });

        return ! is_null($caller) ? $caller['function'] : null;
    }

    /**
     * Get the joining object name for a many-to-many relation.
     *
     * @param  string  $related
     * @param  \Illuminate\Database\Eloquent\Model|null  $instance
     * @return string
     */
    public function joiningObjectName($foreign, $related)
    {
        // The joining table name, by convention, is simply the snake cased object
        // names without the vpl__ prefix and __c suffix concatenated to a new
        // vpl__ prefix and __c suffix object name.
        $foreign = Str::between($foreign, 'vpl__', '__c');
        $related = Str::between($related, 'vpl__', '__c');

        return "vpl__{$foreign}_{$related}__c";
    }

    /**
     * Set the given relationship on the model.
     *
     * @param  string  $relation
     * @param  mixed  $value
     * @return $this
     */
    public function setRelation($name, $value): self
    {
        $this->relations[$name] = $value;

        return $this;
    }
}
