<?php
/**
 * Retract missing events ability tests.
 *
 * Seeds a flow + venue + published future events, feeds a synthetic ICS
 * string through the ics_content override (no network), and exercises the
 * dry-run/apply threshold flow end to end.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Abilities\RetractMissingEventsAbilities;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;
use WP_UnitTestCase;

class RetractMissingEventsAbilitiesTest extends WP_UnitTestCase {

	private const FLOW_ID    = 8799;
	private const SOURCE_URL = 'https://calendar.example.com/starlight/basic.ics';

	private ?array $retracted_action = null;

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
		if ( ! EventDatesTable::table_exists() ) {
			EventDatesTable::create_table();
		}

		$this->retracted_action = null;
		add_action(
			'datamachine_event_retracted',
			function ( $post_id, $flow_id, $reason ) {
				$this->retracted_action = array(
					'post_id' => (int) $post_id,
					'flow_id' => (int) $flow_id,
					'reason'  => (string) $reason,
				);
			},
			10,
			3
		);
	}

	public function tearDown(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'datamachine_flows';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- test cleanup against a test-only table.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- test cleanup.
			$wpdb->delete( $table, array( 'flow_id' => self::FLOW_ID ), array( '%d' ) );
		}

		parent::tearDown();
	}

	public function test_non_ics_flow_is_rejected(): void {
		$this->seedFlowTable( 'https://venue.example.com/schedule' );

		$result = ( new RetractMissingEventsAbilities() )->executeRetractMissingEvents(
			array( 'flow_id' => self::FLOW_ID )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'retract_not_ics_flow', $result->get_error_code() );
	}

	public function test_dry_run_counts_miss_without_drafting_and_apply_retracts_on_threshold(): void {
		$venue_id = $this->createVenue( 'Retract Test Venue' );
		$this->seedFlowTable( self::SOURCE_URL, $venue_id );

		$present = $this->seedFlowEvent( 'Present Band', '+30 days 21:00', $venue_id );
		$missing = $this->seedFlowEvent( 'Ghost Act', '+31 days 21:00', $venue_id );
		$this->seedFlowEvent( 'Edited Act', '+32 days 21:00', $venue_id, true );

		$ics = $this->buildIcs(
			array(
				array( 'Present Band', '+30 days 21:00' ),
				array( 'Filler Night Act', '+33 days 20:00' ),
			)
		);

		$abilities = new RetractMissingEventsAbilities();

		// Run 1: dry run, default threshold of 2 consecutive misses.
		$first = $abilities->executeRetractMissingEvents(
			array(
				'flow_id'     => self::FLOW_ID,
				'ics_content' => $ics,
			)
		);

		$this->assertNotWPError( $first );

		$by_id = array_column( $first['items'], null, 'post_id' );

		$this->assertSame( 3, $first['scanned'] );
		$this->assertSame( 1, $first['present'] );
		$this->assertSame( 1, $first['missing_pending'] );
		$this->assertSame( 0, $first['eligible'] );
		$this->assertSame( 0, $first['retracted'] );
		$this->assertSame( 1, $first['skipped_hand_edited'] );

		$this->assertSame( 'present', $by_id[ $present ]['action'] );
		$this->assertSame( 'publish', get_post_status( $present ) );

		$this->assertSame( 'missing - pending', $by_id[ $missing ]['action'] );
		$this->assertSame( 1, (int) get_post_meta( $missing, RetractMissingEventsAbilities::MISSING_RUN_COUNT_META, true ) );
		$this->assertNotEmpty( get_post_meta( $missing, RetractMissingEventsAbilities::MISSING_SINCE_META, true ) );
		$this->assertSame( 'publish', get_post_status( $missing ) );

		$this->assertNull( $this->retracted_action );

		// Run 2: apply — the second consecutive miss crosses the threshold.
		$second = $abilities->executeRetractMissingEvents(
			array(
				'flow_id'     => self::FLOW_ID,
				'apply'       => true,
				'ics_content' => $ics,
			)
		);

		$this->assertNotWPError( $second );
		$this->assertSame( 1, $second['eligible'] );
		$this->assertSame( 1, $second['retracted'] );

		$this->assertSame( 'draft', get_post_status( $missing ) );
		$this->assertSame( 'publish', get_post_status( $present ) );

		$attrs = $this->extractBlockAttributes( $missing );
		$this->assertSame( 'EventCancelled', $attrs['eventStatus'] ?? '' );

		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
			(string) get_post_meta( $missing, RetractMissingEventsAbilities::RETRACTED_AT_META, true )
		);
		$this->assertSame( (string) self::FLOW_ID, (string) get_post_meta( $missing, RetractMissingEventsAbilities::RETRACTED_BY_FLOW_META, true ) );

		$this->assertNotNull( $this->retracted_action );
		$this->assertSame( $missing, $this->retracted_action['post_id'] );
		$this->assertSame( self::FLOW_ID, $this->retracted_action['flow_id'] );
		$this->assertSame( RetractMissingEventsAbilities::RETRACT_REASON, $this->retracted_action['reason'] );
	}

	public function test_present_event_miss_counter_stays_clear_across_runs(): void {
		$venue_id = $this->createVenue( 'Retract Counter Venue' );
		$this->seedFlowTable( self::SOURCE_URL, $venue_id );

		$present = $this->seedFlowEvent( 'Always Listed Act', '+40 days 20:00', $venue_id );

		$ics = $this->buildIcs(
			array(
				array( 'Always Listed Act', '+40 days 20:00' ),
			)
		);

		$abilities = new RetractMissingEventsAbilities();

		for ( $run = 0; $run < 2; ++$run ) {
			$result = $abilities->executeRetractMissingEvents(
				array(
					'flow_id'     => self::FLOW_ID,
					'apply'       => 1 === $run,
					'ics_content' => $ics,
				)
			);

			$this->assertNotWPError( $result );
		}

		$this->assertSame( '', get_post_meta( $present, RetractMissingEventsAbilities::MISSING_RUN_COUNT_META, true ) );
		$this->assertSame( '', get_post_meta( $present, RetractMissingEventsAbilities::MISSING_SINCE_META, true ) );
		$this->assertSame( 'publish', get_post_status( $present ) );
	}

	public function test_low_source_coverage_aborts_without_touching_counters(): void {
		$venue_id = $this->createVenue( 'Retract Coverage Venue' );
		$this->seedFlowTable( self::SOURCE_URL, $venue_id );

		$this->seedFlowEvent( 'Coverage Act One', '+50 days 20:00', $venue_id );
		$this->seedFlowEvent( 'Coverage Act Two', '+51 days 20:00', $venue_id );
		$this->seedFlowEvent( 'Coverage Act Three', '+52 days 20:00', $venue_id );

		// One feed occurrence against three published events: presumed truncated.
		$ics = $this->buildIcs(
			array(
				array( 'Coverage Act One', '+50 days 20:00' ),
			)
		);

		$result = ( new RetractMissingEventsAbilities() )->executeRetractMissingEvents(
			array(
				'flow_id'     => self::FLOW_ID,
				'ics_content' => $ics,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'retract_low_source_coverage', $result->get_error_code() );
	}

	public function test_post_from_another_source_is_skipped(): void {
		$venue_id = $this->createVenue( 'Retract Source Venue' );
		$this->seedFlowTable( self::SOURCE_URL, $venue_id );

		$foreign = $this->seedFlowEvent( 'Foreign Source Act', '+55 days 20:00', $venue_id );
		update_post_meta( $foreign, '_datamachine_event_source', 'ticketmaster' );

		$ics = $this->buildIcs(
			array(
				array( 'Unrelated Feed Act', '+55 days 20:00' ),
				array( 'Another Feed Act', '+56 days 20:00' ),
			)
		);

		$result = ( new RetractMissingEventsAbilities() )->executeRetractMissingEvents(
			array(
				'flow_id'     => self::FLOW_ID,
				'ics_content' => $ics,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['skipped_other_source'] );
		$this->assertSame( 'publish', get_post_status( $foreign ) );
		$this->assertSame( '', get_post_meta( $foreign, RetractMissingEventsAbilities::MISSING_RUN_COUNT_META, true ) );
	}

	private function seedFlowTable( string $source_url, ?int $venue_id = null ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'datamachine_flows';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- test-only table bootstrap.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( $exists !== $table ) {
			$charset = $wpdb->get_charset_collate();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- test-only table bootstrap.
			$wpdb->query(
				"CREATE TABLE {$table} (
					flow_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
					flow_config LONGTEXT NULL,
					PRIMARY KEY (flow_id)
				) {$charset}"
			);
		}

		$handler_config = array( 'source_url' => $source_url );
		if ( null !== $venue_id ) {
			$handler_config['venue'] = (string) $venue_id;
		}

		$config = array(
			self::FLOW_ID . '_step_fetch' => array(
				'flow_step_id'    => self::FLOW_ID . '_step_fetch',
				'step_type'       => 'event_import',
				'enabled'         => true,
				'handler_slugs'   => array( 'universal_web_scraper' ),
				'handler_configs' => array(
					'universal_web_scraper' => $handler_config,
				),
			),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture.
		$wpdb->replace(
			$table,
			array(
				'flow_id'     => self::FLOW_ID,
				'flow_config' => wp_json_encode( $config ),
			),
			array( '%d', '%s' )
		);
	}

	/**
	 * Build a synthetic ICS calendar string. No network: callers pass it via
	 * the ics_content input override.
	 *
	 * @param array<array{0:string,1:string}> $events Title + strtotime-style when.
	 */
	private function buildIcs( array $events ): string {
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Retract Test//EN',
			'X-WR-TIMEZONE:America/New_York',
		);

		foreach ( $events as $i => [ $title, $when ] ) {
			$dt = $this->localDateTime( $when );

			$lines = array_merge(
				$lines,
				array(
					'BEGIN:VEVENT',
					'UID:retract-test-' . $i . '@example.com',
					'DTSTART;TZID=America/New_York:' . $dt->format( 'Ymd\THis' ),
					'SUMMARY:' . $title,
					'LOCATION:Retract Test Venue',
					'END:VEVENT',
				)
			);
		}

		$lines[] = 'END:VCALENDAR';

		return implode( "\r\n", $lines );
	}

	private function localDateTime( string $when ): \DateTimeImmutable {
		$date = new \DateTimeImmutable( $when, new \DateTimeZone( 'America/New_York' ) );

		if ( preg_match( '/(\d{2}:\d{2})\s*$/', $when, $matches ) ) {
			$parts = explode( ':', $matches[1] );
			$date  = $date->setTime( (int) $parts[0], (int) $parts[1] );
		}

		return $date;
	}

	private function seedFlowEvent( string $title, string $when, int $venue_id, bool $hand_edited = false ): int {
		$post_date = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );

		$dt    = $this->localDateTime( $when );
		$attrs = array(
			'title'     => $title,
			'startDate' => $dt->format( 'Y-m-d' ),
			'startTime' => '00:00' !== $dt->format( 'H:i' ) ? $dt->format( 'H:i' ) : '',
			'venue'     => $this->venueName( $venue_id ),
		);
		$block = '<!-- wp:data-machine-events/event-details ' . wp_json_encode( $attrs ) . ' /-->';

		$post_id = self::factory()->post->create(
			array(
				'post_type'     => Event_Post_Type::POST_TYPE,
				'post_status'   => 'publish',
				'post_title'    => $title,
				'post_content'  => $block,
				'post_date'     => $post_date,
				'post_date_gmt' => $post_date,
			)
		);

		if ( $hand_edited ) {
			// wp_update_post bumps post_modified to now while post_date_gmt
			// stays 30 days back: the hand-edit signature.
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $block,
				)
			);
		}

		wp_set_object_terms( $post_id, array( $venue_id ), 'venue' );
		update_post_meta( $post_id, '_datamachine_post_flow_id', (string) self::FLOW_ID );
		update_post_meta( $post_id, '_datamachine_event_source', 'universal_web_scraper' );

		EventDatesTable::upsert( $post_id, $dt->format( 'Y-m-d H:i:s' ), null, 'publish' );

		return (int) $post_id;
	}

	private function venueName( int $venue_id ): string {
		$term = get_term( $venue_id, 'venue' );

		return ( $term instanceof \WP_Term ) ? $term->name : '';
	}

	private function createVenue( string $name ): int {
		$venue = wp_insert_term( $name . ' ' . uniqid(), 'venue' );
		$this->assertNotWPError( $venue );

		return (int) $venue['term_id'];
	}

	/**
	 * Extract event-details block attributes from a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array Block attributes.
	 */
	private function extractBlockAttributes( int $post_id ): array {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return array();
		}

		foreach ( parse_blocks( (string) $post->post_content ) as $block ) {
			if ( 'data-machine-events/event-details' === $block['blockName'] ) {
				return is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			}
		}

		return array();
	}
}
