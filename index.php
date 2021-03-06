<?php

declare( strict_types = 1 );

use Wikibase\SimilarEntities\ConditionBuilder;
use Wikibase\SimilarEntities\ItemQueryRunner;
use Wikibase\SimilarEntities\QueryBuilder;

require_once __DIR__ . '/vendor/autoload.php';

$entityId = $_GET['entityId'] ?? null;
$limit = $_GET['limit'] ?? 3;
$limit = max( 1, min( intval( $limit ), 25 ) );

if ( !$entityId ) {
	?>
<html>
	<head>
		<link rel="stylesheet" href="bootstrap-3.4.1-dist/css/bootstrap.min.css">
		<link rel="stylesheet" href="bootstrap-3.4.1-dist/css/bootstrap-theme.min.css">
		<script src="bootstrap-3.4.1-dist/js/bootstrap.min.js"></script>
		<title>SimilarEntities</title>
	</head>
	<body>
		<div style="margin-left: 20%; margin-right: 20%; margin-top: 10%">
			<h1>Similar entity finder</h1>
			<form type="GET">
				<div class="form-group">
					<label for="entityId">Entity Id</label>
					<input type="text" name="entityId" class="form-control" value="Q8359">
				</div>
				<div class="form-group">
					<label for="limit">Limit</label>
					<input type="text" name="limit" class="form-control" value="3">
				</div>
				<input type="submit" value="Find entities" class="btn btn-primary">
			</form>
			<small>Source code can be found at <a href="https://github.com/mariushoch/wikibase-similar-entities">github.com/mariushoch/wikibase-similar-entities</a>.</small>
		</div>
	</body>
</html>
	<?php
	exit( 0 );
}

if ( !preg_match( '/^Q[1-9]\d{0,9}\z/i', $entityId ) ) {
	throw new RuntimeException( "Invalid entity id $entityId given." );
}
$entityJson = file_get_contents(
	'https://www.wikidata.org/wiki/Special:EntityData/' . urlencode( $entityId ) . '.json'
);
$entityData = json_decode( $entityJson, true );
if ( !isset( $entityData['entities'][$entityId] ) ) {
	throw new RuntimeException( "Invalid Special:EntityData response." );
}
$entityData = $entityData['entities'][$entityId];

$sortedPropertiesRaw = file_get_contents(
	'https://www.wikidata.org/w/index.php?title=MediaWiki:Wikibase-SortedProperties&action=raw&sp_ver=1'
);
preg_match_all( '/\n\* (P[1-9]\d*)/', $sortedPropertiesRaw, $sortedProperties );
$sortedProperties = $sortedProperties[1];

$conditionBuilder = new ConditionBuilder( $sortedProperties );
$conditions = $conditionBuilder->getConditions( $entityData );

$conditionCount = count( $conditions['value-relevant'] ) + count( $conditions['value-irrelevant'] );
if ( $conditionCount === 0 ) {
	throw new RuntimeException( 'Could not make any conditions for entity ' . $entityId );
}

$queryRunner = new ItemQueryRunner( 'https://query.wikidata.org/bigdata/namespace/wdq/sparql?format=json&query=$1' );

function injectGeneralConditions( array $conditions ): array {
	global $entityId, $conditionCount;

	return array_merge(
		$conditions,
		[
			'?item wikibase:statements ?statementCount .',
			'FILTER(?statementCount < ' . round( $conditionCount * 1.25 ) . ') .',
			"FILTER(?item != wd:$entityId) .",
		]
	);
}

function handleResults( array $results, int $limit = null ) {
	$limit = $limit ? $limit : count( $results );

	if ( count( $results ) >= $limit ) {
		header( 'Content-Type: application/json' );
		echo json_encode( array_slice( $results, 0, $limit ) );
		exit( 0 );
	}
}

$results = [];

$queryBuilder = new QueryBuilder( 4000 );

// Step 1: Has all conditions
$conditionsToConsider = $conditions['value-relevant'] + $conditions['value-irrelevant'];
$query = $queryBuilder->buildQuery(
	[ '?item' ],
	injectGeneralConditions( $conditionsToConsider ),
	$limit
);

$results = $queryRunner->getItemsForQuery( $query );
handleResults( $results, $limit );

// Step 2: Has n-1 conditions
if ( count( $conditionsToConsider ) < 15 ) {
	$queryUnionParts = [];
	for ( $i = 0; $i < count( $conditionsToConsider ); $i++ ) {
		$conditionsToConsiderClone = $conditionsToConsider;
		unset( $conditionsToConsiderClone[$i] );

		$queryUnionParts[] = injectGeneralConditions( $conditionsToConsiderClone );
	}

	$queries = $queryBuilder->buildUnionQueries( [ '?item' ], $queryUnionParts, $limit );

	foreach ( $queries as $query ) {
		$results = array_merge( $results, $queryRunner->getItemsForQuery( $query ) );
		$results = array_unique( $results );
		handleResults( $results, $limit );
	}
}

// Step 3: Has all value-relevant conditions
$query = $queryBuilder->buildQuery(
	[ '?item' ],
	injectGeneralConditions( $conditions['value-relevant'] ),
	$limit
);

$results = array_merge( $results, $queryRunner->getItemsForQuery( $query ) );
$results = array_unique( $results );
handleResults( $results, $limit );

// Step 4: Remove 25%, 35%, 60%, 70% and 80% of the conditions
$queryUnionParts = [];
foreach ( [ 0.75, 0.65, 0.55, 0.4, 0.3, 0.2 ] as $reductionFactor ) {
	$numberOfConditions = round( count( $conditionsToConsider ) * $reductionFactor );
	if ( !$numberOfConditions ) {
		break;
	}
	if ( $numberOfConditions === count( $conditionsToConsider ) - 1 ) {
		// This is equivalent to step 2, skip
		continue;
	}

	// First try to remove the least important conditions...
	ksort( $conditionsToConsider );
	$queryUnionParts[] = injectGeneralConditions(
		array_slice( $conditionsToConsider, 0, $numberOfConditions )
	);
	// if that doesn't work, try dropping conditions randomly.
	for ( $i = 0; $i < min( 14, $conditionCount ); $i++ ) {
		$conditionsToConsiderClone = [];
		for ( $j = 0; $j < $numberOfConditions; $j++ ) {
			$desicionBarrier = mt_rand() / mt_getrandmax();
			// Scale by y = x * sqrt(0.8) + 0.6 (y integrated from 0 to 1 is 1)
			// This makes it more likely for less relevant conditions to
			// be purged, but keeps the overall reduction factor into account.
			$desicionBarrier *= ( $j / $numberOfConditions ) * sqrt( 0.8 ) + 0.6;
			if ( $desicionBarrier < $reductionFactor ) {
				$conditionsToConsiderClone[] = array_values( $conditionsToConsider )[$j];
			}
		}
		if ( $conditionsToConsiderClone ) {
			$queryUnionParts[] = injectGeneralConditions( $conditionsToConsiderClone );
		}
	}

	$queries = $queryBuilder->buildUnionQueries( [ '?item' ], $queryUnionParts, $limit );

	foreach ( $queries as $query ) {
		$results = array_merge( $results, $queryRunner->getItemsForQuery( $query ) );
		$results = array_unique( $results );
		handleResults( $results, $limit );
	}
}
handleResults( $results );