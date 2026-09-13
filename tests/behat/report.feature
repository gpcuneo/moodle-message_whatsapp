@message @message_whatsapp @javascript
Feature: Read the WhatsApp delivery report and queue a failed message again
  In order to find out why a notification never arrived and do something about it
  As someone who operates the site
  I need a report of what was queued for WhatsApp, filtered by what became of it

  # This feature is driven by the administrator and not by a manager, which is not what T3.1 asks for.
  # A plugin of type `message` has its settings.php included only for a user with moodle/site:config
  # (admin/settings/messaging.php wraps everything in `if ($hassiteconfig)`, and
  # \core\plugininfo\message::load_settings() returns early on the same condition), so a manager never gets the
  # admin_externalpage into their tree and admin_externalpage_setup() answers "Access denied" before
  # message/whatsapp:viewlog is ever looked at. That is written up in docs/decisiones-pendientes.md under
  # "T3.1 — Un manager no puede llegar a una admin_externalpage de un plugin message", and the scenario moves to
  # a manager as soon as it is resolved. The capability itself is covered by report_test.php, which grants it to
  # a role of its own and reads the report as that user.
  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Ana       | Alumna   | student1@example.com |
      | student2 | Beto      | Alumno   | student2@example.com |
      | student3 | Carla     | Alumna   | student3@example.com |
    And the following WhatsApp queue entries exist:
      | user     | component  | name                | status  | attempts | error                                         |
      | student1 | mod_assign | assign_notification | failed  | 5        | Cloud API error 131026: Message undeliverable |
      | student2 | mod_forum  | posts               | sent    | 1        |                                               |
      | student3 | moodle     | instantmessage      | skipped | 0        | nooptin                                       |

  Scenario: The report is read, the failures are filtered out of it and one is queued again
    Given I log in as "admin"
    And I visit "/message/output/whatsapp/report.php"
    Then I should see "WhatsApp delivery report"
    And the following should exist in the "reportbuilder-table" table:
      | Recipient   | Component                        | Status  | Attempts |
      | Ana Alumna  | mod_assign / assign_notification | Failed  | 5        |
      | Beto Alumno | mod_forum / posts                | Sent    | 1        |
      | Carla Alumna | moodle / instantmessage         | Skipped | 0        |
    And I should see "Cloud API error 131026" in the "reportbuilder-table" "table"
    # The reason a skipped row carries is a constant of the plugin and not a diagnostic of the provider, so it is
    # shown as a sentence and never as the raw value stored in the column.
    And I should see "has not consented" in the "reportbuilder-table" "table"
    And I should not see "nooptin" in the "reportbuilder-table" "table"

    When I click on "Filters" "button"
    And I set the following fields in the "Status" "core_reportbuilder > Filter" to these values:
      | Status operator | Is equal to |
      | Status value    | Failed      |
    And I click on "Apply" "button" in the "[data-region='report-filters']" "css_element"
    And I click on "Filters" "button"
    Then I should see "Ana Alumna" in the "reportbuilder-table" "table"
    And I should not see "Beto Alumno" in the "reportbuilder-table" "table"
    And I should not see "Carla Alumna" in the "reportbuilder-table" "table"

    # Following the link changes nothing: it opens a confirmation, and the confirmation is what posts.
    When I press "Retry" action in the "Ana Alumna" report row
    Then I should see "It goes out on the next run of the sending task"
    And the WhatsApp entry of "student1" is "failed" with 5 tries
    When I click on "Retry" "button"
    Then I should see "is queued again"
    # Back to pending with the attempts cleared, which is what lets the sending task pick the row up again.
    And the WhatsApp entry of "student1" is "pending" with 0 tries

    # The stored filter is still "Failed" and the row is not failed any more, so it is looked for where it went.
    When I click on "Filters" "button"
    And I set the following fields in the "Status" "core_reportbuilder > Filter" to these values:
      | Status operator | Is equal to |
      | Status value    | Waiting     |
    And I click on "Apply" "button" in the "[data-region='report-filters']" "css_element"
    And I click on "Filters" "button"
    Then I should see "Ana Alumna" in the "reportbuilder-table" "table"
    # The diagnostic survives the retry, because zeroing the attempts throws away the only other trace of it.
    And I should see "Cloud API error 131026" in the "reportbuilder-table" "table"

  Scenario: Someone who does not hold the capability is refused the page
    Given I log in as "student1"
    When I visit "/message/output/whatsapp/report.php"
    Then I should see "Access denied"
    And the WhatsApp entry of "student1" is "failed" with 5 tries
