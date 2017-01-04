<?php

echo "--------------bootstrapping in " .  __FILE__."\n\n";
// bootstrap 4.6
$_SERVER['HTTP_HOST'] =getenv("CIVICRM_TEST_HOST");
$civicrm_root = getenv("CIVICRM_ROOT");
$extension_root = realpath(dirname(__FILE__)) .  '/../..';

// point the configuration at the settings dirtory
define('CIVICRM_CONFDIR', getenv("CIVICRM_TEST_CONFDIR"));

require_once "$civicrm_root/civicrm.config.php";

// fixme - there should be a pattern for this
// see http://civicrm.stackexchange.com/questions/16418/class-naming-and-namespaces-best-practice-as-an-extension-author
require_once "$extension_root/CRM/Clif/CRM_Clif_Engine.php";

$config = CRM_Core_Config::singleton();
//echo '$config: '.json_encode($config,JSON_PRETTY_PRINT).' #'.__LINE__.' '. __FILE__."\n";
$db = explode('?', explode('/', $config->dsn)[3])[0];
echo "db: $db\n";
echo "baseurl: $config->userFrameworkBaseURL\n";

echo "\n--------------done bootstrapping\n";
