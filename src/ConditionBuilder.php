<?php

declare( strict_types = 1 );
namespace Wikibase\SimilarEntities;

use RuntimeException;

class ConditionBuilder {

    /**
     * @param array $entityData As obtained via Special:EntityData
     *
     * @return string[][] SPARQL WHERE conditions
     */
    public function getConditions( array $entityData ): array {
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
                if ( $dataValueType === 'wikibase-entityid' ) {
                    $id = $mainSnak['datavalue']['value']['id'];
                    $newConditions['value-relevant'][] = "?item wdt:$propertyId wd:$id .";
                } else {
                    $bytes = random_bytes( 6 );
                    $varName = bin2hex( $bytes );
                    $newConditions['value-irrelevant'][] = "?item wdt:$propertyId ?$varName .";
                }
            }
            $conditions = array_merge_recursive( $conditions, $newConditions );
        }

        return $conditions;
    }
}