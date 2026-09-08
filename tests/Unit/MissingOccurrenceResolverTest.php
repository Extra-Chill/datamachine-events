<?php
/**
 * Missing occurrence resolver tests.
 *
 * Pure matching + miss-counter logic for the retract-missing primitive.
 * No HTTP: presence decisions run against in-memory feed indexes and
 * injected title matchers.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Core\Retraction\MissingOccurrenceResolver;
use WP_UnitTestCase;

class MissingOccurrenceResolverTest extends WP_UnitTestCase {

	public function test_feed_index_collects_md5_and_occurrence_identities(): void {
		$index = MissingOccurrenceResolver::buildFeedIndex(
			array(
				array(
					'title'              => 'Soul Nite',
					'startDate'          => '2026-09-09',
					'startTime'          => '21:00',
					'venue'              => 'Starlight Motor Inn',
					'venueTimezone'      => 'America/New_York',
					'occurrenceIdentity' => 'uid-1::2026-09-09',
				),
				array(
					'title'     => 'No Identity Act',
					'startDate' => '2026-09-10',
				),
				array(
					'title'     => '',
					'startDate' => '2026-09-11',
				),
			)
		);

		$this->assertCount( 2, $index['md5'] );
		$this->assertArrayHasKey( 'uid-1::2026-09-09', $index['occurrence'] );
		$this->assertCount( 2, $index['slots'] );
		$this->assertSame( '2026-09-09', $index['slots'][0]['date'] );
	}

	public function test_present_when_stored_source_id_matches_feed_identity(): void {
		$feed_event = array(
			'title'         => 'Soul Nite',
			'startDate'     => '2026-09-09',
			'startTime'     => '21:00',
			'venue'         => 'Starlight Motor Inn',
			'venueTimezone' => 'America/New_York',
		);
		$index      = MissingOccurrenceResolver::buildFeedIndex( array( $feed_event ) );

		$identity = \DataMachineEvents\Utilities\EventIdentifierGenerator::generate(
			'Soul Nite',
			'2026-09-09',
			'Starlight Motor Inn',
			'21:00',
			'America/New_York'
		);

		$this->assertTrue( MissingOccurrenceResolver::isPresent( array( $identity ), $index, 'Soul Nite', '2026-09-09 21:00' ) );
	}

	public function test_present_when_rederived_identity_matches_despite_missing_stored_id(): void {
		$index = MissingOccurrenceResolver::buildFeedIndex(
			array(
				array(
					'title'         => 'Soul Nite',
					'startDate'     => '2026-09-09',
					'startTime'     => '21:00',
					'venue'         => 'Starlight Motor Inn',
					'venueTimezone' => 'America/New_York',
				),
			)
		);

		// Legacy posts carry no stored source id; the identity is re-derived
		// from block attrs with an empty timezone — the timezone name is not
		// part of the identity, only the local wall clock is.
		$identities = MissingOccurrenceResolver::buildPostIdentities(
			'',
			array(
				'title'     => 'Soul Nite',
				'startDate' => '2026-09-09',
				'startTime' => '21:00',
				'venue'     => 'Starlight Motor Inn',
			)
		);

		$this->assertNotEmpty( $identities );
		$this->assertTrue( MissingOccurrenceResolver::isPresent( $identities, $index, 'Soul Nite', '2026-09-09 21:00' ) );
	}

	public function test_present_via_title_and_date_fallback(): void {
		$index = MissingOccurrenceResolver::buildFeedIndex(
			array(
				array(
					'title'     => 'The Fuzzy Kicks',
					'startDate' => '2026-09-09',
					'startTime' => '20:00',
					'venue'     => 'Elsewhere',
				),
			)
		);

		$matcher = static function ( string $a, string $b ): bool {
			$normalize = static fn( string $t ): string => strtolower( trim( preg_replace( '/^(the|a|an)\s+/i', '', trim( $t ) ) ) );

			return $normalize( $a ) === $normalize( $b );
		};

		$this->assertTrue(
			MissingOccurrenceResolver::hasTitleDateMatch( 'Fuzzy Kicks', '2026-09-09 20:00', $index['slots'], $matcher )
		);
	}

	public function test_cross_midnight_title_match_within_window_keeps_event_present(): void {
		$index = MissingOccurrenceResolver::buildFeedIndex(
			array(
				array(
					'title'     => 'Midnight Band',
					'startDate' => '2026-09-10',
					'startTime' => '00:30',
					'venue'     => 'Elsewhere',
				),
			)
		);

		$matcher = static fn( string $a, string $b ): bool => $a === $b;

		$this->assertTrue(
			MissingOccurrenceResolver::hasTitleDateMatch( 'Midnight Band', '2026-09-09 23:00', $index['slots'], $matcher )
		);
	}

	public function test_same_title_on_different_date_beyond_window_is_missing(): void {
		$index = MissingOccurrenceResolver::buildFeedIndex(
			array(
				array(
					'title'     => 'Two Night Stand Band',
					'startDate' => '2026-09-10',
					'startTime' => '20:00',
					'venue'     => 'Elsewhere',
				),
			)
		);

		$matcher = static fn( string $a, string $b ): bool => $a === $b;

		$this->assertFalse(
			MissingOccurrenceResolver::hasTitleDateMatch( 'Two Night Stand Band', '2026-09-09 20:00', $index['slots'], $matcher )
		);
	}

	public function test_absent_identities_and_titles_are_missing(): void {
		$index = MissingOccurrenceResolver::buildFeedIndex(
			array(
				array(
					'title'     => 'Other Act',
					'startDate' => '2026-09-09',
					'startTime' => '20:00',
					'venue'     => 'Elsewhere',
				),
			)
		);

		$matcher = static fn( string $a, string $b ): bool => false;

		$this->assertFalse( MissingOccurrenceResolver::isPresent( array( 'deadbeef' ), $index, 'Ghost Act', '2026-09-09 21:00' ) );

		// Even a title-matching post is absent when the matcher is wired to
		// the real contract and titles differ; with the false matcher above
		// the fallback cannot rescue it either.
		$this->assertFalse(
			MissingOccurrenceResolver::hasTitleDateMatch( 'Ghost Act', '2026-09-09 21:00', $index['slots'], $matcher )
		);
	}

	public function test_miss_state_bumps_when_missing_and_resets_when_present(): void {
		$missing = MissingOccurrenceResolver::nextMissState( 0, false );
		$this->assertSame( 1, $missing['count'] );
		$this->assertFalse( $missing['present'] );

		$second = MissingOccurrenceResolver::nextMissState( 1, false );
		$this->assertSame( 2, $second['count'] );

		$reset = MissingOccurrenceResolver::nextMissState( 3, true );
		$this->assertSame( 0, $reset['count'] );
		$this->assertTrue( $reset['present'] );
	}

	public function test_eligibility_requires_threshold(): void {
		$this->assertFalse( MissingOccurrenceResolver::isEligible( 1, 2 ) );
		$this->assertTrue( MissingOccurrenceResolver::isEligible( 2, 2 ) );
		$this->assertTrue( MissingOccurrenceResolver::isEligible( 5, 2 ) );
		// A threshold below one is clamped so a single confirmed miss can act.
		$this->assertTrue( MissingOccurrenceResolver::isEligible( 1, 0 ) );
	}

	public function test_hand_edit_heuristic_uses_modified_past_creation(): void {
		$created = '2026-02-11 03:00:00';

		// Freshly imported: modified == created.
		$this->assertFalse( MissingOccurrenceResolver::isHandEdited( $created, $created ) );

		// Feed-driven content update within the epsilon.
		$this->assertFalse( MissingOccurrenceResolver::isHandEdited( gmdate( 'Y-m-d H:i:s', strtotime( $created ) + 60 ), $created ) );

		// Edited hours later.
		$this->assertTrue( MissingOccurrenceResolver::isHandEdited( gmdate( 'Y-m-d H:i:s', strtotime( $created ) + 7200 ), $created ) );

		// Unparseable input never flags.
		$this->assertFalse( MissingOccurrenceResolver::isHandEdited( '', '' ) );
	}

	public function test_low_coverage_guard(): void {
		$this->assertFalse( MissingOccurrenceResolver::isLowCoverage( 2, 3 ) );
		$this->assertTrue( MissingOccurrenceResolver::isLowCoverage( 1, 3 ) );
		// No candidates: nothing to protect, never aborts.
		$this->assertFalse( MissingOccurrenceResolver::isLowCoverage( 0, 0 ) );
	}
}
