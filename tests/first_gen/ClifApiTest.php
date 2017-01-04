<?php

use PHPUnit\Framework\TestCase;

// fixme - how to do this bootstrapping "properly"?
require_once 'bootstrap.php';

/**
 * Unit tests of the CLIF engine that do not require a bootstrapped CiviCRM
 */
class AgcClifAPITest extends TestCase {

  public function testGetLogic() {
      //select id, name, title from civicrm_tests_dev.civicrm_group;
      //id	name	title
      //1	Administrators	Administrators
      //2	Newsletter Subscribers	Newsletter Subscribers
      //3	Summer Program Volunteers	Summer Program Volunteers
      //4	Advisory Board	Advisory Board

    // Arrange - some simple lists
    $filter_api_group = array(
      'type' => 'api3',
      'params' => array(
        'entity' => "GroupContact",
        'action' => "get",
        'params' => array(
          'group_id' => array('IN' => array("Administrators", "Newsletter Subscribers")),
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
        'expected_count' => 0
      ),
      array(
        'title' => 'raw',
        'clif' => array(
          'type' => 'raw',
          'params'=> array(10=>1, 20=>1)
        ),
        'expected_count' => 2
      ),
      array(
        'title' => 'api group',
        'clif' => $filter_api_group,
        'expected_count' => 60
      ),
//      array(
//        'title' => 'api tag',
//        'clif' => $filter_api_tag,
//        'expected_count' => 0
//      ),
    );
      echo "--\n##startiing#".__LINE__.' '. __FILE__."\n";
    foreach ($tests as $test) {
      echo "--\n##test: ".$test['title']."\n";

      // Act
      $api_params = array(
        'clif' => $test['clif'],
      );

      $result = civicrm_api3('Contact', 'getclif', $api_params);
      if ($result['is_error'] || $test['expected_count'] != count($result['values'])) {
        // test will fail so some debugging
        echo '$api_params: '.json_encode($api_params,JSON_PRETTY_PRINT)."\n";
        echo '$result: '.json_encode($result,JSON_PRETTY_PRINT)."\n";
      }

      // Assert
      // no failure
      $this->assertEquals(0, $result['is_error'], "$test[title] runs ok");
      // correct count
      $this->assertEquals($test['expected_count'], count($result['values']), "$test[title] count");
    }
  }
}

