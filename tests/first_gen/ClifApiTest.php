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

//      select id,name  from civicrm_tests_dev.civicrm_tag;
//      id	name
//      2	Company
//      3	Government Entity
//      4	Major Donor
//      1	Non-profit
//      5	Volunteer

    /*

SELECT
t1.id, t1.name
, count(*)
FROM civicrm_tests_dev.civicrm_tag t1
INNER JOIN civicrm_tests_dev.civicrm_entity_tag et1 ON et1.tag_id=t1.id
  AND et1.entity_table = 'civicrm_contact'
INNER JOIN civicrm_tests_dev.civicrm_contact c1
  ON et1.entity_id=c1.id
GROUP BY t1.id

id	name	count(*)
1	Non-profit	3
2	Company	5
3	Government Entity	2
4	Major Donor	55
5	Volunteer	53

SELECT
t1.id, t1.name,
t2.id, t2.name #--, c1.id, c1.display_name
, count(*)
FROM civicrm_tests_dev.civicrm_tag t1
INNER JOIN civicrm_tests_dev.civicrm_entity_tag et1 ON et1.tag_id=t1.id
  AND et1.entity_table = 'civicrm_contact'
LEFT JOIN civicrm_tests_dev.civicrm_contact c1
  ON et1.entity_id=c1.id
LEFT JOIN civicrm_tests_dev.civicrm_entity_tag et2
  ON et2.entity_id=c1.id
LEFT JOIN civicrm_tests_dev.civicrm_tag t2
  ON et2.tag_id=t2.id
  AND et2.entity_table = 'civicrm_contact'
GROUP BY t1.id, t2.id

id	name	id	name	count(*)
1	Non-profit	1	Non-profit	3
2	Company	2	Company	5
3	Government Entity	3	Government Entity	2
4	Major Donor	4	Major Donor	55
4	Major Donor	5	Volunteer	28
5	Volunteer	4	Major Donor	28
5	Volunteer	5	Volunteer	53

SELECT count(*)
FROM civicrm_tests_dev.civicrm_contact c1
count(*)
201

     */

    // utility wrapper to make V3 API GroupContact parameters:
    // returns array cliff
    $group_clif = function($group_id) {
      return array(
        'type' => 'api3',
        'params' => array(
          'entity' => "GroupContact",
          'action' => "get",
          'params' => array(
            'group_id' => $group_id,
            'status' => "Added",
          )
        )
      );
    };

    // utility wrapper to make V3 API EntityTag parameters:
    // returns array cliff
    $tag_clif = function($tag_id) {
      return array (
        'type' => 'api3',
        'params' => array(
          'entity' => 'EntityTag',
          'action' => "get",
          'params' => array(
            'tag_id' => $tag_id,
            'entity_table' => "civicrm_contact"
          )
        )
      );
    };

    // Arrange - some simple lists
    $subscribers = $group_clif(
       array('IN' => array("Newsletter Subscribers")));
    $admin_and_subscribers = $group_clif(
       array('IN' => array("Administrators", "Newsletter Subscribers")));
    $admins = $group_clif(
       array('IN' => array("Administrators")));
    $sp_vols = $group_clif(
       array('IN' => array("Summer Program Volunteers")));
    $board = $group_clif(
       array('IN' => array("Advisory Board")));
    $volunteers = $tag_clif(array('IN' => array("Volunteer")));
    $donors = $tag_clif(array('IN' => array("Major Donor")));

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
        'title' => 'all',
        'clif' => array(
          'type' => 'all',
        ),
        'expected_count' => 201
      ),
      array(
        'title' => 'api group - a',
        'clif' => $admins,
        'expected_count' => 0
      ),
      array(
        'title' => 'api group - s',
        'clif' => $subscribers,
        'expected_count' => 60
      ),
      array(
        'title' => 'api group - b',
        'clif' => $board,
        'expected_count' => 8
      ),
      array(
        'title' => 'api group - sp',
        'clif' => $sp_vols,
        'expected_count' => 15
      ),
      array(
        'title' => 'api group a&s',
        'clif' => $admin_and_subscribers,
        'expected_count' => 60
      ),
      array(
        'title' => 'vollunteers tag',
        'clif' => $volunteers,
        'expected_count' => 53
      ),
      array(
        'title' => 'donor tag',
        'clif' => $donors,
        'expected_count' => 55
      ),
      array(
        'title' => 'donors combined with vollunteers',
        'clif' => array(
          'type' => 'union',
          'params' => array($donors, $volunteers)),
        'expected_count' => 80
      ),
      array(
        'title' => 'union board and summer prog',
        'clif' => array(
          'type' => 'union',
          'params' => array($board, $sp_vols)),
        'expected_count' => 23
      ),
      array(
        'title' => 'union volunteers and admin sub',
        'clif' => array(
          'type' => 'union',
          'params' => array($volunteers, $admin_and_subscribers)),
        'expected_count' => 96
      ),
      array(
        'title' => 'intersection volunteers and admin sub',
        'clif' => array(
          'type' => 'intersection',
          'params' => array($volunteers, $admin_and_subscribers)),
        'expected_count' => 17
      ),
      array(
        'title' => 'intersection volunteers and admin sub',
        'clif' => array(
          'type' => 'intersection',
          'params' => array(
            $volunteers,
            $admin_and_subscribers,
            array(
              'type' => 'union',
              'params' => array($board, $donors))
          )),
        'expected_count' => 10
      ),
      array(
        'title' => 'intersection volunteers and admin sub',
        'clif' => array(
          'type' => 'union',
          'params' => array(
            $volunteers,
            array(
              'type' => 'intersection',
              'params' => array($board, $donors))
          )),
        'expected_count' => 55
      ),
    );
    //echo "--\n##startiing#".__LINE__.' '. __FILE__."\n";
    foreach ($tests as $test) {
      //echo "--\n##test: ".$test['title']."\n";
      // Act
      $api_params = array(
        'clif' => $test['clif'],
        'debug' => 1
      );
      try {
        $result = civicrm_api3('Contact', 'getclif', $api_params);
        //echo '$api_params: '.json_encode($api_params,JSON_PRETTY_PRINT).' #'.__LINE__.' '. __FILE__."\n";
        if ($result['is_error']) {
          // test will fail so some debugging
          echo '$result: '.json_encode($result, JSON_PRETTY_PRINT)."\n";
        }
      }
      catch (Exception $e) {
        log_err($e);
        $result = array('is_error' => 1);
      }

      // Assert
      // no failure
      $this->assertEquals(0, $result['is_error'], "$test[title] got API error");
      // correct count
      $this->assertEquals(
        $test['expected_count'],
        count($result['values']),
        "$test[title] count is wrong");
    }
  }
}

