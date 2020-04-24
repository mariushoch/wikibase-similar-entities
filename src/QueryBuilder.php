<?php

declare( strict_types = 1 );
namespace Wikibase\SimilarEntities;

use RuntimeException;

class QueryBuilder {

    /**
     * @var int
     */
    private $maxQueryLength;

    public function __construct( int $maxQueryLength ) {
        $this->maxQueryLength = $maxQueryLength;
    }

    /**
     * Build a single SPARQL query for the specified fields with the given conditions
     * and limit.
     *
     * @param string[] $fields
     * @param string[] $conditions
     * @param int $limit
     *
     * @return string SPARQL query
     */
    public function buildQuery( array $fields, array $conditions, int $limit ): string {
        return $this->buildUnionQueries( $fields, [ $conditions ], $limit )[0];
    }

    /**
     * Build SPARQL UNION queries for the given conditions for each UNION clause.
     * Queries will be split if they exceed the maximum query length.
     *
     * @param string[] $fields
     * @param string[] $conditions
     * @param int $limit
     *
     * @return string[] SPARQL queries
     */
    public function buildUnionQueries( array $fields, array $conditionsPerQuery, int $limit ): array {
        $queries = [];

        $chunkSize = count( $conditionsPerQuery );
        while ( $conditionsPerQuery ) {
            $conditionsPerQueryChunk = array_slice( $conditionsPerQuery, 0, $chunkSize );
            $query = 'SELECT ' . implode( ' ', $fields ) . " WHERE {\n";

            $unionParts = [];
            foreach ( $conditionsPerQueryChunk as $conditions ) {
                $unionParts[] = implode( "\n", $conditions );
            }
            if ( $chunkSize > 1 ) {
                $query .= '{' . implode( "\n} UNION {\n", $unionParts ) . "}\n";
            } else {
                $query .= $unionParts[0] . "\n";
            }
            $query .= '} LIMIT ' . $limit;

            if ( strlen( $query ) > $this->maxQueryLength ) {
                $chunkSize--;

                if ( $chunkSize < 1 ) {
                    throw new RuntimeException(
                        'Could not build a single query with the specified length limit.'
                    );
                }

                continue;
            }
            $queries[] = $query;
            $conditionsPerQuery = array_slice( $conditionsPerQuery, $chunkSize );
            $chunkSize = count( $conditionsPerQuery );
        }

        return $queries;
    }
}