<?php

namespace Stratease\Salesforcery\Salesforce\Query\Grammar;

use Stratease\Salesforcery\Salesforce\Query\Builder;

class Grammar
{

    protected $selectComponents = [
        'fields',
        'from',
        'wheres',
        'orders',
        'limit',
        'offset',
    ];

    /**
     * @param Builder $query
     *
     * @return string
     */
    public function compileSelect(Builder $query): string
    {
        return trim($this->concatenate(
            $this->compileComponents($query))
        );
    }

    protected function compileFields(Builder $query, $fields): string
    {
        $field_string = implode(', ', $fields);

        return "SELECT {$field_string}";
    }

    protected function compileWheres(Builder $query): string
    {
        if(is_null($query->wheres)) {
            return '';
        }

        if(count($sql = $this->compileWheresToArray($query)) > 0) {
            return $this->concatenateWhereClauses($query, $sql);
        }

        return '';
    }

    protected function compileWheresToArray($query)
    {
        return collect($query->wheres)->map(function($where) use ($query) {
            return 'AND ' . $this->{"where{$where['type']}"}($query, $where);
        })->all();
    }

    protected function whereBasic(Builder $query, $where): string
    {
        return "{$where['field']} {$where['operator']} {$this->wrap($where['value'])}";
    }

    protected function whereBoolean(Builder $query, $where): string
    {
        $boolean = $where['value'] ? 'true' : 'false';

        return "{$where['field']} {$where['operator']} {$boolean}";
    }

    protected function whereDate(Builder $query, $where)
    {
        $format = 'Y-m-d';
        $date = $where['date']->format($format);

        return "{$where['field']} {$where['operator']} {$date}";
    }

    protected function whereTimestamp(Builder $query, $where)
    {
        $format = 'Y-m-d\TH:i:s.vO';
        $date = $where['date']->format($format);

        return "{$where['field']} {$where['operator']} {$date}";
    }

    protected function whereIn(Builder $query, $where): string
    {
        if(! empty($where['values'])) {
            $stringifiedValues = implode(', ', $this->wrap($where['values']));

            return "{$where['field']} {$where['type']} ({$stringifiedValues})";
        }

        // SOQL equivalent for 0 = 1
        return 'Id = null';
    }

    protected function whereNotIn(Builder $query, $where): string
    {
        return $this->whereIn($query, $where);
    }

    protected function whereInSub(Builder $query, $where): string
    {
        return "{$where['field']} IN ({$where['values']})";
    }

    protected function whereNotInSub(Builder $query, $where): string
    {
        return "{$where['field']} NOT IN ({$where['values']})";
    }

    protected function whereNull(Builder $query, $where): string
    {
        return "{$where['field']} = NULL";
    }

    protected function whereNotNull(Builder $query, $where): string
    {
        return "{$where['field']} != NULL";
    }

    protected function whereSub(Builder $query, $where)
    {
        $select = $this->compileSelect($where['query']);

        return "{$where['field']} {$where['operator']} ({$select})";
    }

    protected function whereNested(Builder $query, $where)
    {
        return '(' . substr($this->compileWheres($where['query']), 6) . ')';
    }

    protected function compileOrders(Builder $query, $orders): string
    {
        if(! empty($orders)) {
            $stringifiedOrders = implode(', ', $this->compileOrdersToArray($query, $orders));

            return "ORDER BY {$stringifiedOrders}";
        }

        return '';
    }

    protected function compileOrdersToArray(Builder $query, $orders)
    {
        return array_map(function($order) {
            return "{$order['field']} {$order['direction']}";
        }, $orders);
    }

    protected function compileLimit(Builder $query, $limit): string
    {
        return "LIMIT {$limit}";
    }

    protected function compileOffset(Builder $query, $offset): string
    {
        return "OFFSET {$offset}";
    }

    protected function compileFrom(Builder $query, $table): string
    {
        return "FROM {$table}";
    }

    /**
     * Compile the components necessary for a select clause.
     *
     * @param Builder $query
     *
     * @return array
     */
    protected function compileComponents(Builder $query): array
    {
        $sql = [];

        foreach($this->selectComponents as $component) {
            if(isset($query->{$component})) {
                $method = 'compile' . ucfirst($component);

                $sql[$component] = $this->{$method}($query, $query->{$component});
            }
        }

        return $sql;
    }

    /**
     * Concatenate an array of segments, removing empties.
     *
     * @param array $segments
     *
     * @return string
     */
    protected function concatenate($segments): string
    {
        return implode(' ', array_filter($segments, function($value) {
            return (string) $value !== '';
        }));
    }

    /**
     * Wrap values for use in the query.
     *
     * @param $data
     *
     * @return mixed
     */
    protected function wrap($data)
    {
        if(is_array($data)) {
            return array_map(function($item) {
                return "'{$item}'";
            }, $data);
        }

        return "'{$data}'";
    }

    protected function concatenateWhereClauses($query, $sql)
    {
        return "WHERE {$this->removeLeadingBoolean(implode(' ', $sql))}";
    }

    protected function removeLeadingBoolean($value)
    {
        return preg_replace('/AND /i', '', $value, 1);
    }
}
