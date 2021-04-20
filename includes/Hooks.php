<?php

namespace MediaWiki\Extension\GeoCrumbs;

use Html;
use MediaWiki\MediaWikiServices;
use OutputPage;
use Parser;
use ParserOutput;
use Title;
use User;

class Hooks {

	/**
	 * @param Parser $parser
	 * @return bool
	 */
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setFunctionHook( 'isin', [ self::class, 'onFuncIsIn' ] );
		return true;
	}

	/**
	 * @param Parser $parser
	 * @param string $article
	 * @return string
	 */
	public static function onFuncIsIn( Parser $parser, $article ) {
		// Tribute to Evan!
		$article = urldecode( $article );

		$title = Title::newFromText( $article, $parser->getTitle()->getNamespace() );
		if ( $title ) {
			$article = [ 'id' => $title->getArticleID() ];
			$parser->getOutput()->setExtensionData( 'GeoCrumbIsIn', $article );
		}

		return '';
	}

	/**
	 * Assumes that mRevisionId is only set for primary wiki text when a new revision is saved.
	 * We need this in order to save IsIn info appropriately.
	 * We could add this at onSkinTemplateOutputPageBeforeExec too, but then it won't be in
	 * ParserCache, available for other articles.
	 *
	 * @param Parser $parser
	 * @param string &$text
	 * @return bool
	 */
	public static function onParserAfterTidy( Parser $parser, &$text ) {
		$title = $parser->getTitle();
		if ( $title->isContentPage() ) {
			self::completeImplicitIsIn( $parser->getOutput(), $title );
		}

		return true;
	}

	/**
	 * Generates an IsIn from title for subpages.
	 *
	 * @param ParserOutput $parserOutput
	 * @param Title $title
	 */
	public static function completeImplicitIsIn( $parserOutput, Title $title ) {
		// only do implicitly if none is defined through parser hook
		$existing = $parserOutput->getExtensionData( 'GeoCrumbIsIn' );
		if ( $existing !== null ) {
			return;
		}

		// if we're dealing with a subpage, the parent should be in breadcrumb
		$parent = $title->getBaseTitle();

		if ( !$parent->equals( $title ) ) {
			$article = [ 'id' => $parent->getArticleID() ];
			$parserOutput->setExtensionData( 'GeoCrumbIsIn', $article );
		}
	}

	/**
	 * @param OutputPage $out
	 * @param ParserOutput $parserOutput
	 * @return bool
	 */
	public static function onOutputPageParserOutput( OutputPage $out, ParserOutput $parserOutput ) {
		$breadcrumbs = self::makeTrail( $out->getTitle(), $parserOutput, $out->getUser() );

		if ( count( $breadcrumbs ) > 1 ) {
			$breadcrumbs = implode( wfMessage( 'geocrumbs-delimiter' )->inContentLanguage()->text(),
				$breadcrumbs );
			$out->addSubtitle( $breadcrumbs );
		}

		return true;
	}

	/**
	 * @param Title $title
	 * @param ParserOutput $parserOutput
	 * @param User $user
	 * @return array
	 */
	public static function makeTrail( Title $title, ParserOutput $parserOutput, User $user ) {
		$breadcrumbs = [];
		$idStack = [];

		if ( $title->getArticleID() <= 0 ) {
			return [];
		}

		for ( $cnt = 0; $title && $cnt < 20; $cnt++ ) {
			if ( $parserOutput ) {
				$parserCache = $parserOutput;
				$parserOutput = false;
			} else {
				$parserCache = self::getParserCache( $title->getArticleID(), $user );
			}
			if (
				$parserCache &&
				$parserCache->getPageProperty( 'displaytitle' ) == null &&
				$parserCache->getTitleText() !== ''
			) {
				$linkText = $parserCache->getTitleText();
				$linkTarget = Title::newFromText( $linkText );
				if ( $linkTarget ) {
					$linkText = $linkTarget->getSubpageText();
				}
			} else {
				$linkText = $title->getSubpageText();
			}
			// do not link the final breadcrumb
			if ( $cnt == 0 ) {
				$link = $linkText;
			} else {
				$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();
				$link = $linkRenderer->makeLink( $title, $linkText );
			}

			// mark redirects with italics.
			if ( $title->isRedirect() ) {
				$link = Html::rawElement( 'i', [], $link );
			}
			array_unshift( $breadcrumbs, $link );

			// avoid cyclic trails
			if ( in_array( $title->getArticleID(), $idStack ) ) {
				$breadcrumbs[0] = Html::rawElement( 'strike', [], $breadcrumbs[0] );
				break;
			}
			$idStack[] = $title->getArticleID();

			$title = $parserCache ? self::getParentRegion( $parserCache ) : null;
		}

		return $breadcrumbs;
	}

	/**
	 * @param ParserOutput $parserOutput
	 * @return Title|null
	 */
	public static function getParentRegion( ParserOutput $parserOutput ) {
		$article = $parserOutput->getExtensionData( 'GeoCrumbIsIn' );
		if ( $article ) {
			return Title::newFromID( $article['id'] );
		}
		return null;
	}

	/**
	 * @param int $pageId
	 * @param User $user
	 * @return bool|ParserOutput false if not found
	 */
	public static function getParserCache( $pageId, User $user ) {
		if ( $pageId <= 0 ) {
			return false;
		}

		$page = MediaWikiServices::getInstance()
			->getWikiPageFactory()
			->newFromID( $pageId );
		if ( !$page ) {
			return false;
		}
		return $page->getParserOutput( $page->makeParserOptions( $user ) );
	}
}
