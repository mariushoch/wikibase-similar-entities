<?php

declare( strict_types = 1 );
namespace Wikibase\SimilarEntities;

use RuntimeException;

class ItemQueryRunner {

    /**
     * @var string
     */
    private $endPoint;

    public function __construct( string $endPoint ) {
        $this->endPoint = $endPoint;
    }

    /**
     * @param string $query
     *
     * @return string JSON
     */
    private function doQuery( string $query ): string {
        $url = str_replace( '$1', urlencode( $query ), $this->endPoint );

        $opts = [ 'header' => 'User-Agent: wikibase-similar-entity' ];
        $opts = stream_context_create( array( 'http' => $opts, 'https' => $opts ) );

        $res = @file_get_contents( $url, false, $opts );

        if ( $res === false ) {
            throw new RuntimeException( "An error occured while trying to query the SPARQL end point." );
        }
        return $res;
    }

    /**
     * @param string $query
     *
     * @return string[] item ids
     */
    public function getItemsForQuery( string $query ): array {
        $json = $this->doQuery( $query );
        $data = json_decode( $json, true );

        $result = [];
        foreach ( $data['results']['bindings'] as $value ) {
            $result[] = $value['item']['value'];
        }

        return $result;
    }

}