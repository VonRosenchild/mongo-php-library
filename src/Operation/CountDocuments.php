<?php
/*
 * Copyright 2015-2017 MongoDB, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace MongoDB\Operation;

use MongoDB\Driver\Server;
use MongoDB\Driver\Exception\RuntimeException as DriverRuntimeException;
use MongoDB\Exception\InvalidArgumentException;
use MongoDB\Exception\UnexpectedValueException;
use MongoDB\Exception\UnsupportedException;

/**
 * Operation for obtaining an exact count of documents in a collection
 *
 * @api
 * @see \MongoDB\Collection::countDocuments()
 * @see https://github.com/mongodb/specifications/blob/master/source/crud/crud.rst#countdocuments
 */
class CountDocuments implements Executable
{
    private $databaseName;
    private $collectionName;
    private $filter;
    private $aggregateOptions;
    private $countOptions;
    private $aggregate;

    /**
     * Constructs an aggregate command for counting documents
     *
     * Supported options:
     *
     *  * collation (document): Collation specification.
     *
     *    This is not supported for server versions < 3.4 and will result in an
     *    exception at execution time if used.
     *
     *  * hint (string|document): The index to use. Specify either the index
     *    name as a string or the index key pattern as a document. If specified,
     *    then the query system will only consider plans using the hinted index.
     *
     *  * limit (integer): The maximum number of documents to count.
     *
     *  * maxTimeMS (integer): The maximum amount of time to allow the query to
     *    run.
     *
     *  * readConcern (MongoDB\Driver\ReadConcern): Read concern.
     *
     *    This is not supported for server versions < 3.2 and will result in an
     *    exception at execution time if used.
     *
     *  * readPreference (MongoDB\Driver\ReadPreference): Read preference.
     *
     *  * session (MongoDB\Driver\Session): Client session.
     *
     *    Sessions are not supported for server versions < 3.6.
     *
     *  * skip (integer): The number of documents to skip before returning the
     *    documents.
     *
     * @param string       $databaseName   Database name
     * @param string       $collectionName Collection name
     * @param array|object $filter         Query by which to filter documents
     * @param array        $options        Command options
     * @throws InvalidArgumentException for parameter/option parsing errors
     */
    public function __construct($databaseName, $collectionName, $filter, array $options = [])
    {
        if ( ! is_array($filter) && ! is_object($filter)) {
            throw InvalidArgumentException::invalidType('$filter', $filter, 'array or object');
        }

        if (isset($options['limit']) && ! is_integer($options['limit'])) {
            throw InvalidArgumentException::invalidType('"limit" option', $options['limit'], 'integer');
        }

        if (isset($options['skip']) && ! is_integer($options['skip'])) {
            throw InvalidArgumentException::invalidType('"skip" option', $options['skip'], 'integer');
        }

        $this->databaseName = (string) $databaseName;
        $this->collectionName = (string) $collectionName;
        $this->filter = $filter;

        $this->aggregateOptions = array_intersect_key($options, ['collation' => 1, 'hint' => 1, 'maxTimeMS' => 1, 'readConcern' => 1, 'readPreference' => 1, 'session' => 1]);
        $this->countOptions = array_intersect_key($options, ['limit' => 1, 'skip' => 1]);

        $this->aggregate = $this->createAggregate();
    }

    /**
     * Execute the operation.
     *
     * @see Executable::execute()
     * @param Server $server
     * @return integer
     * @throws UnexpectedValueException if the command response was malformed
     * @throws UnsupportedException if collation or read concern is used and unsupported
     * @throws DriverRuntimeException for other driver errors (e.g. connection errors)
     */
    public function execute(Server $server)
    {
        $cursor = $this->aggregate->execute($server);
        $allResults = $cursor->toArray();

        /* If there are no documents to count, the aggregation pipeline has no items to group, and
         * hence the result is an empty array (PHPLIB-376) */
        if (count($allResults) == 0) {
            return 0;
        }

        $result = current($allResults);
        if ( ! isset($result->n) || ! (is_integer($result->n) || is_float($result->n))) {
            throw new UnexpectedValueException('count command did not return a numeric "n" value');
        }

        return (integer) $result->n;
    }

    /**
     * @return Aggregate
     */
    private function createAggregate()
    {
        $pipeline = [
            ['$match' => (object) $this->filter]
        ];

        if (isset($this->countOptions['skip'])) {
            $pipeline[] = ['$skip' => $this->countOptions['skip']];
        }

        if (isset($this->countOptions['limit'])) {
            $pipeline[] = ['$limit' => $this->countOptions['limit']];
        }

        $pipeline[] = ['$group' => ['_id' => 1, 'n' => ['$sum' => 1]]];

        return new Aggregate($this->databaseName, $this->collectionName, $pipeline, $this->aggregateOptions);
    }
}
