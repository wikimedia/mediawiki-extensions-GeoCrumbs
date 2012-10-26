<?php

if ( !defined( 'MEDIAWIKI' ) ) {
	die( 'This file is a MediaWiki extension, it is not a valid entry point' );
}

require_once( __DIR__ . "/../CustomData/CustomData.php" );

# Internationalisation file
$wgExtensionMessagesFiles['GeoCrumbs'] = __DIR__ . '/GeoCrumbs.i18n.php';

$wgExtensionFunctions[] = 'wfSetupGeoCrumbs';

$wgExtensionCredits['parserhook']['GeoCrumbs'] = array(
	'name' => 'GeoCrumbs',
	'url' => 'http://wikivoyage.org/tech/GeoCrumbs-Extension',
	'author' => 'Roland Unger/Hans Musil',
	'descriptionmsg' => 'bct-desc'
);

$wgHooks['LanguageGetMagic'][]	= 'wfGeoCrumbsParserFunction_Magic';


class GeoCrumbs {

	var $mParserOptions = null;

	function GeoCrumbs() {
		$this->mPCache =& ParserCache::singleton();
	}

	function onFuncIsIn( &$parser, $supreg ) {
		# Tribute to Evan!
		$supreg = urldecode( $supreg );

		$nt = Title::newFromText( $supreg, $parser->mTitle->getNamespace() );

		if( !is_object( $nt ) ) {
			return '';
		}

		$linktext = $this->shortTitle( $nt->getText() );
		$id = $nt->getArticleID();
		$lnk = new Linker;
		$link = $lnk->makeKnownLinkObj( $nt, $linktext );

		$sr = array(
			'id' => $id,
			'linktext' => $linktext,
			'link' => $link,
			'namespace' => $nt->getNamespace(),
			'DBkey' => $nt->getDBkey(),
		);

		global $wgCustomData;
		$wgCustomData->setParserData( $parser->mOutput, 'BcIsIn', $sr );

		return '';
	}

	function onParserBeforeTidy( &$parser, &$text ) {
		/*
		 *	Assumes that mRevisionId is only set for primary wiki text when a new revision is saved.
		 * 	We need this in order to save IsIn info appropriately.
		 */
		if( $parser->mRevisionId ) {
			$this->completeImplicitIsIn( $parser->mOutput, $parser->mTitle );
		}

		return true;
	}

	/**
	 * Generates an IsIn from title for subpages.
	 */
	function completeImplicitIsIn( &$ParserOutput, $Title) {
		global $wgCustomData;

		# Don't touch talk pages. Hm, realy?
		if ( $Title->getNamespace() % 2 ) {
			return;
		}

		$sr = $wgCustomData->getParserData( $ParserOutput, 'BcIsIn' );
		if( $sr ) {
			return;
		}

		$trail = explode( '/', $Title->getText() );
		array_pop( $trail );

		if( !$trail ) {
			return;
		}

		$nt = Title::makeTitle( $Title->getNamespace(), implode( '/', $trail ) );

		$linktext = array_pop( $trail );
		$id = $nt->getArticleID();
		$lnk = new Linker;
		$link = $lnk->makeKnownLinkObj( $nt, $linktext );

		$sr = array(
			'id' => $id,
			'linktext' => $linktext,
			'link'		=> $link,
			'namespace' => $nt->getNamespace(),
			'DBkey' => $nt->getDBkey(),
		);

		$wgCustomData->setParserData( $ParserOutput, 'BcIsIn', $sr );
	}

	function shortTitle( $title ) {
		$subparts = explode( '/', $title );
		return array_pop( $subparts );
	}

	/**
	 * Hooked in from hook SkinTemplateOutputPageBeforeExec.
	 */
	function onSkinTemplateOutputPageBeforeExec( &$SkTmpl, &$QuickTmpl ) {

		if( !wfRunHooks( 'GeoCrumbsBeforeOutput', array( &$this, &$SkTmpl, &$QuickTmpl ) ) ) {
			return true;
		}

		global $wgCustomData, $wgOut, $wgTitle;

		# Parser hook onParserBeforeTidy doesn't trigger completition if only previewing.
		$this->completeImplicitIsIn( $wgOut, $wgTitle );

		$sr = $wgCustomData->getPageData( $wgOut, 'BcIsIn' );
		$bc_arr = $this->mkBcTrail( $sr );

		if( !$bc_arr ) {
			return true;
		}

		array_push( $bc_arr, $this->shortTitle( $wgTitle->getText() ) );
		$bc = implode( ' '.wfMsgForContent( 'bct-delimiter' ).' ', $bc_arr );

		$oldsubtitle = $QuickTmpl->data['subtitle'];

		$subtitle = $oldsubtitle ? $bc."<br />\n".$oldsubtitle : $bc;

		$QuickTmpl->set( 'subtitle', $subtitle );

		wfDebug( "onSkinTemplateOutputPageBeforeExec: subtitle = $subtitle\noldsubtitle = $oldsubtitle\n");

		return true;
	}


	function mkBcTrail( $sr ) {

		$bc_arr = array();

		if( !$sr ) {
			return $bc_arr;
		}

		# Avoid cyclic trails.
		$idStack = array();
		# Emergency break.
		$cnt = 20;

		while ( is_array( $sr ) && $cnt-- ) {

			if ( $sr['link'] ) {
				array_unshift( $bc_arr, $sr['link'] );
			} else {
				# Mark redirects with italics.
				$bc_arr[0] = '<i>' . $bc_arr[0] . '</i>';
			}

			if ( array_key_exists( $sr['id'], $idStack) ) {
				$bc_arr[0] = '<strike>' . $bc_arr[0] . '</strike>';
				break;
			}

			$idStack[ $sr['id'] ] = true;
			$sr = $this->getSupRegion( $sr );
		}

		return $bc_arr;
	}

	function getSupRegion( $oldsr ) {
		global $wgCustomData;

		if ( $oldsr['id'] <= 0 ) {
			return null;
		}

		$pc = $this->getParserCache( $oldsr['id'] );
		$sr = $wgCustomData->getParserData( $pc, 'BcIsIn' );

		# We cannot be sure that cached page id is still valid since articles may have moved.
		if ( $sr ) {
			$nt = Title::makeTitle( $sr['namespace'], $sr['DBkey'] );
		} else {
			# Is Title a redirect?
			$ot = Title::makeTitle( $oldsr['namespace'], $oldsr['DBkey'] );
			$art = new Article( $ot );
			$nt = $art->followRedirect();
			# run an empty loop, bail out on double redirects since no title info given.
			$sr = array( 'namespace' => null, 'DBkey' => '', 'link' => '');
		}

		if ( !is_object( $nt ) ) {
			return null;
		}

		$sr['id'] = $nt->getArticleID();

		return $sr;
	}

	function getParserCache( $pageid ) {
		global $wgParserCacheExpireTime, $wgContLang, $wgMemc, $parserMemc;

		if( $pageid <= 0 ) {
			return null;
		}

		# We look for the most usual key.
		$key = wfMemcKey( 'pcache', 'idhash', "$pageid-0!1!1500!!" . $wgContLang->getCode() . "!2" );
		$parserOutput = $parserMemc->get( $key );

		if( !is_object( $parserOutput ) ) {

			$nt = Title::newFromId( $pageid );

			$dbr =& wfGetDB( DB_SLAVE );

			#$tbl_page     = $dbr->tableName( 'page' );
			#$tbl_revision = $dbr->tableName( 'revision' );
			#$tbl_text     = $dbr->tableName( 'text' );

			#$sql = "SELECT old_text, rev_timestamp
			#	FROM $tbl_page
			#	JOIN $tbl_revision ON rev_id=page_latest
			#	JOIN $tbl_text ON $tbl_text.old_id=rev_text_id
			#	WHERE page_id= $pageid";

			$res = $dbr->select(
				array(
					'p' => 'page',
					't' => 'text',
					'r' => 'revision',
				),
				array( 'old_text', 'rev_timestamp' ),
				array( 'page_id' => $pageid ),
				__METHOD__,
				null,
				array(
					'revision' => array( 'JOIN', 'r.rev_id=p.page_latest' ),
					'text' => array( 'JOIN', 't.old_id=r.rev_text_id' ),
				)
			);

			#$res = $dbr->query( $sql, __METHOD__ );
			$row = $dbr->fetchObject( $res );
			$text = $row->old_text;
			$ts = $row->rev_timestamp;
			$dbr->freeResult( $res );

			$parserOutput = $this->parseBc( $text, $nt );

			$now = wfTimestampNow();
			$parserOutput->setCacheTime( $now );

			// Save the timestamp so that we don't have to load the revision row on views.
			$parserOutput->mTimestamp = wfTimestamp( TS_MW, $ts );

			if ( $parserOutput->containsOldMagic() ) {
				$expire = 3600; # 1 hour
			} else {
				$expire = $wgParserCacheExpireTime;
			}

			$parserMemc->set( $key, $parserOutput, $expire );
		}

		return $parserOutput;
	}

	function parseBc( $text, $Title ) {
		global $wgParser;
		$parserOutput = $wgParser->parse( $text, $Title, $this->getParserOptions() );
		$this->completeImplicitIsIn( $parserOutput, $Title );

		return $parserOutput;
	}

	function getParserOptions() {
		if( !$this->mParserOptions ) {
			$this->mParserOptions = new ParserOptions;
		}

		return $this->mParserOptions;
	}

	#
	# Only for debuging.
	#
	function ParserAfterStrip( &$parser, &$text, &$strip_state) {
		global $wgCustomData;
		global $wgTitle;

		return true;
	}


}


function wfSetupGeoCrumbs() {
	global $wgParser, $wgHooks;
	global $wgGeoCrumbs;

	$wgGeoCrumbs = new GeoCrumbs;

	$wgParser->setFunctionHook( 'isin', array( &$wgGeoCrumbs, 'onFuncIsIn' ) );
	$wgHooks['ParserBeforeTidy'][] = array( &$wgGeoCrumbs, 'onParserBeforeTidy' );
	$wgHooks['SkinTemplateOutputPageBeforeExec'][] = array( &$wgGeoCrumbs, 'onSkinTemplateOutputPageBeforeExec' );
}


function wfGeoCrumbsParserFunction_Magic( &$magicWords, $langCode ) {
	$magicWords['isin'] = array( 0, 'isin' );
	return true;
}

?>
