<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

// ParserOutput->mCustomData
$cfg['suppress_issue_types'][] = 'PhanUndeclaredProperty';

return $cfg;
