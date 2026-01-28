<?php

namespace Civi\Paymentprocessingcore\Hook\TabSet;

/**
 * Hides the Memberships tab on contribution page admin settings.
 *
 * CiviCRM fires hook_civicrm_tabset('civicrm/admin/contribute', ...)
 * when rendering contribution page configuration tabs. This handler
 * unconditionally removes the membership tab since the platform does
 * not support membership integration on contribution pages.
 */
class ContributionPageTabs {

  /**
   * The tabset name passed by CiviCRM.
   *
   * @var string
   */
  private string $tabsetName;

  /**
   * Reference to the tabs array.
   *
   * @var array<string, mixed>
   */
  private array $tabs;

  /**
   * Constructor.
   *
   * @param string $tabsetName
   *   The tabset identifier from CiviCRM.
   * @param array<string, mixed> $tabs
   *   Reference to the tabs array.
   */
  public function __construct(string $tabsetName, array &$tabs) {
    $this->tabsetName = $tabsetName;
    $this->tabs = &$tabs;
  }

  /**
   * Remove the membership tab from contribution page settings.
   *
   * The hook fires in two contexts with different array structures:
   * - TabHeader::process(): string-keyed array ('membership' => [...])
   * - ContributionPage::configureActionLinks(): int-keyed array with
   *   'uniqueName' => 'membership'
   *
   * This method handles both.
   */
  public function run(): void {
    if ($this->tabsetName !== 'civicrm/admin/contribute') {
      return;
    }

    // TabHeader tabs: string-keyed by tab name.
    unset($this->tabs['membership']);

    // Configure action links: integer-keyed with 'uniqueName' field.
    foreach ($this->tabs as $key => $tab) {
      if (is_array($tab) && ($tab['uniqueName'] ?? NULL) === 'membership') {
        unset($this->tabs[$key]);
      }
    }
  }

}
