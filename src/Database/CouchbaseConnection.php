<?php

/**
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

namespace Ytake\LaravelCouchbase\Database;

use Closure;
use CouchbaseBucket;
use Illuminate\Database\Connection;
use Ytake\LaravelCouchbase\Events\QueryPrepared;
use Ytake\LaravelCouchbase\Events\ResultReturning;
use Ytake\LaravelCouchbase\Query\Grammar;
use Ytake\LaravelCouchbase\Query\Processor;
use Ytake\LaravelCouchbase\Exceptions\NotSupportedException;
use Ytake\LaravelCouchbase\VersionTrait;

/**
 * Class CouchbaseConnection.
 *
 * @author Yuuki Takezawa<yuuki.takezawa@comnect.jp.net>
 */
class CouchbaseConnection extends Connection
{
    use VersionTrait;

    /** @var string */
    protected $bucket;

    /** @var \CouchbaseCluster */
    protected $connection;

    /** @var */
    protected $managerUser;

    /** @var */
    protected $managerPassword;

    /** @var array */
    protected $options = [];

    /** @var int */
    protected $fetchMode = 0;

    /** @var array */
    protected $enableN1qlServers = [];

    /** @var string */
    protected $bucketPassword = '';

    /** @var string[] */
    protected $metrics;

    /** @var int  default consistency */
    protected $consistency = \CouchbaseN1qlQuery::NOT_BOUNDED;

    /** @var string[]  function to handle the retrieval of various properties. */
    private $properties = [
        'operationTimeout',
        'viewTimeout',
        'durabilityInterval',
        'durabilityTimeout',
        'httpTimeout',
        'configTimeout',
        'configDelay',
        'configNodeTimeout',
        'htconfigIdleTimeout',
    ];

    /**
     * @param array $config
     */
    public function __construct(array $config)
    {
        $this->connection = $this->createConnection($config);
        $this->getManagedConfigure($config);

        $this->useDefaultQueryGrammar();

        $this->useDefaultPostProcessor();
    }

    /**
     * @param $password
     *
     * @return $this
     */
    public function setBucketPassword($password)
    {
        $this->bucketPassword = $password;

        return $this;
    }

    /**
     * @param $name
     *
     * @return \CouchbaseBucket
     */
    public function openBucket($name)
    {
        return $this->connection->openBucket($name, $this->bucketPassword);
    }

    /**
     * @param CouchbaseBucket $bucket
     *
     * @return string[]
     */
    public function getOptions(\CouchbaseBucket $bucket)
    {
        $options = [];
        foreach ($this->properties as $property) {
            $options[$property] = $bucket->$property;
        }

        return $options;
    }

    /**
     * @param CouchbaseBucket $bucket
     */
    protected function registerOption(\CouchbaseBucket $bucket)
    {
        if (count($this->options)) {
            foreach ($this->options as $option => $value) {
                $bucket->$option = $value;
            }
        }
    }

    /**
     * @return Processor
     */
    protected function getDefaultPostProcessor()
    {
        return new Processor();
    }

    /**
     * @return Grammar
     */
    protected function getDefaultQueryGrammar()
    {
        return new Grammar();
    }

    /**
     * @param array $config
     */
    protected function getManagedConfigure(array $config)
    {
        $this->enableN1qlServers = (isset($config['enables'])) ? $config['enables'] : [];
        $this->options = (isset($config['options'])) ? $config['options'] : [];
        $manager = (isset($config['manager'])) ? $config['manager'] : null;
        if (is_null($manager)) {
            $this->managerUser = (isset($config['user'])) ? $config['user'] : null;
            $this->managerPassword = (isset($config['password'])) ? $config['password'] : null;

            return;
        }
        $this->managerUser = $config['manager']['user'];
        $this->managerPassword = $config['manager']['password'];
    }

    /**
     * @param $dsn
     *
     * @return \CouchbaseCluster
     */
    protected function createConnection($dsn)
    {
        return (new CouchbaseConnector)->connect($dsn);
    }

    /**
     * {@inheritdoc}
     */
    public function getDriverName()
    {
        return 'couchbase';
    }

    /**
     * @return \CouchbaseCluster
     */
    public function getCouchbase()
    {
        return $this->connection;
    }

    /**
     * @param string $table
     *
     * @return \Ytake\LaravelCouchbase\Database\QueryBuilder
     */
    public function table($table)
    {
        $this->bucket = $table;

        return $this->query()->from($table);
    }

    /**
     * @param int      $consistency
     * @param callable $callback
     *
     * @return mixed
     */
    public function callableConsistency($consistency, callable $callback)
    {
        $clone = clone $this;
        $clone->consistency = $consistency;

        return call_user_func_array($callback, [$clone]);
    }

    /**
     * @param int $consistency
     *
     * @return $this
     */
    public function consistency($consistency)
    {
        $this->consistency = $consistency;

        return $this;
    }

    /**
     * @param string $bucket
     *
     * @return $this
     */
    public function bucket($bucket)
    {
        $this->bucket = $bucket;

        return $this;
    }

    /**
     * @param \CouchbaseN1qlQuery $query
     *
     * @return mixed
     */
    protected function executeQuery(\CouchbaseN1qlQuery $query)
    {
        $bucket = $this->openBucket($this->bucket);
        $this->registerOption($bucket);
        $this->firePreparedQuery($query);
        $result = $bucket->query($query);
        $this->fireReturning($result);

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function select($query, $bindings = [], $useReadPdo = true)
    {
        return $this->run($query, $bindings, function ($me, $query, $bindings) {
            if ($me->pretending()) {
                return [];
            }
            $query = \CouchbaseN1qlQuery::fromString($query);
            if ($this->breakingVersion()) {
                $query->consistency($this->consistency);
                $query->positionalParams($bindings);
                $result = $this->executeQuery($query);
                $this->metrics = (isset($result->metrics)) ? $result->metrics : [];

                return (isset($result->rows)) ? $result->rows : [];
            }
            // @codeCoverageIgnoreStart
            $query->options['args'] = $bindings;
            $query->consistency($this->consistency);

            return $this->executeQuery($query);
            // @codeCoverageIgnoreEnd
        });
    }

    /**
     * @param string $query
     * @param array  $bindings
     *
     * @return int|mixed
     */
    public function insert($query, $bindings = [])
    {
        return $this->affectingStatement($query, $bindings);
    }

    /**
     * {@inheritdoc}
     */
    public function affectingStatement($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($me, $query, $bindings) {
            if ($me->pretending()) {
                return 0;
            }
            $query = \CouchbaseN1qlQuery::fromString($query);

            if ($this->breakingVersion()) {
                $query->consistency($this->consistency);
                $query->namedParams(['parameters' => $bindings]);
                $result = $this->executeQuery($query);
                $this->metrics = (isset($result->metrics)) ? $result->metrics : [];

                return (isset($result->rows[0])) ? $result->rows[0] : false;
            }
            // @codeCoverageIgnoreStart
            $query->consistency($this->consistency);
            $bucket = $this->openBucket($this->bucket);
            $this->registerOption($bucket);
            $this->firePreparedQuery($query);
            $result = $bucket->query($query, ['parameters' => $bindings]);
            $this->fireReturning($result);

            return (isset($result[0])) ? $result[0] : false;
            // @codeCoverageIgnoreEnd
        });
    }

    /**
     * @param       $query
     * @param array $bindings
     *
     * @return mixed
     */
    public function positionalStatement($query, array $bindings = [])
    {
        return $this->run($query, $bindings, function ($me, $query, $bindings) {
            if ($me->pretending()) {
                return 0;
            }
            $query = \CouchbaseN1qlQuery::fromString($query);

            if ($this->breakingVersion()) {
                $query->consistency($this->consistency);
                $query->positionalParams($bindings);
                $result = $this->executeQuery($query);
                $this->metrics = (isset($result->metrics)) ? $result->metrics : [];

                return (isset($result->rows[0])) ? $result->rows[0] : false;
            }

            // @codeCoverageIgnoreStart
            $query->consistency($this->consistency);
            $query->options['args'] = $bindings;
            $result = $this->executeQuery($query);

            return (isset($result[0])) ? $result[0] : false;
            // @codeCoverageIgnoreEnd
        });
    }

    /**
     * {@inheritdoc}
     */
    public function transaction(Closure $callback)
    {
        throw new NotSupportedException(__METHOD__);
    }

    /**
     * {@inheritdoc}
     */
    public function beginTransaction()
    {
        throw new NotSupportedException(__METHOD__);
    }

    /**
     * {@inheritdoc}
     */
    public function commit()
    {
        throw new NotSupportedException(__METHOD__);
    }

    /**
     * {@inheritdoc}
     */
    public function rollBack()
    {
        throw new NotSupportedException(__METHOD__);
    }

    /**
     * {@inheritdoc}
     */
    protected function reconnectIfMissingConnection()
    {
        if (is_null($this->connection)) {
            $this->reconnect();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function disconnect()
    {
        $this->connection = null;
    }

    /**
     * @param CouchbaseBucket $bucket
     *
     * @return CouchbaseBucket
     */
    protected function enableN1ql(CouchbaseBucket $bucket)
    {
        if (!count($this->enableN1qlServers)) {
            return $bucket;
        }
        $bucket->enableN1ql($this->enableN1qlServers);

        return $bucket;
    }

    /**
     * N1QL upsert query.
     *
     * @param string $query
     * @param array  $bindings
     *
     * @return int
     */
    public function upsert($query, $bindings = [])
    {
        return $this->affectingStatement($query, $bindings);
    }

    /**
     * Get a new query builder instance.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function query()
    {
        return new QueryBuilder(
            $this, $this->getQueryGrammar(), $this->getPostProcessor()
        );
    }

    /**
     * Run an update statement against the database.
     *
     * @param string $query
     * @param array  $bindings
     *
     * @return int|\stdClass
     */
    public function update($query, $bindings = [])
    {
        return $this->positionalStatement($query, $bindings);
    }

    /**
     * Run a delete statement against the database.
     *
     * @param string $query
     * @param array  $bindings
     *
     * @return int|\stdClass
     */
    public function delete($query, $bindings = [])
    {
        return $this->positionalStatement($query, $bindings);
    }

    /**
     * @return \string[]
     */
    public function metrics()
    {
        return $this->metrics;
    }

    /**
     * @param \CouchbaseN1qlQuery $queryObject
     */
    protected function firePreparedQuery(\CouchbaseN1qlQuery $queryObject)
    {
        if (isset($this->events)) {
            $this->events->fire(new QueryPrepared($queryObject));
        }
    }

    /**
     * @param mixed $returning
     */
    protected function fireReturning($returning)
    {
        if (isset($this->events)) {
            $this->events->fire(new ResultReturning($returning));
        }
    }
}
