<?php
/**
 * All hooked functions used by VoteNY extension.
 *
 * @file
 * @ingroup Extensions
 */
class VoteHooks {

	/**
	 * Set up the <vote> parser hook.
	 *
	 * @param Parser $parser
	 * @return bool
	 */
	public static function registerParserHook( &$parser ) {
		$parser->setHook( 'vote', array( 'VoteHooks', 'renderVote' ) );
		return true;
	}

	/**
	 * Callback function for registerParserHook.
	 *
	 * @param string $input User-supplied input, unused
	 * @param array $args User-supplied arguments
	 * @param Parser $parser Instance of Parser, unused
	 * @return string HTML
	 */
	public static function renderVote( $input, $args, $parser ) {
		global $wgOut, $wgUser;

		wfProfileIn( __METHOD__ );

		// Disable parser cache (sadly we have to do this, because the caching is
		// messing stuff up; we want to show an up-to-date rating instead of old
		// or totally wrong rating, i.e. another page's rating...)
		$parser->disableCache();

		// Add CSS & JS
		// In order for us to do this *here* instead of having to do this in
		// registerParserHook(), we must've disabled parser cache
		$parser->getOutput()->addModuleStyles( 'ext.voteNY.styles' );
		if ( $wgUser->isAllowed( 'voteny' ) ) {
			$parser->getOutput()->addModules( 'ext.voteNY.scripts' );
		}

		// Define variable - 0 means that we'll get that green voting box by default
		$type = 0;

		// Determine what kind of a voting gadget the user wants: a box or pretty stars?
		if ( preg_match( "/^\s*type\s*=\s*(.*)/mi", $input, $matches ) ) {
			$type = htmlspecialchars( $matches[1] );
		} elseif ( !empty( $args['type'] ) ) {
			$type = intval( $args['type'] );
		}

		$output = null;
		$title = $wgOut->getTitle();
		if ( $title ) {
			$articleID = $title->getArticleID();
			switch( $type ) {
				case 0:
					$vote = new Vote( $articleID );
					break;
				case 1:
					$vote = new VoteStars( $articleID );
					break;
				default:
					$vote = new Vote( $articleID );
			}

			$output = $vote->display();
		}

		wfProfileOut( __METHOD__ );

		return $output;
	}

	/**
	 * For the Renameuser extension.
	 *
	 * @param RenameuserSQL $renameUserSQL
	 * @return bool
	 */
	public static function onUserRename( $renameUserSQL ) {
		$renameUserSQL->tables['Vote'] = array( 'username', 'vote_user_id' );
		return true;
	}

	/**
	 * Assign a value to {{NUMBEROFVOTES}}. First we try memcached and if that
	 * fails, we fetch it directly from the database and cache it for 24 hours.
	 *
	 * @param Parser $parser
	 * @param $cache
	 * @param string $magicWordId Magic word ID
	 * @param int $ret Return value (number of votes)
	 * @return bool
	 */
	public static function assignValueToMagicWord( &$parser, &$cache, &$magicWordId, &$ret ) {
		global $wgMemc;

		if ( $magicWordId == 'NUMBEROFVOTES' ) {
			$key = wfMemcKey( 'vote', 'magic-word' );
			$data = $wgMemc->get( $key );
			if ( $data != '' ) {
				// We have it in cache? Oh goody, let's just use the cached value!
				wfDebugLog(
					'VoteNY',
					'Got the amount of votes from memcached'
				);
				// return value
				$ret = $data;
			} else {
				// Not cached → have to fetch it from the database
				$dbr = wfGetDB( DB_SLAVE );
				$voteCount = (int)$dbr->selectField(
					'Vote',
					'COUNT(*) AS count',
					array(),
					__METHOD__
				);
				wfDebugLog( 'VoteNY', 'Got the amount of votes from DB' );
				// Store the count in cache...
				// (86400 = seconds in a day)
				$wgMemc->set( $key, $voteCount, 86400 );
				// ...and return the value to the user
				$ret = $voteCount;
			}
		} elseif ( $magicWordId == 'NUMBEROFVOTESPAGE' ) {
			$ret = VoteHooks::getNumberOfVotesPage( $parser->getTitle() );
		} elseif ( $magicWordId == 'SCOREPAGE' ) {
            $ret = VoteHooks::getScorePage( $parser->getTitle() );
        }

		return true;
	}

	/**
	 * Main function to get the number of votes for a specific page
	 *
	 * @param Title $title Page to get votes for
	 * @return int Number of votes for the given page
	 */
	public static function getNumberOfVotesPage( Title $title ) {
		global $wgMemc;

		$id = $title->getArticleID();

		$key = wfMemcKey( 'vote', 'magic-word-page', $id );
		$data = $wgMemc->get( $key );

		if ( $data ) {
			return $data;
		} else {
			$dbr = wfGetDB( DB_SLAVE );

			$voteCount = (int)$dbr->selectField(
				'Vote',
				'COUNT(*) AS count',
				array( 'vote_page_id' => $id ),
				__METHOD__
			);

			$wgMemc->set( $key, $voteCount, 3600 );

			return $voteCount;
		}
	}
	
	/**
	 * Main function to get the score for a specific page
	 * @param Title $title: page to get votes for
	 * @return float: score for the given page
	 */
	public static function getScorePage( Title $title ) {
		global $wgMemc;
		$id = $title->getArticleID();

		$key = wfMemcKey( 'vote', 'magic-word-score-page', $id );
		$data = $wgMemc->get( $key );

		if ( $data ) {
			return $data;
		} else {
			$dbr = wfGetDB( DB_SLAVE );

			$score = (float)$dbr->selectField(
					'Vote',
					'AVG(vote_value) AS voteavg',
					array( 'vote_page_id' => $id ),
					__METHOD__
			);

			$wgMemc->set( $key, $score, 3600 );

			return $score;
		}
	}


	/**
	 * Hook for parser function {{NUMBEROFVOTESPAGE:<page>}}
	 *
	 * @param Parser $parser
	 * @param string $pagename Page name
	 * @return int Amount of votes for the given page
	 */
	static function getNumberOfVotesPageParser( $parser, $pagename ) {
		$title = Title::newFromText( $pagename );

		if ( !$title instanceof Title ) {
			$title = $parser->getTitle();
		}

		return VoteHooks::getNumberOfVotesPage( $title );
	}

	/**
	 * Hook for parser function {{SCOREPAGE:<page>}}
	 * @param Parser $parser
	 * @param string $pagename
	 * @return float
	 */
	static function getScorePageParser( $parser, $pagename ) {
			$title = Title::newFromText( $pagename );

			if ( !$title instanceof Title ) {
					$title = $parser->getTitle();
			}

			return VoteHooks::getScorePage( $title );
	}

	/**
	 * Register the magic word ID for {{NUMBEROFVOTES}} and {{NUMBEROFVOTESPAGE}}
	 *
	 * @param array $variableIds Array of pre-existing variable IDs
	 * @return bool
	 */
	public static function registerVariableId( &$variableIds ) {
		$variableIds[] = 'NUMBEROFVOTES';
		$variableIds[] = 'NUMBEROFVOTESPAGE';
		$variableIds[] = 'SCOREPAGE';
		
		return true;
	}

	/**
	 * Hook to setup parser function {{NUMBEROFVOTESPAGE:<page>}}
	 *
	 * @param Parser $parser
	 * @return bool
	 */
	static function setupNumberOfVotesPageParser( &$parser ) {
		$parser->setFunctionHook( 'NUMBEROFVOTESPAGE', 'VoteHooks::getNumberOfVotesPageParser', Parser::SFH_NO_HASH );
		return true;
	}
	
	/**
	 * Hook to setup parser function {{SCOREPAGE:<page>}}
	 * @param Parser $parser
	 * @return boolean
	 */
	static function setupScorePageParser( &$parser ) {
		$parser->setFunctionHook( 'SCOREPAGE', 'VoteHooks::getScorePageParser', SFH_NO_HASH );
		return true;
	}


	/**
	 * Creates the necessary database table when the user runs
	 * maintenance/update.php.
	 *
	 * @param DatabaseUpdater $updater
	 * @return bool
	 */
	public static function addTable( $updater ) {
		$dbt = $updater->getDB()->getType();
		$file = __DIR__ . "/vote.$dbt";
		if ( file_exists( $file ) ) {
			$updater->addExtensionUpdate( array( 'addTable', 'Vote', $file, true ) );
		} else {
			throw new MWException( "VoteNY does not support $dbt." );
		}
		return true;
	}
}
