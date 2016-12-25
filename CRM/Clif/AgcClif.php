<?php

/**
 * Contact List Interchange Format engine clase
 *
 */
class AgcClif {

  /**
   * Constructor
   *
   * @params array
   * - clif
   */
  function __construct(array $params) {
    $defaults = array(
      'clif' => false
    );
    $p = $params + $defaults;
    // $this->cacheEngine = new AgcCache();
    if (!$p['clif']) {
      throw new Exception("missing clif parameter");
    }
    $this->clif = $p['clif'];
  }

  public function get($params = array()) {
    return 'hello world';
  }

  // todo
  // - type switch
  // - validation checker
  // - cache hack
  // - impmentent API
  //  $params['return'] = 'id';
  //  return civicrm_api3('Contact', 'get', $params);

}
