<?php

declare( strict_types = 1 );
namespace Wikibase\SimilarEntities;

use RuntimeException;

class ConditionBuilder {

    /**
     * @var string[]
     */
    private $sortedProperties;

    /**
     * @var int
     */
    private $counter;

    public function __construct( array $sortedProperties ) {
        $this->sortedProperties = $sortedProperties;
        $this->counter = 0;
    }

    /**
     * @param array $entityData As obtained via Special:EntityData
     *
     * @return string[][] SPARQL WHERE conditions
     */
    public function getConditions( array $entityData ): array {
        $this->counter = 0;
        if ( !isset( $entityData['claims'] ) ) {
            throw new RuntimeException( 'Given entity data has no claims.' );
        }
        
        $conditions = [
            'value-relevant' => [],
            'value-irrelevant' => [],
        ];
        foreach ( $entityData['claims'] as $claimsByPropertyId ) {
            foreach ( $claimsByPropertyId as $claim ) {
                if ( $claim['rank'] === 'deprecated' ) {
                    continue;
                }

                $hasPreferred = false;
                $newConditions = [
                    'value-relevant' => [],
                    'value-irrelevant' => [],
                ];
        
                if ( !$hasPreferred && $claim['rank'] === 'preferred' ) {
                    $hasPreferred = true;
                    $newConditions = [
                        'value-relevant' => [],
                        'value-irrelevant' => [],
                    ];
                } elseif ( $hasPreferred && $claim['rank'] !== 'preferred' ) {
                    continue;
                }

                $mainSnak = $claim['mainsnak'];
                $propertyId = $mainSnak['property'];

                if ( $mainSnak['snaktype'] !== 'value' ) {
                    // XXX: Non-value Snaks ignored for now
                    continue;
                }
                $dataValueType = $mainSnak['datavalue']['type'];

                $key = array_search( $propertyId, $this->sortedProperties, true );
                if ( $key ) {
                    $key = $key * 100e9 + $this->counter;
                } else {
                    $key = count( $this->sortedProperties ) * 100e9 + $this->counter;
                }
                if ( $dataValueType === 'wikibase-entityid' ) {
                    $id = $mainSnak['datavalue']['value']['id'];
                    $newConditions['value-relevant'][$key] = "?item wdt:$propertyId wd:$id .";
                } else {
                    $bytes = random_bytes( 6 );
                    $varName = bin2hex( $bytes );
                    $newConditions['value-irrelevant'][$key] = "?item wdt:$propertyId ?$varName .";
                }
            }
            $conditions['value-relevant'] = $conditions['value-relevant'] + $newConditions['value-relevant'];
            $conditions['value-irrelevant'] = $conditions['value-irrelevant'] + $newConditions['value-irrelevant'];
        }

        return $conditions;
    }
}