@mod @mod_agora
Feature: Эфир Agora в курсе
  Чтобы смотреть эфир прямо в курсе
  преподаватель привязывает элемент к эфиру Agora,
  а записанный слушатель открывает его и получает плеер со своим билетом.

  Background:
    Given the Agora stand is faked
    And the following "courses" exist:
      | fullname | shortname | category | showactivitydates |
      | Оптика   | optics    | 0        | 1                 |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Тимур     | Петров   |
      | student1 | Иван      | Иванов   |
      | outsider | Пётр      | Чужой    |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | optics | editingteacher |
      | student1 | optics | student        |

  @javascript
  Scenario: Преподаватель добавляет элемент на эфир из списка Agora
    When I am on the "Оптика" course page logged in as "teacher1"
    And I turn editing mode on
    And I add a "Agora broadcast" to section "1" using the activity chooser
    And I set the following fields to these values:
      | Activity name | Лекция по оптике |
    And I set the field "Stream" to "optics-2026"
    And I press "Save and return to course"
    Then I should see "Лекция по оптике"
    And I am on the "Лекция по оптике" "agora activity" page
    And "iframe.mod-agora-player" "css_element" should exist
    And I should not see "Open in the Agora console"

  @javascript
  Scenario: Слушатель получает плеер с билетом, а секретов учёток в разметке нет
    Given the following "activities" exist:
      | activity | course | name             | stream_name | display_mode |
      | agora    | optics | Лекция по оптике | optics-2026 | auto         |
    When I am on the "Лекция по оптике" "agora activity" page logged in as "student1"
    Then "iframe.mod-agora-player" "css_element" should exist
    And the "src" attribute of "iframe.mod-agora-player" "css_element" should contain "token="
    And the "src" attribute of "iframe.mod-agora-player" "css_element" should not contain "moodle-player"
    And the "src" attribute of "iframe.mod-agora-player" "css_element" should not contain "player-secret"
    And I should not see "Open in the Agora console"

  @javascript
  Scenario: Предупреждение об открытом проигрывании видит только преподаватель
    Given the Agora stand has open playback enabled
    And the following "activities" exist:
      | activity | course | name             | stream_name | display_mode |
      | agora    | optics | Лекция по оптике | optics-2026 | auto         |
    When I am on the "Лекция по оптике" "agora activity" page logged in as "teacher1"
    Then I should see "Viewing is open to everyone, a ticket protects nothing."
    And I am on the "Лекция по оптике" "agora activity" page logged in as "student1"
    And I should not see "Viewing is open to everyone, a ticket protects nothing."

  @javascript
  Scenario: Незаписанный пользователь видит штатный отказ доступа
    Given the following "activities" exist:
      | activity | course | name             | stream_name | display_mode |
      | agora    | optics | Лекция по оптике | optics-2026 | auto         |
    When I am on the "Лекция по оптике" "agora activity" page logged in as "outsider"
    Then I should see "You cannot enrol yourself in this course."
    And "iframe.mod-agora-player" "css_element" should not exist

  @javascript
  Scenario: Дата начала видна на карточке курса, а без даты строки нет
    Given the following "activities" exist:
      | activity | course | name             | stream_name    | display_mode | timestart  |
      | agora    | optics | Лекция с датой   | optics-2026    | auto         | 1830000000 |
      | agora    | optics | Лекция прошедшая | operator-only  | auto         | 1730000000 |
      | agora    | optics | Лекция без даты  | townhall       | auto         | 0          |
    When I am on the "Оптика" course page logged in as "student1"
    Then I should see "Scheduled for" in the "Лекция с датой" "activity"
    And I should see "Broadcast started" in the "Лекция прошедшая" "activity"
    And I should not see "Scheduled for" in the "Лекция без даты" "activity"

  @javascript
  Scenario: Слушатель не из нужной группы видит штатный отказ доступа
    Given the following "groups" exist:
      | name     | course | idnumber |
      | Группа Б | optics | GB       |
    And the following "activities" exist:
      | activity | course | name             | stream_name | display_mode |
      | agora    | optics | Лекция по оптике | optics-2026 | auto         |
    And I am on the "Лекция по оптике" "agora activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    And I click on "Add restriction..." "button"
    And I click on "Group" "button" in the "Add restriction..." "dialogue"
    And I set the field with xpath "//*[contains(@class,'availability_group')]//select[@name='id']" to "Группа Б"
    And I press "Save and return to course"
    When I am on the "Лекция по оптике" "agora activity" page logged in as "student1"
    Then I should see "Not available unless"
    And I should see "Группа Б"
    And "iframe.mod-agora-player" "css_element" should not exist

  @javascript
  Scenario: Бейдж «в эфире» виден на странице курса
    Given the following "activities" exist:
      | activity | course | name             | stream_name | display_mode |
      | agora    | optics | Лекция по оптике | optics-2026 | auto         |
    When I am on the "Оптика" course page logged in as "student1"
    Then I should see "Live" in the "Лекция по оптике" "activity"

  @javascript
  Scenario: Бейджи различают конец эфира и эфир, который не начинался
    Given the following "activities" exist:
      | activity | course | name          | stream_name   | display_mode |
      | agora    | optics | Запись лекции | townhall      | auto         |
      | agora    | optics | Ещё не начат  | operator-only | auto         |
    And the following config values are set as admin:
      | allowuntagged | 1 | mod_agora |
    When I am on the "Оптика" course page logged in as "student1"
    Then I should see "Recorded" in the "Запись лекции" "activity"
    And I should see "Not started" in the "Ещё не начат" "activity"

  @javascript
  Scenario: Превью-кадр показывается записанному слушателю
    Given the following "activities" exist:
      | activity | course | name             | stream_name | display_mode |
      | agora    | optics | Запись лекции    | townhall    | record_only  |
    When I am on the "Оптика" course page logged in as "student1"
    Then "img.mod-agora-preview" "css_element" should exist

  @javascript
  Scenario: Слушатель после конца эфира видит плеер записи
    Given the following "activities" exist:
      | activity | course | name          | stream_name | display_mode |
      | agora    | optics | Запись лекции | townhall    | auto         |
    When I am on the "Запись лекции" "agora activity" page logged in as "student1"
    Then "iframe.mod-agora-player" "css_element" should exist
    And the "src" attribute of "iframe.mod-agora-player" "css_element" should contain "dvr=true"
    And the "src" attribute of "iframe.mod-agora-player" "css_element" should contain "token="

  @javascript
  Scenario: Преподаватель создаёт эфир по шаблону, и он появляется в Agora
    When I am on the "Оптика" course page logged in as "teacher1"
    And I turn editing mode on
    And I add a "Agora broadcast" to section "1" using the activity chooser
    And I set the following fields to these values:
      | Activity name          | Новая лекция       |
      | Where the broadcast comes from | Create from a template |
      | Name of the new stream | Оптика, лекция 3   |
      | Template               | lecture            |
    And I press "Save and return to course"
    Then I should see "Новая лекция"
    And the Agora stand has stream "hr-lms-optika-lekciya-3" tagged "moodle.hr-lms"

  @javascript
  Scenario: Занятое имя нового эфира — ошибка у поля, введённое на месте
    When I am on the "Оптика" course page logged in as "teacher1"
    And I turn editing mode on
    And I add a "Agora broadcast" to section "1" using the activity chooser
    And I set the following fields to these values:
      | Activity name          | Первая лекция      |
      | Where the broadcast comes from | Create from a template |
      | Name of the new stream | Оптика, лекция 3   |
      | Template               | lecture            |
    And I press "Save and return to course"
    And I add a "Agora broadcast" to section "1" using the activity chooser
    And I set the following fields to these values:
      | Activity name          | Вторая лекция      |
      | Where the broadcast comes from | Create from a template |
      | Name of the new stream | Оптика, лекция 3   |
      | Template               | lecture            |
    And I press "Save and return to course"
    Then I should see "Agora already has a stream named «hr-lms-optika-lekciya-3»"
    And the field "Name of the new stream" matches value "Оптика, лекция 3"
    And the field "Activity name" matches value "Вторая лекция"

  @javascript
  Scenario: Прохождение по открытию: открывший отмечен, не открывавший — нет
    Given the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Физика   | physics   | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course  | role           |
      | teacher1 | physics | editingteacher |
      | student1 | physics | student        |
      | outsider | physics | student        |
    And the following "activities" exist:
      | activity | course  | name             | stream_name | display_mode | completion | completionview |
      | agora    | physics | Лекция по оптике | optics-2026 | auto         | 2          | 1              |
    When I am on the "Лекция по оптике" "agora activity" page logged in as "student1"
    And I am on the "Физика" course page
    Then the "View" completion condition of "Лекция по оптике" is displayed as "done"
    And I am on the "Физика" course page logged in as "outsider"
    And the "View" completion condition of "Лекция по оптике" is displayed as "todo"

  @javascript
  Scenario: Слушатель видит накопленные минуты в карточке правила прохождения
    Given the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Биология | biology   | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course  | role    |
      | student1 | biology | student |
    And the following "activities" exist:
      | activity | course  | name        | stream_name | display_mode | completion | completion_minutes |
      | agora    | biology | Лекция по БИО | optics-2026 | auto       | 2          | 30                 |
    And user "student1" has watched "10" minutes of "Лекция по БИО"
    When I am on the "Лекция по БИО" "agora activity" page logged in as "student1"
    Then I should see "watched so far: 10"

  @javascript
  Scenario: Подсказка условия по длительности называет фактический интервал задачи
    Given the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Химия    | chemistry | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course    | role           |
      | teacher1 | chemistry | editingteacher |
    And the following "activities" exist:
      | activity | course    | name          | stream_name | display_mode | completion |
      | agora    | chemistry | Лекция по ХИМ | optics-2026 | auto         | 2          |
    When I am on the "Лекция по ХИМ" "agora activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    And I click on "Watched for at least" "checkbox"
    Then I should see "Server → Scheduled tasks"
