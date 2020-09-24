<?php
/**
 * Created by PhpStorm.
 * User: edwindaniels
 * Date: 3/20/18
 * Time: 8:49 AM
 */

namespace Stratease\Salesforcery\Salesforce\Database;

use Illuminate\Database\Eloquent\Concerns\QueriesRelationships;
use Illuminate\Database\Eloquent\RelationNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\ForwardsCalls;
use Stratease\Salesforcery\Salesforce\Connection\REST\Client;
use Stratease\Salesforcery\Salesforce\Database\Relations\Relation;
use Stratease\Salesforcery\Salesforce\Query\Builder as QueryBuilder;

class Builder
{

    use QueriesRelationships, ForwardsCalls;

    /**
     * @var QueryBuilder
     */
    public $query;

    /**
     * @var Model
     */
    public $model;

    /**
     * The methods that should be passed trough to the
     * QueryBuilder instance.
     *
     * @var string[]
     */
    protected $passthrough = [
        'toSql',
    ];

    /**
     * The relations that should be eager loaded.
     *
     * @var array
     */
    protected $eagerLoad = [];

    /**
     * QueryBuilder constructor.
     *
     * @param \Stratease\Salesforcery\Salesforce\Query\Builder $query
     *
     * @return void
     */
    public function __construct(QueryBuilder $query)
    {
        $this->query = $query;
    }

    /**
     * @param Model $model
     *
     * @return $this
     */
    public function setModel(Model $model): self
    {
        $this->model = $model;

        return $this;
    }

    /**
     * @param $relations
     *
     * @return $this
     */
    public function with($relations): self
    {
        $this->eagerLoad = array_merge($this->eagerLoad, $relations);

        return $this;
    }

    /**
     * Execute the query as a "select" statement with limit 1 and hydrate the model.
     *
     * @return Model
     */
    public function first(): Model
    {
        $this->limit(1);
        $data = $this->get();

        if($data->offsetExists(0)) {
            return $data[0];
        }

        // @todo throw not found exception
        return $this->model::hydrateFactory([]);
    }

    /**
     * Execute the query as a "select" statement and hydrate models.
     *
     * @return Collection
     */
    public function get()
    {
        $response = $this->query->runSelect();

        $records = $response['records'];

        while (!$response['done']) {
            $response = $this->query->connection->request(
                'GET',
                $this->query->connection->authentication->getInstanceUrl() . $response['nextRecordsUrl']
            );
            $response = json_decode($response->getBody(), true);
            $records  = array_merge($records, $response['records']);
        }

        $results = array_map(function($result) {
            return $this->model::hydrateFactory($result);
        }, $records);

        if(count($results) > 0) {
            $results = $this->eagerLoadRelations($results);
        }

        return new Collection($results);
    }

    /**
     * Verify which of the different endpoints for Salesforce REST API
     *
     * @return bool
     */
    public function isQueryAll()
    {
        foreach($this->query->wheres as $where) {
            switch($where[0]) {
                case 'IsArchived':
                case 'IsDeleted':
                    return true;
            }
        }

        return false;
    }

    /**
     * @param          $batchSize
     * @param callable $closure
     *
     * @return bool
     * @todo $batchSize - not sure how to get this to work with the REST API. Specifying LIMIT will stop further
     *       pagination, and the batch header didn't seem to work.... leaving the param here for now
     */
    public function chunk($batchSize, callable $closure)
    {
        $model = $this->model;

        if($this->isQueryAll()) {
            $response = $this->connection->queryAll(
                $this->toSql()
            );
        } else {
            $response = $this->connection->query(
                $this->toSql()
            );
        }

        // first batch...
        $records = $response['records'];
        $results =
            array_map(function($result) use ($model) {
                return $model::hydrateFactory($result);
            }, $records);
        $closure(new Collection($results));

        // iterate to get any remaining batches
        while(! empty($response['nextRecordsUrl'])) {
            $response = $this->connection->request('GET', $this->connection->authentication->getInstanceUrl() . $response['nextRecordsUrl']);
            $response = json_decode($response->getBody(), true);
            $results =
                array_map(function($result) use ($model) {
                    return $model::hydrateFactory($result);
                }, $response['records']);

            $closure(new Collection($results));
        }

        return true;
    }

    /**
     * Eager loads relations on the models.
     *
     * @param array $models
     *
     * @return array
     */
    public function eagerLoadRelations(array $models): array
    {
        foreach($this->eagerLoad as $name) {
            if(strpos($name, '.') === false) {
                $models = $this->eagerLoadRelation($models, $name);
            }
        }

        return $models;
    }

    /**
     * Eager load a single relation on the models.
     *
     * @param array $models
     * @param       $name
     *
     * @return array
     */
    public function eagerLoadRelation(array $models, $name): array
    {
        $relation = $this->getRelation($name);

        $relation->addEagerConstraints($models);

        return $relation->match(
            $relation->initRelation($models, $name),
            $relation->getEagerResults(),
            $name,
        );
    }

    /**
     * Get a relation object by name.
     *
     * @param $name
     *
     * @return Relation
     */
    public function getRelation($name): Relation
    {
        try {
            $relation = $this->model->newInstance()->$name();
            $relation->withoutDefaultConstraint();

            $nested = $this->relationsNestedUnder($name);
            if(count($nested) > 0) {
                $relation->getQueryBuilder()->with($nested);
            }

            return $relation;
        } catch(\BadMethodCallException $e) {
            throw RelationNotFoundException::make($this->model, $name);
        }
    }

    /**
     * Get the deeply nested relations for a given top-level relation.
     *
     * @param $relation
     *
     * @return array
     */
    protected function relationsNestedUnder($relation): array
    {
        $nested = [];

        foreach($this->eagerLoad as $name) {
            if($this->isNestedUnder($relation, $name)) {
                $nested[] = Str::after($name, "{$relation}.");
            }
        }

        return $nested;
    }

    /**
     * Determine if the relationship is nested.
     *
     * @param $relation
     * @param $name
     *
     * @return bool
     */
    protected function isNestedUnder($relation, $name): bool
    {
        return Str::contains($name, '.') && Str::startsWith($name, "{$relation}.");
    }

    /**
     * Get the database connection instance.
     *
     * @return Client
     */
    public function getConnection()
    {
        return $this->query->connection;
    }

    /**
     * Get a new instance of the query builder.
     *
     * @return Builder
     */
    public function newQuery()
    {
        return new static($this->query);
    }

    /**
     * Dynamically forwards calls to the Query Builder instance.
     *
     * @param $name
     * @param $arguments
     *
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        if(in_array($name, $this->passthrough)) {
            return $this->query->{$name}(...$arguments);
        }

        $this->forwardCallTo($this->query, $name, $arguments);

        return $this;
    }
}
