<?php

/**
* Allow to send a POST request with a body (not simply URI parameters)
*
*/
final class HarbormasterHTTPRequestWithContentBuildStepImplementation
  extends HarbormasterBuildStepImplementation {

  public function getName() {
    return pht('Make POST HTTP Request with payload');
  }

  public function getGenericDescription() {
    return pht('Make a POST HTTP request with payload.');
  }

  public function getBuildStepGroupKey() {
    return HarbormasterExternalBuildStepGroup::GROUPKEY;
  }

  public function getDescription() {
    $domain = null;
    $uri = $this->getSetting('uri');
    if ($uri) {
      $domain = id(new PhutilURI($uri))->getDomain();
    }

    $method = $this->formatSettingForDescription('method', 'POST');
    $domain = $this->formatValueForDescription($domain);

    if ($this->getSetting('credential')) {
      return pht(
        'Make an authenticated HTTP %s request to %s.',
        $method,
        $domain);
    } else {
      return pht(
        'Make an HTTP %s request to %s.',
        $method,
        $domain);
    }
  }

  public function execute(
    HarbormasterBuild $build,
    HarbormasterBuildTarget $build_target) {

    $viewer = PhabricatorUser::getOmnipotentUser();
    $settings = $this->getSettings();
    $variables = $build_target->getVariables();

    $uri = $this->mergeVariables(
      'vurisprintf',
      $settings['uri'],
      $variables);

    $method  = nonempty(idx($settings, 'method'), 'POST');

    $content = $this->mergeVariables(
      'vcsprintf',
      $settings['content'],
      $variables);
    $content = preg_replace ( "/'/", "", $content);

    $future = id(new HTTPSFuture($uri))
      ->setMethod($method)
      ->setTimeout(60);
    if(strlen(trim($content))>0)
    {
	$future->write(trim($content));
    }
    $credential_phid = $this->getSetting('credential');
    if ($credential_phid) {
      $key = PassphrasePasswordKey::loadFromPHID(
        $credential_phid,
        $viewer);
      $future->setHTTPBasicAuthCredentials(
        $key->getUsernameEnvelope()->openEnvelope(),
        $key->getPasswordEnvelope());
    }

    $this->resolveFutures(
      $build,
      $build_target,
      array($future));

    $this->logHTTPResponse($build, $build_target, $future, $uri);

    list($status) = $future->resolve();
    if ($status->isError()) {
      throw new HarbormasterBuildFailureException();
    }
  }

  public function getFieldSpecifications() {
    return array(
      'uri' => array(
        'name' => pht('URI'),
        'type' => 'text',
        'required' => true,
      ),
      'method' => array(
        'name' => pht('HTTP Method'),
        'type' => 'select',
        'options' => array_fuse(array('POST')),
      ),
      'content' => array(
        'name' => pht('Content'),
        'type' => 'text',
        'required' => true,
      ),
      'credential' => array(
        'name' => pht('Credentials'),
        'type' => 'credential',
        'credential.type'
          => PassphrasePasswordCredentialType::CREDENTIAL_TYPE,
        'credential.provides'
          => PassphrasePasswordCredentialType::PROVIDES_TYPE,
      ),
    );
  }

  public function supportsWaitForMessage() {
    return true;
  }

}
