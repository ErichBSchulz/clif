<?php

use PHPUnit\Framework\TestCase;

// fixme - there should be a pattern for this
// see http://civicrm.stackexchange.com/questions/16418/class-naming-and-namespaces-best-practice-as-an-extension-author
require_once realpath(dirname(__FILE__)) .  '/../../CRM/Clif/CRM_Clif_Engine.php';

if (empty($civicrm_root)) {
  $civicrm_root = getenv("CIVICRM_ROOT");
}

require_once "$civicrm_root/CRM/Utils/Cache/Interface.php";

class AgcClifTest extends TestCase {

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

    // Arrange
    $list_A = array(1, 2, 3);
    $list_B = array(3, 4, 5);
    $list_C = array(6, 8);
    $filter_A = CRM_Clif_Engine::contactIdsToClif($list_A);
    $filter_B = CRM_Clif_Engine::contactIdsToClif($list_B);
    $filter_C = CRM_Clif_Engine::contactIdsToClif($list_C);
    // todo test this!
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
        'title' => 'union',
        'clif' => array(
          'type' => 'union',
          'params'=> array($filter_A, $filter_B)
        ),
        'expected' => array(1,2,3,4,5)
      ),
      array(
        'title' => 'intersection',
        'clif' => array(
          'type' => 'intersection',
          'params'=> array($filter_A, $filter_B)
        ),
        'expected' => array(3)
      ),
      array(
        'title' => 'api group',
        'clif' => $filter_api_group,
        'expected' => array(3,5)
      ),
    );
    foreach ($tests as $test) {
      // Act
      $clif = new CRM_Clif_Engine(array(
        'clif' => $test['clif'],
        'cache' => $cache,
        'inject' => array(
          'api3' => $api_mock
        )
      ));
      try {
        $result = $clif->get(array(
          'length' => 1000,
        ));
      }
      catch (Exception $e) {
        echo "----trace:\n" . implode($clif->trace, "\n") . "\n----\n";
        throw $e;
      }
      // Assert
      $this->assertEquals(
        $test['expected'],
        $result,
        "$test[title] result");
    }
  }

  public function testContactIdsToClif() {
    // Arrange

    // These lists will all return this:
    //    {"type": "raw", "params": {"10": 1, "20": 1, "30": 1}
    $valid_lists = array(
      'integer' => array(10, 20, 30),
      'duplicates' => array(10, 20, 20, 30), //silently deduped
      'string' => array("10", "20", "30"),
      'leading_zero' => array("010", 20, 30), // checking not octal!
      'mixed' => array(10, "20", 20, 30),
    );

    // These lists will throw errors
    $invalid_lists = array(
      'zero' => array(0, 20, 30),
      'letter' => array(10, 20, 'a'),
      'numberletter' => array(10, 20, '30a'),
      'false_last' => array(10, 20, false),
      'false_first' => array(false, 20, 30),
      'csv' => array("10,20"),
      'true_firt' => array(true, 20, 30),
      'null_first' => array(null, 20, 30),
    );

    foreach($valid_lists as $test_name => $contacts) {
      // Act
      $filter = CRM_Clif_Engine::contactIdsToClif($contacts);
      // Assert
      $this->assertArrayHasKey('type', $filter,
        "expect $test_name to have type");
      $this->assertArrayHasKey('params', $filter,
        "expect $test_name to have params");
      $this->assertArrayHasKey(10, $filter['params'],
        "expect $test_name to have 10 id");
      $this->assertArrayHasKey(20, $filter['params'],
        "expect $test_name to have 20 id");
      $this->assertArrayHasKey(30, $filter['params'],
        "expect $test_name to have 30 id");
      $this->assertEquals(
        array(1 => 3),
        array_count_values($filter['params']), "$test_name values");
    }

    foreach($invalid_lists as $test_name => $contacts) {
      // Act
      try {
        $filter = CRM_Clif_Engine::contactIdsToClif($contacts);
        $error_thrown = false;
      }
      catch (Exception $e) {
        $error_thrown = true;
      }
      // Assert
      $this->assertEquals(true, $error_thrown, "expect $test_name to throw");
    }

  }
}

/**
 * Cache mock stolen from CRM_Utils_Cache_Arraycache
 */
class TestCache implements CRM_Utils_Cache_Interface {
  private $_cache;
  public function __construct($config) {
    $this->_cache = array();
  }
  public function set($key, &$value) {
    $this->_cache[$key] = $value;
  }
  public function get($key) {
    return CRM_Utils_Array::value($key, $this->_cache);
  }
  public function delete($key) {
    unset($this->_cache[$key]);
  }
  public function flush() {
    unset($this->_cache);
    $this->_cache = array();
  }
}

