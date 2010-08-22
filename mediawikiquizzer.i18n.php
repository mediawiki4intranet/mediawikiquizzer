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
    'mwquizzer-actions'                 => '<p><a href="$2">Try the quiz "$1"</a> &nbsp; | &nbsp; <a href="$3">Export the quiz "$1"</a></p>',

    /* Errors */
    'mwquizzer-no-test-id-title'        => 'Quiz ID is undefined!',
    'mwquizzer-no-test-id-text'         => 'You opened a hyperlink not containing quiz ID.',
    'mwquizzer-test-not-found-title'    => 'Quiz not found',
    'mwquizzer-test-not-found-text'     => 'Quiz with this ID is not found in database!',
    'mwquizzer-check-no-ticket-title'   => 'Incorrect check link',
    'mwquizzer-check-no-ticket-text'    => 'You want to check the test, but no correct ticket ID is present in the request.<br />Try <a href="$2">the quiz «$1»</a> again.',

    'mwquizzer-question'                => 'Question',
    'mwquizzer-counter-format'          => '%%H%%:%%M%%:%%S%% elapsed.',
    'mwquizzer-prompt'                  => 'If you want to receive a test completion certificate, please, enter you name:',
    'mwquizzer-submit'                  => 'Submit answers',
    'mwquizzer-pagetitle'               => '$1 — questions',
    'mwquizzer-question-sheet'          => 'Question List',
    'mwquizzer-test-sheet'              => 'Questionnaire',
    'mwquizzer-answer-sheet'            => 'Control Sheet',
    'mwquizzer-print-pagetitle'         => '$1 — printable version',
    'mwquizzer-table-number'            => '№',
    'mwquizzer-table-answer'            => 'Answer',
    'mwquizzer-table-stats'             => 'Statistics',
    'mwquizzer-table-label'             => 'Label',
    'mwquizzer-table-remark'            => 'Remarks',
    'mwquizzer-right-answer'            => 'Correct answer',
    'mwquizzer-your-answer'             => 'Selected answer',
    'mwquizzer-variant-already-seen'    => 'You have already tried this variant. Try <a href="$1">another one</a>.',
    'mwquizzer-check-pagetitle'         => '$1 — results',
    'mwquizzer-results'                 => 'Results',
    'mwquizzer-variant'                 => '<p>Variant $1.</p>',
    'mwquizzer-right-answers'           => 'Correct answers count',
    'mwquizzer-score'                   => 'Score',
    'mwquizzer-random-correct'          => '<i>Note that the expectation of  матожидание числа правильных ответов при случайном выборе ≈ <b>$1</b></i>',
    'mwquizzer-try'                     => 'Try <a href="$2">the quiz «$1»</a>!',
    'mwquizzer-congratulations'         => 'You passed the quiz! Insert the following HTML code into your blog or homepage:',
    'mwquizzer-explanation'             => 'Explanation',

    /* Field names for result e-mails */
    'mwquizzer-email-quiz'              => 'Quiz',
    'mwquizzer-email-who'               => 'Name',
    'mwquizzer-email-user'              => 'Mediawiki username',
    'mwquizzer-email-start'             => 'Start time',
    'mwquizzer-email-end'               => 'End time',
    'mwquizzer-email-ip'                => 'IP',
    'mwquizzer-email-answers'           => 'Correct answers',
    'mwquizzer-email-score'             => 'Score',

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
    'mediawikiquizzer'                  => 'Опросы MediaWiki',
    'mwquizzer-actions'                 => '<p><a href="$2">Пройти тест «$1»</a> &nbsp; | &nbsp; <a href="$3">Экспортировать тест «$1»</a></p>',

    /* Ошибки */
    'mwquizzer-no-test-id-title'        => 'Не задан идентификатор теста!',
    'mwquizzer-no-test-id-text'         => 'Вы перешли по ссылке, не содержащей идентификатор теста.',
    'mwquizzer-test-not-found-title'    => 'Тест не найден',
    'mwquizzer-test-not-found-text'     => 'Тест с этим номером не определен!',
    'mwquizzer-check-no-ticket-title'   => 'Неверная ссылка',
    'mwquizzer-check-no-ticket-text'    => 'Запрошен режим проверки, но идентификатор вашей попытки прохождения теста не задан или неверен.<br />Попробуйте <a href="$2">пройти тест «$1»</a> заново.',

    'mwquizzer-question'                => 'Вопрос $1',
    'mwquizzer-counter-format'          => 'Прошло %%H%%:%%M%%:%%S%%.',
    'mwquizzer-prompt'                  => 'Если хотите получить сертификат прохождения теста, пожалуйста, введите свое имя:',
    'mwquizzer-submit'                  => 'Отправить ответы',
    'mwquizzer-pagetitle'               => '$1 — вопросы',
    'mwquizzer-question-sheet'          => 'Лист вопросов',
    'mwquizzer-test-sheet'              => 'Форма для тестирования',
    'mwquizzer-answer-sheet'            => 'Проверочный лист',
    'mwquizzer-print-pagetitle'         => '$1 — версия для печати',
    'mwquizzer-table-number'            => '№',
    'mwquizzer-table-answer'            => 'Ответ',
    'mwquizzer-table-stats'             => 'Статистика',
    'mwquizzer-table-label'             => 'Метка',
    'mwquizzer-table-remark'            => 'Примечание',
    'mwquizzer-right-answer'            => 'Правильный ответ',
    'mwquizzer-your-answer'             => 'Выбранный ответ',
    'mwquizzer-variant-already-seen'    => 'На этот вариант вы уже отвечали. Попробуйте <a href="$1">другой вариант</a>.',
    'mwquizzer-check-pagetitle'         => '$1 — результаты',
    'mwquizzer-results'                 => 'Итог',
    'mwquizzer-variant'                 => '<p>Вариант $1.</p>',
    'mwquizzer-right-answers'           => 'Число правильных ответов',
    'mwquizzer-score'                   => 'Набрано очков',
    'mwquizzer-random-correct'          => '<i>Кстати, матожидание числа правильных ответов при случайном выборе ≈ <b>$1</b></i>',
    'mwquizzer-try'                     => 'Попробуй <a href="$2">пройти тест «$1»</a>!',
    'mwquizzer-congratulations'         => 'Вы успешно прошли тест! Можете вставить следующий HTML-код в ваш блог или сайт:',
    'mwquizzer-explanation'             => 'Пояснение',

    /* Названия полей в e-mail письмах с результатами */
    'mwquizzer-email-quiz'              => 'Тест',
    'mwquizzer-email-who'               => 'Имя',
    'mwquizzer-email-user'              => 'Пользователь',
    'mwquizzer-email-start'             => 'Время начала',
    'mwquizzer-email-end'               => 'Время окончания',
    'mwquizzer-email-ip'                => 'IP',
    'mwquizzer-email-answers'           => 'Число правильных ответов',
    'mwquizzer-email-score'             => 'Набрано очков',

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
