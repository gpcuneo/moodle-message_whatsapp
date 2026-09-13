@message @message_whatsapp
Feature: Check the WhatsApp channel from the administration screens
  In order to know the channel works before anybody depends on it
  As an administrator
  I need to choose a sending mode and have the site tell me whether it can send

  Background:
    # The in memory transport stands in for Meta. Without it this feature would need credentials in the
    # repository, an internet connection on whoever runs the suite, and a green run that depends on a third party.
    Given the following config values are set as admin:
      | message_whatsapp_fake_transport | 1 |
    And I log in as "admin"

  Scenario: The administrator configures direct mode and the site reports a working connection
    Given I visit "/admin/settings.php?section=messagesettingwhatsapp"
    When I set the field "Sending mode" to "Direct (own Meta Cloud API credentials)"
    And I press "Save changes"
    Then I should see "Changes saved"
    When I visit "/message/output/whatsapp/test.php"
    Then I should see "Current configuration"
    And I should see "Direct (own Meta Cloud API credentials)"
    And I should see "webhook.php"
    When I press "Test connection"
    Then I should see "Connection OK"
    And I should see "This site sets message_whatsapp_fake_transport in config.php"

  Scenario: A transport that refuses says so instead of failing quietly
    Given the following config values are set as admin:
      | message_whatsapp_fake_transport | fail |
    And I visit "/message/output/whatsapp/test.php"
    When I press "Test connection"
    Then I should see "Connection failed"
    And I should see "The in memory transport is set to refuse everything"

  Scenario: A test message is not queued for an administrator who never opted in
    Given I visit "/message/output/whatsapp/test.php"
    When I press "Send a test to my number"
    Then I should see "You have never set WhatsApp notifications up for yourself"
