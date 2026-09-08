<?php
/**
 * WP-CLI command for retracting ICS occurrences missing from the feed
 *
 * Wraps RetractMissingEventsAbilities for CLI consumption. Dry run by
 * default; pass --apply to actually retract (draft + EventCancelled).
 *
 * Usage examples:
 *   wp data-machine-events retract-missing --flow=53
 *   wp data-machine-events retract-missing --flow=53 --format=json
 *   wp data-machine-events retract-missing --flow=53 --min-misses=3 --apply
 *
 * @package DataMachineEvents\Cli
 * @since 0.61.2
 */

namespace DataMachineEvents\Cli;

use DataMachineEvents\Abilities\RetractMissingEventsAbilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RetractMissingCommand {

	private const DEFAULT_LIMIT = 200;

	/**
	 * Retract published future events an ICS feed no longer lists.
	 *
	 * ## OPTIONS
	 *
	 * --flow=<id>
	 * : Required. Data Machine flow ID that imported the events.
	 *
	 * [--apply]
	 * : Actually retract eligible events (draft + EventCancelled). Default is a dry run.
	 *
	 * [--min-misses=<n>]
	 * : Consecutive runs an event must be absent before eligibility. Default: 2.
	 *
	 * [--limit=<n>]
	 * : Maximum published upcoming flow events to scan. Default: 200.
	 *
	 * [--format=<format>]
	 * : Output format (table or json). Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     # Dry run against the Starlight Motor Inn ICS flow
	 *     $ wp data-machine-events retract-missing --flow=53
	 *
	 *     # JSON report for scripting
	 *     $ wp data-machine-events retract-missing --flow=53 --format=json
	 *
	 *     # Apply after reviewing two consecutive dry runs
	 *     $ wp data-machine-events retract-missing --flow=53 --apply
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		$flow_id = absint( $assoc_args['flow'] ?? 0 );

		if ( $flow_id <= 0 ) {
			\WP_CLI::error( '--flow parameter is required' );
		}

		$apply     = isset( $assoc_args['apply'] );
		$min_misses = max( 1, (int) ( $assoc_args['min-misses'] ?? RetractMissingEventsAbilities::DEFAULT_MIN_CONSECUTIVE_MISSES ) );
		$limit     = max( 1, (int) ( $assoc_args['limit'] ?? self::DEFAULT_LIMIT ) );
		$format    = $assoc_args['format'] ?? 'table';

		$abilities = new RetractMissingEventsAbilities();
		$result    = $abilities->executeRetractMissingEvents(
			array(
				'flow_id'                => $flow_id,
				'apply'                  => $apply,
				'min_consecutive_misses' => $min_misses,
				'limit'                  => $limit,
			)
		);

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		if ( 'json' === $format ) {
			\WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		$this->outputTable( $result, $apply );
	}

	/**
	 * Output results as a formatted table.
	 *
	 * @param array $data  Result data from the ability.
	 * @param bool  $apply Whether this was an apply run.
	 */
	private function outputTable( array $data, bool $apply ): void {
		\WP_CLI::log( 'Flow: ' . $data['flow_id'] );
		\WP_CLI::log( 'Mode: ' . ( $apply ? 'APPLY' : 'DRY RUN' ) );
		\WP_CLI::log( '' );
		\WP_CLI::log( 'Scanned: ' . $data['scanned'] );
		\WP_CLI::log( 'Present: ' . $data['present'] );
		\WP_CLI::log( 'Missing (pending): ' . $data['missing_pending'] );
		\WP_CLI::log( 'Eligible: ' . $data['eligible'] );
		\WP_CLI::log( 'Retracted: ' . $data['retracted'] );
		\WP_CLI::log( 'Skipped (hand edited): ' . $data['skipped_hand_edited'] );
		\WP_CLI::log( 'Skipped (other source): ' . $data['skipped_other_source'] );
		\WP_CLI::log( '' );

		$items = $data['items'] ?? array();

		if ( empty( $items ) ) {
			\WP_CLI::log( 'No published upcoming events found for this flow.' );
			return;
		}

		$table_data = array();

		foreach ( $items as $item ) {
			$table_data[] = array(
				'ID'       => $item['post_id'],
				'Title'    => mb_substr( (string) $item['title'], 0, 40 ),
				'Start'    => $item['start_datetime'],
				'Misses'   => $item['miss_count'],
				'Action'   => $item['action'],
			);
		}

		\WP_CLI\Utils\format_items(
			'table',
			$table_data,
			array( 'ID', 'Title', 'Start', 'Misses', 'Action' )
		);

		if ( ! $apply ) {
			\WP_CLI::log( '' );
			\WP_CLI::warning( 'DRY RUN — pass --apply to retract' );
		}
	}
}
