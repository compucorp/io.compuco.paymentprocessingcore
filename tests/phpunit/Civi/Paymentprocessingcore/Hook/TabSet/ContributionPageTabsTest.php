<?php

namespace Civi\Paymentprocessingcore\Hook\TabSet;

use PHPUnit\Framework\TestCase;

/**
 * Tests for ContributionPageTabs hook handler.
 *
 * @group headless
 */
class ContributionPageTabsTest extends TestCase {

  /**
   * Tests that the membership tab is removed from contribution page tabs.
   */
  public function testRemovesMembershipTab(): void {
    $tabs = [
      'settings' => ['title' => 'Title'],
      'membership' => ['title' => 'Memberships'],
      'amount' => ['title' => 'Amounts'],
    ];

    $hook = new ContributionPageTabs('civicrm/admin/contribute', $tabs);
    $hook->run();

    $this->assertArrayNotHasKey('membership', $tabs);
    $this->assertArrayHasKey('settings', $tabs);
    $this->assertArrayHasKey('amount', $tabs);
  }

  /**
   * Tests that non-contribution tabsets are not affected.
   */
  public function testIgnoresOtherTabsets(): void {
    $tabs = [
      'membership' => ['title' => 'Memberships'],
      'other' => ['title' => 'Other'],
    ];

    $hook = new ContributionPageTabs('civicrm/admin/event', $tabs);
    $hook->run();

    $this->assertArrayHasKey('membership', $tabs);
    $this->assertArrayHasKey('other', $tabs);
  }

  /**
   * Tests that the membership action link is removed from configure links.
   *
   * The configureActionLinks() array uses integer keys (CRM_Core_Action
   * constants) with a 'uniqueName' field to identify each link.
   */
  public function testRemovesMembershipActionLink(): void {
    $tabs = [
      1 => [
        'name' => 'Title and Settings',
        'url' => 'civicrm/admin/contribute/settings',
        'uniqueName' => 'settings',
      ],
      4 => [
        'name' => 'Membership Settings',
        'url' => 'civicrm/admin/contribute/membership',
        'uniqueName' => 'membership',
      ],
      8 => [
        'name' => 'Contribution Amounts',
        'url' => 'civicrm/admin/contribute/amount',
        'uniqueName' => 'amount',
      ],
    ];

    $hook = new ContributionPageTabs('civicrm/admin/contribute', $tabs);
    $hook->run();

    $this->assertCount(2, $tabs);
    $this->assertArrayHasKey(1, $tabs);
    $this->assertArrayHasKey(8, $tabs);
    $this->assertArrayNotHasKey(4, $tabs);
  }

  /**
   * Tests that it handles tabs without a membership key gracefully.
   */
  public function testHandlesMissingMembershipTab(): void {
    $tabs = [
      'settings' => ['title' => 'Title'],
      'amount' => ['title' => 'Amounts'],
    ];

    $hook = new ContributionPageTabs('civicrm/admin/contribute', $tabs);
    $hook->run();

    $this->assertCount(2, $tabs);
    $this->assertArrayHasKey('settings', $tabs);
    $this->assertArrayHasKey('amount', $tabs);
  }

}
