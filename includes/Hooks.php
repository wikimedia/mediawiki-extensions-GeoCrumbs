<?php

namespace MediaWiki\Extension\GeoCrumbs;

use Html;
use MediaWiki\Hook\OutputPageParserOutputHook;
use MediaWiki\Hook\ParserAfterTidyHook;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use OutputPage;
use Parser;
use ParserOutput;
use User;

class Hooks implements
	ParserFirstCallInitHook,
	ParserAfterTidyHook,
	OutputPageParserOutputHook
{

	/**
	 * @param Parser $parser
	 */
	public function onParserFirstCallInit( $parser ) {
		$parser->setFunctionHook( 'isin', [ self::class, 'onFuncIsIn' ] );
	}

	/**
	 * @param Parser $parser
	 * @param string $article
	 * @return string
	 */
	public static function onFuncIsIn( Parser $parser, $article ) {
		// Tribute to Evan!
		$article = urldecode( $article );

		$page = $parser->getPage();
		$title = Title::newFromText( $article, $page ? $page->getNamespace() : NS_MAIN );
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
	 */
	public function onParserAfterTidy( $parser, &$text ) {
		$page = $parser->getPage();
		if ( $page && MediaWikiServices::getInstance()->getNamespaceInfo()->isContent( $page->getNamespace() ) ) {
			// @phan-suppress-next-line PhanTypeMismatchArgumentNullable The cast cannot return null here
			self::completeImplicitIsIn( $parser->getOutput(), Title::castFromPageReference( $page ) );
		}
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
	 */
	public function onOutputPageParserOutput( $out, $parserOutput ): void {
		$breadCrumbs = self::makeTrail( $out->getTitle(), $parserOutput, $out->getUser() );

		if ( count( $breadCrumbs ) > 1 ) {
			$breadCrumbs = Html::rawElement( 'span', [ 'class' => 'ext-geocrumbs-breadcrumbs' ],
				implode( wfMessage( 'geocrumbs-delimiter' )->inContentLanguage()->text(), $breadCrumbs )
			);
			$out->addSubtitle( $breadCrumbs );
		}
	}

	/**
	 * @param Title $title
	 * @param ParserOutput $parserOutput
	 * @param User $user
	 * @return array
	 */
	public static function makeTrail( Title $title, ParserOutput $parserOutput, User $user ): array {
		if ( $title->getArticleID() <= 0 ) {
			return [];
		}

		$breadCrumbs = [];
		$idStack = [];
		$langConverter = MediaWikiServices::getInstance()->getLanguageConverterFactory()
			->getLanguageConverter( $title->getPageLanguage() );

		for ( $i = 0; $title && $i < 20; $i++ ) {
			$linkText = $langConverter->convert( $title->getSubpageText() );

			// do not link the final breadcrumb
			if ( $i === 0 ) {
				$link = $linkText;
			} else {
				$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();
				$link = $linkRenderer->makeLink( $title, $linkText );
			}

			// mark redirects with italics.
			if ( $title->isRedirect() ) {
				$link = Html::rawElement( 'i', [], $link );
			}

			// enclose the links with <bdi> tags. T318507
			$link = Html::rawElement( 'bdi', [], $link );

			array_unshift( $breadCrumbs, $link );

			// avoid cyclic trails
			if ( in_array( $title->getArticleID(), $idStack ) ) {
				$breadCrumbs[0] = Html::rawElement( 'strike', [], $breadCrumbs[0] );
				break;
			}
			$idStack[] = $title->getArticleID();

			$parserOutput = $parserOutput ?? self::getParserOutput( $title->getArticleID(), $user );
			if ( $parserOutput ) {
				$title = self::getParentRegion( $parserOutput );
				// Reset so we can fetch parser output for the parent page
				$parserOutput = null;
			} else {
				$title = null;
			}
		}

		return $breadCrumbs;
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
	public static function getParserOutput( int $pageId, User $user ) {
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
