<?php

if (empty($civicrm_root)) {
  $civicrm_root = getenv("CIVICRM_ROOT");
}
$extension_root = realpath(dirname(__FILE__)) .  '/../..';

require_once "$civicrm_root/civicrm.config.php";

// fixme - there should be a pattern for this
// see http://civicrm.stackexchange.com/questions/16418/class-naming-and-namespaces-best-practice-as-an-extension-author
require_once "$extension_root/CRM/Clif/CRM_Clif_Engine.php";


$config = CRM_Core_Config::singleton();

//
// Call a CiviCRM API or class to do something useful
