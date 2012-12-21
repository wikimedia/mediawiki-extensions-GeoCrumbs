<?php

class GeoCrumbs {
	var $mParserOptions = array();

	/**
	 * @param Parser $parser
	 * @return bool
	 */
	public static function parserHooks( Parser &$parser ) {
		global $wgGeoCrumbs;
		$parser->setFunctionHook( 'isin', array( &$wgGeoCrumbs, 'onFuncIsIn' ) );
		return true;
	}

	/**
	 * @return CustomData
	 */
	public function getCustomData() {
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
	public function onFuncIsIn( Parser &$parser, $article ) {
		// Tribute to Evan!
		$article = urldecode( $article );

		$title = Title::newFromText( $article, $parser->mTitle->getNamespace() );
		if ( $title ) {
			$article = array( 'id' => $title->getArticleID() );
			$this->getCustomData()->setParserData( $parser->mOutput, 'GeoCrumbIsIn', $article );
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
	public function onParserBeforeTidy( Parser &$parser, &$text ) {
		if ( $parser->getTitle() ) {
			$this->completeImplicitIsIn( $parser->mOutput, $parser->mTitle );
		}

		return true;
	}

	/**
	 * Generates an IsIn from title for subpages.
	 *
	 * @param ParserOutput|OutputPage $parserOutput
	 * @param Title $title
	 */
	public function completeImplicitIsIn( &$parserOutput, Title $title ) {
		// only do implicitly if none is defined through parser hook
		$customData = $this->getCustomData();
		$existing = $customData->getParserData( $parserOutput, 'GeoCrumbIsIn' );
		if ( !empty( $existing ) ) {
			return;
		}
		// if we're dealing with a subpage, the parent should be in breadcrumb
		$parent = $title->getBaseTitle();
		if ( $parent->getArticleID() != $title->getArticleID() ) {
			$article = array( 'id' => $parent->getArticleID() );
			$customData->setParserData( $parserOutput, 'GeoCrumbIsIn', $article );
		}
	}

	/**
	 * @param SkinTemplate $skinTpl
	 * @param QuickTemplate $QuickTmpl
	 * @return bool
	 */
	public function onSkinTemplateOutputPageBeforeExec( SkinTemplate &$skinTpl, &$QuickTmpl ) {
		if ( !wfRunHooks( 'GeoCrumbsBeforeOutput', array( &$this, &$skinTpl, &$QuickTmpl ) ) ) {
			return true;
		}

		$breadcrumbs = $this->makeTrail( $skinTpl->getTitle() );

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
	public function makeTrail( Title $title ) {
		$breadcrumbs = array();

		// avoid cyclic trails & define emergency break
		$idStack = array();
		$cnt = 20;

		while ( $title && $cnt-- ) {
			$link = Linker::link( $title, $title->getSubpageText() );

			// mark redirects with italics.
			if ( $title->isRedirect() ) {
				$link = Html::rawElement( 'i', array(), $link );
			}
			array_unshift( $breadcrumbs, $link );

			if ( in_array( $title->getArticleID(), $idStack ) ) {
				$breadcrumbs[0] = Html::rawElement( 'strike', array(), $breadcrumbs[0] );
				break;
			}

			$idStack[] = $title->getArticleID();
			$title = $this->getParentRegion( $title );
		}

		return $breadcrumbs;
	}

	/**
	 * @param Title $oldTitle
	 * @return Title|null
	 */
	public function getParentRegion( Title $childTitle ) {
		if ( $childTitle->getArticleID() <= 0 ) {
			return null;
		}

		$parserCache = $this->getParserCache( $childTitle->getArticleID() );
		$article = $this->getCustomData()->getParserData( $parserCache, 'GeoCrumbIsIn' );
		if ( $article ) {
			return Title::newFromID( $article['id'] );
		}

		return null;
	}

	/**
	 * @param $pageId
	 * @return null|object|ParserOutput
	 */
	public function getParserCache( $pageId ) {
		global $wgUser;

		if ( $pageId <= 0 ) {
			return null;
		}

		$page = WikiPage::newFromID( $pageId );
		return $page->getParserOutput( $page->makeParserOptions( $wgUser ) );
	}
}
