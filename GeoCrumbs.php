<?php

if ( function_exists( 'wfLoadExtension' ) ) {
	wfLoadExtension( 'GeoCrumbs' );
	// Keep i18n globals so mergeMessageFileList.php doesn't break
	$wgMessagesDirs['GeoCrumbs'] = __DIR__ . '/i18n';
	$wgExtensionMessagesFiles['GeoCrumbsMagic'] = __DIR__ . '/GeoCrumbs.i18n.magic.php';
	/* wfWarn(
		'Deprecated PHP entry point used for GeoCrumbs extension. ' .
		'Please use wfLoadExtension instead, ' .
		'see https://www.mediawiki.org/wiki/Extension_registration for more details.'
	); */
	return;
} else {
	die( 'This version of the GeoCrumbs extension requires MediaWiki 1.25+' );
}
