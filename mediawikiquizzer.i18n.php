<?php

/*
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * @author Stas Fomin <stas-fomin@yandex.ru>
 * @author Vitaliy Filippov <vitalif@mail.ru>
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 */

$messages = array();

$messages['en'] = array(
    'mediawikiquizzer'                  => 'MediaWiki Quizzer',
    'mwquizzer-actions'                 => '<p><a href="$2">Try the quiz "$1"</a> &nbsp; | &nbsp; <a href="$3">Printable version</a></p>',
    'mwquizzer-show-parselog'           => '[+] Show quiz parse log',
    'mwquizzer-hide-parselog'           => '[-] Hide quiz parse log',
    'mwquizzer-complete-stats'          => 'Correct answers: $1/$2 ($3 %)',
    'mwquizzer-no-complete-stats'       => '(no statistics)',

    /* Errors */
    'mwquizzer-no-test-id-title'        => 'Quiz ID is undefined!',
    'mwquizzer-no-test-id-text'         => 'You opened a link without valid quiz ID to run.',
    'mwquizzer-test-not-found-title'    => 'Quiz not found',
    'mwquizzer-test-not-found-text'     => 'Quiz with this ID is not found in database!',
    'mwquizzer-check-no-ticket-title'   => 'Incorrect check link',
    'mwquizzer-check-no-ticket-text'    => 'You want to check the test, but no correct ticket ID is present in the request.<br />Try <a href="$2">the quiz «$1»</a> again.',
    'mwquizzer-review-denied-title'     => 'Review access denied',
    'mwquizzer-review-option'           =>
'You opened a link without valid quiz ID to run.

Maybe you\'ve meant to open quiz result review form?

If so, enter the ID (article title) of a test \'\'\'to source of which you do have read\'\'\' into the field below and click "Select results".',
    'mwquizzer-review-denied-all'       =>
'Reviewing completion attempts for \'\'\'all\'\'\' quizzes is available only for MWQuizzer administrators.

However, you can review completion attempts for quizzes to source of which you do have access.

To do so, enter the ID (article title) of such a test into the field below and click "Select results".',
    'mwquizzer-review-denied-quiz'      =>
'Reviewing completion attempts for \'\'\'this\'\'\' quiz is not available for only.

Try entering the ID (article title) of quiz to source of which you do have access.

Then click "Select results" again.',

    'mwquizzer-pagetitle'               => '$1 — questions',
    'mwquizzer-print-pagetitle'         => '$1 — printable version',
    'mwquizzer-check-pagetitle'         => '$1 — results',
    'mwquizzer-review-pagetitle'        => 'MediaWiki Quizzer — review test results',

    'mwquizzer-question'                => 'Question $1',
    'mwquizzer-counter-format'          => '%%H%%:%%M%%:%%S%% elapsed.',
    'mwquizzer-prompt'                  => 'Your name:',
    'mwquizzer-prompt-needed'           => 'Please fill in these required fields:',
    'mwquizzer-submit'                  => 'Submit answers',
    'mwquizzer-question-sheet'          => 'Question List',
    'mwquizzer-test-sheet'              => 'Questionnaire',
    'mwquizzer-user-answers'            => 'User Answers',
    'mwquizzer-is-correct'              => 'Correct',
    'mwquizzer-is-incorrect'            => 'Incorrect',
    'mwquizzer-answer-sheet'            => 'Control Sheet',
    'mwquizzer-table-number'            => '№',
    'mwquizzer-table-answer'            => 'Answer',
    'mwquizzer-table-stats'             => 'Statistics',
    'mwquizzer-table-label'             => 'Label',
    'mwquizzer-table-remark'            => 'Remarks',
    'mwquizzer-right-answer'            => 'Correct answer',
    'mwquizzer-your-answer'             => 'Selected answer',
    'mwquizzer-variant-already-seen'    => 'You have already tried this variant. Try <a href="$1">another one</a>.',
    'mwquizzer-ticket-details'          => '<p>User: $1. Time: $2 — $3 ($4).</p>',
    'mwquizzer-ticket-reviewed'         => '<b>This result is already reviewed by administrator.</b>',
    'mwquizzer-results'                 => 'Results',
    'mwquizzer-variant-msg'             => '<p>Variant $1.</p>',
    'mwquizzer-right-answers'           => 'Correct answers',
    'mwquizzer-score-long'              => 'Score',
    'mwquizzer-random-correct'          => '<i>Note that the average correct answers with randoms selection ≈ <b>$1</b></i>',
    'mwquizzer-test-average'            => 'All users average correct answers to this test ≈ <b>$1 %</b>',
    'mwquizzer-try-quiz'                => 'Try <a href="$2">the quiz «$1»</a>!',
    'mwquizzer-try'                     => 'try',
    'mwquizzer-congratulations'         => 'You passed the quiz! Insert the following HTML code into your blog or homepage:',
    'mwquizzer-explanation'             => 'Explanation',
    'mwquizzer-anonymous'               => 'Anonymous',
    'mwquizzer-select-tickets'          => 'Select results',
    'mwquizzer-ticket-count'            => 'Found $1, showing $3 from $2.',
    'mwquizzer-no-tickets'              => 'No tickets found.',
    'mwquizzer-pages'                   => 'Pages: ',

    /* Names of various fields */
    'mwquizzer-ticket-id'               => 'Ticket ID',
    'mwquizzer-quiz'                    => 'Quiz',
    'mwquizzer-quiz-title'              => 'Quiz title',
    'mwquizzer-variant'                 => 'Variant',
    'mwquizzer-who'                     => 'Display name',
    'mwquizzer-user'                    => 'User',
    'mwquizzer-start'                   => 'Start time',
    'mwquizzer-end'                     => 'End time',
    'mwquizzer-duration'                => 'Duration',
    'mwquizzer-ip'                      => 'IP address',
    'mwquizzer-perpage'                 => 'Count on one page',
    'mwquizzer-show-details'            => 'show user details',
    'mwquizzer-score'                   => 'Score',
    'mwquizzer-correct'                 => 'Correct',

    /* Regular expressions used to parse various quiz field names */
    'mwquizzer-parse-test_name'                         => 'Name|Title',
    'mwquizzer-parse-test_intro'                        => 'Intro|Short[\s_]*Desc(?:ription)?',
    'mwquizzer-parse-test_mode'                         => 'Mode',
    'mwquizzer-parse-test_shuffle_questions'            => 'Shuffle[\s_]*questions',
    'mwquizzer-parse-test_shuffle_choices'              => 'Shuffle[\s_]*answers|Shuffle[\s_]*choices',
    'mwquizzer-parse-test_limit_questions'              => 'Limit[\s_]*questions|Questions?[\s_]*limit',
    'mwquizzer-parse-test_ok_percent'                   => 'OK\s*%|Pass[\s_]*percent|OK[\s_]*percent|Completion\s*percent',
    'mwquizzer-parse-test_autofilter_min_tries'         => '(?:too[\s_]*simple|autofilter)[\s_]*min[\s_]*tries',
    'mwquizzer-parse-test_autofilter_success_percent'   => '(?:too[\s_]*simple|autofilter)[\s_]*(?:ok|success)[\s_]*percent',
    'mwquizzer-parse-test_user_details'                 => 'Ask[\s_]*user',

    /* Regular expressions used to parse questions etc */
    'mwquizzer-parse-question'      => 'Question[:\s]*',
    'mwquizzer-parse-choice'        => '(?:Choice|Answer)(?!s)',
    'mwquizzer-parse-choices'       => 'Choices|Answers',
    'mwquizzer-parse-correct'       => '(?:Correct|Right)\s*(?:Choice|Answer)(?!s)[:\s]*',
    'mwquizzer-parse-corrects'      => '(?:Correct|Right)\s*(?:Choices|Answers)',
    'mwquizzer-parse-label'         => 'Label',
    'mwquizzer-parse-explanation'   => 'Explanation',
    'mwquizzer-parse-comments'      => 'Comments?',
    'mwquizzer-parse-true'          => 'Yes|True|1',
);

$messages['ru'] = array(
    'mediawikiquizzer'                  => 'ВикиЭкзамены',
    'mwquizzer-actions'                 => '<p><a href="$2">Пройти тест «$1»</a> &nbsp; | &nbsp; <a href="$3">Версия для печати</a></p>',
    'mwquizzer-show-parselog'           => '[+] Показать лог разбора страницы теста',
    'mwquizzer-hide-parselog'           => '[-] Скрыть лог разбора страницы теста',
    'mwquizzer-complete-stats'          => 'Правильных ответов: $1/$2 ($3 %)',
    'mwquizzer-no-complete-stats'       => '(нет статистики)',

    /* Ошибки */
    'mwquizzer-no-test-id-title'        => 'Не задан идентификатор теста!',
    'mwquizzer-no-test-id-text'         => 'Вы перешли по ссылке, не содержащей идентификатор теста для запуска.',
    'mwquizzer-test-not-found-title'    => 'Тест не найден',
    'mwquizzer-test-not-found-text'     => 'Тест с этим номером не определен!',
    'mwquizzer-check-no-ticket-title'   => 'Неверная ссылка',
    'mwquizzer-check-no-ticket-text'    => 'Запрошен режим проверки, но идентификатор вашей попытки прохождения теста не задан или неверен.<br />Попробуйте <a href="$2">пройти тест «$1»</a> заново.',
    'mwquizzer-review-denied-title'     => 'Просмотр результатов запрещён',
    'mwquizzer-review-option'           =>
'Вы перешли по ссылке, не содержащей идентификатор теста для запуска.

Возможно, вы хотели перейти к форме просмотра результатов (только для теста, к которому имеете доступ)?

Если это действительно так, введите ID (название вики-статьи) теста, к которому имеете доступ, в поле ниже и нажмите «Выбрать результаты».',
    'mwquizzer-review-denied-all'       =>
'Просмотр результатов по \'\'\'всем\'\'\' тестам доступен только администраторам системы тестирования.

Тем не менее, вы можете просмотреть результаты по тем тестам, к исходному коду (вики-статье) которых имеете доступ.

Для этого введите ID (название вики-статьи) теста в поле ниже и нажмите «Выбрать результаты».',
    'mwquizzer-review-denied-quiz'      =>
'Вам запрещён просмотр результатов по \'\'\'выбранному\'\'\' тесту.

Введите ID (название вики-статьи) теста, к исходному коду (вики-статье) которого имеете доступ.

Далее снова нажмите «Выбрать результаты».',

    'mwquizzer-pagetitle'               => '$1 — вопросы',
    'mwquizzer-print-pagetitle'         => '$1 — версия для печати',
    'mwquizzer-check-pagetitle'         => '$1 — результаты',
    'mwquizzer-review-pagetitle'        => 'Опросы MediaWiki — просмотр результатов',

    'mwquizzer-question'                => 'Вопрос $1',
    'mwquizzer-counter-format'          => 'Прошло %%H%%:%%M%%:%%S%%.',
    'mwquizzer-prompt'                  => 'Ваше имя:',
    'mwquizzer-prompt-needed'           => 'Обязательно заполните следующие поля:',
    'mwquizzer-submit'                  => 'Отправить ответы',
    'mwquizzer-question-sheet'          => 'Лист вопросов',
    'mwquizzer-test-sheet'              => 'Форма для тестирования',
    'mwquizzer-user-answers'            => 'Ответы пользователя',
    'mwquizzer-is-correct'              => 'Правильный',
    'mwquizzer-is-incorrect'            => 'Неправильный',
    'mwquizzer-answer-sheet'            => 'Проверочный лист',
    'mwquizzer-table-number'            => '№',
    'mwquizzer-table-answer'            => 'Ответ',
    'mwquizzer-table-stats'             => 'Статистика',
    'mwquizzer-table-label'             => 'Метка',
    'mwquizzer-table-remark'            => 'Примечание',
    'mwquizzer-right-answer'            => 'Правильный ответ',
    'mwquizzer-your-answer'             => 'Выбранный ответ',
    'mwquizzer-variant-already-seen'    => 'На этот вариант вы уже отвечали. Попробуйте <a href="$1">другой вариант</a>.',
    'mwquizzer-ticket-details'          => '<p>Имя: $1. Время теста: $2 — $3 ($4).</p>',
    'mwquizzer-ticket-reviewed'         => '<b>Уже проверено администратором.</b>',
    'mwquizzer-results'                 => 'Итог',
    'mwquizzer-variant-msg'             => '<p>Вариант $1.</p>',
    'mwquizzer-right-answers'           => 'Число правильных ответов',
    'mwquizzer-score-long'              => 'Набрано очков',
    'mwquizzer-random-correct'          => '<i>Кстати, матожидание числа правильных ответов при случайном выборе ≈ <b>$1</b></i>',
    'mwquizzer-test-average'            => 'Общий средний балл по данному тесту ≈ <b>$1 %</b> правильных ответов.',
    'mwquizzer-try-quiz'                => 'Попробуй <a href="$2">пройти тест «$1»</a>!',
    'mwquizzer-try'                     => 'пройти',
    'mwquizzer-congratulations'         => 'Вы успешно прошли тест! Можете вставить следующий HTML-код в ваш блог или сайт:',
    'mwquizzer-explanation'             => 'Пояснение',
    'mwquizzer-anonymous'               => 'Анонимный',
    'mwquizzer-select-tickets'          => 'Выбрать результаты',
    'mwquizzer-ticket-count'            => 'Найдено $1, показано $3, начиная с №$2.',
    'mwquizzer-no-tickets'              => 'Не найдено ни одной попытки прохождения.',
    'mwquizzer-pages'                   => 'Страницы: ',

    /* Имена разных полей */
    'mwquizzer-ticket-id'               => 'ID попытки',
    'mwquizzer-quiz'                    => 'Тест',
    'mwquizzer-quiz-title'              => 'Заголовок',
    'mwquizzer-variant'                 => 'Вариант',
    'mwquizzer-who'                     => 'Имя',
    'mwquizzer-user'                    => 'Пользователь',
    'mwquizzer-start'                   => 'Время начала',
    'mwquizzer-end'                     => 'Время окончания',
    'mwquizzer-to'                      => ' до',
    'mwquizzer-duration'                => 'Длительность',
    'mwquizzer-ip'                      => 'IP-адрес',
    'mwquizzer-perpage'                 => 'На странице',
    'mwquizzer-show-details'            => 'показать анкеты',
    'mwquizzer-score'                   => 'Очки',
    'mwquizzer-correct'                 => 'Ответы',

    /* Регулярные выражения для разбора названий различных полей теста */
    'mwquizzer-parse-test_name'                         => 'Название|Name|Title',
    'mwquizzer-parse-test_intro'                        => 'Введение|Описание|Intro|Short[\s_]*Desc(?:ription)?',
    'mwquizzer-parse-test_mode'                         => 'Режим|Mode',
    'mwquizzer-parse-test_shuffle_questions'            => 'Переставлять\s*вопросы|Перемешать\s*вопросы|Перемешивать\s*вопросы|Shuffle[\s_]*questions',
    'mwquizzer-parse-test_shuffle_choices'              => 'Переставлять\s*ответы|Перемешать\s*ответы|Перемешивать\s*ответы|Shuffle[\s_]*answers|Shuffle[\s_]*choices',
    'mwquizzer-parse-test_limit_questions'              => 'Количество\s*вопросов|Число\s*вопросов|Ограничить\s*число\s*вопросов|Limit[\s_]*questions|Questions?[\s_]*limit',
    'mwquizzer-parse-test_ok_percent'                   => 'Процент\s*завершения|%\s*завершения|ОК\s*%|OK\s*%|Pass[\s_]*percent|OK[\s_]*percent|Completion\s*percent',
    'mwquizzer-parse-test_autofilter_min_tries'         => 'Мин[\s\.]*попыток\s*слишком\s*простых\s*вопросов|(?:too[\s_]*simple|autofilter)[\s_]*min[\s_]*tries',
    'mwquizzer-parse-test_autofilter_success_percent'   => '%\s*успехов\s*слишком\s*простых\s*вопросов|(?:too[\s_]*simple|autofilter)[\s_]*(?:ok|success)[\s_]*percent',
    'mwquizzer-parse-test_user_details'                 => 'Спросить\s*пользователя|Ask[\s_]*user',

    /* Регулярные выражения для разбора названий вопросов и т.п. */
    'mwquizzer-parse-question'      => '(?:Вопрос|Question)[:\s]*',
    'mwquizzer-parse-choice'        => 'Ответ(?!ы)|(?:Choice|Answer)(?!s)',
    'mwquizzer-parse-choices'       => 'Ответы|Варианты\s*ответа|Choices|Answers',
    'mwquizzer-parse-correct'       => '(?:Правильный\s*ответ(?!ы)|(?:Correct|Right)\s*(?:Choice|Answer)(?!s))[:\s]*',
    'mwquizzer-parse-corrects'      => 'Правильные\s*ответы|Правильные\s*варианты\s*ответа|(?:Correct|Right)\s*(?:Choices|Answers)',
    'mwquizzer-parse-label'         => 'Метка|Label',
    'mwquizzer-parse-explanation'   => '(?:Об|Раз)[ъь]яснение|Explanation',
    'mwquizzer-parse-comments'      => 'Примечани[ея]|Комментари[ий]|Comments?',
    'mwquizzer-parse-true'          => 'Да|Yes|True|1',
);
