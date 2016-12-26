<?php

use PHPUnit\Framework\TestCase;

// fixme - there should be a pattern for this
require_once realpath(dirname(__FILE__)) .  '/../../CRM/Clif/CRM_Clif_Engine.php';


class AgcClifTest extends TestCase {

  public function testHello() {
    // Arrange
    $clif = new CRM_Clif_Engine(array(
      'clif' => array(
        'type' => 'null'
      )
    ));
    // Act
    $result = $clif->get();
    // Assert
    $this->assertEquals('hello world', $result);
  }

  public function testContactIdsToClif() {
    // Arrange

    // These lists will all return this:
    //    {"type": "raw", "params": {"10": 1, "20": 1, "30": 1}
    $valid_lists = array(
      'integer' => array(10, 20, 30),
      'duplicates' => array(10, 20, 20, 30),
      'string' => array("10", "20", "30"),
      'leading_zero' => array("010", 20, 30),
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
      echo $test_name . ': $filter: '.json_encode($filter,JSON_PRETTY_PRINT)." \n";
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
        echo '$filter: '.json_encode($filter,JSON_PRETTY_PRINT)."\n";
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

