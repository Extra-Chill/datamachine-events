<?php
/**
 * Missing occurrence matching and miss-counter logic for ICS retraction.
 *
 * Pure decision helpers for the retract-missing-events ability. Matching is
 * deliberately generous: a published event counts as "present" when ANY of
 * its identities appears in the feed's identity index, because a false
 * retraction is far worse than a missed one. See issue #799.
 *
 * Identity model (mirrors the import path):
 *  - The feed identity for an occurrence is EventIdentifierGenerator::generate()
 *     over (title, startDate, venue, startTime, venueTimezone) — the same value
 *     persisted as `_datamachine_event_source_id` on upsert.
 *  - ICS occurrence identities (UID + discriminator, see IcsExtractor) are
 *    indexed alongside so stored source ids that carry them still match.
 *  - Fallback matching is title + local calendar date (or the dedup time
 *    window for midnight-crossing slots), the same contract
 *    StaleListingReconciler uses.
 *
 * @package DataMachineEvents\Core\Retraction
 * @since   0.61.2
 */

namespace DataMachineEvents\Core\Retraction;

use DataMachineEvents\Utilities\EventIdentifierGenerator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MissingOccurrenceResolver {

	/**
	 * Same-slot tolerance used by duplicate detection, in seconds. A source
	 * slot whose title matches and whose start sits within this window of the
	 * published event keeps it "present" even across a midnight boundary.
	 */
	public const MATCH_TIME_WINDOW_SECONDS = 7200;

	/**
	 * Build the feed identity index from normalized feed events.
	 *
	 * @param array $feed_events Feed events with title/startDate/startTime/venue/venueTimezone
	 *                           and optional occurrenceIdentity.
	 * @return array{md5:array<string,bool>,occurrence:array<string,bool>,slots:list<array{title:string,start:string,date:string}>}
	 */
	public static function buildFeedIndex( array $feed_events ): array {
		$index = array(
			'md5'         => array(),
			'occurrence'  => array(),
			'slots'       => array(),
		);

		foreach ( $feed_events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$title     = (string) ( $event['title'] ?? '' );
			$startDate = (string) ( $event['startDate'] ?? '' );

			if ( '' === trim( $title ) || '' === trim( $startDate ) ) {
				continue;
			}

			$startTime = (string) ( $event['startTime'] ?? '' );
			$venue     = (string) ( $event['venue'] ?? '' );
			$timezone  = (string) ( $event['venueTimezone'] ?? '' );

			$identity = EventIdentifierGenerator::generate( $title, $startDate, $venue, $startTime, $timezone );
			if ( '' !== $identity ) {
				$index['md5'][ $identity ] = true;
			}

			$occurrence = trim( (string) ( $event['occurrenceIdentity'] ?? '' ) );
			if ( '' !== $occurrence ) {
				$index['occurrence'][ $occurrence ] = true;
			}

			$normalized = EventIdentifierGenerator::normalizeStartDateTime( $startDate, $startTime, $timezone );
			if ( '' === $normalized || ! preg_match( '/^\d{4}-\d{2}-\d{2}/', $normalized ) ) {
				continue;
			}

			$index['slots'][] = array(
				'title' => $title,
				'start' => $normalized,
				'date'  => substr( $normalized, 0, 10 ),
			);
		}

		return $index;
	}

	/**
	 * Build the identity candidates for one published post.
	 *
	 * Combines the stored source id with an identifier re-derived from the
	 * event-details block attributes. Only production posts imported before
	 * source-id provenance existed lack the stored value; re-derivation
	 * covers those.
	 *
	 * @param string $stored_source_id Value of `_datamachine_event_source_id`, may be empty.
	 * @param array  $block_attrs      Event-details block attributes (title/startDate/startTime/venue).
	 * @return list<string> Identity candidates, deduplicated.
	 */
	public static function buildPostIdentities( string $stored_source_id, array $block_attrs ): array {
		$identities = array();

		$stored = trim( $stored_source_id );
		if ( '' !== $stored ) {
			$identities[] = $stored;
		}

		$title     = (string) ( $block_attrs['title'] ?? '' );
		$startDate = (string) ( $block_attrs['startDate'] ?? '' );
		$startTime = (string) ( $block_attrs['startTime'] ?? '' );
		$venue     = (string) ( $block_attrs['venue'] ?? '' );

		if ( '' !== trim( $title ) && '' !== trim( $startDate ) ) {
			$identities[] = EventIdentifierGenerator::generate( $title, $startDate, $venue, $startTime, '' );
		}

		return array_values( array_unique( array_filter( $identities ) ) );
	}

	/**
	 * Whether a published post is present in the feed.
	 *
	 * Generous by design: identity membership (stored or re-derived, md5 or
	 * occurrence identity) OR the title/date fallback keeps the post present.
	 *
	 * @param array  $post_identities Identities from buildPostIdentities().
	 * @param array  $feed_index      Index from buildFeedIndex().
	 * @param string $post_title      Published post title.
	 * @param string $post_local_start Published local start (Y-m-d H:i or Y-m-d).
	 * @return bool True when the post is still listed by the feed.
	 */
	public static function isPresent( array $post_identities, array $feed_index, string $post_title, string $post_local_start ): bool {
		foreach ( $post_identities as $identity ) {
			if ( isset( $feed_index['md5'][ $identity ] ) || isset( $feed_index['occurrence'][ $identity ] ) ) {
				return true;
			}
		}

		return self::hasTitleDateMatch( $post_title, $post_local_start, $feed_index['slots'] ?? array() );
	}

	/**
	 * Title + local date fallback match.
	 *
	 * A feed slot matches when its title matches through the dedup title
	 * contract AND it shares the published event's calendar date, or starts
	 * within the dedup time window (source-side midnight crossing). When
	 * both sides carry only a date, the window match is skipped.
	 *
	 * @param string        $post_title      Published post title.
	 * @param string        $post_local_start Published local start (Y-m-d H:i or Y-m-d).
	 * @param array         $feed_slots      Feed slots from buildFeedIndex().
	 * @param callable|null $title_matcher   Optional fn(string,string):bool matcher; defaults to EventIdentifierGenerator::titlesMatch().
	 * @return bool True when a slot matches.
	 */
	public static function hasTitleDateMatch( string $post_title, string $post_local_start, array $feed_slots, ?callable $title_matcher = null ): bool {
		if ( '' === trim( $post_title ) || '' === trim( $post_local_start ) ) {
			return false;
		}

		$matcher = $title_matcher ?? static fn( string $a, string $b ): bool => EventIdentifierGenerator::titlesMatch( $a, $b );
		$post_date = substr( $post_local_start, 0, 10 );

		foreach ( $feed_slots as $slot ) {
			if ( ! $matcher( $post_title, (string) $slot['title'] ) ) {
				continue;
			}

			if ( (string) $slot['date'] === $post_date ) {
				return true;
			}

			if ( self::isWithinTimeWindow( $post_local_start, (string) $slot['start'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether two datetimes sit within the dedup time window.
	 *
	 * Only reached when calendar dates differ, so both sides must carry a
	 * time component; a date-only side never matches cross-date.
	 *
	 * @param string $datetime1 First datetime (Y-m-d H:i).
	 * @param string $datetime2 Second datetime (Y-m-d H:i).
	 * @return bool
	 */
	public static function isWithinTimeWindow( string $datetime1, string $datetime2 ): bool {
		if ( ! preg_match( '/\d{2}:\d{2}/', $datetime1 ) || ! preg_match( '/\d{2}:\d{2}/', $datetime2 ) ) {
			return false;
		}

		$time1 = strtotime( $datetime1 );
		$time2 = strtotime( $datetime2 );

		if ( false === $time1 || false === $time2 ) {
			return true;
		}

		return abs( $time1 - $time2 ) <= self::MATCH_TIME_WINDOW_SECONDS;
	}

	/**
	 * Next miss-counter state for one candidate.
	 *
	 * Present resets the counter to zero (state to clear); missing bumps it
	 * and starts the missing-since clock when unset.
	 *
	 * @param int  $stored_count Current `_datamachine_missing_run_count` value.
	 * @param bool $present      Whether the feed still lists the event.
	 * @return array{count:int,present:bool}
	 */
	public static function nextMissState( int $stored_count, bool $present ): array {
		if ( $present ) {
			return array(
				'count'   => 0,
				'present' => true,
			);
		}

		return array(
			'count'   => max( 0, $stored_count ) + 1,
			'present' => false,
		);
	}

	/**
	 * Whether a miss count has reached the retraction threshold.
	 *
	 * @param int $miss_count             Current miss count.
	 * @param int $min_consecutive_misses Required consecutive misses (minimum 1).
	 * @return bool
	 */
	public static function isEligible( int $miss_count, int $min_consecutive_misses ): bool {
		return $miss_count >= max( 1, $min_consecutive_misses );
	}

	/**
	 * Hand-edit heuristic.
	 *
	 * No import-timestamp meta exists on the upsert path, so a post whose
	 * modified time moved past its creation time by more than a small
	 * epsilon is treated as hand-edited and skipped. Feed updates cannot
	 * produce this signature for a genuinely missing occurrence: the feed
	 * dropped it, so no further automated content updates arrive.
	 *
	 * @param string $post_modified_gmt Post modified time (UTC mysql).
	 * @param string $post_date_gmt     Post creation time (UTC mysql).
	 * @param int    $epsilon_seconds   Tolerance in seconds.
	 * @return bool True when the post looks hand-edited.
	 */
	public static function isHandEdited( string $post_modified_gmt, string $post_date_gmt, int $epsilon_seconds = 120 ): bool {
		$modified = strtotime( $post_modified_gmt );
		$created  = strtotime( $post_date_gmt );

		if ( false === $modified || false === $created ) {
			return false;
		}

		return ( $modified - $created ) > $epsilon_seconds;
	}

	/**
	 * Coverage guard.
	 *
	 * A healthy ICS extraction must account for at least half of the
	 * published upcoming events being compared against it. Below that the
	 * feed response is presumed truncated or broken and the run aborts
	 * without touching any counters.
	 *
	 * @param int $feed_upcoming_count Feed occurrences inside the comparison window.
	 * @param int $candidate_count     Published upcoming flow events being compared.
	 * @param float $min_ratio         Minimum required feed/candidate ratio.
	 * @return bool True when the run should abort.
	 */
	public static function isLowCoverage( int $feed_upcoming_count, int $candidate_count, float $min_ratio = 0.5 ): bool {
		if ( $candidate_count <= 0 ) {
			return false;
		}

		return $feed_upcoming_count < (int) ceil( $candidate_count * $min_ratio );
	}
}
