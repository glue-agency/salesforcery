<?php

namespace Stratease\Salesforcery\Salesforce\Query;

use Carbon\Carbon;
use Closure;
use DateTime;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Stratease\Salesforcery\Salesforce\Connection\REST\Client as Connection;
use Stratease\Salesforcery\Salesforce\Database\Relations\Relation;
use Stratease\Salesforcery\Salesforce\Query\Grammar\Grammar;

class Builder
{

    /**
     * The database connection instance.
     *
     * @var Connection
     */
    public $connection;

    /**
     * The database query grammar instance.
     *
     * @var Grammar
     */
    public $grammar;

    /**
     * The fields which the query is targeting.
     *
     * @var array
     */
    public $fields;

    /**
     * Parsed where statements, ready for normalization to query language
     *
     * @var array
     */
    public $wheres = [];

    /**
     * The Ordering for the query.
     *
     * @var array
     */
    public $orders;

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
     * The object which the query is targeting.
     *
     * @var string
     */
    public $from;

    public function __construct(Connection $connection, Grammar $grammar = null)
    {
        $this->connection = $connection;
        $this->grammar = $grammar ?: $connection->getQueryGrammar();
    }

    /**
     * Set the fields to be selected.
     *
     * @param array $fields
     *
     * @return Builder
     */
    public function select($fields = null): self
    {
        $this->fields = is_array($fields) ? $fields : func_get_args();

        return $this;
    }

    /**
     * Add a new select column to the query.
     *
     * @param array|mixed $field
     *
     * @return Builder
     */
    public function addSelect($field)
    {
        $fields = is_array($field) ? $field : func_get_args();

        foreach($fields as $field) {
            if($this->isQueryable($field)) {
                $this->fields[] = $this->selectSub($field);
            } else {
                $this->fields[] = $field;
            }
        }

        return $this;
    }

    /**
     * Add a subselect expression to the query.
     *
     * @param Closure $query
     *
     * @return string
     */
    protected function selectSub($query)
    {
        return "({$this->createSub($query)})";
    }

    /**
     * @param      $field
     * @param null $operator
     * @param null $value
     *
     * @return Builder
     */
    public function where($field, $operator = null, $value = null): self
    {
        if(is_array($field)) {
            return $this->addArrayOfWheres($field);
        }

        // assumed = operator for 2 args
        if(func_num_args() == 2) {
            [$operator, $value] = ['=', $operator];
        }

        if ($this->isQueryable($field) && is_null($operator)) {
            return $this->whereNested($field);
        }

        if($value instanceof Carbon || $value instanceof DateTime) {
            return $this->whereDate($field, $operator, $value);
        }

        if(is_bool($value)) {
            return $this->whereBoolean($field, $operator, $value);
        }

        // Preform a sub-select
        if($this->isQueryable($value)) {
            return $this->whereSub($field, $operator, $value);
        }

        if(is_null($value)) {
            return $this->whereNull($field, $operator !== '=');
        }

        $type = 'Basic';

        $this->wheres[] = compact('type', 'field', 'operator', 'value');

        return $this;
    }

    /**
     * Add an array of where clauses to the query.
     *
     * @param array $wheres
     *
     * @return Builder
     */
    protected function addArrayOfWheres($wheres): self
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
     * @param string $field
     * @param array  $values
     * @param bool   $not
     *
     * @return Builder
     */
    public function whereIn($field, $values, $not = false)
    {
        $type = $not ? 'NotIn' : 'In';

        if($this->isQueryable($values)) {
            $values = $this->createSub($values);
            $type = "{$type}Sub";
        }

        if($values instanceof Arrayable) {
            $values = $values->toArray();
        }

        $this->wheres[] = compact('type', 'field', 'values');

        return $this;
    }

    /**
     * Add a "where not in" clause to the query.
     *
     * @param string $field
     * @param mixed  $values
     *
     * @return Builder
     */
    public function whereNotIn($field, $values)
    {
        return $this->whereIn($field, $values, true);
    }

    /**
     * Add an "order by" clause to the query.
     *
     * @param string $field
     * @param string $direction
     *
     * @return Builder
     */
    public function orderBy($field, $direction = 'asc')
    {
        $this->orders[] = [
            'field'     => $field,
            'direction' => strtolower($direction) == 'asc' ? 'ASC' : 'DESC',
        ];

        return $this;
    }

    /**
     * Add a "where date" statement to the query.
     *
     * @param string $field
     * @param string $operator
     * @param mixed  $value
     *
     * @return  \Stratease\Salesforcery\Salesforce\Database\Builder|static
     */
    public function whereDate($field, $operator, $value = null)
    {
        $type = 'Date';
        $date = $this->parseDate($value);

        $this->wheres[] = compact('type', 'field', 'operator', 'date');

        return $this;
    }

    /**
     * Add a nested where statement to the query.
     *
     * @param  \Closure  $callback
     * @return Builder
     */
    public function whereNested(Closure $callback)
    {
        call_user_func($callback, $query = $this->newQuery()->select($this->fields)->from($this->from));

        return $this->addNestedWhereQuery($query);
    }

    /**
     * Add a boolean where statement to the query.
     *
     * @param string $field
     * @param string $operator
     * @param bool   $value
     *
     * @return Builder
     */
    public function whereBoolean($field, $operator, bool $value)
    {
        $type = 'Boolean';
        $this->wheres[] = compact('type', 'field', 'operator', 'value');

        return $this;
    }

    /**
     * Add a full sub-select to the query.
     *
     * @param string  $field
     * @param string  $operator
     * @param Closure $callback
     *
     * @return Builder
     */
    protected function whereSub($field, $operator, Closure $callback)
    {
        $type = 'Sub';

        // Once we have the query instance we can simply execute it so it can add all
        // of the sub-select's conditions to itself, and then we can cache it off
        // in the array of where clauses for the "main" parent query instance.
        call_user_func($callback, $query = $this->newQuery());

        $this->wheres[] = compact(
            'type', 'field', 'operator', 'query'
        );

        return $this;
    }

    /**
     * Add another query builder as a nested where to the query builder.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function addNestedWhereQuery($query)
    {
        if (count($query->wheres)) {
            $type = 'Nested';

            $this->wheres[] = compact('type', 'query');
        }

        return $this;
    }

    /**
     * Creates a subquery and parse it.
     *
     * @param Closure|Builder $query
     *
     * @return string
     */
    protected function createSub($query)
    {
        if($query instanceof Closure) {
            $callback = $query;

            $callback($query = $this->newQuery());
        }

        return $this->parseSub($query);
    }

    /**
     * Parse the subquery into SQL.
     *
     * @param  mixed  $query
     * @return string
     *
     * @throws \InvalidArgumentException
     */
    protected function parseSub($query)
    {
        if ($query instanceof self || $query instanceof \Stratease\Salesforcery\Salesforce\Database\Builder || $query instanceof Relation) {
            return $query->toSql();
        } elseif (is_string($query)) {
            return $query;
        } else {
            throw new InvalidArgumentException(
                'A subquery must be a query builder instance, a Closure, or a string.'
            );
        }
    }

    /**
     * Parse a date string or obejct into a Carbon object.
     *
     * @param  mixed  date
     *
     * @return Carbon
     */
    protected function parseDate($date): Carbon
    {
        if($date instanceof Carbon) {
            return $date;
        }

        if( $date instanceof DateTime || is_string($date)) {
            return Carbon::parse($date);
        }

        throw new InvalidArgumentException('A datequery must be a DateTime instance, Carbon instance, or a string.');
    }

    /**
     * Add a "where null" clause to the query.
     *
     * @param string $field
     * @param bool   $not
     *
     * @return Builder
     */
    public function whereNull($field, $not = false)
    {
        $type = $not ? 'NotNull' : 'Null';
        $this->wheres[] = compact('type', 'field');

        return $this;
    }

    /**
     * Alias to set the "limit" value of the query.
     *
     * @param int $value
     *
     * @return Builder
     */
    public function take($value): self
    {
        return $this->limit($value);
    }

    /**
     * Set the "limit" value of the query.
     *
     * @param $limit
     *
     * @return Builder
     */
    public function limit($limit): self
    {
        $this->limit = $limit;

        return $this;
    }

    /**
     * Alias to set the "offset" value of the query.
     *
     * @param int $value
     *
     * @return Builder
     */
    public function skip($value): self
    {
        return $this->offset($value);
    }

    /**
     * Set the "offset" value of the query.
     *
     * @param int $value
     *
     * @return Builder
     */
    public function offset($value): self
    {
        $this->offset = max(0, $value);

        return $this;
    }

    /**
     * Set the object which the query should target.
     *
     * @param $table
     *
     * @return Builder
     */
    public function from($table): self
    {
        $this->from = $table;

        return $this;
    }

    public function count(): int
    {
        $this->select('COUNT()');
        $results = $this->runSelect();

        return (int) $results['totalSize'] ?? 0;
    }

    public function runSelect($withTrashed = false)
    {
        $method = $withTrashed ? 'queryAll' : 'query';

        return $this->connection->{$method}(
            $this->toSql()
        );
    }

    /**
     * Get a new instance of the query builder.
     *
     * @return Builder
     */
    public function newQuery()
    {
        return new static($this->connection, $this->grammar);
    }

    /**
     * Determine if the value is a query builder instance or a Closure.
     *
     * @param  mixed  $value
     *
     * @return bool
     */
    protected function isQueryable($value)
    {
        return $value instanceof \Stratease\Salesforcery\Salesforce\Query\Builder ||
            $value instanceof \Stratease\Salesforcery\Salesforce\Database\Builder ||
            $value instanceof Closure;
    }

    /**
     * Get the SQL representation of the query
     *
     * @return string
     */
    public function toSql(): string
    {
        $sql = $this->grammar->compileSelect($this);
        Log::channel('query')->debug($sql);

        return $sql;
    }
}
