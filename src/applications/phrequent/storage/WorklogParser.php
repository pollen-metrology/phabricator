<?php

/* !
*
* Add time to a timestamp using a formatted string
*
* Formatted string uses tokens composed by a number and a character:
* - Nw : add N week(s) of work. One week is five days
* - Nd : add N day(s) of work. One day is 7 hours
* - Nh : add N hours of work
* - Nm : add N minutes of work
*
* Tokens can be chained, ex. 1h30m, 1w3d2h20m
*
*/
final class WorklogParser {

  /* capture blocks of digits + 1 letter */
  protected static $regexWorklog = '/(\s?\d{1,}\w\s?)/';

  /* separate digit and letter */
  protected static $regexWorklogItem = '/(\d{1,})(\w)/';

  protected static $durationMap = [
    /*
    'w' => 126000,// 60 * 60 * 7 * 5
    'd' => 25200, // 60 * 60 * 7
    */
    'h' => 3600,  // 60 * 60
    'm' => 60,
  ];

  private $timestamp;
  private $error;

  public function __construct($timestamp, $worklog) {
    $this->timestamp = $timestamp;
    $matches = [];
    preg_match_all(static::$regexWorklog, $worklog, $matches);

    // shift of the first match, which is the full string
    $this->timestamp += $this->worklogToSeconds(array_shift($matches));
  }

  public function getError() {
    return $this->error;
  }

  public function getTimeStamp() {
    return $this->timestamp;
  }

  private function worklogToSeconds($tokens) {
    $duration_in_second = 0;

    foreach ($tokens as $token) {
      $matches = ['', 0, 'm']; // default to 0 minutes 
      preg_match(static::$regexWorklogItem, $token, $matches);
      if (!$matches[1] || !$matches[2]) {

        $this->error = pht('Trailing characters in the worklog');
        $this->error .= pht(' (digit without letter?)');
      } else {
        $type  = $matches[2];
        $count = $matches[1];
        $duration_in_second += $count * static::$durationMap[$type];
      }
    }
    return $duration_in_second;
  }
}
