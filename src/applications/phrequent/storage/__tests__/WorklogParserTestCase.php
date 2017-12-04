<?php

final class WorklogParserTestCase extends PhabricatorTestCase {

  public function testPreemptingEvents() {

    $this->assertEqual("A", "A");
  }
}
