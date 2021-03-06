<?php

// fixme what is the autoload pattern?
require_once realpath(dirname(__FILE__)) .  '/../../../CRM/Clif/CRM_Clif_Engine.php';

/**
 * Contact.Getcliff API specification
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRM/API+Architecture+Standards
 */
function _civicrm_api3_contact_getclif_spec(&$spec) {
  $spec['clif']['api.required'] = 1;
}

/**
 * Contact.Getcliff API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_contact_getclif($params) {
  $defaults = array(
    'clif' => false,
    'return' => 'id',
    'offset' => 0,
    'length' => null,
    'debug' => 0, // todo confirm this is the correct pattern..
  );
  // merge params in with defaults
  $p = $params + $defaults;

  // validate parameters
  if (!$p['clif']) {
    throw new API_Exception('Missing "clif" parameter');
  }
  if ($p['return'] != 'id') {
    throw new API_Exception('Can only return ID until Erich is pointed in right
      direction');
  }
  $cache = new CRM_Utils_Cache_SerializeCache(array());
  $clif = new CRM_Clif_Engine(array(
    'clif' => $p['clif'],
    'cache' => $cache,
  ));
  $contacts = $clif->get(array(
    'offset' => (int)$p['offset'],
    'length' => (int)$p['length'],
  ));

  $result = civicrm_api3_create_success(
    $contacts, //
    $params, // todo ?? clarify best as $p or $params
    'Contact',
    'Get'); // todo get or Get??
  // todo - is trace the appropriate propoerty
  if ($p['debug']) {
    $result['trace'] = $clif->trace;
  }
  return $result;
}

