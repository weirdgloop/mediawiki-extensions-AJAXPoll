<?php

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
 * @link http://www.mediawiki.org/wiki/Extension:AJAX_Poll Documentation
 */
class AJAXPoll {

	public static function onRegistration() {
		global $wgAjaxExportList;
		$wgAjaxExportList[] = 'AJAXPoll::submitVote';
	}

	/**
	 * Register <poll> tag with the parser
	 *
	 * @param Parser $parser A parser instance, not necessarily $wgParser
	 * @return bool true
	 */
	static function onParserInit( $parser ) {
		$parser->setHook( 'poll', [ __CLASS__, 'AJAXPollRender' ] );

		return true;
	}

	/**
	 * The callback function for converting the input text to HTML output
	 * @param string $input
	 * @param array $args
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @return string
	 */
	static function AJAXPollRender( $input, $args = [], Parser $parser, $frame ) {
		global $wgUser, $wgRequest, $wgUseAjax;

		if ( wfReadOnly() ) {
			return 'This poll is disabled while the wiki is in read-only mode.';
		}

		$parser->getOutput()->updateCacheExpiry( 600 );
		$parser->addTrackingCategory( 'ajaxpoll-tracking-category' );
		$parser->getOutput()->addModules( 'ext.ajaxpoll' );

		if ( $wgUser->getName() == '' ) {
			$userName = $wgRequest->getIP();
		} else {
			$userName = $wgUser->getName();
		}

		// ID of the poll
		if ( isset( $args["id"] ) ) {
			$id = $args["id"];
		} else {
			$id = strtoupper( md5( $input ) );
		}

		// get the input
		$input = $parser->recursiveTagParse( $input, $frame );
		$input = trim( strip_tags( $input ) );
		$lines = explode( "\n", trim( $input ) );

		// compatibility for non-ajax requests - just in case
		if ( !$wgUseAjax ) {
			$responseId = "ajaxpoll-post-id";
			$responseAnswer = "ajaxpoll-post-answer-$id";
			$responseToken = "ajaxPollToken";

			if ( isset( $_POST[$responseId] )
				&& isset( $_POST[$responseAnswer] )
				&& ( $_POST[$responseId] == $id )
				&& isset( $_POST[$responseToken] )
			) {
				self::submitVote( $id, intval( $_POST[$responseAnswer] ), $_POST[$responseToken] );
			}
		}

		$dbw = wfGetDB( DB_MASTER );
		$dbw->startAtomic( __METHOD__ );

		/**
		 * Register poll in the database
		 */

		$existingResultsBeforeVoting = $dbw->selectField(
			'ajaxpoll_info',
			'poll_show_results_before_voting',
			[ 'poll_id' => $id ],
			__METHOD__
		);

		$showResultsBeforeVoting = null;
		if ( array_key_exists( 'show-results-before-voting', $args ) ) {
			if ( strval( $args['show-results-before-voting'] ) !== '0' ) {
				$showResultsBeforeVoting = '1';
			} else {
				$showResultsBeforeVoting = '0';
			}
		}

		// Only register the poll if it doesn't already exist.
		if ( $existingResultsBeforeVoting === false ) {
			$dbw->insert(
				'ajaxpoll_info',
				[
					'poll_id' => $id,
					'poll_show_results_before_voting' => $showResultsBeforeVoting,
					'poll_txt' => $input,
					'poll_date' => wfTimestampNow(),
				],
				__METHOD__
			);
		// Only update the poll settings if the settings need changing.
		} elseif ( $existingResultsBeforeVoting != $showResultsBeforeVoting ) {
			$dbw->update(
				'ajaxpoll_info',
				[
					'poll_show_results_before_voting' => $showResultsBeforeVoting,
				],
				[
					'poll_id' => $id,
				],
				__METHOD__
			);
		}

		$dbw->endAtomic( __METHOD__ );

		switch ( $lines[0] ) {
			case 'STATS':
				$ret = self::buildStats( $id, $userName );
				break;
			default:
				$ret = Html::rawElement( 'div',
					[
						'id' => 'ajaxpoll-container-' . $id
					],
					self::buildHTML( $id, $userName, $lines )
				);
				break;
		}

		return $ret;
	}

	private static function buildStats( $id, $userName ) {
		$dbr = wfGetDB( DB_REPLICA );

		$res = $dbr->select(
			'ajaxpoll_vote',
			[
				'COUNT(*)',
				'COUNT(DISTINCT poll_id)',
				'COUNT(DISTINCT poll_user)',
				'TIMEDIFF(NOW(), MAX(poll_date))'
			],
			[],
			__METHOD__
		);
		$tab = $dbr->fetchRow( $res );

		$clock = explode( ':', $tab[3] );

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
				$x = floor( $hr / 24 );
				$y = 'day';
			}
		}

		$clockago = $x . ' ' . $y . ( $x > 1 ? 's' : '' );

		$res = $dbr->select(
			'ajaxpoll_vote',
			'COUNT(*)',
			[ 'DATE_SUB(CURDATE(), INTERVAL 2 DAY) <= poll_date' ],
			__METHOD__
		);
		$tab2 = $dbr->fetchRow( $res );

		return "There are $tab[1] polls and $tab[0] votes given by $tab[2] different people.<br />
The last vote has been given $clockago ago.<br/>
During the last 48 hours, $tab2[0] votes have been given.";
	}

	public static function submitVote( $id, $answer, $token ) {
		global $wgUser, $wgRequest;

		if ( $wgUser->getName() == '' ) {
			$userName = $wgRequest->getIP();
		} else {
			$userName = $wgUser->getName();
		}

		if ( !$wgUser->matchEditToken( $token, $id ) ) {
			$pollContainerText = 'ajaxpoll-error-csrf-wrong-token';

			return self::buildHTML( $id, $userName, '', $pollContainerText );
		}

		if ( !$wgUser->isAllowed( 'ajaxpoll-vote' ) || $wgUser->isBot() ) {
			return self::buildHTML( $id, $userName );
		}

		$dbw = wfGetDB( DB_MASTER );
		$dbw->startAtomic( __METHOD__ );

		if ( $answer != 0 ) {
			$answer = ++$answer;

			$q = $dbw->select(
				'ajaxpoll_vote',
				'COUNT(*) AS count',
				[
					'poll_id' => $id,
					'poll_user' => $userName
				],
				__METHOD__
			);
			$row = $dbw->fetchRow( $q );

			if ( $row['count'] > 0 ) {
				$updateQuery = $dbw->update(
					'ajaxpoll_vote',
					[
						'poll_answer' => $answer,
						'poll_date' => wfTimestampNow()
					],
					[
						'poll_id' => $id,
						'poll_user' => $userName,
					],
					__METHOD__
				);
				$pollContainerText = ( $updateQuery ) ? 'ajaxpoll-vote-update' : 'ajaxpoll-vote-error';
			} else {

				$insertQuery = $dbw->insert(
					'ajaxpoll_vote',
					[
						'poll_id' => $id,
						'poll_user' => $userName,
						'poll_ip' => $wgRequest->getIP(),
						'poll_answer' => $answer,
						'poll_date' => wfTimestampNow()
					],
					__METHOD__
				);
				$pollContainerText = ( $insertQuery ) ? 'ajaxpoll-vote-add' : 'ajaxpoll-vote-error';
			}
		} else { // revoking a vote

			$deleteQuery = $dbw->delete(
				'ajaxpoll_vote',
				[
					'poll_id' => $id,
					'poll_user' => $userName,
				],
				__METHOD__
			);
			$pollContainerText = ( $deleteQuery ) ? 'ajaxpoll-vote-revoked' : 'ajaxpoll-vote-error';
		}

		$dbw->endAtomic( __METHOD__ );

		return self::buildHTML( $id, $userName, '', $pollContainerText );
	}

	private static function escapeContent( $string ) {
		return htmlspecialchars( Sanitizer::decodeCharReferences( $string ), ENT_QUOTES );
	}

	private static function buildHTML( $id, $userName, $lines = '', $extra_from_ajax = '' ) {
		global $wgTitle, $wgUser, $wgLang, $wgUseAjax;

		$dbr = wfGetDB( DB_REPLICA );

		$q = $dbr->select(
			'ajaxpoll_info',
			[ 'poll_txt', 'poll_date', 'poll_show_results_before_voting' ],
			[ 'poll_id' => $id ],
			__METHOD__
		);
		$row = $dbr->fetchRow( $q );

		if ( empty( $lines ) ) {
			$lines = explode( "\n", trim( $row['poll_txt'] ) );
		}

		if ( $row['poll_show_results_before_voting'] !== null ) {
			$showResultsBeforeVoting = ( $row['poll_show_results_before_voting'] === '1' );
		} else {
			$showResultsBeforeVoting = $wgUser->isAllowed( 'ajaxpoll-view-results-before-vote' );
		}

		$start_date = $row['poll_date'];

		$q = $dbr->select(
			'ajaxpoll_vote',
			[ 'poll_answer', 'count' => 'COUNT(*)' ],
			[ 'poll_id' => $id ],
			__METHOD__,
			[ 'GROUP BY' => 'poll_answer' ]
		);

		$poll_result = [];

		foreach ( $q as $row ) {
			$poll_result[$row->poll_answer] = $row->count;
		}

		$amountOfVotes = array_sum( $poll_result );

		// Did we vote?
		$userVoted = false;

		$q = $dbr->select(
			'ajaxpoll_vote',
			[ 'poll_answer', 'poll_date' ],
			[
				'poll_id' => $id,
				'poll_user' => $userName
			],
			__METHOD__
		);

		$row = $dbr->fetchRow( $q );
		if ( $row ) {
			$ts = wfTimestamp( TS_MW, $row[1] );
			$ourLastVoteDate = wfMessage(
				'ajaxpoll-your-vote',
				$lines[$row[0] - 1],
				$wgLang->timeanddate( $ts, true /* adjust? */ ),
				$wgLang->date( $ts, true /* adjust? */ ),
				$wgLang->time( $ts, true /* adjust? */ )
			)->escaped();
			$userVoted = true;
		}

		if ( is_object( $wgTitle ) ) {
			if ( !empty( $extra_from_ajax ) ) {
				$style = 'display:inline-block';
				$ajaxMessage = wfMessage( $extra_from_ajax )->escaped();
			} else {
				$style = 'display:none';
				$ajaxMessage = '';
			}

			$ret = Html::element( 'script',
				[],
				'var useAjax=' . ( !empty( $wgUseAjax ) ? "true" : "false" ) . ';'
			);

			$ret .= Html::openElement( 'div',
				[
					'id' => 'ajaxpoll-id-' . $id,
					'class' => 'ajaxpoll'
				]
			);

			$ret .= Html::element( 'div',
				[
					'id' => 'ajaxpoll-ajax-' . $id,
					'class' => 'ajaxpoll-ajax',
					'style' => $style
				],
				$ajaxMessage
			);

			$ret .= Html::rawElement( 'div',
				[ 'class' => 'ajaxpoll-question' ],
				self::escapeContent( $lines[0] )
			);

			// Different messages depending whether the user has already voted
			// or has not voted, or is entitled to vote

			$canRevoke = false;

			if ( $wgUser->isAllowed( 'ajaxpoll-vote' ) ) {
				if ( isset( $row[0] ) ) {
					$message = $ourLastVoteDate;
					$canRevoke = true;
					$lines[] = wfMessage( 'ajaxpoll-revoke-vote' )->text();
				} else {
					if ( $showResultsBeforeVoting ) {
						$message = wfMessage( 'ajaxpoll-no-vote' )->text();
					} else {
						$message = wfMessage( 'ajaxpoll-no-vote-results-after-voting' )->text();
					}
				}
			} else {
				$message = wfMessage( 'ajaxpoll-vote-permission' )->text();
			}

			if ( !$wgUser->isAllowed( 'ajaxpoll-view-results' ) ) {
				$message .= "<br/>" . wfMessage( 'ajaxpoll-view-results-permission' )->text();
			} elseif ( !$userVoted
				&& !$wgUser->isAllowed( 'ajaxpoll-view-results-before-vote' )
				&& !$showResultsBeforeVoting
			) {
				if ( $wgUser->isAllowed( 'ajaxpoll-vote' ) ) {
					$message .= "<br/>" . wfMessage( 'ajaxpoll-view-results-before-vote-permission' )->text();
				} else {
					$message .= "<br/>" . wfMessage( 'ajaxpoll-view-results-permission' )->text();
				}
			}

			$ret .= Html::rawElement( 'div',
				[ 'class' => 'ajaxpoll-misc' ],
				$message
			);

			$ret .= Html::rawElement( 'form',
				[
					'method' => 'post',
					'action' => $wgTitle->getLocalURL(),
					'id' => 'ajaxpoll-answer-id-' . $id
				],
				Html::element( 'input',
					[
						'type' => 'hidden',
						'name' => 'ajaxpoll-post-id',
						'value' => $id
					]
				)
			);

			$linesCount = count( $lines );
			for ( $i = 1; $i < $linesCount; $i++ ) {
				$vote = !( $canRevoke && ( $i == $linesCount - 1 ) );

				// answers are counted from 1 ... n
				// last answer is pseudo-answer for "I want to revoke vote"
				// and becomes answer number 0

				$answer = ( $vote ) ? $i : 0;
				$xid = $id . "-" . $answer;

				if ( ( $amountOfVotes !== 0 ) && ( isset( $poll_result[$i + 1] ) ) ) {
					$pollResult = $poll_result[$i + 1];
					$percent = round( $pollResult * 100 / $amountOfVotes, 2 );
				} else {
					$pollResult = 0;
					$percent = 0;
				}

				// If AJAX is enabled, as it is by default in modern MWs, we can
				// just use action=ajax function here for that AJAX-y feel.
				// If not, we'll have to submit the form old-school way...

				$border = ( $percent == 0 ) ? ' border:0;' : '';
				$isOurVote = ( isset( $row[0] ) && ( $row[0] - 1 == $i ) );

				$resultBar = '';

				if ( $wgUser->isAllowed( 'ajaxpoll-view-results' )
					&& ( $showResultsBeforeVoting || ( !$showResultsBeforeVoting && $userVoted ) )
					&& $vote
				) {
					$resultBar = Html::rawElement( 'div',
						[
							'class' => 'ajaxpoll-answer-vote' . ( $isOurVote ? ' ajaxpoll-our-vote' : '' )
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
				}

				if ( $wgUser->isAllowed( 'ajaxpoll-vote' ) ) {
					$ret .= Html::rawElement( 'div',
						[
							'id' => 'ajaxpoll-answer-' . $xid,
							'class' => 'ajaxpoll-answer',
							'poll' => $id,
							'answer' => $answer,
						],
						Html::rawElement( 'div',
							[
								'class' => 'ajaxpoll-answer-name' . ( $vote ? ' ajaxpoll-answer-name-revoke' : '' )
							],
							Html::rawElement( 'label',
								[ 'for' => 'ajaxpoll-post-answer-' . $xid ],
								Html::element( 'input',
									[
										'type' => 'radio',
										'id' => 'ajaxpoll-post-answer-' . $xid,
										'name' => 'ajaxpoll-post-answer-' . $id,
										'value' => $answer,
										'checked' => $isOurVote ? "true" : false,
									]
								) .
								self::escapeContent( $lines[$i] )
							)
						) .
						$resultBar
					);
				} else {

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
									'onclick' => '$("#ajaxpoll-ajax-"' . $xid . '").html("' .
										wfMessage( 'ajaxpoll-vote-permission' )->text() .
										'").css("display","block");'
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
						),
						$resultBar
					);
				}
			}

			$ret .= Html::Hidden( 'ajaxPollToken', $wgUser->getEditToken( $id ) ) .
				Xml::closeElement( 'form' );

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
				self::escapeContent( $pollSummary ) .
				Html::element( 'div',
					[ 'class' => 'ajaxpoll-id-info' ],
					'poll-id ' . $id
				)
			);

			$ret .= Html::closeElement( 'div' ) .
				Html::element( 'br' );
		} else {
			$ret = '';
		}

		return $ret;
	}

	public static function onLoadExtensionSchemaUpdates( $updater = null ) {
		if ( $updater === null ) {
			// no < 1.17 support
		} else {
			// >= 1.17 support
			$db = $updater->getDB();

			$patchPath = __DIR__ . '/../sql/';

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
		}

		return true;
	}
}
