<?php
/*
Copyright (C) 2014, Siemens AG
Authors: Daniele Fognini, Johannes Najjar

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
version 2 as published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License along
with this program; if not, write to the Free Software Foundation, Inc.,
51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/
namespace Fossology\Lib\Data;

use Fossology\Lib\BusinessRules\NewestEditedLicenseSelector;
use Fossology\Lib\Data\Clearing\ClearingLicense;


class NewestEditedLicenseSelectorTest extends \PHPUnit_Framework_TestCase
{

  /**
   * @var NewestEditedLicenseSelector
   */
  private $newestEditedLicenseSelector;

  /**
   * @param $id
   * @param $isLocal
   * @param $scope
   * @param $name
   * @param $ud
   * @return ClearingDecision
   */
  public function clearingDec($id, $isLocal, $scope, $name, $ud)
  {
    $clearingDecision = ClearingDecisionBuilder::create()
        ->setClearingId($id)
        ->setUserName($name)
        ->setSameFolder($isLocal)
        ->setSameUpload($isLocal)
        ->setType($ud)
        ->setScope($scope);

    $licref = new LicenseRef(5, $name . "shortName", $name . "fullName");
    $clearLic = new ClearingLicense($licref, false);
    $clearingDecision->setLicenses(array($clearLic));

    return $clearingDecision->build();
  }

  public function setUp()
  {
    $this->newestEditedLicenseSelector = new NewestEditedLicenseSelector();
  }

  public function testCreateClearingDec()
  {
    $licenses = $this->clearingDec(0, true, 'global', "Test", 'Identified')->getLicenses();
    $firstLicense = reset($licenses);
    assertThat($firstLicense->getShortName(), is("TestshortName"));
  }

  public function testEmptyIsEmpty()
  {
    $editedLicensesArray = array();
    assertThat($this->newestEditedLicenseSelector->extractGoodClearingDecisionsPerFileID($editedLicensesArray), is(array()));
  }

  public function testNotFoundIsEmpty()
  {
    $editedLicensesArray = array(134 => $this->clearingDec(0, false, 'upload', "Test", ClearingDecision::IDENTIFIED));
    assertThat($this->newestEditedLicenseSelector->extractGoodClearingDecisionsPerFileID($editedLicensesArray), is(array()));
  }

  public function testFoundIsNotEmpty()
  {
    $cd = $this->clearingDec(0, false, 'global', "Test", ClearingDecision::IDENTIFIED);
    $editedLicensesArray = array($cd);
    $licenses = $this->newestEditedLicenseSelector->extractGoodLicenses($editedLicensesArray);
    assertThat(implode(", ", $licenses), is("TestshortName"));
  }

  public function testNewerGlobalwinsAgainstGlobal()
  {
    $editedLicensesArray = array(
        $this->clearingDec(1, false, 'global', "A", ClearingDecision::IDENTIFIED),
        $this->clearingDec(0, false, 'global', "B",  ClearingDecision::IDENTIFIED)
    );
    $licenses = $this->newestEditedLicenseSelector->extractGoodLicenses($editedLicensesArray);

    assertThat(implode(", ", $licenses), is("AshortName"));
  }

  public function testNewerLocalwinsAgainstGlobal()
  {
    $editedLicensesArray = array(
        $this->clearingDec(1, true, 'upload', "A",  ClearingDecision::IDENTIFIED),
        $this->clearingDec(0, false, 'global', "B", ClearingDecision::IDENTIFIED)
    );
    $licenses = $this->newestEditedLicenseSelector->extractGoodLicenses($editedLicensesArray);

    assertThat(implode(", ", $licenses), is("AshortName"));
  }

  public function testOlderLocalwinsAgainstGlobal()
  {
    $editedLicensesArray = array(
        $this->clearingDec(0, false, 'global', "B", ClearingDecision::IDENTIFIED),
        $this->clearingDec(1, true, 'upload', "A", ClearingDecision::IDENTIFIED)

    );
    $licenses = $this->newestEditedLicenseSelector->extractGoodLicenses($editedLicensesArray);

    assertThat(implode(", ", $licenses), is("AshortName"));
  }

  public function testToBeDeterminedIsIgnored()
  {
    $editedLicensesArray = array($this->clearingDec(0, true, 'upload', "Test", ClearingDecision::TO_BE_DISCUSSED));
    assertThat($this->newestEditedLicenseSelector->extractGoodClearingDecisionsPerFileID($editedLicensesArray), is(array()));
  }

  public function testOlderGlobalWinsAgainstTBD()
  {
    $editedLicensesArray = array(
        $this->clearingDec(2, true, 'upload', "Test", ClearingDecision::TO_BE_DISCUSSED),
        $this->clearingDec(1, false, 'global', "A",  ClearingDecision::IDENTIFIED),
    );
    $licenses = $this->newestEditedLicenseSelector->extractGoodLicenses($editedLicensesArray);

    assertThat(implode(", ", $licenses), is("AshortName"));
  }

  public function testOlderLocalWinsAgainstTBD()
  {
    $editedLicensesArray = array(
        $this->clearingDec(2, true, 'upload', "Test", ClearingDecision::TO_BE_DISCUSSED),
        $this->clearingDec(1, true, 'upload', "A", ClearingDecision::IDENTIFIED),
    );
    $licenses = $this->newestEditedLicenseSelector->extractGoodLicenses($editedLicensesArray);

    assertThat(implode(", ", $licenses), is("AshortName"));
  }

  public function testOlderLocalWinsAgainstNewerGlobalAndTBD()
  {
    $editedLicensesArray = array(
        $this->clearingDec(2, true, 'upload', "Test", ClearingDecision::TO_BE_DISCUSSED),
        $this->clearingDec(1, true, 'global', "A", ClearingDecision::IDENTIFIED),
        $this->clearingDec(0, true, 'upload', "B", ClearingDecision::IDENTIFIED)
    );
    $licenses = $this->newestEditedLicenseSelector->extractGoodLicenses($editedLicensesArray);

    assertThat(implode(", ", $licenses), is("BshortName"));
  }

  public function testOlderGlobalWinsAgainstNewerLocalForDifferentFileAndTBD()
  {
    $editedLicensesArray = array(
        $this->clearingDec(2, true, 'upload', "Test", ClearingDecision::TO_BE_DISCUSSED),
        $this->clearingDec(1, false, 'upload', "A", ClearingDecision::IDENTIFIED),
        $this->clearingDec(0, false, 'global', "B", ClearingDecision::IDENTIFIED)
    );
    $licenses = $this->newestEditedLicenseSelector->extractGoodLicenses($editedLicensesArray);

    assertThat(implode(", ", $licenses), is("BshortName"));
  }


  public function testClearingDecisionTBDIsInActive()
  {
    assertThat($this->newestEditedLicenseSelector->isInactive($this->clearingDec(2, true, 'upload', "Test", ClearingDecision::TO_BE_DISCUSSED)), is(true));
  }

  public function testClearingDecisionUserDecisionIsNotInActive()
  {
    assertThat($this->newestEditedLicenseSelector->isInactive($this->clearingDec(2, true, 'upload', "Test", ClearingDecision::IDENTIFIED)), is(false));
  }

}
