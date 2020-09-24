<?php

namespace Stratease\Salesforcery\Salesforce\Query;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Log;
use Stratease\Salesforcery\Salesforce\Connection\REST\Client as Connection;
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
     * The columns which the query is targeting.
     *
     * @var array
     */
    public $columns;

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
     * Set the columns to be selected.
     *
     * @param array|mixed $columns
     *
     * @return Builder
     */
    public function select($columns = ['*']): self
    {
        $this->columns = is_array($columns) ? $columns : func_get_args();

        return $this;
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
     * @param string $column
     * @param array  $values
     * @param bool   $not
     *
     * @return Builder
     */
    public function whereIn($column, $values, $not = false)
    {
        $type = $not ? 'NotIn' : 'In';

        if ($values instanceof Arrayable) {
            $values = $values->toArray();
        }

        $this->wheres[] = compact('type', 'column', 'values');

        return $this;
    }

    /**
     * Add a "where not in" clause to the query.
     *
     * @param string $column
     * @param mixed  $values
     *
     * @return Builder
     */
    public function whereNotIn($column, $values)
    {
        return $this->whereIn($column, $values, true);
    }

    /**
     * Add an "order by" clause to the query.
     *
     * @param string $column
     * @param string $direction
     *
     * @return Builder
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
     * @return  \Stratease\Salesforcery\Salesforce\Database\Builder|static
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
     * @return Builder
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
     * @return Builder
     */
    public function whereNull($column, $not = false)
    {
        $type = $not ? 'NotNull' : 'Null';
        $this->wheres[] = compact('type', 'column');

        return $this;
    }

    /**
     * Add a new select column to the query.
     *
     * @param array|mixed $column
     *
     * @return Builder
     */
    public function addSelect($column)
    {
        $column = is_array($column) ? $column : func_get_args();
        $this->columns = array_merge((array) $this->columns, $column);

        return $this;
    }

    /**
     * Set the "limit" value of the query.
     *
     * @param $limit
     *
     * @return $this
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
    public function offset($value)
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

    public function runSelect($withTrashed = false)
    {
        $method = $withTrashed ? 'queryAll' : 'query';

        return $this->connection->{$method}(
            $this->toSql()
        );
    }

    /**
     * Get the SQL representation of the query
     *
     * @return string
     */
    public function toSql(): string
    {
        return $this->grammar->compileSelect($this);
    }
}
