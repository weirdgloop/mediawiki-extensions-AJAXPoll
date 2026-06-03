<?php

use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;

/**
 * AJAX Poll class
 * Created by Dariusz Siedlecki, based on the work by Eric David.
 * Licensed under the GFDL.
 *
 * @file
 * @ingroup Extensions
 * @author Dariusz Siedlecki <datrio@gmail.com>
 * @author Jack Phoenix
 * @author Thomas Gries
 * @maintainer Thomas Gries
 * @link https://www.mediawiki.org/wiki/Extension:AJAX_Poll Documentation
 */
class AJAXPoll {

	/**
	 * Register <poll> tag with the parser
	 *
	 * @param Parser $parser A parser instance, not necessarily $wgParser
	 */
	public static function onParserFirstCallInit( $parser ) {
		$parser->setHook( 'poll', [ __CLASS__, 'render' ] );
	}

	/**
	 * The callback function for converting the input text to HTML output
	 *
	 * @param string $input
	 * @param array $args
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @return string
	 */
	public static function render( $input, $args, Parser $parser, $frame ) {
		$parser->addTrackingCategory( 'ajaxpoll-tracking-category' );
		$parser->getOutput()->addModules( [ 'ext.ajaxpoll' ] );

		// ID of the poll
		if ( isset( $args['id'] ) ) {
			$id = $args['id'];
		} else {
			$id = strtoupper( md5( $input ) );
		}

		// get the input
		$input = $parser->recursiveTagParse( $input, $frame );
		$input = trim( strip_tags( $input ) );
		$lines = explode( "\n", trim( $input ) );

		switch ( $lines[0] ) {
			case 'STATS':
				$ret = self::buildStats();
				break;
			default:
				$ret = Html::rawElement( 'div',
					[
						'id' => 'ajaxpoll-container-' . $id
					],
					self::buildHTML( $id, $lines )
				);
				break;
		}

		return $ret;
	}

	private static function buildStats() {
		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );

		$tab = $dbr->selectRow(
			'ajaxpoll_vote',
			[
				'votes' => 'COUNT(*)',
				'polls' => 'COUNT(DISTINCT poll_id)',
				'actors' => 'COUNT(DISTINCT poll_actor)',
				'timediff' => 'TIMEDIFF(NOW(), MAX(poll_date))'
			],
			[],
			__METHOD__
		);

		$clock = explode( ':', $tab->timediff );

		if ( $clock[0] == '00' && $clock[1] == '00' ) {
			$x = $clock[2];
			$y = 'second';
		} elseif ( $clock[0] == '00' ) {
			$x = $clock[1];
			$y = 'minute';
		} else {
			if ( $clock[0] < 24 ) {
				$x = $clock[0];
				$y = 'hour';
			} else {
				// WGL - Typoed as $hr since AJAXPoll's initial commit.
				$x = floor( $clock[0] / 24 );
				$y = 'day';
			}
		}

		$clockago = $x . ' ' . $y . ( $x > 1 ? 's' : '' );

		$tab2 = $dbr->selectRow(
			'ajaxpoll_vote',
			[ 'votes' => 'COUNT(*)' ],
			[ 'DATE_SUB(CURDATE(), INTERVAL 2 DAY) <= poll_date' ],
			__METHOD__
		);

		return "There are {$tab->polls} polls and {$tab->votes} votes given by {$tab->actors} different people.<br />
The last vote has been given $clockago ago.<br/>
During the last 48 hours, {$tab2->votes} votes have been given.";
	}

	private static function escapeContent( $string ) {
		return htmlspecialchars( Sanitizer::decodeCharReferences( $string ), ENT_QUOTES );
	}

	private static function buildHTML( $id, $lines = '' ) {
		global $wgTitle, $wgLang;

		$services = MediaWikiServices::getInstance();
		$dbr = $services->getDBLoadBalancer()->getConnection( DB_REPLICA );

		$row = $dbr->selectRow(
			'ajaxpoll_info',
			[ 'poll_txt', 'poll_date' ],
			[ 'poll_id' => $id ],
			__METHOD__
		);

		if ( empty( $lines ) && $row !== false ) {
			$lines = explode( "\n", trim( $row->poll_txt ) );
		}

		$start_date = ( $row !== false) ? $row->poll_date : 0;

		$q = $dbr->select(
			'ajaxpoll_vote',
			[ 'poll_answer', 'count' => 'COUNT(*)' ],
			[ 'poll_id' => $id ],
			__METHOD__,
			[ 'GROUP BY' => 'poll_answer' ]
		);

		$poll_result = [];

		if ( $row !== false ) {
			foreach ( $q as $row ) {
				$poll_result[$row->poll_answer] = $row->count;
			}
		}

		$amountOfVotes = array_sum( $poll_result );

		$ret = '';
		if ( is_object( $wgTitle ) ) {
			$ret = Html::openElement( 'div',
				[
					'id' => 'ajaxpoll-id-' . $id,
					'class' => 'ajaxpoll'
				]
			);

			$ret .= Html::element( 'div',
				[
					'id' => 'ajaxpoll-ajax-' . $id,
					'class' => 'ajaxpoll-ajax',
					'style' => 'display:none',
				],
				'Voting is disabled.'
			);

			$ret .= Html::rawElement( 'div',
				[ 'class' => 'ajaxpoll-question' ],
				self::escapeContent( $lines[0] )
			);

			$ret .= Html::rawElement( 'form',
				[
					'id' => 'ajaxpoll-answer-id-' . $id
				],
			);

			$linesCount = count( $lines );
			for ( $i = 1; $i < $linesCount; $i++ ) {

				// answers are counted from 1 ... n
				// last answer is pseudo-answer for "I want to revoke vote"
				// and becomes answer number 0

				$answer = $i;
				$xid = $id . '-' . $answer;

				if ( ( $amountOfVotes !== 0 ) && ( isset( $poll_result[$i + 1] ) ) ) {
					$pollResult = $poll_result[$i + 1];
					$percent = round( $pollResult * 100 / $amountOfVotes, 2 );
				} else {
					$pollResult = 0;
					$percent = 0;
				}

				$border = ( $percent == 0 ) ? ' border:0;' : '';

				$resultBar = Html::rawElement( 'div',
					[
						'class' => 'ajaxpoll-answer-vote'
					],
					Html::rawElement( 'span',
						[
							'title' => wfMessage( 'ajaxpoll-percent-votes' )->numParams( $percent )->escaped()
						],
						self::escapeContent( $pollResult )
					) .
					Html::element( 'div',
						[
							'style' => 'width:' . $percent . '%;' . $border
						]
					)
				);

				$ret .= Html::rawElement( 'div',
					[
						'id' => 'ajaxpoll-answer-' . $xid,
						'class' => 'ajaxpoll-answer',
						'poll' => $id,
						'answer' => $answer
					],
					Html::rawElement( 'div',
						[
							'class' => 'ajaxpoll-answer-name'
						],
						Html::rawElement( 'label',
							[
								'for' => 'ajaxpoll-post-answer-' . $xid,
								'id' => 'ajaxpoll-label-disabled',
								'title' => 'Voting is disabled.'
							],
							Html::element( 'input',
								[
									'disabled' => 'disabled',
									'type' => 'radio',
									'id' => 'ajaxpoll-post-answer-' . $xid,
									'name' => 'ajaxpoll-post-answer-' . $id,
									'value' => $answer
								]
							) .
							self::escapeContent( $lines[$i] )
						)
					) .
					$resultBar
				);
			}

			$ret .= Xml::closeElement( 'form' );

			// Display information about the poll (creation date, amount of votes)
			$pollSummary = wfMessage(
				'ajaxpoll-info',
				$amountOfVotes, // amount of votes
				$wgLang->timeanddate( wfTimestamp( TS_MW, $start_date ), true /* adjust? */ )
			)->text();

			$ret .= Html::rawElement( 'div',
				[
					'id' => 'ajaxpoll-info-' . $id,
					'class' => 'ajaxpoll-info'
				],
				self::escapeContent( $pollSummary )
			);

			$ret .= Html::closeElement( 'div' ) .
				Html::element( 'br' );
		}

		return $ret;
	}

	/**
	 * Adds the two new required database tables into the database when the
	 * end-user (sysadmin) runs /maintenance/update.php
	 * (the core database updater script) and performs other DB updates, such as
	 * the renaming of tables, if upgrading from an older version of this extension.
	 *
	 * @param DatabaseUpdater $updater
	 */
	public static function onLoadExtensionSchemaUpdates( $updater ) {
		$db = $updater->getDB();

		$patchPath = __DIR__ . '/../sql/';
		if ( $db->getType() == 'postgres' ) {
			$patchPath .= 'postgres/';
		}

		if ( $db->tableExists( 'poll_info' ) ) {
			# poll_info.poll_title field was dropped in AJAXPoll version 1.72
			$updater->dropExtensionField(
				'poll_info',
				'poll_title',
				$patchPath . 'drop-field--poll_info-poll_title.sql'
			);
			$updater->addExtensionTable(
				'ajaxpoll_info',
				$patchPath . 'rename-table--poll_info.sql'
			);
		} else {
			$updater->addExtensionTable(
				'ajaxpoll_info',
				$patchPath . 'create-table--ajaxpoll_info.sql'
			);
		}

		if ( $db->tableExists( 'ajaxpoll_info' ) ) {
			$updater->addExtensionField(
				'ajaxpoll_info',
				'poll_show_results_before_voting',
				$patchPath . 'add-field--ajaxpoll_info-poll_show_results_before_voting.sql'
			);
		}

		if ( $db->tableExists( 'poll_vote' ) ) {
			$updater->addExtensionTable(
				'poll_vote',
				$patchPath . 'rename-table--poll_vote.sql'
			);
		} else {
			$updater->addExtensionTable(
				'ajaxpoll_vote',
				$patchPath . 'create-table--ajaxpoll_vote.sql'
			);
		}

		// Actor support

		// 1) add new actor column
		$updater->addExtensionField(
			'ajaxpoll_vote',
			'poll_actor',
			$patchPath . 'add-field-ajaxpoll_vote-poll_actor.sql'
		);

		// 2) do magic
		// This includes, but is not limited to, changing the PRIMARY KEY,
		// adding a new, UNIQUE INDEX on a new AUTO_INCREMENT field (which the
		// script also creates) and, of course, finally the new column is populated
		// with data.
		// PITFALL WARNING! Do NOT change this to $updater->runMaintenance,
		// THEY ARE NOT THE SAME THING and this MUST be using addExtensionUpdate
		// instead for the code to work as desired!
		// HT Skizzerz
		$updater->addExtensionUpdate( [
			'runMaintenance',
			'MigrateOldAJAXPollUserColumnsToActor',
			'../maintenance/migrateOldAJAXPollUserColumnsToActor.php'
		] );

		// 3) drop the now unused column
		$updater->dropExtensionField(
			'ajaxpoll_vote',
			'poll_user',
			$patchPath . 'drop-field-poll_user-ajaxpoll_vote.sql'
		);
	}
}
