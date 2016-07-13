<?php

class GeoCrumbs {

	/**
	 * @param Parser $parser
	 * @return bool
	 */
	public static function onParserFirstCallInit( Parser &$parser ) {
		$parser->setFunctionHook( 'isin', 'GeoCrumbs::onFuncIsIn' );
		return true;
	}

	/**
	 * @return CustomData
	 */
	private static function getCustomData() {
		global $wgCustomData;

		if ( !$wgCustomData instanceof CustomData ) {
			throw new Exception( 'CustomData extension is not properly installed.' );
		}

		return $wgCustomData;
	}

	/**
	 * @param Parser $parser
	 * @param string $article
	 * @return string
	 */
	public static function onFuncIsIn( Parser &$parser, $article ) {
		// Tribute to Evan!
		$article = urldecode( $article );

		$title = Title::newFromText( $article, $parser->mTitle->getNamespace() );
		if ( $title ) {
			$article = array( 'id' => $title->getArticleID() );
			self::getCustomData()->setParserData( $parser->mOutput, 'GeoCrumbIsIn', $article );
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
	 * @param string $text
	 * @return bool
	 */
	public static function onParserBeforeTidy( Parser &$parser, &$text ) {
		if ( $parser->getTitle() ) {
			self::completeImplicitIsIn( $parser->mOutput, $parser->mTitle );
		}

		return true;
	}

	/**
	 * Generates an IsIn from title for subpages.
	 *
	 * @param ParserOutput|OutputPage $parserOutput
	 * @param Title $title
	 */
	public static function completeImplicitIsIn( &$parserOutput, Title $title ) {
		// only do implicitly if none is defined through parser hook
		$customData = self::getCustomData();
		$existing = $customData->getParserData( $parserOutput, 'GeoCrumbIsIn' );
		if ( !empty( $existing ) ) {
			return;
		}

		// if we're dealing with a subpage, the parent should be in breadcrumb
		$parent = $title->getBaseTitle();

		if ( $parent && $parent->getArticleID() != $title->getArticleID() ) {
			$article = array( 'id' => $parent->getArticleID() );
			$customData->setParserData( $parserOutput, 'GeoCrumbIsIn', $article );
		}
	}

	/**
	 * @param SkinTemplate $skinTpl
	 * @param QuickTemplate $QuickTmpl
	 * @return bool
	 */
	public static function onSkinTemplateOutputPageBeforeExec( SkinTemplate &$skinTpl, &$QuickTmpl ) {
		$breadcrumbs = self::makeTrail( $skinTpl->getTitle() );

		if ( count( $breadcrumbs ) > 1 ) {
			$breadcrumbs = implode( wfMessage( 'geocrumbs-delimiter' )->inContentLanguage()->text(), $breadcrumbs );

			$oldsubtitle = $QuickTmpl->data['subtitle'];
			$subtitle = $oldsubtitle ? "$breadcrumbs<br />\n$oldsubtitle" : $breadcrumbs;

			$QuickTmpl->set( 'subtitle', $subtitle );
		}

		return true;
	}

	/**
	 * @param Title $title
	 * @return array
	 */
	public static function makeTrail( Title $title ) {
		$breadcrumbs = array();
		$idStack = array();

		if ( $title->getArticleID() <= 0 ) {
			return array();
		}

		for ( $cnt = 0; $title && $cnt < 20; $cnt++ ) {
			$parserCache = self::getParserCache( $title->getArticleID() );
			if (
				$parserCache &&
				$parserCache->getProperty( 'displaytitle' ) == false &&
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
				$link = Linker::link( $title, $linkText );
			}

			// mark redirects with italics.
			if ( $title->isRedirect() ) {
				$link = Html::rawElement( 'i', array(), $link );
			}
			array_unshift( $breadcrumbs, $link );

			// avoid cyclic trails
			if ( in_array( $title->getArticleID(), $idStack ) ) {
				$breadcrumbs[0] = Html::rawElement( 'strike', array(), $breadcrumbs[0] );
				break;
			}
			$idStack[] = $title->getArticleID();

			$title = self::getParentRegion( $title );
		}

		return $breadcrumbs;
	}

	/**
	 * @param Title $oldTitle
	 * @return Title|null
	 */
	public static function getParentRegion( Title $childTitle ) {
		if ( $childTitle->getArticleID() <= 0 ) {
			return null;
		}

		$parserCache = self::getParserCache( $childTitle->getArticleID() );
		$article = self::getCustomData()->getParserData( $parserCache, 'GeoCrumbIsIn' );
		if ( $article ) {
			return Title::newFromID( $article['id'] );
		}

		return null;
	}

	/**
	 * @param $pageId
	 * @return null|object|ParserOutput
	 */
	public static function getParserCache( $pageId ) {
		global $wgUser;

		if ( $pageId <= 0 ) {
			return null;
		}

		$page = WikiPage::newFromID( $pageId );
		if ( !$page ) {
			return null;
		}
		return $page->getParserOutput( $page->makeParserOptions( $wgUser ) );
	}
}
