@message @message_whatsapp @javascript
Feature: Opt in to WhatsApp notifications
  In order to be notified on WhatsApp
  As a user
  I need to see the number the site found for me and consent to its use

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | phone2           |
      | mobile1  | Celu      | Uno      | mobile1@example.com  | 011 15 1234-5678 |
      | fixed1   | Fijo      | Uno      | fixed1@example.com   | 011 4123-4567    |
    And the following config values are set as admin:
      | messaging   | 1      | core             |
      | mode        | direct | message_whatsapp |
      | phonesource | phone2 | message_whatsapp |

  Scenario: The number of the profile is shown normalised and the user opts in
    Given I log in as "mobile1"
    And I visit "/message/notificationpreferences.php"
    When I click on "WhatsApp" "link"
    Then the field "WhatsApp phone number" matches value "+5491112345678"
    And I should not see "This number looks like a landline"
    And I set the field "Send my notifications to WhatsApp" to "1"
    And I click on "Save changes" "button" in the "Processor settings" "dialogue"
    And I wait until the page is ready
    Then the WhatsApp opt-in of "mobile1" should be "1"
    And the WhatsApp number of "mobile1" should be "+5491112345678"

  Scenario: A number that looks like a landline is reported with a way out
    Given I log in as "fixed1"
    And I visit "/message/notificationpreferences.php"
    When I click on "WhatsApp" "link"
    Then the field "WhatsApp phone number" matches value "+541141234567"
    And I should see "This number looks like a landline"
    And I set the field "WhatsApp phone number" to "011 15 4123-4567"
    And I set the field "Send my notifications to WhatsApp" to "1"
    And I click on "Save changes" "button" in the "Processor settings" "dialogue"
    And I wait until the page is ready
    Then the WhatsApp number of "fixed1" should be "+5491141234567"
    And the WhatsApp opt-in of "fixed1" should be "1"
