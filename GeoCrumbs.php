<?php

if ( !defined( 'MEDIAWIKI' ) ) {
	die( 'This file is a MediaWiki extension, it is not a valid entry point' );
}

// autoloader
$wgAutoloadClasses['GeoCrumbs'] = __DIR__ . '/GeoCrumbs.class.php';

// extension & magic words i18n
$wgMessagesDirs['GeoCrumbs'] = __DIR__ . '/i18n';
$wgExtensionMessagesFiles['GeoCrumbs'] = __DIR__ . '/GeoCrumbs.i18n.php';
$wgExtensionMessagesFiles['GeoCrumbsMagic'] = __DIR__ . '/GeoCrumbs.i18n.magic.php';

// hooks
$wgHooks['ParserFirstCallInit'][] = 'GeoCrumbs::onParserFirstCallInit';
$wgHooks['ParserBeforeTidy'][] = 'GeoCrumbs::onParserBeforeTidy';
$wgHooks['SkinTemplateOutputPageBeforeExec'][] = 'GeoCrumbs::onSkinTemplateOutputPageBeforeExec';

// credits
$wgExtensionCredits['parserhook'][] = array(
	'path' => __FILE__,
	'name' => 'GeoCrumbs',
	'url' => 'https://www.mediawiki.org/wiki/Extension:GeoCrumbs',
	'descriptionmsg' => 'geocrumbs-desc',
	'author' => array( 'Roland Unger', 'Hans Musil', 'Matthias Mullie' ),
	'version' => '1.1.0'
);
