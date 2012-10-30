<?php

if ( !defined( 'MEDIAWIKI' ) ) {
	die( 'This file is a MediaWiki extension, it is not a valid entry point' );
}

// autoloader
$wgAutoloadClasses['GeoCrumbs'] = __DIR__ . '/GeoCrumbs.class.php';

// extension & magic words i18n
$wgExtensionMessagesFiles['GeoCrumbs'] = __DIR__ . '/GeoCrumbs.i18n.php';
$wgExtensionMessagesFiles['GeoCrumbsMagic'] = __DIR__ . '/GeoCrumbs.i18n.magic.php';

// hooks
$wgGeoCrumbs = new GeoCrumbs;
$wgHooks['ParserFirstCallInit'][] = 'GeoCrumbs::parserHooks';
$wgHooks['ParserBeforeTidy'][] = array( &$wgGeoCrumbs, 'onParserBeforeTidy' );
$wgHooks['SkinTemplateOutputPageBeforeExec'][] = array( &$wgGeoCrumbs, 'onSkinTemplateOutputPageBeforeExec' );

// credits
$wgExtensionCredits['parserhook']['Insider'] = array(
	'path' => __FILE__,
	'name' => 'GeoCrumbs',
	'url' => '//www.mediawiki.org/wiki/Extension:GeoCrumbs',
	'descriptionmsg' => 'geocrumbs-desc',
	'author' => array( 'Roland Unger', 'Hans Musil', 'Matthias Mullie' ),
	'version' => '1.01'
);
