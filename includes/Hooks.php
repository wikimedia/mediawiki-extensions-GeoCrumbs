<?php

namespace MediaWiki\Extension\GeoCrumbs;

use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Html\Html;
use MediaWiki\Languages\LanguageConverterFactory;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Output\Hook\OutputPageParserOutputHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Title\NamespaceInfo;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

class Hooks implements
	ParserFirstCallInitHook,
	OutputPageParserOutputHook
{
	private LanguageConverterFactory $langConvFactory;
	private LinkRenderer $linkRenderer;
	private NamespaceInfo $nsInfo;
	private WikiPageFactory $wikiPageFactory;

	public function __construct(
		LanguageConverterFactory $langConvFactory,
		LinkRenderer $linkRenderer,
		NamespaceInfo $nsInfo,
		WikiPageFactory $wikiPageFactory
	) {
		$this->langConvFactory = $langConvFactory;
		$this->linkRenderer = $linkRenderer;
		$this->nsInfo = $nsInfo;
		$this->wikiPageFactory = $wikiPageFactory;
	}

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
	 * @param OutputPage $out
	 * @param ParserOutput $parserOutput
	 */
	public function onOutputPageParserOutput( $out, $parserOutput ): void {
		$breadCrumbs = $this->makeTrail( $out->getTitle(), $parserOutput, $out->getUser() );

		if ( count( $breadCrumbs ) > 1 ) {
			$breadCrumbs = Html::rawElement( 'span', [ 'class' => 'ext-geocrumbs-breadcrumbs' ],
				implode( wfMessage( 'geocrumbs-delimiter' )->inContentLanguage()->escaped(), $breadCrumbs )
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
	public function makeTrail( Title $title, ParserOutput $parserOutput, User $user ): array {
		if ( $title->getArticleID() <= 0 ) {
			return [];
		}

		$breadCrumbs = [];
		$idStack = [];
		$langConverter = $this->langConvFactory->getLanguageConverter( $title->getPageLanguage() );

		for ( $i = 0; $title && $i < 20; $i++ ) {
			$linkText = $langConverter->convert( $title->getSubpageText() );

			// do not link the final breadcrumb
			if ( $i === 0 ) {
				$link = $linkText;
			} else {
				$link = $this->linkRenderer->makeLink( $title, $linkText );
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

			$parserOutput ??= $this->getParserOutput( $title->getArticleID(), $user );
			if ( $parserOutput ) {
				$title = $this->getParentRegion( $title, $parserOutput );
				// Reset so we can fetch parser output for the parent page
				$parserOutput = null;
			} else {
				$title = null;
			}
		}

		return $breadCrumbs;
	}

	/**
	 * @param Title $title
	 * @param ParserOutput $parserOutput
	 * @return Title|null
	 */
	public function getParentRegion( Title $title, ParserOutput $parserOutput ) {
		$article = $parserOutput->getExtensionData( 'GeoCrumbIsIn' );
		if ( $article ) {
			return Title::newFromID( $article['id'] );
		}

		// Generates an implicit IsIn from title for subpages
		if ( $this->nsInfo->isContent( $title->getNamespace() ) ) {
			$parent = $title->getBaseTitle();
			if ( !$parent->equals( $title ) ) {
				return $parent;
			}
		}

		return null;
	}

	/**
	 * @param int $pageId
	 * @param User $user
	 * @return bool|ParserOutput false if not found
	 */
	public function getParserOutput( int $pageId, User $user ) {
		if ( $pageId <= 0 ) {
			return false;
		}
		$page = $this->wikiPageFactory->newFromID( $pageId );
		if ( !$page ) {
			return false;
		}
		return $page->getParserOutput( $page->makeParserOptions( $user ) );
	}
}
