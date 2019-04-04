<?php
final class PhabricatorPeopleTimeProfileMenuItem
  extends PhabricatorProfileMenuItem {

  const MENUITEMKEY = 'people.time';

  public function getMenuItemTypeName() {
    return pht('Time');
  }

  private function getDefaultName() {
    return pht('Time');
  }

  public function canHideMenuItem(
    PhabricatorProfileMenuItemConfiguration $config) {
    return true;
  }

  public function getDisplayName(
    PhabricatorProfileMenuItemConfiguration $config) {
    $name = $config->getMenuItemProperty('name');

    if (strlen($name)) {
      return $name;
    }

    return $this->getDefaultName();
  }

  public function buildEditEngineFields(
    PhabricatorProfileMenuItemConfiguration $config) {
    return array(
      id(new PhabricatorTextEditField())
        ->setKey('name')
        ->setLabel(pht('Name'))
        ->setPlaceholder($this->getDefaultName())
        ->setValue($config->getMenuItemProperty('name')),
    );
  }

  protected function newMenuItemViewList(
    PhabricatorProfileMenuItemConfiguration $config) {

    $user = $config->getProfileObject();
    $id = $user->getID();

    $item = $this->newItem()
      ->setHref("/people/time/{$id}/")
      ->setName($this->getDisplayName($config))
      ->setIcon('clock-o');

    return array(
      $item,
    );
  }

}
