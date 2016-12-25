<?php

use PHPUnit\Framework\TestCase;

// fixme - there should be a pattern for this
require_once '../../CRM/Clif/CRM_Clif_Engine.php';

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

}

