<?php

namespace Stratease\Salesforcery\Salesforce\Database;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use JsonSerializable;
use Stratease\Salesforcery\Salesforce\Connection\REST\Client;
use Stratease\Salesforcery\Salesforce\Database\Concerns\HasRelations;
use Stratease\Salesforcery\Salesforce\Exceptions\InvalidFieldException;

abstract class Model implements Arrayable, Jsonable, JsonSerializable
{

    use HasRelations;

    /**
     * @var Client Our REST client library
     */
    protected static $connection;

    /**
     * @var string Salesforce object 'name'
     */
    public static $resourceName;

    /**
     * @var string
     */
    public $primaryKey = 'Id';

    /**
     * @var array The field => value for this model
     */
    protected $attributes = [];

    /**
     * @var array Fields that have changed since model hydration with their previous values, field => prevValue
     */
    protected $changed = [];

    /**
     * Model constructor.
     *
     * @param array $data Salesforce fields
     */
    public function __construct($data = [])
    {
        $this->hydrate($data);
    }

    /**
     * @param $connection Client
     */
    public static function registerConnection(Client $connection)
    {
        self::$connection = $connection;
    }

    /**
     * @param $data
     *
     * @return Model
     */
    public static function hydrateFactory($data)
    {
        $instanceName = static::class;
        $instance = new $instanceName();
        $instance->hydrate($data);

        return $instance;
    }

    /**
     * @param $field
     * @param $value
     *
     * @return Collection
     */
    public static function findBy()
    {
        $instance = new static;
        $args = func_get_args() ? func_get_args() : [[]];
        $query = call_user_func_array([$instance, 'where'], $args);

        return $query->get();
    }

    /**
     * @return mixed
     */
    public static function first() {
        return (new static)->newQuery()->first();
    }

    /**
     * @return Collection
     */
    public static function all()
    {
        return (new static)->newQuery()->get();
    }

    /**
     * Handle dynamic static method calls into the method.
     *
     * @param string $method
     * @param array  $parameters
     *
     * @return mixed
     */
    public static function __callStatic($method, $parameters)
    {
        return (new static)->$method(...$parameters);
    }

    /**
     * Convert the model to its string representation.
     *
     * @return string
     */
    public function __toString()
    {
        return $this->toJson();
    }

    public static function with($relations): Builder
    {
        $relations = is_array($relations) ? $relations : func_get_args();

        return (new static)->newQuery()->with($relations);
    }

    /**
     * @return Builder
     */
    public function newQuery()
    {
        return (new Builder(self::$connection->getQueryBuilder()))
            ->select(array_keys(static::getSchema()))
            ->from(static::resolveObjectName())
            ->setModel($this);
    }

    public function newInstance($attributes = [])
    {
        $model = new static((array) $attributes);

        $model->registerConnection(self::$connection);

        return $model;
    }

    /**
     * @param $field
     * @param $value
     *
     * @return Model
     */
    public static function findOneBy()
    {
        $results = self::findBy(...func_get_args());
        if(isset($results[0])) {

            return $results[0];
        }

        return null;
    }

    /**
     * @return array
     */
    public static function getSchema()
    {
        SchemaInspector::registerConnection(self::$connection);

        return SchemaInspector::getSchema(self::resolveObjectName());
    }

    /**
     * @param array $data
     *
     * @return $this
     */
    public function hydrate(array $data)
    {
        $schema = self::getSchema();

        foreach($schema as $field => $dataType) {
            $this->hydrateField($field, isset($data[$field]) ? $data[$field] : null);
        }

        return $this;
    }

    /**
     * @return bool|string Attempts to detect the Salesforce object name
     */
    public static function resolveObjectName()
    {
        if(static::$resourceName) {

            return static::$resourceName;
        }

        return substr(static::class, strrpos(static::class, '\\') + 1);
    }

    /**
     * Will update this entry in the database
     *
     * @return $this
     */
    public function update()
    {
        $primaryKey = $this->primaryKey;
        if($this->$primaryKey) {
            if(self::$connection->update(self::resolveObjectName(),
                $this->$primaryKey,
                $this->getChanges())) {

                $this->discardChanges();
            }
        }

        return $this;
    }

    /**
     * Will insert a new entry into the database
     *
     * @return $this
     */
    public function insert()
    {
        if($id = self::$connection->create(self::resolveObjectName(),
            $this->getChanges())) {
            $this->hydrateField($this->primaryKey, $id);
            $this->discardChanges();
        }

        return $this;
    }

    /**
     * Resets any pending changes made since instance was initially hydrated
     *
     * @return $this
     */
    public function discardChanges()
    {
        $this->changed = [];

        return $this;
    }

    /**
     * @return array Gets an array of field => val with pending updates to be pushed to the database
     */
    public function getChanges()
    {
        return array_intersect_key($this->attributes, $this->changed);
    }

    /**
     * @return array Returns an array with all current values for this resource
     */
    public function toArray()
    {
        return array_merge($this->attributesToArray(), $this->relationsToArray());
    }

    public function attributesToArray()
    {
        return $this->attributes;
    }

    public function relationsToArray()
    {
        $attributes = [];

        foreach($this->relations as $name => $value) {
            if ($value instanceof Arrayable) {
                $relation = $value->toArray();
            }
            elseif (is_null($value)) {
                $relation = $value;
            }

            if (isset($relation) || is_null($value)) {
                $attributes[$name] = $relation;
            }

            unset($relation);
        }

        return $attributes;
    }

    public function toJson($options = 0)
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    /**
     * Convert the object into something JSON serializable.
     *
     * @return array
     */
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    /**
     * Inspects this objects fields to detect if a field is valid
     *
     * @param $field
     *
     * @return bool
     */
    public static function isValidField($field)
    {
        SchemaInspector::registerConnection(self::$connection);

        return isset(SchemaInspector::getSchema(self::resolveObjectName())[$field]);
    }

    /**
     * Track a field changed event
     *
     * @param $field    string
     * @param $oldValue mixed
     */
    protected function fireChange($field, $oldValue)
    {
        $this->changed[$field] = $oldValue;
    }

    /**
     * Has this field been modified since model hydration?
     *
     * @param $field
     *
     * @return bool
     */
    public function hasChanged($field)
    {
        return isset($this->changed[$field]);
    }

    /**
     * @param $field
     * @param $value
     *
     * @return mixed
     */
    public function __set($field, $value)
    {
        $setter = 'set' . $field;
        // do change operation
        if(self::isValidField($field)) {
            $this->fireChange($field, $this->$field);
        }

        return $this->$setter($value);
    }

    /**
     * @param $field
     *
     * @return mixed
     */
    public function __get($field)
    {
        if(method_exists($this, $field)) {
            return $this->getRelationValue($field);
        }

        if(self::isValidField($field)) {
            return isset($this->attributes[$field]) ? $this->attributes[$field] : null;
        }

        throw new InvalidFieldException(sprintf('The field %s does not exist on %s. Available fieds are: %s.',
            $field,
            self::resolveObjectName(),
            implode(', ', array_keys(self::getSchema()))
        ));
    }

    public function __isset($name)
    {
        return in_array($name, array_keys($this->attributes));
    }

    /**
     * @param $name
     * @param $arguments
     *
     * @return $this|null|mixed
     */
    public function __call($name, $arguments)
    {
        // setter?
        if(substr($name, 0, 3) === 'set') {
            $field = substr($name, 3);
            $this->fireChange($field, $this->attributes[$field] ?: null);
            $this->attributes[$field] = $arguments[0]; // @todo datatypeing?

            return $this;
        }

        if(in_array($name, ['increment', 'decrement'])) {
            return $this->$name(...$arguments);
        }

        return $this->newQuery()->$name(...$arguments);
    }

    /**
     * @param $field
     * @param $value
     *
     * @return $this
     */
    protected function hydrateField($field, $value)
    {
        $this->attributes[$field] = $value;

        return $this;
    }

    /**
     * Will either do an update or insert on the database.
     *
     * @return $this
     */
    public function save()
    {
        $primaryKey = $this->primaryKey;

        if(! $this->$primaryKey) {
            // do insert
            return $this->insert();
        }

        // we exist, do update
        return $this->update();
    }

    /**
     * @return $this
     */
    public function delete()
    {
        $connection = self::$connection;
        $primaryKey = $this->primaryKey;
        $connection->delete(self::resolveObjectName(), $this->$primaryKey);

        return $this;
    }
}
