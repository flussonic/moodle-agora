@mod @mod_agora
Feature: Страница эфира с соседями
  Чтобы после эфира не возвращаться на страницу курса глазами искать следующий,
  слушатель видит рядом с плеером список эфиров курса, под плеером комментарии,
  а справа от них — свои другие курсы.

  Background:
    Given the Agora stand is faked
    And the following "courses" exist:
      | fullname | shortname | category |
      | Оптика   | optics    | 0        |
      | Механика | mech      | 0        |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Тимур     | Петров   |
      | student1 | Иван      | Иванов   |
      | student2 | Пётр      | Петров   |
      | lonely   | Ольга     | Одинцова |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | optics | editingteacher |
      | student1 | optics | student        |
      | student2 | optics | student        |
      | student1 | mech   | student        |
      | lonely   | mech   | student        |
    And the following "activities" exist:
      | activity | course | name               | stream_name   | display_mode | intro                     | visible |
      | agora    | optics | Лекция по оптике   | optics-2026   | auto         | Дифракция и интерференция | 1       |
      | agora    | optics | Вторая лекция      | townhall      | auto         |                           | 1       |
      | agora    | optics | Совсем скрытая     | operator-only | auto         |                           | 0       |
      | agora    | mech   | Лекция по механике | townhall      | auto         |                           | 1       |

  @javascript
  Scenario: Слушатель видит соседей курса и карточку другого курса
    When I am on the "Лекция по оптике" "agora activity" page logged in as "student1"
    Then I should see "In this course"
    And I should see "Вторая лекция"
    And I should see "Other courses"
    And I should not see "Совсем скрытая"
    And ".mod-agora-row-current" "css_element" should exist
    And I should see "Open now" in the ".mod-agora-row-current" "css_element"
    And I should see "Лекция по оптике" in the ".mod-agora-row-current" "css_element"

  @javascript
  Scenario: Текущего курса среди карточек других курсов нет
    When I am on the "Лекция по оптике" "agora activity" page logged in as "student1"
    Then I should see "Механика" in the ".mod-agora-cards" "css_element"
    And I should not see "Оптика" in the ".mod-agora-cards" "css_element"

  @javascript
  Scenario: Имя и описание на странице по одному разу
    When I am on the "Лекция по оптике" "agora activity" page logged in as "student1"
    Then I should see "Лекция по оптике"
    And "h2.mod-agora-title" "css_element" should not exist
    And I should see "Дифракция и интерференция" in the ".mod-agora-intro" "css_element"
    And "#intro" "css_element" should not exist

  @javascript
  Scenario: Преподаватель видит скрытый элемент с ярлыком
    When I am on the "Лекция по оптике" "agora activity" page logged in as "teacher1"
    Then I should see "Совсем скрытая" in the ".mod-agora-playlist" "css_element"
    And I should see "Hidden from students" in the ".mod-agora-playlist" "css_element"

  @javascript
  Scenario: Слушателю одного курса с одним эфиром ни списка, ни карточек не нужно
    When I am on the "Лекция по механике" "agora activity" page logged in as "lonely"
    Then I should not see "Other courses"
    And I should not see "In this course"
    And ".mod-agora-playlist" "css_element" should not exist
    And ".mod-agora-courses" "css_element" should not exist

  @javascript
  Scenario: Холодный кэш курсов: страница собирается без исключений
    Given the Agora course caches are stale
    When I am on the "Лекция по оптике" "agora activity" page logged in as "student1"
    Then I should see "In this course"
    And I should see "Механика" in the ".mod-agora-cards" "css_element"
    And I should see "Дифракция и интерференция" in the ".mod-agora-intro" "css_element"

  @javascript
  Scenario: Живой эфир другого курса становится целью его карточки
    Given the following "activities" exist:
      | activity | course | name           | stream_name | display_mode |
      | agora    | mech   | Живая механика | optics-2026 | auto         |
    And the Agora stream states are refreshed
    When I am on the "Лекция по оптике" "agora activity" page logged in as "student1"
    Then I should see "Live: Живая механика" in the ".mod-agora-cards" "css_element"
    And ".mod-agora-cards .mod-agora-state-badge" "css_element" should exist
    And I click on "//a[contains(@class, 'mod-agora-card-link')]" "xpath_element"
    And I should see "Живая механика" in the ".mod-agora-row-current" "css_element"

  @javascript
  Scenario: Карточка другого курса ведёт на первый непросмотренный эфир
    Given the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Химия    | chem      | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | chem   | student |
    And the following "activities" exist:
      | activity | course | name      | stream_name | display_mode | completion | completionview |
      | agora    | chem   | Химия раз | chem-1      | auto         | 2          | 1              |
      | agora    | chem   | Химия два | chem-2      | auto         | 2          | 1              |
    And I am on the "Химия раз" "agora activity" page logged in as "student1"
    When I am on the "Лекция по оптике" "agora activity" page
    Then I should see "Next: Химия два" in the ".mod-agora-cards" "css_element"
    And I click on "//a[contains(@class, 'mod-agora-card-link')][contains(., 'Химия')]" "xpath_element"
    And I should see "Химия два" in the ".mod-agora-row-current" "css_element"

  @javascript
  Scenario: Узкий экран сворачивает список эфиров курса
    Given I change window size to "360x720"
    When I am on the "Лекция по оптике" "agora activity" page logged in as "student1"
    Then ".mod-agora-playlist-inner.mod-agora-collapsed" "css_element" should exist
    And the Agora page is laid out in one column without horizontal scroll
    And I click on "Show all" "button"
    And ".mod-agora-playlist-inner.mod-agora-collapsed" "css_element" should not exist

  @javascript
  Scenario: Слушатель пишет комментарий и он появляется без перезагрузки
    When I am on the "Лекция по оптике" "agora activity" page logged in as "student1"
    Then I should see "Comments (0)"
    And ".mod-agora-comments .comment-area + .comment-list" "css_element" should exist
    And I set the field "content" to "Спасибо за лекцию"
    And I follow "Save comment"
    And I should see "Спасибо за лекцию" in the ".mod-agora-comments" "css_element"
    And ".mod-agora-comments .comment-area + .comment-list" "css_element" should exist

  @javascript
  Scenario: Второй слушатель видит чужой комментарий
    Given I am on the "Лекция по оптике" "agora activity" page logged in as "student1"
    And I set the field "content" to "Спасибо за лекцию"
    And I follow "Save comment"
    And I should see "Спасибо за лекцию" in the ".mod-agora-comments" "css_element"
    And I log out
    When I am on the "Лекция по оптике" "agora activity" page logged in as "student2"
    Then I should see "Comments (1)"
    And I should see "Спасибо за лекцию" in the ".mod-agora-comments" "css_element"

  @javascript
  Scenario: Преподаватель удаляет чужой комментарий
    Given I am on the "Лекция по оптике" "agora activity" page logged in as "student1"
    And I set the field "content" to "Спасибо за лекцию"
    And I follow "Save comment"
    And I should see "Спасибо за лекцию" in the ".mod-agora-comments" "css_element"
    And I log out
    When I am on the "Лекция по оптике" "agora activity" page logged in as "teacher1"
    Then I should see "Спасибо за лекцию" in the ".mod-agora-comments" "css_element"
    And I click on ".mod-agora-comments .comment-delete a" "css_element"
    And I wait "4" seconds
    And I should not see "Спасибо за лекцию"

  @javascript
  Scenario: Эфир начался, пока слушатель на странице — плеер появился без перезагрузки
    Given the Agora stream "optics-2026" becomes "not_started"
    When I am on the "Лекция по оптике" "agora activity" page logged in as "student1"
    Then I should see "The broadcast has not started yet."
    And "[data-region='agora-scene'] iframe" "css_element" should not exist
    And I mark the Agora page to detect a reload
    And the Agora stream "optics-2026" becomes "live"
    And I wait until the Agora page shows the live player
    And I should not see "The broadcast has not started yet."
    And I should see "Live" in the ".mod-agora-metaline" "css_element"
    And the Agora page has not been reloaded

  @javascript
  Scenario: Эфир начался, пока слушатель смотрит запись — запись не выдёргивается
    When I am on the "Вторая лекция" "agora activity" page logged in as "student1"
    Then the Agora stage plays the recording
    And the Agora recording plays within its archive window
    And I mark the Agora page to detect a reload
    And the Agora stream "townhall" becomes "live"
    And I wait until the Agora page shows the live banner
    And I should see "The broadcast has started."
    And the Agora stage plays the recording
    When I click on "Go to the broadcast" "button"
    And I wait until the Agora page shows the live player
    Then the Agora stage plays the live broadcast
    And "[data-region='agora-livebanner']" "css_element" should not exist
    And the Agora page has not been reloaded

  @javascript
  Scenario: Эфир вернулся после обрыва — плеер, бейдж в списке и кнопки обновились без перезагрузки
    Given the Agora stream "optics-2026" becomes "signal_lost"
    When I am on the "Лекция по оптике" "agora activity" page logged in as "student1"
    Then "Watch the recording" "link" should exist in the ".mod-agora-metaline" "css_element"
    And I should see "Signal lost" in the ".mod-agora-row-current" "css_element"
    And I mark the Agora page to detect a reload
    And the Agora stream "optics-2026" becomes "live"
    And I wait until the Agora page shows the live player
    Then "Watch the recording" "link" should not exist in the ".mod-agora-metaline" "css_element"
    And I should see "Live" in the ".mod-agora-row-current" "css_element"
    And I should not see "Signal lost" in the ".mod-agora-row-current" "css_element"
    And the Agora page has not been reloaded

  @javascript
  Scenario: Билет не выписался в начале эфира — страница остаётся на заглушке и пробует снова
    Given the Agora stream "optics-2026" becomes "not_started"
    When I am on the "Лекция по оптике" "agora activity" page logged in as "student1"
    And I mark the Agora page to detect a reload
    And the Agora stand refuses tickets
    And the Agora stream "optics-2026" becomes "live"
    And I wait "40" seconds
    Then I should see "The broadcast has not started yet."
    And "[data-region='agora-scene'] iframe" "css_element" should not exist
    And the Agora page has not been reloaded
    And the Agora stand issues tickets
    And I wait until the Agora page shows the live player
    And the Agora page has not been reloaded

  @javascript
  Scenario: Сбой сети на пути к плееру — страница не перезагружается и пробует снова
    Given the Agora stream "optics-2026" becomes "not_started"
    When I am on the "Лекция по оптике" "agora activity" page logged in as "student1"
    And I mark the Agora page to detect a reload
    And the browser breaks requests for the Agora scene
    And the Agora stream "optics-2026" becomes "live"
    And I wait "40" seconds
    Then I should see "The broadcast has not started yet."
    And the Agora page has not been reloaded
    And the browser repairs requests for the Agora scene
    And I wait until the Agora page shows the live player
    And the Agora page has not been reloaded

  @javascript
  Scenario: Сбой по кнопке «Перейти к эфиру» — запись на месте, плашка одна, опрос не перезапущен
    When I am on the "Вторая лекция" "agora activity" page logged in as "student1"
    And I mark the Agora page to detect a reload
    And the Agora stream "townhall" becomes "live"
    And I wait until the Agora page shows the live banner
    And the browser breaks requests for the Agora scene
    When I click on "Go to the broadcast" "button"
    And I wait "25" seconds
    Then there is exactly one Agora live banner
    And the Agora stage plays the recording
    And the Agora page has not been reloaded
    And the browser repairs requests for the Agora scene
    When I click on "Go to the broadcast" "button"
    And I wait until the Agora page shows the live player
    Then the Agora stage plays the live broadcast
    And the Agora page has not been reloaded

  @javascript
  Scenario: Повторяющийся сбой на пути к плееру — интервал повторов растёт
    Given the Agora stream "optics-2026" becomes "not_started"
    When I am on the "Лекция по оптике" "agora activity" page logged in as "student1"
    And I mark the Agora page to detect a reload
    And the browser breaks requests for the Agora scene
    And the Agora stream "optics-2026" becomes "live"
    And I wait "125" seconds
    Then the Agora scene was retried with a growing interval
    And I should see "The broadcast has not started yet."
    And the Agora page has not been reloaded
