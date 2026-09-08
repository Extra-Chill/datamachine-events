<?php
/**
 * Retract Missing Events Abilities
 *
 * Retracts published future events owned by an ICS-driven flow that the feed
 * no longer contains (series ended/shortened via RRULE UNTIL, EXDATE added,
 * or a VEVENT deleted). This is the delete half of the recurring-series gap
 * closed for edits by #796/#797. See issue #799.
 *
 * Deterministic and dry-run by default: no cron, no automatic scheduling.
 * A run fetches the feed through the SAME extraction path imports use,
 * builds a generous presence index, and tracks consecutive misses per post
 * via `_datamachine_missing_run_count` / `_datamachine_missing_since`.
 * Only posts missing across N consecutive successful runs are eligible,
 * and only `apply` unpublishes them (draft + EventCancelled, never delete).
 *
 * Guards:
 *  - ICS-only: flow source_url must look like an .ics feed, or the flow's
 *    posts must carry an .ics `_datamachine_source_url`.
 *  - A failed or empty fetch aborts the whole run without touching counters.
 *  - Low source coverage (feed accounts for less than half of the published
 *    upcoming flow events) is treated as a truncated/broken fetch and aborts.
 *  - Hand-edited posts (modified well after creation) are skipped.
 *  - Posts from another source (`_datamachine_event_source` set and not
 *    universal_web_scraper) are skipped.
 *
 * @package DataMachineEvents\Abilities
 * @since 0.61.2
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Retraction\MissingOccurrenceResolver;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\IcsExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\UniversalWebScraper;
use DataMachineEvents\Steps\Upsert\Events\EventUpsert;
use DataMachineEvents\Utilities\EventIdentifierGenerator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RetractMissingEventsAbilities {

	public const ABILITY_ID                    = 'data-machine-events/retract-missing-events';
	public const DEFAULT_MIN_CONSECUTIVE_MISSES = 2;
	public const DEFAULT_LIMIT                 = 200;
	public const MIN_SOURCE_COVERAGE_RATIO     = 0.5;

	public const MISSING_RUN_COUNT_META = '_datamachine_missing_run_count';
	public const MISSING_SINCE_META     = '_datamachine_missing_since';
	public const RETRACTED_AT_META      = '_datamachine_retracted_at';
	public const RETRACTED_BY_FLOW_META = '_datamachine_retracted_by_flow';
	public const RETRACT_REASON         = 'missing_from_ics_feed';

	private const BLOCK_NAME = 'data-machine-events/event-details';

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbility();
			self::$registered = true;
		}
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				self::ABILITY_ID,
				array(
					'label'       => __( 'Retract Missing ICS Events', 'data-machine-events' ),
					'description' => __( 'Draft published future events an ICS feed no longer lists (dry run by default)', 'data-machine-events' ),
					'category'    => 'datamachine-events-events',
					'input_schema' => array(
						'type'       => 'object',
						'required'   => array( 'flow_id' ),
						'properties' => array(
							'flow_id'               => array(
								'type'        => 'integer',
								'description' => 'Data Machine flow ID that imported the events',
							),
							'apply'                 => array(
								'type'        => 'boolean',
								'description' => 'Unpublish eligible events (draft + EventCancelled). Default false: dry run.',
							),
							'min_consecutive_misses' => array(
								'type'        => 'integer',
								'description' => 'Consecutive successful runs an event must be absent before eligibility. Default 2.',
							),
							'limit'                 => array(
								'type'        => 'integer',
								'description' => 'Maximum published upcoming flow events to scan. Default 200.',
							),
							'ics_content'           => array(
								'type'        => 'string',
								'description' => 'Override: parse this raw ICS content instead of fetching the flow source URL.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'flow_id'               => array( 'type' => 'integer' ),
							'apply'                 => array( 'type' => 'boolean' ),
							'scanned'               => array( 'type' => 'integer' ),
							'present'               => array( 'type' => 'integer' ),
							'missing_pending'       => array( 'type' => 'integer' ),
							'eligible'              => array( 'type' => 'integer' ),
							'retracted'             => array( 'type' => 'integer' ),
							'skipped_hand_edited'   => array( 'type' => 'integer' ),
							'skipped_other_source'  => array( 'type' => 'integer' ),
							'items'                 => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'post_id'       => array( 'type' => 'integer' ),
										'title'         => array( 'type' => 'string' ),
										'start_datetime' => array( 'type' => 'string' ),
										'miss_count'    => array( 'type' => 'integer' ),
										'action'        => array( 'type' => 'string' ),
									),
								),
							),
							'message'               => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'executeRetractMissingEvents' ),
					'permission_callback' => AbilityPermissions::canWrite(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		add_action( 'wp_abilities_api_init', $register_callback );
	}

	/**
	 * Execute the retract-missing scan for one flow.
	 *
	 * @param array $input Input parameters.
	 * @return array|\WP_Error Structured report.
	 */
	public function executeRetractMissingEvents( array $input ): array|\WP_Error {
		$flow_id                = absint( $input['flow_id'] ?? 0 );
		$apply                  = ! empty( $input['apply'] );
		$min_consecutive_misses = max( 1, (int) ( $input['min_consecutive_misses'] ?? self::DEFAULT_MIN_CONSECUTIVE_MISSES ) );
		$limit                  = max( 1, (int) ( $input['limit'] ?? self::DEFAULT_LIMIT ) );
		$ics_content            = trim( (string) ( $input['ics_content'] ?? '' ) );

		if ( $flow_id <= 0 ) {
			return new \WP_Error( 'missing_flow_id', 'flow_id parameter is required', array( 'status' => 400 ) );
		}

		$source = $this->loadRetractionSource( $flow_id );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$feed_events = $this->fetchFeedEvents( $source['source_url'], $source['venue_term_id'], $ics_content );
		if ( is_wp_error( $feed_events ) ) {
			return $feed_events;
		}

		$candidates = $this->queryFlowCandidates( $flow_id, $limit );
		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}

		$feed_index = MissingOccurrenceResolver::buildFeedIndex( $feed_events );

		// Guard rail: treat a feed that accounts for far less than what the
		// database holds as truncated or broken. A confirmed-absence decision
		// must never be made against a partial listing.
		if ( MissingOccurrenceResolver::isLowCoverage( count( $feed_index['slots'] ), count( $candidates ), self::MIN_SOURCE_COVERAGE_RATIO ) ) {
			return new \WP_Error(
				'retract_low_source_coverage',
				sprintf(
					'Feed extraction lists %1$d upcoming occurrence(s) but the flow holds %2$d published upcoming event(s); below the %3$s coverage threshold the fetch is treated as truncated. Counters untouched.',
					count( $feed_index['slots'] ),
					count( $candidates ),
					(string) self::MIN_SOURCE_COVERAGE_RATIO
				),
				array( 'status' => 409 )
			);
		}

		$report = array(
			'flow_id'              => $flow_id,
			'apply'                => $apply,
			'scanned'              => count( $candidates ),
			'present'              => 0,
			'missing_pending'      => 0,
			'eligible'             => 0,
			'retracted'            => 0,
			'skipped_hand_edited'  => 0,
			'skipped_other_source' => 0,
			'items'                => array(),
			'message'              => '',
		);

		foreach ( $candidates as $candidate ) {
			$item = array(
				'post_id'        => $candidate['post_id'],
				'title'          => $candidate['title'],
				'start_datetime' => $candidate['start'],
				'miss_count'     => 0,
				'action'         => '',
			);

			if ( '' !== $candidate['source_name'] && 'universal_web_scraper' !== $candidate['source_name'] ) {
				++$report['skipped_other_source'];
				$item['action'] = 'skipped - other source';
				$item['miss_count'] = (int) get_post_meta( $candidate['post_id'], self::MISSING_RUN_COUNT_META, true );
				$report['items'][] = $item;
				continue;
			}

			if ( MissingOccurrenceResolver::isHandEdited( $candidate['modified_gmt'], $candidate['date_gmt'] ) ) {
				++$report['skipped_hand_edited'];
				$item['action'] = 'skipped - hand edited';
				delete_post_meta( $candidate['post_id'], self::MISSING_RUN_COUNT_META );
				delete_post_meta( $candidate['post_id'], self::MISSING_SINCE_META );
				$report['items'][] = $item;
				continue;
			}

			$block_attrs = $this->extractBlockAttributes( $candidate['post_id'] );

			$post_identities = MissingOccurrenceResolver::buildPostIdentities(
				$candidate['source_id'],
				$block_attrs
			);

			$present = MissingOccurrenceResolver::isPresent(
				$post_identities,
				$feed_index,
				$candidate['title'],
				$candidate['start']
			);

			$stored_count = (int) get_post_meta( $candidate['post_id'], self::MISSING_RUN_COUNT_META, true );
			$state        = MissingOccurrenceResolver::nextMissState( $stored_count, $present );

			if ( $state['present'] ) {
				++$report['present'];
				$item['action'] = 'present';
				if ( $stored_count > 0 ) {
					delete_post_meta( $candidate['post_id'], self::MISSING_RUN_COUNT_META );
					delete_post_meta( $candidate['post_id'], self::MISSING_SINCE_META );
				}
				$report['items'][] = $item;
				continue;
			}

			$this->recordMiss( $candidate['post_id'], $state['count'] );
			$item['miss_count'] = $state['count'];

			if ( ! MissingOccurrenceResolver::isEligible( $state['count'], $min_consecutive_misses ) ) {
				++$report['missing_pending'];
				$item['action'] = 'missing - pending';
				$report['items'][] = $item;
				continue;
			}

			++$report['eligible'];

			if ( ! $apply ) {
				$item['action'] = 'eligible - dry run';
				$report['items'][] = $item;
				continue;
			}

			$retracted = $this->retractPost( $candidate['post_id'], $flow_id );
			if ( is_wp_error( $retracted ) ) {
				$item['action'] = 'retraction failed: ' . $retracted->get_error_code();
				$report['items'][] = $item;
				continue;
			}

			++$report['retracted'];
			$item['action'] = 'retracted';
			$report['items'][] = $item;
		}

		$report['message'] = sprintf(
			'%1$s: %2$d scanned, %3$d present, %4$d missing (pending), %5$d eligible, %6$d retracted, %7$d skipped (hand edited), %8$d skipped (other source).',
			$apply ? 'APPLY' : 'DRY RUN',
			$report['scanned'],
			$report['present'],
			$report['missing_pending'],
			$report['eligible'],
			$report['retracted'],
			$report['skipped_hand_edited'],
			$report['skipped_other_source']
		);

		do_action(
			'datamachine_log',
			'info',
			'Retract Missing Events: run complete',
			array(
				'flow_id' => $flow_id,
				'apply'   => $apply,
				'scanned' => $report['scanned'],
				'present' => $report['present'],
				'eligible' => $report['eligible'],
				'retracted' => $report['retracted'],
			)
		);

		return $report;
	}

	/**
	 * Resolve the flow's retraction source: ICS source URL plus the pinned
	 * venue term (for identity parity with the import path).
	 *
	 * A flow qualifies when its universal_web_scraper source_url looks like
	 * an .ics feed, or when any of its posts carries an .ics
	 * `_datamachine_source_url` (persisted evidence of ics_feed extraction).
	 *
	 * @param int $flow_id Flow ID.
	 * @return array{source_url:string,venue_term_id:int}|\WP_Error
	 */
	private function loadRetractionSource( int $flow_id ) {
		$flow_config = $this->loadFlowConfig( $flow_id );

		if ( null === $flow_config ) {
			return new \WP_Error(
				'retract_flow_not_found',
				sprintf( 'Flow %d was not found or has no readable config.', $flow_id ),
				array( 'status' => 404 )
			);
		}

		$step = $this->findEventImportStep( $flow_config );

		if ( null === $step ) {
			return new \WP_Error(
				'retract_no_event_import_step',
				sprintf( 'Flow %d has no enabled event_import step.', $flow_id ),
				array( 'status' => 422 )
			);
		}

		$handler_config = is_array( $step['handler_configs']['universal_web_scraper'] ?? null )
			? $step['handler_configs']['universal_web_scraper']
			: array();

		$handler_slugs = is_array( $step['handler_slugs'] ?? null ) ? $step['handler_slugs'] : array();
		if ( ! in_array( 'universal_web_scraper', $handler_slugs, true ) ) {
			return new \WP_Error(
				'retract_handler_not_supported',
				sprintf(
					'Flow %d event_import step does not use the universal_web_scraper handler; only ICS scraper flows are supported.',
					$flow_id
				),
				array( 'status' => 422 )
			);
		}

		$source_url = trim( (string) ( $handler_config['source_url'] ?? '' ) );
		$venue_term_id = absint( $handler_config['venue'] ?? 0 );

		if ( '' !== $source_url && $this->isIcsUrl( $source_url ) ) {
			return array(
				'source_url'    => $source_url,
				'venue_term_id' => $venue_term_id,
			);
		}

		// Fall back to persisted post evidence: the flow previously produced
		// items extracted from an ICS feed even though the configured source
		// URL no longer advertises it.
		if ( $this->flowHasIcsProvenance( $flow_id ) ) {
			return array(
				'source_url'    => '' !== $source_url ? $source_url : 'ics://provenance',
				'venue_term_id' => $venue_term_id,
			);
		}

		return new \WP_Error(
			'retract_not_ics_flow',
			sprintf(
				'Flow %d is not an ICS feed flow: its universal_web_scraper source_url does not end in .ics and no flow post carries an .ics source URL. Only ICS flows are supported.',
				$flow_id
			),
			array( 'status' => 422 )
		);
	}

	/** Whether a URL points at an ICS feed. */
	private function isIcsUrl( string $url ): bool {
		return (bool) preg_match( '/\.ics($|\?)/i', $url );
	}

	/**
	 * Check persisted posts of a flow for an .ics `_datamachine_source_url`.
	 *
	 * @param int $flow_id Flow ID.
	 * @return bool
	 */
	private function flowHasIcsProvenance( int $flow_id ): bool {
		$query = new \WP_Query(
			array(
				'post_type'      => Event_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => '_datamachine_post_flow_id',
						'value' => (string) $flow_id,
					),
					array(
						'key'     => '_datamachine_source_url',
						'value'   => '\.ics($|\?)',
						'compare' => 'REGEXP',
					),
				),
			)
		);

		return ! empty( $query->posts );
	}

	/**
	 * Fetch and normalize the feed's current occurrences.
	 *
	 * Reuses the same extraction path imports use: UniversalWebScraper for
	 * the live fetch (identity/pagination/horizon semantics identical to the
	 * import run), IcsExtractor directly when raw ICS content is injected.
	 * Both paths receive the flow's pinned venue override so identities are
	 * generated against the same venue string imports used.
	 *
	 * @param string $source_url    Resolved flow source URL.
	 * @param int    $venue_term_id Pinned venue term ID from handler config (0 = none).
	 * @param string $ics_content   Optional raw ICS content override.
	 * @return array|\WP_Error Normalized feed events.
	 */
	private function fetchFeedEvents( string $source_url, int $venue_term_id, string $ics_content ) {
		if ( '' !== $ics_content ) {
			$extractor = new IcsExtractor();
			if ( ! $extractor->canExtract( $ics_content ) ) {
				return new \WP_Error(
					'retract_invalid_ics_content',
					'The provided ics_content override is not parseable ICS content.',
					array( 'status' => 400 )
				);
			}

			$events = $extractor->extract( $ics_content, $source_url );

			if ( $venue_term_id > 0 ) {
				$venue_name = $this->getVenueName( $venue_term_id );
				if ( '' !== $venue_name ) {
					foreach ( $events as &$event ) {
						$event['venue'] = $venue_name;
					}
					unset( $event );
				}
			}

			return $events;
		}

		$config = array(
			'source_url'   => $source_url,
			'flow_step_id' => 'retract_missing_' . wp_generate_uuid4(),
			'flow_id'      => 'direct',
		);

		// Retraction needs the full listing; the production item cap must not
		// silently truncate the inventory being compared against.
		$handler = new UniversalWebScraper();
		$results = $handler->get_fetch_data( 'direct', $config, null );

		if ( empty( $results ) || ! is_array( $results ) ) {
			return new \WP_Error(
				'retract_fetch_failed',
				'Feed fetch returned no results; refusing to retract against an empty or failed fetch. Counters untouched.',
				array( 'status' => 503 )
			);
		}

		$events = array();

		foreach ( $results as $packet ) {
			$entries = $packet->addTo( array() );

			foreach ( $entries as $entry ) {
				$body = is_array( $entry ) ? (string) ( $entry['data']['body'] ?? '' ) : '';
				if ( '' === $body ) {
					continue;
				}

				$payload = json_decode( $body, true );
				$event   = is_array( $payload ) ? ( $payload['event'] ?? null ) : null;

				if ( ! is_array( $event ) ) {
					continue;
				}

				$events[] = $event;
			}
		}

		if ( empty( $events ) ) {
			return new \WP_Error(
				'retract_fetch_empty',
				'Feed fetch produced no structured events; refusing to retract against an empty listing. Counters untouched.',
				array( 'status' => 503 )
			);
		}

		return $events;
	}

	/**
	 * Query published upcoming events owned by the flow.
	 *
	 * @param int $flow_id Flow ID.
	 * @param int $limit   Maximum candidates.
	 * @return array|\WP_Error Candidate rows.
	 */
	private function queryFlowCandidates( int $flow_id, int $limit ) {
		$ability = new EventDateQueryAbilities();
		$result  = $ability->executeQueryEvents(
			array(
				'scope'      => 'upcoming',
				'order'      => 'ASC',
				'status'     => 'publish',
				'per_page'   => $limit,
				'meta_query' => array(
					array(
						'key'     => '_datamachine_post_flow_id',
						'value'   => (string) $flow_id,
						'compare' => '=',
					),
				),
			)
		);

		$posts = is_array( $result['posts'] ?? null ) ? $result['posts'] : array();

		$candidates = array();

		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$dates = EventDatesTable::get( $post->ID );

			if ( ! $dates || '' === $dates->start_datetime ) {
				continue;
			}

			$start = EventIdentifierGenerator::normalizeStartDateTime( $dates->start_datetime );

			if ( '' === $start || ! preg_match( '/^\d{4}-\d{2}-\d{2}/', $start ) ) {
				continue;
			}

			$candidates[] = array(
				'post_id'      => (int) $post->ID,
				'title'        => (string) $post->post_title,
				'start'        => $start,
				'source_id'    => (string) get_post_meta( $post->ID, EventUpsert::SOURCE_ID_META_KEY, true ),
				'source_name'  => (string) get_post_meta( $post->ID, EventUpsert::SOURCE_NAME_META_KEY, true ),
				'modified_gmt' => (string) $post->post_modified_gmt,
				'date_gmt'     => (string) $post->post_date_gmt,
			);
		}

		return $candidates;
	}

	/**
	 * Record one consecutive miss on a post.
	 *
	 * @param int $post_id    Post ID.
	 * @param int $miss_count New miss count.
	 */
	private function recordMiss( int $post_id, int $miss_count ): void {
		update_post_meta( $post_id, self::MISSING_RUN_COUNT_META, $miss_count );

		if ( '' === (string) get_post_meta( $post_id, self::MISSING_SINCE_META, true ) ) {
			update_post_meta( $post_id, self::MISSING_SINCE_META, gmdate( 'Y-m-d H:i:s' ) );
		}
	}

	/**
	 * Retract one post: draft + EventCancelled + audit meta + action + log.
	 *
	 * The events post type is unpublish-only here by policy: the post is
	 * drafted (never deleted, never trashed) and marked EventCancelled so a
	 * restored draft still communicates the source retracted it.
	 *
	 * @param int $post_id Post ID.
	 * @param int $flow_id  Owning flow ID.
	 * @return true|\WP_Error
	 */
	private function retractPost( int $post_id, int $flow_id ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'retract_post_missing', 'Post not found.', array( 'status' => 404 ) );
		}

		$content = $this->withEventStatus( (string) $post->post_content, 'EventCancelled' );
		if ( null === $content ) {
			return new \WP_Error( 'retract_block_missing', 'Post has no event-details block to mark cancelled.', array( 'status' => 422 ) );
		}

		update_post_meta( $post_id, self::RETRACTED_AT_META, gmdate( 'Y-m-d H:i:s' ) );
		update_post_meta( $post_id, self::RETRACTED_BY_FLOW_META, $flow_id );

		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_status'  => 'draft',
				'post_content' => $content,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			delete_post_meta( $post_id, self::RETRACTED_AT_META );
			delete_post_meta( $post_id, self::RETRACTED_BY_FLOW_META );
			return $result;
		}

		do_action( 'datamachine_event_retracted', $post_id, $flow_id, self::RETRACT_REASON );

		do_action(
			'datamachine_log',
			'info',
			'Retract Missing Events: event retracted',
			array(
				'post_id' => $post_id,
				'flow_id' => $flow_id,
				'reason'  => self::RETRACT_REASON,
			)
		);

		return true;
	}

	/**
	 * Set eventStatus in the event-details block attrs of post content.
	 *
	 * @param string $post_content Raw post content.
	 * @param string $status       eventStatus value.
	 * @return string|null Updated content, or null when no block matched.
	 */
	private function withEventStatus( string $post_content, string $status ): ?string {
		$blocks = parse_blocks( $post_content );

		foreach ( $blocks as $index => $block ) {
			if ( self::BLOCK_NAME !== $block['blockName'] ) {
				continue;
			}

			$blocks[ $index ]['attrs']['eventStatus'] = $status;

			return serialize_blocks( $blocks );
		}

		return null;
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

		$blocks = parse_blocks( (string) $post->post_content );

		foreach ( $blocks as $block ) {
			if ( self::BLOCK_NAME === $block['blockName'] ) {
				return is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			}
		}

		return array();
	}

	/**
	 * Resolve the display name of a venue term.
	 *
	 * @param int $venue_term_id Venue term ID.
	 * @return string Venue name or empty string.
	 */
	private function getVenueName( int $venue_term_id ): string {
		$term = get_term( $venue_term_id, 'venue' );

		return ( $term instanceof \WP_Term ) ? $term->name : '';
	}

	/**
	 * Load and decode a flow's config from the Data Machine flows table.
	 *
	 * Same access pattern as StaleListingReconciler: the flows table has no
	 * cache surface and is not part of the object cache domain.
	 *
	 * @param int $flow_id Flow ID.
	 * @return array|null Decoded flow config, or null when unavailable.
	 */
	private function loadFlowConfig( int $flow_id ): ?array {
		global $wpdb;

		$table  = $wpdb->prefix . 'datamachine_flows';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( $exists !== $table ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- flows table has no cache surface and is not part of the object cache domain.
		$raw = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$wpdb->prepare( "SELECT flow_config FROM {$table} WHERE flow_id = %d", $flow_id )
		);

		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Find the enabled event_import step in a decoded flow config.
	 *
	 * @param array $flow_config Decoded flow config.
	 * @return array|null Step config or null.
	 */
	private function findEventImportStep( array $flow_config ): ?array {
		foreach ( $flow_config as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			if ( 'event_import' === ( $step['step_type'] ?? '' ) && ! empty( $step['enabled'] ) ) {
				return $step;
			}
		}

		return null;
	}
}
