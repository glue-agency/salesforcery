<?php
/**
 * Created by PhpStorm.
 * User: edwindaniels
 * Date: 3/20/18
 * Time: 8:49 AM
 */

namespace Stratease\Salesforcery\Salesforce\Database;

use Illuminate\Database\Eloquent\RelationNotFoundException;
use Illuminate\Support\Str;
use Stratease\Salesforcery\Salesforce\Connection\REST\Client;
use Stratease\Salesforcery\Salesforce\Database\Relations\Relation;

class QueryBuilder
{

    /**
     * @var Client
     */
    public $connection;

    /**
     * Parsed where statements, ready for normalization to query language
     *
     * @var array
     */
    public $wheres = [];

    /**
     * Table we are querying
     *
     * @var string
     */
    public $from = '';

    /**
     * Ordering for query
     *
     * @var array
     */
    public $orders = [];

    /**
     * The maximum number of records to return.
     *
     * @var int
     */
    public $limit;

    /**
     * The number of records to skip.
     *
     * @var int
     */
    public $offset;

    /**
     * @var Model
     */
    public $model;

    /**
     * The columns that should be returned.
     *
     * @var array
     */
    public $columns = [];

    /**
     * The relations that should be eager loaded.
     *
     * @var array
     */
    protected $eagerLoad = [];

    /**
     * QueryBuilder constructor.
     *
     * @param Client $connection
     */
    public function __construct(Client $connection)
    {
        $this->connection = $connection;
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
     * @param      $field
     * @param null $operator
     * @param null $value
     *
     * @return $this
     */
    public function where($field, $operator = null, $value = null)
    {
        if(is_array($field)) {
            return $this->addArrayOfWheres($field);
        }

        // assumed = operator for 2 args
        if(func_num_args() == 2) {
            [$operator, $value] = ['=', $operator];
        }

        $this->wheres[] = [$field, $operator, $value];

        return $this;
    }

    /**
     * Add an array of where clauses to the query.
     *
     * @param array $wheres
     *
     * @return $this
     */
    protected function addArrayOfWheres($wheres)
    {

        foreach($wheres as $key => $value) {
            if(is_numeric($key) && is_array($value)) {
                $this->where(...array_values($value));
            } else {
                $this->where($key, '=', $value);
            }
        }

        return $this;
    }

    /**
     * Add a "where in" clause to the query.
     *
     * @param string $column
     * @param array  $values
     * @param bool   $not
     *
     * @return $this
     */
    public function whereIn($column, $values, $not = false)
    {
        $type = $not ? 'NotIn' : 'In';

        return $this->where($column, $type, $values);
    }

    /**
     * Alias to set the "offset" value of the query.
     *
     * @param int $value
     *
     * @return QueryBuilder
     */
    public function skip($value)
    {
        return $this->offset($value);
    }

    /**
     * Set the "offset" value of the query.
     *
     * @param int $value
     *
     * @return $this
     */
    public function offset($value)
    {
        $this->offset = max(0, $value);

        return $this;
    }

    /**
     * Set the "limit" value of the query.
     *
     * @param int $value
     *
     * @return $this
     */
    public function limit($value)
    {
        $this->limit = (int) $value;

        return $this;
    }

    /**
     * Get the SQL representation of the query.
     *
     * @return string
     */
    public function toSql()
    {
        return $this->compileSelect();
    }

    /**
     * @return string
     */
    protected function compileSelect()
    {

        // select
        $columns = implode(', ', $this->columns);

        // from
        $from = $this->from;

        // where
        $sqlWheres = [];
        $where = '';
        foreach($this->wheres as $where) {
            $sqlWheres[] = $where[0] . ' ' . $this->operatorAndValueToSql($where[0], $where[1], $where[2]);
        }
        if($sqlWheres) {
            $where = 'WHERE ' . implode(' AND ', $sqlWheres); // @todo assume AND for now...
        }

        // limit
        $limit = '';
        if($this->limit) {
            $limit = "LIMIT " . (int) $this->limit;
        }

        return trim(sprintf(
            "SELECT %s FROM %s %s %s",
            $columns,
            $from,
            $where,
            $limit
        ));
    }

    /**
     * Where the magic happens.
     *
     * @param $column
     * @param $operator
     * @param $value
     *
     * @return string
     */
    protected function operatorAndValueToSql($column, $operator, $value)
    {

        $model = $this->model;

        $schema = $model::getSchema();

        switch($schema[$column]['type']) {
            case 'boolean':
                return $operator . " " . (($value) ? 'TRUE' : 'FALSE');
            case 'datetime':
                return $operator . " " . date('c', strtotime($value));
            case 'date':
                return $operator . " " . date('Y-m-d', strtotime($value));
        }

        if(is_array($value)) {
            $sql = $operator . " (";

            foreach($value as $item) {
                $sql .= " '" . addslashes($item) . "',";
            }

            $sql = rtrim($sql, ',');
            $sql .= ")";

            return $sql;
        }

        return $operator . " '" . addslashes($value) . "'";
    }

    /**
     * Execute the query as a "select" statement with limit 1 and hydrate the model.
     *
     * @return Model
     */
    public function first(): Model
    {
        $this->limit = 1;

        $data = $this->get();

        if($data->offsetExists(0)) {
            return $data[0];
        }

        return $this->model::hydrateFactory([]);
    }

    /**
     * Execute the query as a "select" statement and hydrate models.
     *
     * @return Collection
     */
    public function get()
    {
        $model = $this->model;

        $results =
            array_map(function($result) use ($model) {
                return $model::hydrateFactory($result);
            }, $this->runSelect());

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
        foreach($this->wheres as $where) {
            switch($where[0]) {
                case 'IsArchived':
                case 'IsDeleted':
                    return true;
            }
        }

        return false;
    }

    /**
     * Run the query as a "select" statement against the connection.
     *
     * @return array
     */
    protected function runSelect()
    {
        if($this->isQueryAll()) {
            $response = $this->connection->queryAll(
                $this->toSql()
            );
        } else {
            $response = $this->connection->query(
                $this->toSql()
            );
        }

        $records = $response['records'];

        // iterate to get all results
        while(! $response['done']) {
            $response = $this->connection->request('GET', $this->connection->authentication->getInstanceUrl() . $response['nextRecordsUrl']);
            $response = json_decode($response->getBody(), true);
            $records = array_merge($records, $response['records']);
        }

        return $records;
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
        return $this->connection;
    }

    /**
     * Get a new instance of the query builder.
     *
     * @return QueryBuilder
     */
    public function newQuery()
    {
        return new static($this->connection);
    }

    /**
     * Add an "order by" clause to the query.
     *
     * @param string $column
     * @param string $direction
     *
     * @return $this
     */
    public function orderBy($column, $direction = 'asc')
    {
        $this->{$this->unions ? 'unionOrders' : 'orders'}[] = [
            'column'    => $column,
            'direction' => strtolower($direction) == 'asc' ? 'asc' : 'desc',
        ];

        return $this;
    }

    /**
     * Add a "where date" statement to the query.
     *
     * @param string $column
     * @param string $operator
     * @param mixed  $value
     * @param string $boolean
     *
     * @return  QueryBuilder|static
     */
    public function whereDate($column, $operator, $value = null, $boolean = 'and')
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value, $operator, func_num_args() == 2
        );

        return $this->addDateBasedWhere('Date', $column, $operator, $value, $boolean);
    }

    /**
     * Add a where between statement to the query.
     *
     * @param string $column
     * @param array  $values
     * @param string $boolean
     * @param bool   $not
     *
     * @return $this
     */
    public function whereBetween($column, array $values, $boolean = 'and', $not = false)
    {
        $type = 'between';
        $this->wheres[] = compact('column', 'type', 'boolean', 'not');
        $this->addBinding($values, 'where');

        return $this;
    }

    /**
     * Add a "where null" clause to the query.
     *
     * @param string $column
     * @param bool   $not
     *
     * @return $this
     */
    public function whereNull($column, $not = false)
    {
        $type = $not ? 'NotNull' : 'Null';
        $this->wheres[] = [$column, '=', $type];

        return $this;
    }

    /**
     * Set the columns to be selected.
     *
     * @param array|mixed $columns
     *
     * @return $this
     */
    public function select($columns = ['*'])
    {
        $this->columns = is_array($columns) ? $columns : func_get_args();

        return $this;
    }

    /**
     * Add a new select column to the query.
     *
     * @param array|mixed $column
     *
     * @return $this
     */
    public function addSelect($column)
    {
        $column = is_array($column) ? $column : func_get_args();
        $this->columns = array_merge((array) $this->columns, $column);

        return $this;
    }

    /**
     * Set the table which the query is targeting.
     *
     * @param string $table
     *
     * @return $this
     */
    public function from($table)
    {
        $this->from = $table;

        return $this;
    }
}
