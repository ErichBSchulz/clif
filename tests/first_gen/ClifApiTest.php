<?php

use PHPUnit\Framework\TestCase;

// fixme - how to do this bootstrapping "properly"?
require_once 'bootstrap.php';

/**
 * Unit tests of the CLIF engine that do not require a bootstrapped CiviCRM
 */
class AgcClifAPITest extends TestCase {

  public function testGetLogic() {

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
    $filter_api_tag = array(
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
        'expected_count' => array(0,0)
      ),
      array(
        'title' => 'raw',
        'clif' => array(
          'type' => 'raw',
          'params'=> array(10=>1, 20=>1)
        ),
        'expected_count' => array(2,2)
      ),
      array(
        'title' => 'api group',
        'clif' => $filter_api_group,
        'expected_count' => array(20,30000)
      ),
      array(
        'title' => 'api tag',
        'clif' => $filter_api_tag,
        'expected_count' => array(20,12000)
      ),
    );
    foreach ($tests as $test) {
      // Act
      $api_params = array(
        'clif' => $test['clif'],
      );

      $result = civicrm_api3('Contact', 'getclif', $api_params);

      // Assert
      $this->assertEquals(0, $result['is_error'], "$test[title] runs ok");
      $this->assertGreaterThanOrEqual(
        $test['expected_count'][0],
        count($result['values']),
        "$test[title] count");
      $this->assertLessThanOrEqual(
        $test['expected_count'][1],
        count($result['values']),
        "$test[title] count");
    }
  }
}

