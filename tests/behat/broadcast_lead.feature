@mod @mod_agora
Feature: Ведение эфира преподавателем
  Чтобы вести занятие без администратора Agora,
  преподаватель видит блок «Как вещать» со строкой подключения,
  объявляет эфир кнопкой и завершает его — со способом завершения в журнале страницы.

  Background:
    Given the Agora stand is faked
    And the following "courses" exist:
      | fullname | shortname | category |
      | Оптика   | optics    | 0        |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Тимур     | Петров   |
      | teacher2 | Степан    | Сидоров  |
      | student1 | Иван      | Иванов   |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | optics | editingteacher |
      | teacher2 | optics | teacher        |
      | student1 | optics | student        |

  @javascript
  Scenario: Созданный по шаблону элемент получает блок «Как вещать» с замаскированным ключом
    When I am on the "Оптика" course page logged in as "teacher1"
    And I turn editing mode on
    And I add a "Agora broadcast" to section "1" using the activity chooser
    And I set the following fields to these values:
      | Activity name          | Новая лекция     |
      | Where the broadcast comes from | Create from a template |
      | Name of the new stream | Оптика, лекция 3 |
      | Template               | lecture          |
    And I press "Save and return to course"
    And I am on the "Новая лекция" "agora activity" page
    Then I should see "How to broadcast"
    And ".mod-agora-publish input[readonly]" "css_element" should not exist
    And I should not see "srt://"
    And I should see "Show address"
    And I am on the "Новая лекция" "agora activity" page logged in as "student1"
    And I should not see "How to broadcast"
    And ".mod-agora-publish" "css_element" should not exist

  @javascript
  Scenario: Ключ скрыт в разметке, показ по клику и смена с подтверждением
    Given the following "activities" exist:
      | activity | course | name               | stream_name   | created_by_plugin | has_phases | srt_port | srt_key                  |
      | agora    | optics | Лекция с публикацией | optics-2026 | 1                 | 1          | 30000    | StandKey0123456789abcdef |
    When I am on the "Лекция с публикацией" "agora activity" page logged in as "teacher1"
    Then ".mod-agora-publish input[readonly]" "css_element" should not exist
    And I should not see "srt://"
    And I should not see "StandKey0123456789abcdef"
    And I should see "Show address"
    And I click on "Show address" "button"
    And the "value" attribute of ".mod-agora-publish input[readonly]" "css_element" should contain "StandKey0123456789abcdef"
    And the browser confirms all dialogs
    And I click on "Change key" "button"
    And the "value" attribute of ".mod-agora-publish input[readonly]" "css_element" should not contain "StandKey0123456789abcdef"
    And the "value" attribute of ".mod-agora-publish input[readonly]" "css_element" should contain "srt://publish.agora.example.com:30000?passphrase="

  @javascript
  Scenario: «Начать эфир» допускает слушателей: до кнопки заглушка, после — плеер
    Given the following "activities" exist:
      | activity | course | name             | stream_name | created_by_plugin | has_phases |
      | agora    | optics | Лекция по оптике | optics-2026 | 1                 | 1          |
    When I am on the "Лекция по оптике" "agora activity" page logged in as "student1"
    Then I should see "The broadcast has not started yet."
    And "[data-region='agora-scene'] iframe" "css_element" should not exist
    And I am on the "Лекция по оптике" "agora activity" page logged in as "teacher1"
    And I should see "The signal is up, learners do not see it yet."
    And "Start broadcast" "button" should exist
    And I click on "Start broadcast" "button"
    And I am on the "Лекция по оптике" "agora activity" page logged in as "student1"
    Then "[data-region='agora-scene'] iframe.mod-agora-player" "css_element" should exist
    And I should not see "The broadcast has not started yet."

  @javascript
  Scenario: «Завершить эфир» показывает запись со способом завершения и подсказкой про копию
    Given the following "activities" exist:
      | activity | course | name             | stream_name | created_by_plugin | has_phases | started_at |
      | agora    | optics | Лекция по оптике | optics-2026 | 1                 | 1          | 1730000000 |
    And I am on the "Лекция по оптике" "agora activity" page logged in as "teacher1"
    And the browser confirms all dialogs
    When I click on "End broadcast" "button"
    Then I should see "Broadcast ended:"
    And I should see "ended by the teacher"
    And I should see "A new class is a new element"
    And "[data-region='agora-scene'] iframe.mod-agora-player" "css_element" should exist
    And I should not see "Signal lost"

  @javascript
  Scenario: Право вести эфир: слушателю кнопок и блока нет, преподавателю без редактирования — есть
    Given the following "activities" exist:
      | activity | course | name             | stream_name | created_by_plugin | has_phases | srt_port | srt_key                  |
      | agora    | optics | Лекция по оптике | optics-2026 | 1                 | 1          | 30000    | StandKey0123456789abcdef |
    When I am on the "Лекция по оптике" "agora activity" page logged in as "student1"
    Then I should not see "How to broadcast"
    And "Start broadcast" "button" should not exist
    And "End broadcast" "button" should not exist
    And I am on the "Лекция по оптике" "agora activity" page logged in as "teacher2"
    And I should see "How to broadcast"
    And "Start broadcast" "button" should exist
    And I should not see "Open in the Agora console"

  @javascript
  Scenario: Элемент-наследие живёт как прежде: живой плеер при сигнале, кнопок ведения нет
    Given the following "activities" exist:
      | activity | course | name             | stream_name | display_mode |
      | agora    | optics | Старая лекция    | optics-2026 | auto         |
    When I am on the "Старая лекция" "agora activity" page logged in as "student1"
    Then "[data-region='agora-scene'] iframe.mod-agora-player" "css_element" should exist
    And I am on the "Старая лекция" "agora activity" page logged in as "teacher1"
    And "Start broadcast" "button" should not exist
    And I should not see "How to broadcast"
