<?php
/*
Copyright (C) 2014, Siemens AG
Author: Johannes Najjar

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

namespace Fossology\Lib\Dao;

use Fossology\Lib\BusinessRules\NewestEditedLicenseSelector;
use Fossology\Lib\Data\LicenseDecision;
use Fossology\Lib\Db\DbManager;
use Fossology\Lib\Test\TestLiteDb;
use Mockery as M;
use Mockery\MockInterface;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Logger;

class ClearingDaoTest extends \PHPUnit_Framework_TestCase
{

  /** @var TestLiteDb */
  private $testDb;

  /** @var DbManager */
  private $dbManager;

  /** @var NewestEditedLicenseSelector|MockInterface */
  private $licenseSelector;

  /** @var UploadDao|MockInterface */
  private $uploadDao;

  /** @var ClearingDao */
  private $clearingDao;

  public function setUp()
  {
    $this->licenseSelector = M::mock(NewestEditedLicenseSelector::classname());
    $this->uploadDao = M::mock(UploadDao::classname());

    $logger = new Logger('default');
    $logger->pushHandler(new ErrorLogHandler());

    $this->testDb = new TestLiteDb("/tmp/fossology.sqlite");
    $this->dbManager = $this->testDb->getDbManager();

    $this->clearingDao = new ClearingDao($this->dbManager, $this->licenseSelector, $this->uploadDao);

    $this->testDb->createPlainTables(
        array(
            'clearing_decision',
            'clearing_decision_events',
            'clearing_decision_type',
            'license_decision_event',
            'license_decision_type',
            'clearing_licenses',
            'license_ref',
            'users',
            'group_user_member',
            'uploadtree'
        ));

    $this->testDb->insertData(
        array(
            'clearing_decision_type',
            'license_decision_type'
        ));

    $this->dbManager->prepare($stmt = 'insert.users',
        "INSERT INTO users (user_name, root_folder_fk) VALUES ($1,$2)");
    $userArray = array(
        array('myself', 1),
        array('in_same_group', 2),
        array('in_trusted_group', 3),
        array('not_in_trusted_group', 4));
    foreach ($userArray as $ur)
    {
      $this->dbManager->freeResult($this->dbManager->execute($stmt, $ur));
    }

    $this->dbManager->prepare($stmt = 'insert.gum',
        "INSERT INTO group_user_member (group_fk, user_fk, group_perm) VALUES ($1,$2,$3)");
    $gumArray = array(
        array(1, 1, 0),
        array(1, 2, 0),
        array(2, 3, 0),
        array(3, 4, 0)
    );
    foreach ($gumArray as $ur)
    {
      $this->dbManager->freeResult($this->dbManager->execute($stmt, $ur));
    }

    $this->dbManager->prepare($stmt = 'insert.ref',
        "INSERT INTO license_ref (rf_pk, rf_shortname, rf_text) VALUES ($1, $2, $3)");
    $refArray = array(
        array(1, 'FOO', 'foo text'),
        array(2, 'BAR', 'bar text'),
        array(3, 'BAZ', 'baz text'),
        array(4, 'QUX', 'qux text')
    );
    foreach ($refArray as $ur)
    {
      $this->dbManager->freeResult($this->dbManager->execute($stmt, $ur));
    }

    $this->dbManager->prepare($stmt = 'insert.uploadtree',
        "INSERT INTO uploadtree (pfile_fk, uploadtree_pk) VALUES ($1, $2)");
    $utArray = array(
        array( 100, 1000),
        array( 100, 1200)
    );
    foreach ($utArray as $ur)
    {
      $this->dbManager->freeResult($this->dbManager->execute($stmt, $ur));
    }
    
    $this->dbManager->prepare($stmt = 'insert.cd',
        "INSERT INTO license_decision_event (license_decision_event_pk, pfile_fk, uploadtree_fk, user_fk, rf_fk, is_removed, type_fk, date_added) VALUES ($1, $2, $3, $4, $5, $6, $7, $8)");
    $cdArray = array(
        array(1, 100, 1000, 1, 1,  false, 1, '2014-08-15T12:12:12'),
        array(2, 100, 1000, 1, 2,  false, 1, '2014-08-15T12:12:12'),
        array(3, 100, 1000, 3, 4,  false, 1, '2014-08-14T14:33:45'),
        array(4, 100, null, 2, 3,  false, 2, '2014-08-15T10:43:58'),
        array(5, 100, 1000, 2, 4,  true, 1, '2014-08-14T14:33:51'),
        array(6, 100, null, 1, 3,  true, 1, '2014-08-15T12:49:52'),
        array(7, 100, 1200, 1, 2,  false, 1, '2014-08-15T13:05:43')
    );
    foreach ($cdArray as $ur)
    {
      $this->dbManager->freeResult($this->dbManager->execute($stmt, $ur));
    }
  }

  public function testLicenseDecisionEventsViaGroupMembership()
  {
    $result = $this->clearingDao->getRelevantLicenseDecisionEvents(1, 1000);
    assertThat($result, contains(
        array(100, 1000, "2014-08-14T14:33:51", 2, 1, LicenseDecision::USER_DECISION, 4, "QUX", 0, 1),
        array(100, 1000, "2014-08-15T10:43:58", 2, 1, LicenseDecision::BULK_RECOGNITION, 3, "BAZ", 1, 0),
        array(100, 1000, "2014-08-15T12:12:12", 1, 1, LicenseDecision::USER_DECISION, 1, "FOO", 0, 0),
        array(100, 1000, "2014-08-15T12:12:12", 1, 1, LicenseDecision::USER_DECISION, 2, "BAR", 0, 0),
        array(100, 1200, "2014-08-15T12:49:52", 1, 1, LicenseDecision::USER_DECISION, 3, "BAZ", 1, 1)
    ));
  }

  public function testLicenseDecisionEventsViaGroupMembershipShouldBeSymmetric()
  {
    $result = $this->clearingDao->getRelevantLicenseDecisionEvents(2, 1000);
    assertThat($result, contains(
        array(100, 1000, "2014-08-14T14:33:51", 2, 1, LicenseDecision::USER_DECISION, 4, "QUX", 0, 1),
        array(100, 1000, "2014-08-15T10:43:58", 2, 1, LicenseDecision::BULK_RECOGNITION, 3, "BAZ", 1, 0),
        array(100, 1000, "2014-08-15T12:12:12", 1, 1, LicenseDecision::USER_DECISION, 1, "FOO", 0, 0),
        array(100, 1000, "2014-08-15T12:12:12", 1, 1, LicenseDecision::USER_DECISION, 2, "BAR", 0, 0),
        array(100, 1200, "2014-08-15T12:49:52", 1, 1, LicenseDecision::USER_DECISION, 3, "BAZ", 1, 1)
    ));
  }

  public function testLicenseDecisionEventsUploadScope()
  {
    $result = $this->clearingDao->getRelevantLicenseDecisionEvents(1, 1200);
    assertThat($result, contains(
        array(100, 1000, "2014-08-15T10:43:58", 2, 1, LicenseDecision::BULK_RECOGNITION, 3, "BAZ", 1, 0),
        array(100, 1200, "2014-08-15T12:49:52", 1, 1, LicenseDecision::USER_DECISION, 3, "BAZ", 1, 1),
        array(100, 1200, "2014-08-15T13:05:43", 1, 1, LicenseDecision::USER_DECISION, 2, "BAR", 0, 0)
    ));
  }

  public function testLicenseDecisionEventsWithoutGroupOverlap()
  {
    $result = $this->clearingDao->getRelevantLicenseDecisionEvents(3, 1000);
    assertThat(count($result), is(1));
    assertThat($result[0], is(
        array(100, 1000, "2014-08-14T14:33:45", 3, 2, LicenseDecision::USER_DECISION, 4, "QUX", 0, 0)
    ));
  }

  public function testLicenseDecisionEventsWithoutMatch()
  {
    $result = $this->clearingDao->getRelevantLicenseDecisionEvents(3, 1200);
    assertThat($result, is(array()));
  }

  public function testCurrentLicenseDecisionViaGroupMembership()
  {
    list($added, $removed) = $this->clearingDao->getCurrentLicenseDecision(1, 1000);
    assertThat($added, is(array("FOO" => 1, "BAR" => 2)));
    assertThat($removed, is(array("QUX" => 4, "BAZ" => 3)));
  }

  public function testCurrentLicenseDecisionViaGroupMembershipShouldBeSymmetric()
  {
    list($added, $removed) = $this->clearingDao->getCurrentLicenseDecision(2, 1000);
    assertThat($added, is(array("FOO" => 1, "BAR" => 2)));
    assertThat($removed, is(array("QUX" => 4, "BAZ" => 3)));
  }

  public function testCurrentLicenseDecisionWithUploadScope()
  {
    list($added, $removed) = $this->clearingDao->getCurrentLicenseDecision(2, 1200);
    assertThat($added, is(array("BAR" => 2)));
    assertThat($removed, is(array("BAZ" => 3)));
  }

  public function testCurrentLicenseDecisionWithoutGroupOverlap()
  {
    list($added, $removed) = $this->clearingDao->getCurrentLicenseDecision(3, 1000);
    assertThat($added, is(array("QUX" => 4)));
    assertThat($removed, is(array()));
  }

  public function testCurrentLicenseDecisionWithoutMatch()
  {
    list($added, $removed) = $this->clearingDao->getCurrentLicenseDecision(3, 1200);
    assertThat($added, is(array()));
    assertThat($removed, is(array()));
  }
}
 