<?php


class ApiQueryGeoSearchDb extends ApiQueryGeoSearch {
	public function __construct( $query, $moduleName ) {
		parent::__construct( $query, $moduleName );
	}

	/**
	 * @param ApiPageSet $resultPageSet
	 */
	protected function run( $resultPageSet = null ) {
		global $wgDefaultGlobe;

		parent::run( $resultPageSet );
		$params = $this->extractRequestParams();

		$this->addTables( 'geo_tags' );
		$this->addFields( array( 'gt_lat', 'gt_lon', 'gt_primary' ) );
		$mapping = Coord::getFieldMapping();
		foreach( $params['prop'] as $prop ) {
			if ( isset( $mapping[$prop] ) ) {
				$this->addFields( $mapping[$prop] );
			}
		}
		$this->addWhereFld( 'gt_globe', $this->coord->globe );
		$this->addWhere( 'gt_page_id = page_id' );
		if ( $this->idToExclude ) {
			$this->addWhere( "gt_page_id <> {$this->idToExclude}" );
		}
		if ( isset( $params['maxdim'] ) ) {
			$this->addWhere( 'gt_dim < ' . intval( $params['maxdim'] ) );
		}
		$primary = $params['primary'];
		$this->addWhereIf( array( 'gt_primary' => intval( $primary === 'primary' ) ), $primary !== 'all' );

		$this->addCoordFilter();

		$limit = $params['limit'];

		$res = $this->select( __METHOD__ );

		$rows = array();
		foreach ( $res as $row ) {
			$row->dist = GeoDataMath::distance( $this->coord->lat, $this->coord->lon, $row->gt_lat, $row->gt_lon );
			$rows[] = $row;
		}
		// sort in PHP because sorting via SQL would involve a filesort
		usort( $rows, function( $row1, $row2 ) {
			if ( $row1->dist == $row2->dist ) {
				return 0;
			}
			return ( $row1->dist < $row2->dist ) ? -1 : 1;
		} );
		$result = $this->getResult();
		foreach ( $rows as $row ) {
			if ( !$limit-- ) {
				break;
			}
			if ( is_null( $resultPageSet ) ) {
				$title = Title::newFromRow( $row );
				$vals = array(
					'pageid' => intval( $row->page_id ),
					'ns' => intval( $title->getNamespace() ),
					'title' => $title->getPrefixedText(),
					'lat' => floatval( $row->gt_lat ),
					'lon' => floatval( $row->gt_lon ),
					'dist' => round( $row->dist, 1 ),
				);
				if ( $row->gt_primary ) {
					$vals['primary'] = '';
				}
				foreach( $params['prop'] as $prop ) {
					if ( isset( $mapping[$prop] ) && isset( $row->{$mapping[$prop]} ) ) {
						$field = $mapping[$prop];
						// Don't output default globe
						if ( !( $prop === 'globe' && $row->$field === $wgDefaultGlobe ) ) {
							$vals[$prop] = $row->$field;
						}
					}
				}
				$fit = $result->addValue( array( 'query', $this->getModuleName() ), null, $vals );
				if ( !$fit ) {
					break;
				}
			} else {
				$resultPageSet->processDbRow( $row );
			}
		}
	}

	protected  function addCoordFilter() {
		$bbox = $this->bbox ?: $this->coord->bboxAround( $this->radius );
		$this->addWhereFld( 'gt_lat_int', self::intRange( $bbox->lat1, $bbox->lat2 ) );
		$this->addWhereFld( 'gt_lon_int', self::intRange( $bbox->lon1, $bbox->lon2 ) );

		$this->addWhereRange( 'gt_lat', 'newer', $bbox->lat1, $bbox->lat2, false );
		if ( $bbox->lon1 > $bbox->lon2 ) {
			$this->addWhere( "gt_lon < {$bbox->lon2} OR gt_lon > {$bbox->lon1}" );
		} else {
			$this->addWhereRange( 'gt_lon', 'newer', $bbox->lon1, $bbox->lon2, false );
		}
		$this->addOption( 'USE INDEX', array( 'geo_tags' => 'gt_spatial' ) );
	}
}