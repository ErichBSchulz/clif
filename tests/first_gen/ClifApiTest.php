<?php

use PHPUnit\Framework\TestCase;

// fixme - how to do this bootstrapping "properly"?
require_once 'bootstrap.php';

/**
 * Unit tests of the CLIF engine that do not require a bootstrapped CiviCRM
 */
class AgcClifAPITest extends TestCase {

  public function testGetLogic() {
    $cache = new TestCache(array());
    $api_mock = function($entity, $action, $params) {
      $values = array(
         array(
          "entity_id" => "3",
          "id" => "503"
        ),
         array(
          "entity_id" => "5",
          "id" => "505"
        ),
      );
      return array(
        "is_error" => 0,
        "version" => 3,
        "count" => count($values),
        "values" => $values
        );
    };

    // Arrange - some simple lists
    $filter_api_group = array(
      'type' => 'api3',
      'params' => array(
        'entity' => "GroupContact",
        'action' => "get",
        'params' => array(
          'group_id' => array('IN' => array("Qld_All", "L2Vic")),
          'status' => "Added",
        )
      )
    );
    $filter_api_group = array(
      'type' => 'api3',
      'params' => array(
        'entity' => 'EntityTag',
        'action' => "get",
        'params' => array(
          'tag_id' => array('IN' => array("testing tag 1473109397")),
          'entity_table' => "civicrm_contact",
        )
      )
    );

    $tests = array(
      array(
        'title' => 'empty',
        'clif' => array('type' => 'empty'), // todo bad case
        'expected' => array()
      ),
      array(
        'title' => 'raw',
        'clif' => array(
          'type' => 'raw',
          'params'=> array(10=>1, 20=>1)
        ),
        'expected' => array(10,20)
      ),
      array(
        'title' => 'api group',
        'clif' => $filter_api_group,
        'expected' => array(3,5)
      ),
    );
    foreach ($tests as $test) {
      // Act
      $api_params = array(
        'clif' => $test['clif'],
        'length' => 100,
      );

      $result = civicrm_api3('Contact', 'getclif', $api_params);

      // Assert
      $this->assertEquals(0, $result['is_error'], "$test[title] runs ok");
      $this->assertEquals(
        $test['expected'],
        $result['values'],
        "$test[title] result");
    }
  }
}


