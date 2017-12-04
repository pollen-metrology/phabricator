<?php

final class PhrequentTimespendConduitAPIMethod
  extends PhrequentConduitAPIMethod {

  public function getAPIMethodName() {
    return 'phrequent.worklog';
  }

  public function getMethodDescription() {
    $description = pht('Log time spend on a given ticket, ');
    $description += pht('using a start timestamp and a ');
    $description += pht('Worklog string (ex. 1d3h20m).');
    return $description;
  }

  public function getMethodStatus() {
    return self::METHOD_STATUS_UNSTABLE;
  }

  protected function defineParamTypes() {
    return array(
      'objectPHID' => 'required phid',
      'startTime' => 'required int',
      'worklog' => 'required text',
      'notes' => 'text',
    );
  }

  protected function defineReturnType() {
    return 'phid';
  }

  protected function execute(ConduitAPIRequest $request) {
    $user            = $request->getUser();
    $object_phid     = $request->getValue('objectPHID');
    $start_timestamp = $request->getValue('startTime');
    $worklog         = $request->getValue('worklog');
    $notes           = $request->getValue('notes');

    if (strlen($worklog) > 0) {
      $worklog_parser = new WorklogParser(
        $start_timestamp->getEpoch(),
        $worklog);
      $parse_error = $worklog_parser->getError();
      if (strlen($parse_error) > 0) {
        return array('ERR_WORKLOG_PARSER' =>  pht('Syntax error'));
      } else {
        $editor = new PhrequentTrackingEditor();
        return $editor->addWorklog(
          $user,
          $object_phid,
          $start_timestamp,
          $worklog);
      }

    } else {
      return array('ERR_WORKLOG_PARSER' =>  pht('Empty worklog'));
    }
  }
}
