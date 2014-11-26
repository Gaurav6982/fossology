<?php
/*
Copyright (C) 2014, Siemens AG

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

namespace Fossology\Lib\BusinessRules;

use Fossology\Lib\Db\DbManager;
use Fossology\Lib\Dao\ClearingDao;
use Fossology\Lib\Data\Clearing\AgentClearingEvent;
use Fossology\Lib\Data\Clearing\ClearingResult;
use Fossology\Lib\Data\Clearing\ClearingLicense;
use Fossology\Lib\Data\Clearing\ClearingEventTypes;
use Fossology\Lib\Data\DecisionScopes;
use Fossology\Lib\Data\LicenseRef;
use Fossology\Lib\Data\Tree\ItemTreeBounds;
use Fossology\Lib\Data\DecisionTypes;

class ClearingDecisionProcessor
{
  const NO_LICENSE_KNOWN_DECISION_TYPE = 2;

  /** @var ClearingDao */
  private $clearingDao;

  /** @var AgentLicenseEventProcessor */
  private $agentLicenseEventProcessor;

  /** @var ClearingEventProcessor */
  private $clearingEventProcessor;

  /** @var DbManager */
  private $dbManager;

  /**
   * @param ClearingDao $clearingDao
   * @param AgentLicenseEventProcessor $agentLicenseEventProcessor
   * @param ClearingEventProcessor $clearingEventProcessor
   */
  public function __construct(ClearingDao $clearingDao, AgentLicenseEventProcessor $agentLicenseEventProcessor, ClearingEventProcessor $clearingEventProcessor, DbManager $dbManager)
  {
    $this->clearingDao = $clearingDao;
    $this->agentLicenseEventProcessor = $agentLicenseEventProcessor;
    $this->clearingEventProcessor = $clearingEventProcessor;
    $this->dbManager = $dbManager;
  }

  public function getUnhandledScannerDetectedLicenses(ItemTreeBounds $itemTreeBounds, $userId) {
    $events = $this->clearingDao->getRelevantClearingEvents($userId, $itemTreeBounds->getItemId());

    $scannerDetectedLicenses = $this->agentLicenseEventProcessor->getScannerDetectedLicenses($itemTreeBounds);

    $clearingLicenseRefs = $this->clearingEventProcessor->getClearingLicenseRefs($events);

    return array_diff_key($scannerDetectedLicenses, $clearingLicenseRefs);
  }

  private function getClearingLicensesForAgentFindings(ItemTreeBounds $itemBounds, $remove = false)
  {
    $clearingLicenses = array();
    foreach($this->agentLicenseEventProcessor->getScannerDetectedLicenses($itemBounds) as $scannerLicenseRef)
    {
      $licId = $scannerLicenseRef->getId();
      $clearingLicensesToDo[$licId] = new ClearingLicense($scannerLicenseRef, $remove, ClearingEventTypes::AGENT);
    }
    return $clearingLicenses;
  }

  public function makeClearingEventToSetNoLicense(ItemTreeBounds $itemBounds, $userId)
  {
    $itemId = $itemBounds->getItemId();

    $this->clearingDao->removeClearingEvents($itemId, $userId);
    $clearingLicensesToDo = $this->getClearingLicensesForAgentFindings($itemBounds, true);
    $this->insertClearingEventsForClearingLicenses($itemId, $userId, $clearingLicensesToDo);
  }

  /**
   * @param ItemTreeBounds $itemBounds
   * @param int $userId
   * @param int $type
   * @param boolean $global
   */
  public function makeDecisionFromLastEvents(ItemTreeBounds $itemBounds, $userId, $type, $global)
  {
    if ($type < self::NO_LICENSE_KNOWN_DECISION_TYPE)
    {
      return;
    }

    $needTransaction = !$this->dbManager->isInTransaction();
    if ($needTransaction) $this->dbManager->begin();

    $itemId = $itemBounds->getItemId();

    if ($type === self::NO_LICENSE_KNOWN_DECISION_TYPE)
    {
      $this->makeClearingEventToSetNoLicense($itemBounds, $userId);
      $type = DecisionTypes::IDENTIFIED;
    }
    else
    {
      $agentClearingLicenses = $this->getClearingLicensesForAgentFindings($itemBounds);
      $this->insertClearingEventsForClearingLicenses($itemId, $userId, $agentClearingLicenses);
      $this->agentLicenseEventProcessor->getScannerDetectedLicenses($itemBounds);
    }

    list($lastDecision, $lastType) = $this->getRelevantClearingDecisionParameters($userId, $itemId);
    $events = $this->clearingDao->getRelevantClearingEvents($userId, $itemId);

    $clearingLicensesToDecide = $this->clearingEventProcessor->getClearingLicenses($events);
    if ($type !== $lastType || count($clearingLicensesToDecide) > 0)
    {
      $scope = $global ? DecisionScopes::REPO : DecisionScopes::ITEM;
      $this->insertClearingDecision($userId, $itemId, $type, $scope, $clearingLicensesToDecide);
    }

    if ($needTransaction) $this->dbManager->commit();
  }

  /**
   * @param ItemTreeBounds $itemTreeBounds
   * @param int $userId
   * @return array
   */
  public function getCurrentClearings(ItemTreeBounds $itemTreeBounds, $userId)
  {
    $itemId = $itemTreeBounds->getItemId();
    $scannedLicenseDetails = $this->agentLicenseEventProcessor->getScannerDetectedLicenseDetails($itemTreeBounds);

    $events = $this->clearingDao->getRelevantClearingEvents($userId, $itemId);
    $selection = $this->clearingEventProcessor->getClearingLicenses($events);

    $events = $this->clearingEventProcessor->filterEffectiveEvents($events);

    $addedResults = array();
    $removedResults = array();

    foreach (array_merge($selection, $this->agentLicenseEventProcessor->getScannedLicenses($scannedLicenseDetails)) as $shortName => $clearingLicense)
    {
      $licenseDecisionEvent = array_key_exists($shortName, $events) ? $events[$shortName] : null;
      $agentClearingEvents = $this->collectAgentDetectedLicenses($shortName, $scannedLicenseDetails);

      if (($licenseDecisionEvent === null) && (count($agentClearingEvents) == 0))
        continue;

      $licenseDecisionResult = new ClearingResult($licenseDecisionEvent, $agentClearingEvents);
      if (!array_key_exists($shortName, $selection) && $licenseDecisionEvent === null) {
        $addedResults[$shortName] = $licenseDecisionResult;
      } else if ($licenseDecisionEvent !== null)
      {
        if ($licenseDecisionEvent->isRemoved()) {
          $removedResults[$shortName] = $licenseDecisionResult;
        } else {
          $addedResults[$shortName] = $licenseDecisionResult;
        }
      }
    }

    return array($addedResults, $removedResults);
  }


  /**
   * @param int $itemId
   * @param int $userId
   * @param ClearingLicense[] $clearingLicenses
   * @param int $eventType
   */
  protected function insertClearingEventsForClearingLicenses($itemId, $userId, $clearingLicenses)
  {
    foreach ($clearingLicenses as $clearingLicense)
    {
      $this->clearingDao->insertClearingEventFromClearingLicense($itemId, $userId, $clearingLicense);
    }
  }

  /**
   * @param $userId
   * @param $item
   * @return array
   */
  private function getRelevantClearingDecisionParameters($userId, $item)
  {
    $clearingDecision = $this->clearingDao->getRelevantClearingDecision($userId, $item);

    if ($clearingDecision)
    {
      return array(
          $clearingDecision->getDateAdded(),
          $clearingDecision->getType()
      );
    }
    return array(null, null);
  }

  /**
   * @param $userId
   * @param $itemId
   * @param $type
   * @param $scope
   * @param $addedLicenses
   * @param $removedLicenses
   * @todo $license and $removedLicenses are symmetrically used: merge them before getting here
   */
  private function insertClearingDecision($userId, $itemId, $type, $scope, $addedLicenses, $removedLicenses = array())
  {
    $this->clearingDao->insertClearingDecision($itemId, $userId, $type, $scope, $addedLicenses, $removedLicenses);
    ReportCachePurgeAll();
  }

  /**
   * @param $licenseProperty
   * @return AgentClearingEvent
   */
  private function createAgentClearingEvent($licenseProperty)
  {
    return new AgentClearingEvent(
        $licenseProperty['licenseRef'],
        $licenseProperty['agentRef'],
        $licenseProperty['matchId'],
        array_key_exists('percentage', $licenseProperty) ? $licenseProperty['percentage'] : null
    );
  }

  /**
   * @param $licenseShortName
   * @param $agentDetectedLicenses
   * @return array
   */
  private function collectAgentDetectedLicenses($licenseShortName, $agentDetectedLicenses)
  {
    $agentClearingEvents = array();
    if (array_key_exists($licenseShortName, $agentDetectedLicenses))
    {
      foreach ($agentDetectedLicenses[$licenseShortName] as $agentName => $licenseProperties)
      {
        foreach ($licenseProperties as $licenseProperty)
        {
          $agentClearingEvents[] = $this->createAgentClearingEvent($licenseProperty);
        }
      }
    }
    return $agentClearingEvents;
  }


}