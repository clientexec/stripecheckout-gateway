<?php

$_GET['fuse'] = 'billing';
$_GET['action'] = 'gatewaycallback';
$_GET['plugin'] = basename(dirname(__FILE__));

chdir('../../..');

require_once dirname(__FILE__).'/../../../library/front.php';

?>