@message @message_whatsapp @javascript
Feature: See at a glance whether the WhatsApp channel is moving
  In order to find out that nothing is going out before somebody tells me
  As someone who operates the site
  I need a page with what the channel did today and what is still waiting

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Ana       | Alumna   | student1@example.com |
      | student2 | Beto      | Alumno   | student2@example.com |
      | manager1 | Dora      | Gestora  | manager1@example.com |
    And the following "role assigns" exist:
      | user     | role    | contextlevel | reference |
      | manager1 | manager | System       |           |
    And the following WhatsApp queue entries exist:
      | user     | component  | name                | status  | attempts | error                 |
      | student1 | mod_assign | assign_notification | sent    | 1        |                       |
      | student2 | mod_forum  | posts               | sent    | 1        |                       |
      | student1 | mod_quiz   | submission          | failed  | 1        | Cloud API error 133010 |
      | student2 | moodle     | instantmessage      | pending | 0        |                       |

  Scenario: The counters of the day and the backlog are on the page
    Given I log in as "admin"
    When I visit "/message/output/whatsapp/status.php"
    Then I should see "WhatsApp channel status"
    # Two sent and one failed today, and nothing was skipped: the zero has to be shown and not left out.
    And I should see "Today"
    And the following should exist in the "generaltable" table:
      | What      | How many |
      | Sent      | 2        |
      | Failed    | 1        |
      | Skipped   | 0        |
    And I should see "Waiting to go out"
    # The queue has one entry that has not been attempted, and cron has never run in a Behat site, so the page
    # has to say so rather than leave a stalled channel looking like an idle one.
    And I should see "the sending task has not run"

  Scenario: A manager reads the status page outside the administration tree
    Given I log in as "manager1"
    When I visit "/message/output/whatsapp/status.php"
    Then I should see "WhatsApp channel status"
    And I should see "Acceptance test site" in the "h1" "css_element"
    And I should not see "Site administration" in the "page-navbar" "region"

  Scenario: The status page leads to the delivery report
    Given I log in as "admin"
    And I visit "/message/output/whatsapp/status.php"
    When I click on "Open the delivery report" "link"
    Then I should see "WhatsApp delivery report"
    And I should see "Ana Alumna" in the "reportbuilder-table" "table"
