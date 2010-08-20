<?php

/*
 * Quizzer extension for MediaWiki
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * @author Stas Fomin <stas-fomin@yandex.ru>
 * @author Vitaliy Filippov <vitalif@mail.ru>
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 */

require_once 'urandom.php';

class MediawikiQuizzerUpdater
{
    static $test_field_types = array(
        'name' => 1,
        'intro' => 0,
        'mode' => 2,
        'shuffle_questions' => 3,
        'shuffle_choices' => 3,
        'limit_questions' => 4,
        'ok_percent' => 4,
        'autofilter_min_tries' => 4,
        'autofilter_success_percent' => 4
    );
    static $test_keys;
    static $regexps;
    static $qn_keys = array('choice', 'choices', 'correct', 'corrects', 'label', 'explanation', 'comments');

    /* Parse wiki-text $text without TOC, heading numbers and EditSection links */
    static function parse($article, $text)
    {
        global $wgParser;
        if (defined('MAG_NUMBEREDHEADINGS') && ($mag = MagicWord::get(MAG_NUMBEREDHEADINGS)))
            $mag->matchAndRemove($text);
        MagicWord::get('toc')->matchAndRemove($text);
        MagicWord::get('forcetoc')->matchAndRemove($text);
        $options = clone $wgParser->mOptions;
        $options->mNumberHeadings = false;
        $options->mEditSection = false;
        $html = $wgParser->parse("__NOTOC__\n$text", $article->getTitle(), $options, true, false)->getText();
        $html = preg_replace('#<a\s+[^<>]*>\s*</a>#is', '', $html);
        return $html;
    }

    /* Build regular expressions to match headings */
    static function getRegexps()
    {
        wfLoadExtensionMessages('MediawikiQuizzer');
        $test_regexp = array();
        $qn_regexp = array();
        self::$test_keys = array_keys(self::$test_field_types);
        foreach (self::$test_keys as $k)
            $test_regexp[] = '('.wfMsgNoTrans("mwquizzer-parse-test_$k").')';
        foreach (self::$qn_keys as $k)
            $qn_regexp[] = '('.wfMsgNoTrans("mwquizzer-parse-$k").')';
        $test_regexp_nq = $test_regexp;
        array_unshift($test_regexp, '('.wfMsgNoTrans('mwquizzer-parse-question').')');
        $test_regexp = str_replace('/', '\\/', implode('|', $test_regexp));
        $test_regexp_nq = '()'.str_replace('/', '\\/', implode('|', $test_regexp_nq));
        $qn_regexp = str_replace('/', '\\/', implode('|', $qn_regexp));
        self::$regexps = array($test_regexp, $test_regexp_nq, $qn_regexp);
    }

    /* Parse extracted question section */
    static function parseQuestion($q)
    {
        $question = array('choices' => array());
        $question['qn_label'] = DOMParseUtils::saveChildNodesXML(DOMParseUtils::trimDOM($q['title']));
        $subsect = DOMParseUtils::getSections($q['content'], self::$regexps[2], true, NULL, true);
        $sect0 = array_shift($subsect);
        $question['qn_text'] = trim(DOMParseUtils::saveChildNodesXML($sect0['content']));
        foreach ($subsect as $ss)
        {
            foreach ($ss['match'] as $i => $v)
            {
                if ($v[0])
                {
                    $field = self::$qn_keys[$i];
                    break;
                }
            }
            if (!$field)
                die(__METHOD__.": Mystical bug detected, this should never happen");
            if ($field == 'comments')
            {
                /* Comments to question are skipped */
            }
            elseif ($field == 'explanation' || $field == 'label')
            {
                /* Explanation and label are of HTML type */
                $question["qn_$field"] = trim(DOMParseUtils::saveChildNodesXML($ss['content']));
            }
            else
            {
                /* Some kind of choice(s) section */
                $e = $ss['content'];
                $correct = ($field == 'correct' || $field == 'corrects') ? 1 : 0;
                if ($field == 'choice' || $field == 'correct')
                {
                    /* Section with a single choice */
                    if ($e->childNodes->length)
                    {
                        /* Allow single <ul> or <ol> item with single <li> inside */
                        $e1 = $e->childNodes->item(0);
                        $n = strtolower($e1->nodeName);
                        if (($n == 'ul' || $n == 'ol') && $e1->childNodes->length == 1)
                        {
                            $e2 = $e1->childNodes->item(0);
                            if (strtolower($e2->nodeName) == 'li')
                                $e = $e2;
                        }
                    }
                    $choices = array($e);
                }
                else
                {
                    /* Section with multiple choices */
                    $choices = DOMParseUtils::getListItems($ss['content']);
                }
                foreach ($choices as $e)
                {
                    /* Allow optional "Correct choice: " at the beginning of the choice */
                    $checked = DOMParseUtils::checkNode($e, wfMsgNoTrans('mwquizzer-parse-correct'), true);
                    if ($checked)
                    {
                        $e = $checked[0];
                        $correct = 1;
                    }
                    $question['choices'][] = array(
                        'ch_text'    => DOMParseUtils::saveChildNodesXML(DOMParseUtils::trimDOM($e)),
                        'ch_correct' => $correct,
                    );
                }
            }
        }
        return $question;
    }

    /* Parse extracted quiz parameter section */
    static function parseQuizField($q)
    {
        foreach ($q['match'] as $i => $v)
        {
            if ($v[0])
            {
                $field = self::$test_keys[$i-1];
                break;
            }
        }
        if (!$field)
            die(__METHOD__.": Mystical bug detected, this should never happen");
        $value = trim(DOMParseUtils::saveChildNodesXML($q['content']));
        $t = self::$test_field_types[$field];
        if ($t > 0) /* not an HTML code */
        {
            $value = trim(strip_tags($value));
            if ($t == 2) /* mode */
                $value = strpos(strtolower($value), 'tutor') !== false ? 1 : 0;
            elseif ($t == 3) /* boolean */
            {
                $re = str_replace('/', '\\/', wfMsgNoTrans('mwquizzer-parse-true'));
                $value = preg_match("/$re/uis", $value) ? 1 : 0;
            }
            elseif ($t == 4) /* integer */
                $value = intval($value);
            /* else ($t == 1) // just a string */
        }
        return array("test_$field", $value);
    }

    /* Extract MediawikiQuizzer questions, choices and quiz parameters from HTML code given in $html */
    static function parseQuiz($html)
    {
        $test = array();
        self::getRegexps();
        $document = DOMParseUtils::loadDOM($html);
        /* match headings */
        $sections = DOMParseUtils::getSections($document->documentElement, self::$regexps[0], true, NULL, true);
        $section0 = array_shift($sections);
        if ($section0['content'])
        {
            /* parse section 0 */
            $s0 = DOMParseUtils::getSections($section0['content'], self::$regexps[1], true, array('dt' => 1), false);
            $sections = array_merge($s0, $sections);
        }
        foreach ($sections as $q)
        {
            if ($q['match'][0][0])
            {
                /* A question */
                $test['questions'][] = self::parseQuestion($q);
            }
            else
            {
                /* Quiz parameter */
                list($key, $value) = self::parseQuizField($q);
                $test[$key] = $value;
            }
        }
        return $test;
    }

    /* Parse $text and update data of the quiz linked to article title */
    static function updateQuiz($article, $text)
    {
        $html = self::parse($article, $text);
        $quiz = self::parseQuiz($html);
        $quiz['test_id'] = mb_substr($article->getTitle()->getText(), 0, 32);
        if (!$quiz['questions'])
            return;
        $t2q = array();
        $qkeys = array();
        $ckeys = array();
        $questions = array();
        $choices = array();
        $hashes = array();
        foreach ($quiz['questions'] as $i => $q)
        {
            $hash = $q['qn_text'];
            foreach ($q['choices'] as $c)
                $hash .= $c['ch_text'];
            $hash = preg_replace('/\s+/s', '', $hash);
            $hash = md5($hash);
            foreach ($q['choices'] as $j => $c)
            {
                $c['ch_question_hash'] = $hash;
                $c['ch_num'] = $j+1;
                $ckeys += $c;
                $choices[] = $c;
            }
            $q['qn_hash'] = $hash;
            $hashes[] = $hash;
            unset($q['choices']);
            $qkeys += $q;
            $questions[] = $q;
            $t2q[] = array(
                'qt_test_id' => $quiz['test_id'],
                'qt_question_hash' => $hash,
                'qt_num' => $i+1,
            );
        }
        foreach ($qkeys as $k => $v)
            if (!array_key_exists($k, $questions[0]))
                $questions[0][$k] = '';
        foreach ($ckeys as $k => $v)
            if (!array_key_exists($k, $choices[0]))
                $choices[0][$k] = '';
        unset($quiz['questions']);
        $dbw = wfGetDB(DB_MASTER);
        $dbw->delete('mwq_question_test', array('qt_test_id' => $quiz['test_id']), __METHOD__);
        $dbw->delete('mwq_choice', array('ch_question_hash' => $hashes), __METHOD__);
        self::insertOrUpdate($dbw, 'mwq_test', array($quiz), __METHOD__);
        self::insertOrUpdate($dbw, 'mwq_question', $questions, __METHOD__);
        self::insertOrUpdate($dbw, 'mwq_question_test', $t2q, __METHOD__);
        self::insertOrUpdate($dbw, 'mwq_choice', $choices, __METHOD__);
    }

    static function insertOrUpdate($dbw, $table, $rows, $fname)
    {
        global $wgDBtype;
        if ($wgDBtype != 'mysql')
            die('MediawikiQuizzer uses MySQL-specific INSERT INTO ... ON DUPLICATE KEY UPDATE by now. Fix it if you want.');
        $keys = array_keys($rows[0]);
        $sql = 'INSERT INTO ' . $dbw->tableName($table) . ' (' . implode(',', $keys) . ') VALUES ';
        foreach ($rows as &$row)
        {
            $r = array();
            foreach ($keys as $k)
                if (array_key_exists($k, $row))
                    $r[] = $row[$k];
                else
                    $r[] = '';
            $row = '(' . $dbw->makeList($r) . ')';
        }
        $sql .= implode(',', $rows);
        foreach ($keys as &$key)
            $key = "`$key`=VALUES(`$key`)";
        $sql .= ' ON DUPLICATE KEY UPDATE '.implode(',', $keys);
        return $dbw->query($sql, $fname);
    }
}

class MediawikiQuizzerPage extends SpecialPage
{
    /* Identical to Xml::element, but does no htmlspecialchars() on $contents */
    static function xelement($element, $attribs = null, $contents = '', $allowShortTag = true)
    {
        if (is_null($contents))
            return Xml::openElement($element, $attribs);
        elseif ($contents == '')
            return Xml::element($element, $attribs, $contents, $allowShortTag);
        return Xml::openElement($element, $attribs) . $contents . Xml::closeElement($element);
    }

    /* Constructor */
    function __construct()
    {
        global $IP, $wgScriptPath, $wgUser, $wgParser, $wgEmergencyContact;
        SpecialPage::SpecialPage('MediawikiQuizzer');
        $this->mListed = false;
    }

    /* The entry point for special page */
    function execute($par = null)
    {
        global $wgOut, $wgRequest, $wgTitle, $wgLang, $wgServer, $wgScriptPath;
        $args = $wgRequest->getValues();
        wfLoadExtensionMessages('MediawikiQuizzer');

        $mode = $args['mode'];
        if ($mode == 'check')
        {
            $this->checkTest($args);
            return;
        }

        $id = $par;
        if (!$id)
            $id = $args['id'];
        if (!$id)
            $id = $args['id_test']; // backward compatibility

        if (!$id)
        {
            $wgOut->showErrorPage('mwquizzer-no-test-id-title', 'mwquizzer-no-test-id-text');
            return;
        }

        $test = $this->loadTest($id);
        if (!$test)
        {
            $wgOut->showErrorPage('mwquizzer-test-not-found-title', 'mwquizzer-test-not-found-text');
            return;
        }

        if ($mode == 'print')
            $this->printTest($test, $args);
        else
            $this->showTest($test, $args);
    }

    /* Load a test from database. Optionally shuffle/limit questions and answers,
       compute variant ID (sequence hash) and scores. */
    function loadTest($id, $variant = NULL)
    {
        global $wgOut;
        $dbr = wfGetDB(DB_SLAVE);

        $result = $dbr->select('mwq_test', '*', array('test_id' => $id), __METHOD__);
        $test = $dbr->fetchRow($result);
        $dbr->freeResult($result);

        if (!$test)
            return NULL;

        if ($variant)
        {
            $variant = @unserialize($variant);
            if (!is_array($variant))
                $variant = NULL;
        }

        $result = $dbr->select(
            array('mwq_question', 'mwq_question_test', 'mwq_choice_stats'),
            'mwq_question.*, COUNT(cs_correct) tries, SUM(cs_correct) correct_tries',
            array('qt_test_id' => $id),
            __METHOD__,
            array('GROUP BY' => 'qn_hash', 'ORDER BY' => 'qt_num'),
            array('mwq_choice_stats' => array('LEFT JOIN', array('cs_question_hash=qn_hash')), 'mwq_question_test' => array('INNER JOIN', array('qt_question_hash=qn_hash')))
        );
        if ($dbr->numRows($result) <= 0)
            return NULL;

        $test['questions'] = array();
        while ($q = $dbr->fetchRow($result))
        {
            if (!$variant &&
                $q['qn_autofilter_min_tries'] > 0 && $q['tries'] >= $q['qn_autofilter_min_tries'] &&
                $q['correct_tries']/$q['tries'] >= $q['qn_autofilter_correct_percent']/100.0)
            {
                /* Statistics tells us this question is too simple, skip it */
                wfDebug(__CLASS__.': Skipping '.$q['qn_hash'].', because correct percent = '.$q['correct_tries'].'/'.$q['tries'].' >= '.$q['qn_autofilter_correct_percent']."%\n");
                continue;
            }

            $result2 = $dbr->select('mwq_choice', '*', array('ch_question_hash' => $q['qn_hash']), __METHOD__, array('ORDER BY' => 'ch_num'));
            if (!$variant && $dbr->numRows($result2) <= 0)
            {
                /* No choices defined for this question, skip it */
                wfDebug(__CLASS__.': Skipping '.$q['qn_hash'].", no choices!\n");
                continue;
            }

            $q['choices'] = array();
            $q['correct_count'] = 0;
            while ($choice = $dbr->fetchRow($result2))
            {
                if ($choice['ch_correct'])
                    $q['correct_count']++;
                $q['choices'][$choice['ch_num']] = $choice;
            }
            $dbr->freeResult($result2);

            if (!$variant && $q['correct_count'] <= 0)
            {
                /* No correct choices defined for this question, skip it */
                wfDebug(__CLASS__.': Skipping '.$q['qn_hash'].", no correct choices!\n");
                continue;
            }
            elseif (!$variant && $q['correct_count'] >= count($q['choices']))
            {
                /* All choices for this question are defined as correct, skip it */
                wfDebug(__CLASS__.': Skipping '.$q['qn_hash'].", all choices defined as correct!\n");
                continue;
            }

            // optionally shuffle choices
            if (!$variant && $test['test_shuffle_choices'])
            {
                $shuffled = $q['choices'];
                shuffle($shuffled);
                $q['choices'] = array();
                foreach ($shuffled as $choice)
                    $q['choices'][$choice['ch_num']] = $choice;
            }

            // build an array of correct choices
            $q['correct_choices'] = array();
            foreach ($q['choices'] as $i => $choice)
            {
                if ($choice['ch_correct'])
                {
                    $choice['index'] = $i+1;
                    $q['correct_choices'][] = &$choice;
                }
            }

            // add 1/n for correct answers
            $q['score_correct'] = 1.0 / $q['correct_count'];
            // subtract 1/(m-n) for incorrect answers, so universal mean would be 0
            $q['score_incorrect'] = -1.0 / (count($q['choices']) - $q['correct_count']);

            $test['questions'][$q['qn_hash']] = $q;
        }
        $dbr->freeResult($result);

        // optionally shuffle and limit questions
        if (!$variant && $test['test_shuffle_questions'])
            shuffle($test['questions']);
        if (!$variant && $test['test_limit_questions'])
            array_splice($test['questions'], $test['test_limit_questions']);

        // When a valid variant ID is passed to this function, no randomness is allowed.
        // Exactly the selected variant is loaded.
        if ($variant)
        {
            $nt = array();
            foreach($variant as $q)
            {
                $nq = $test['questions'][$q[0]];
                if (!$nq)
                    continue;
                $nc = array();
                foreach ($q[1] as $num)
                    $nc[] = $nq['choices'][$num];
                $nq['choices'] = $nc;
                $nt[] = $nq;
            }
            $test['questions'] = $nt;
        }

        // a variant ID is computed using hashes of selected questions and sequences of their answers
        $variant = array();
        foreach ($test['questions'] as $q)
            $variant[] = array($q['qn_hash'], array_keys($q['choices']));
        $test['variant_hash'] = serialize($variant);
        $test['variant_hash_crc32'] = crc32($test['variant_hash']);
        $test['variant_hash_md5'] = md5($test['variant_hash']);

        $test['random_correct'] = 0;
        $test['max_score'] = 0;
        foreach ($test['questions'] as $q)
        {
            // correct answers count for random selection
            $test['random_correct'] += $q['correct_count'] / count($q['choices']);
            // maximum total score
            $test['max_score'] += $q['score_correct'];
        }

        return $test;
    }

    /* Get a table with question numbers linked to the appropriate questions */
    function getToc($n)
    {
        if ($n <= 0)
            return '';
        $s = '';
        for ($k = 0; $k < $n;)
        {
            $row = '';
            for ($j = 0; $j < 10; $j++, $k++)
            {
                $text = $k < $n ? self::xelement('a', array('href' => "#q$k"), $k+1) : '&nbsp;';
                $row .= self::xelement('td', NULL, $text);
            }
            $s .= self::xelement('tr', NULL, $row);
        }
        $s = self::xelement('table', array('class' => 'mwq-toc'), $s);
        return $s;
    }

    /* Get HTML ordered list with questions, choices, and optionally radio-buttons for selecting them when $inputs is TRUE */
    function getQuestionList($questions, $inputs = false)
    {
        $html = '';
        $i = 0;
        foreach ($questions as $k => $q)
        {
            $html .= Xml::element('hr');
            $html .= self::xelement('a', array('name' => "q$i"), '', false);
            $html .= self::xelement('h3', NULL, wfMsg('mwquizzer-question', $i+1));
            $html .= self::xelement('div', array('class' => 'mwq-question'), $q['qn_text']);
            $choices = '';
            foreach($q['choices'] as $ck => $c)
            {
                if ($inputs)
                {
                    /* Question hashes are hidden from user. They are taken from ticket during check. */
                    $h = Xml::radio("a[$i]", $c['ch_num'], array('id' => "q$i-c$ck")) .
                         '&nbsp;' .
                         $c['ch_text'];
                }
                else
                    $h = $c['ch_text'];
                $choices .= self::xelement('li', array('class' => 'mwq-choice'), $h);
            }
            $html .= self::xelement('ol', array('class' => 'mwq-choices'), $choices);
            $i++;
        }
        return $html;
    }

    /* Get javascript code for HH:MM:SS counter */
    function getCounterJs()
    {
        global $wgScriptPath;
        $format = wfMsg('mwquizzer-counter-format');
        return <<<EOT
<script language="JavaScript">
BackColor = "white";
ForeColor = "navy";
CountActive = true;
CountStepper = 1;
LeadingZero = true;
DisplayFormat = "$format";
FinishMessage = "";
</script>
<script language="JavaScript" src="$wgScriptPath/extensions/mediawikiquizzer/countdown.js"></script>
EOT;
    }

    /* Create a ticket and a secret key for testing, and remember the variant */
    function createTicket($test)
    {
        global $wgUser;
        $key = unpack('H*', urandom(16));
        $key = $key[1];
        $start = wfTimestampNow(TS_MW);
        $userid = $wgUser->getId();
        if (!$userid)
            $userid = NULL;
        $dbw = wfGetDB(DB_MASTER);
        $ticket = array(
            'tk_id'          => $dbw->nextSequenceValue('tk_id'),
            'tk_key'         => $key,
            'tk_start_time'  => $start,
            'tk_end_time'    => NULL,
            'tk_displayname' => NULL,
            'tk_user_id'     => $userid,
            'tk_user_text'   => $wgUser->getName(),
            'tk_user_ip'     => wfGetIP(),
            'tk_test_id'     => $test['test_id'],
            'tk_variant'     => $test['variant_hash'],
        );
        $dbw->insert('mwq_ticket', $ticket, __METHOD__);
        $ticket['tk_id'] = $dbw->insertId();
        return $ticket;
    }

    /* Display main form for testing */
    function showTest($test, $args)
    {
        global $wgTitle, $wgOut;

        $ticket = $this->createTicket($test);
        $action = $wgTitle->getFullUrl(array(
            'id'         => $test['test_id'],
            'ticket_id'  => $ticket['tk_id'],
            'ticket_key' => $ticket['tk_key'],
        ));

        $form = '';
        $form .= wfMsg('mwquizzer-prompt') . '&nbsp;' . Xml::input('prompt', 20);
        $form .= $this->getQuestionList($test['questions'], true);
        $form = self::xelement('form', array('action' => $action, 'method' => 'POST'), $form);

        $html = $this->getToc(count($test['questions']));
        if ($test['test_intro'])
            $html .= self::xelement('div', array('class' => 'mwq-intro'), $test['test_intro']);
        $html .= $this->getCounterJs();
        $html .= $form;

        $wgOut->setPageTitle(wfMsg('mwquizzer-pagetitle', $test['test_name']));
        $wgOut->addHTML($html);
    }

    /* Display a "dump" for the test:
     * - all questions without information about correct answers
     * - a printable empty table for filling it with answer numbers
     * - a table similar to the previous, but filled with correct answer numbers and question labels ("check-list")
     *   (question label is intended to briefly describe question subject)
     * Check list is shown only to test administrators and users who can read
     * the test source article according to HaloACL rights, if HaloACL is enabled.
     */
    function printTest($test, $args)
    {
        global $wgOut;
        $html = '';

        /* Display question list */
        $html .= Xml::element('hr', array('style' => 'page-break-after: always'), '');
        $html .= self::xelement('h2', NULL, wfMsg('mwquizzer-question-sheet'));
        if ($test['test_intro'])
            $html .= self::xelement('div', array('class' => 'mwq-intro'), $test['test_intro']);
        $html .= $this->getQuestionList($test['questions'], false);

        /* Display questionnaire */
        $html .= Xml::element('hr', array('style' => 'page-break-after: always'), '');
        $html .= self::xelement('h2', NULL, wfMsg('mwquizzer-test-sheet'));
        $html .= $this->getCheckList($test, $args, false);

        $title = Title::newFromText('Quiz:'.$test['id']);
        if (MediawikiQuizzer::isTestAdmin() ||
            $title && method_exists($title, 'userCanReadEx') && $title->userCanReadEx())
        {
            /* Display check-list to users who can read source article */
            $html .= Xml::element('hr', array('style' => 'page-break-after: always'), '');
            $html .= self::xelement('h2', NULL, wfMsg('mwquizzer-answer-sheet'));
            $html .= $this->getCheckList($test, $args, true);
        }

        $wgOut->setPageTitle(wfMsg('mwquizzer-print-pagetitle', $test['test_name']));
        $wgOut->addHTML($html);
    }

    /* Display a table with question numbers, correct answers, statistics and labels when $checklist is TRUE
       Display a table with question numbers and two blank columns - "answer" and "remark" when $checklist is FALSE */
    function getCheckList($test, $args, $checklist = false)
    {
        $table = '';
        $table .= self::xelement('th', NULL, wfMsg('mwquizzer-table-number'));
        $table .= self::xelement('th', NULL, wfMsg('mwquizzer-table-answer'));
        if ($checklist)
        {
            $table .= self::xelement('th', NULL, wfMsg('mwquizzer-table-stats'));
            $table .= self::xelement('th', NULL, wfMsg('mwquizzer-table-label'));
        }
        else
            $table .= self::xelement('th', NULL, wfMsg('mwquizzer-table-remark'));
        foreach ($test['questions'] as $k => $q)
        {
            $row = array($k+1);
            if ($checklist)
            {
                /* build a list of correct choice indexes in the shuffled array */
                $correct_indexes = array();
                foreach ($q['correct_choices'] as $c)
                    $correct_indexes[] = $c['index'];
                $row[] = implode(', ', $correct_indexes);
                $row[] = $q['tries'] ? $q['correct_tries'] . '/' . $q['tries'] . ' ≈ ' . round($q['correct_tries'] * 100.0 / $q['tries']) : '';
                $row[] = $q['qn_label'];
            }
            $table .= '<tr><td>'.implode('</td><td>', $row).'</td></tr>';
        }
        $table = self::xelement('table', array('class' => $checklist ? 'mwq-checklist' : 'mwq-questionnaire'), $table);
        return $table;
    }

    /* Load saved answer numbers from database */
    function loadAnswers($ticket_id)
    {
        $answers = array();
        $dbr = wfGetDB(DB_SLAVE);
        $result = $dbr->select('mwq_choice_stats', '*', array(
            'cs_ticket' => $ticket_id,
        ), __FUNCTION__);
        while ($row = $dbr->fetchRow($result))
            $answers[$row['cs_question_hash']] = $row['cs_choice_num'];
        $dbr->freeResult($result);
        return $answers;
    }

    /* Load answers from POST data, save them into DB and return as the result */
    function checkAnswers($test, $ticket_id, $args)
    {
        $answers = array();
        $rows = array();
        foreach ($test['questions'] as $i => $q)
        {
            $n = $args['a'][$i];
            $is_correct = $q['choices'][$n]['ch_correct'] ? 1 : 0;
            $answers[$q['qn_hash']] = $n;
            /* Build rows for saving answers into database */
            $rows[] = array(
                'cs_ticket'        => $ticket['tk_id'],
                'cs_question_hash' => $q['qn_hash'],
                'cs_choice_num'    => $n,
                'cs_correct'       => $is_correct,
            );
        }
        $dbw = wfGetDB(DB_MASTER);
        $dbw->insert('mwq_choice_stats', $rows, __METHOD__);
        return $answers;
    }

    /* Either check an unchecked ticket, or load results from the database
       if the ticket is already checked */
    function checkOrLoadResult(&$ticket, $test, $args)
    {
        global $wgUser;
        $testresult = array(
            'correct_count' => 0,
            'score'         => 0,
        );

        $sendmail = false;
        if ($ticket['tk_end_time'])
        {
            /* Ticket already checked, load answers from database */
            $testresult['answers'] = $this->loadAnswers($ticket['tk_id']);
            $testresult['seen'] = true;
        }
        else
        {
            /* Else check POSTed answers */
            $testresult['answers'] = $this->checkAnswers($test, $ticket['tk_id'], $args);
            /* Update ticket */
            $userid = $wgUser->getId();
            if (!$userid)
                $userid = NULL;
            $update = array(
                'tk_end_time'    => wfTimestampNow(TS_MW),
                'tk_displayname' => $args['prompt'],
                'tk_user_id'     => $userid,
                'tk_user_text'   => $wgUser->getName(),
                'tk_user_ip'     => wfGetIP(),
            );
            $ticket = array_merge($ticket, $update);
            $dbw = wfGetDB(DB_MASTER);
            $dbw->update('mwq_ticket', $update, array('tk_id' => $ticket['tk_id']), __METHOD__);
            /* Need to send mail */
            $sendmail = true;
        }

        /* Calculate scores */
        foreach ($test['questions'] as $q)
        {
            $n = $testresult['answers'][$q['qn_hash']];
            $c = $q['choices'][$n]['ch_correct'] ? 1 : 0;
            $testresult['correct_count'] += $c;
            $testresult['score'] += $q['choices'][$n][$c ? 'score_correct' : 'score_incorrect'];
        }

        $testresult['correct_percent'] = round($testresult['correct_count']/count($test['questions'])*100, 1);
        $testresult['score_percent'] = round($testresult['score']/$test['max_score']*100, 1);
        if ($testresult['score_percent'] >= $test['test_ok_percent'])
            $testresult['passed'] = true;

        if ($sendmail)
        {
            /* Send mail with test results to administrator(s) */
            $this->sendMail($ticket, $test, $testresult);
        }

        return $testresult;
    }

    /* Build email text */
    function buildMailText($ticket, $test, $testresult)
    {
        $msg_q = wfMsg('mwquizzer-question');
        $msg_r = wfMsg('mwquizzer-right-answer');
        $msg_y = wfMsg('mwquizzer-your-answer');
        $text = '';
        foreach ($test['questions'] as $q)
        {
            $num = $testresult['answers'][$q['qn_hash']];
            if (!$num || !$q['choices'][$num]['ch_correct'])
            {
                $qn_text = trim(strip_tags($q['qn_text']));
                $qn_correct = trim(strip_tags($q['correct_choices'][0]['ch_text']));
                $qn_user = $num ? trim(strip_tags($q['choices'][$num]['ch_text'])) : '';
                /* TODO (?) format this as HTML and send HTML emails */
                $text .= <<<EOT
================================================================================
$msg_q $q[qt_num] | $q[qn_label] | $q[correct_tries]/$q[tries]
--------------------------------------------------------------------------------
$qn_text

$msg_r
$qn_correct
--------------------------------------------------------------------------------
$msg_y: [№$num] $qn_user
≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈
EOT;
            }
        }
        /* TODO (?) format this as HTML and send HTML emails */
        $values = array(
            array('quiz',       "$test[test_name] /* Id: $test[test_id] */"),
            array('who',        $ticket['tk_displayname'] ? $ticket['tk_displayname'] : $ticket['tk_user_text']),
            array('user',       $ticket['tk_user_text']),
            array('start',      $ticket['tk_start_time']),
            array('end',        $ticket['tk_end_time']),
            array('ip',         $ticket['tk_ip']),
            array('answers',    "$testresult[correct_count] ≈ $testresult[correct_percent]% (random: $test[random_correct])"),
            array('score',      "$testresult[score] ≈ $testresult[score_percent]%"),
        );
        $len = 0;
        foreach ($values as &$v)
        {
            $v[2] = wfMsg('mwquizzer-email-'.$v[0]);
            $v[3] = mb_strlen($v[2]);
            if ($v[3] > $len)
                $len = $v[3];
        }
        $header = '';
        foreach ($values as &$v)
            $header .= $v[2] . ': ' . str_repeat(' ', $len-$v[3]) . $v[1] . "\n";
        $text = $header . $text;
        return $text;
    }

    /* Send emails with test results to administrators */
    function sendMail($ticket, $test, $testresult)
    {
        global $egMWQuizzerAdmins, $wgEmergencyContact;
        /* TODO (?) send mail without correct answers to user */
        $text = $this->buildMailText($ticket, $test, $testresults);
        $sender = new MailAddress($wgEmergencyContact);
        foreach ($egMWQuizzerAdmins as $admin)
        {
            if (($user = User::newFromName($admin)) &&
                ($user = $user->getEmail()))
            {
                $to = new MailAddress($user);
                $mailResult = UserMailer::send($to, $sender, "[Quiz] «".$test['test_name']."» $ticket[tk_id] => $testresult[score_percent]%", $text);
            }
        }
    }

    /* Get ticket from the database */
    function loadTicket($id, $key)
    {
        $dbr = wfGetDB(DB_SLAVE);
        $result = $dbr->select('mwq_ticket', '*', array(
            'tk_id'  => $id,
            'tk_key' => $key,
        ), __FUNCTION__);
        $ticket = $dbr->fetchRow($result);
        $dbr->freeResult($result);
        return $ticket;
    }

    /* Check mode: check selected choices if not already checked, 
       display results and completion certificate */
    function checkTest($args)
    {
        $ticket = $this->loadTicket($args['ticket_id'], $args['ticket_key']);
        if (!$ticket)
        {
            global $wgTitle;
            if ($args['id'])
            {
                $test = $this->loadTest($args['id']);
                $name = $test['test_name'];
                $href = $wgTitle->getFullUrl(array('id' => $test['test_id']));
            }
            $wgOut->showErrorPage('mwquizzer-check-no-ticket-title', 'mwquizzer-check-no-ticket-text', array($name, $href));
            return;
        }

        $test = $this->loadTest($ticket['tk_test'], $ticket['tk_variant']);
        $testresult = $this->checkOrLoadResult($ticket, $test, $args);

        $html = '';
        if ($testresult['seen'])
            $html .= wfMsg('mwquizzer-variant-already-seen');

        if ($test['test_intro'])
            $html .= self::xelement('div', array('class' => 'mwq-intro'), $test['test_intro']);

        $html .= wfMsg('mwquizzer-variant', $test['variant_hash_crc32']);

        $html .= $this->getResultHtml($ticket, $test, $testresult);

        if ($testresult['passed'] && ($ticket['tk_displayname'] || $ticket['tk_user_id']))
        {
            $html .= Xml::element('hr');
            $html .= $this->getCertificateHtml($ticket, $test, $testresult);
        }

        if ($test['test_mode'] == 'TUTOR')
        {
            $html .= Xml::element('hr');
            $html .= $this->getTutorList($ticket, $test, $testresult);
        }

        $wgOut->setPageTitle(wfMsg('mwquizzer-check-pagetitle', $test['test_name']));
        $wgOut->addHTML($html);
    }

    /* A cell with <span>$n</span> ≈ $p% */
    static function resultCell($n, $p)
    {
        $cell = self::xelement('span', array('class' => 'mwq-count'), $n);
        $cell .= ' ≈ ' . $p . '%';
        return $cell;
    }

    /* Get HTML code for result table (answers/score count/percent) */
    function getResultHtml($ticket, $test, $testresult)
    {
        $html = self::xelement('h2', NULL, wfMsg('mwquizzer-results'));
        $row = self::xelement('th', NULL, wfMsg('mwquizzer-right-answers'))
             . self::xelement('th', NULL, wfMsg('mwquizzer-score'));
        $html .= self::xelement('tr', NULL, $row);
        $row = self::resultCell($testresult['correct_count'], $testresult['correct_percent'])
             . self::resultCell($testresult['score'], $testresult['score_percent']);
        $html .= self::xelement('tr', NULL, $row);
        $html .= self::xelement('p', array('class' => 'mwq-rand'), wfMsg('mwquizzer-random-correct', round($testresult['random_correct'], 1)));
        return $html;
    }

    /* Draw an image with test completion certificate */
    function drawCertificate($certpath, $ticket, $test, $testresult)
    {
        global $egMWQuizzerCertificateTemplate;

        if (file_exists("$certpath.jpg") && file_exists("$certpath.thumb.jpg"))
            return true;

        $username = $ticket['tk_displayname'] ? $ticket['tk_displayname'] : $ticket['tk_user_text'];
        $name = preg_replace('/ {2,}/s', ' ', strip_tags($test['test_name']));
        $intro = preg_replace('/ {2,}/s', ' ', strip_tags($test['test_intro']));

        try
        {
            $image = new Imagick($egMWQuizzerCertificateTemplate);
            $draw = new ImagickDraw();
            $draw->setFontFamily("Times");
            $draw->setTextAlignment(imagick::ALIGN_CENTER);
            $draw->setFillColor("#062BFE");
            $draw->setFontSize(36);
            $draw->annotation(400, 190, $username);
            $draw->annotation(400, 330, $name);
            $draw->setFontSize(18);
            $draw->annotation(400, 370, $intro);
            $image->drawImage($draw);
            $image->setCompressionQuality(80);
            $image->writeImage("$certpath.jpg");
            $image->thumbnailImage(160, 0);
            $image->writeImage("$certpath.thumb.jpg");
        }
        catch (Exception $e)
        {
            wfDebug(__METHOD__.": ImageMagick failed with the following message:\n".$e->getMessage()."\n");
            return false;
        }

        return true;
    }

    /* Generate a completion certificate and get HTML code for the certificate */
    function getCertificateHtml($ticket, $test, $testresult)
    {
        global $egMWQuizzerCertificateDir, $egMWQuizzerCertificateUri;
        $code = $ticket['tk_key'] . '-' . $ticket['tk_id'];

        $hash = substr($code, 0, 1) . '/' . substr($code, 0, 2) . '/';
        mkdir($egMWQuizzerCertificateDir . $hash, 0777, true);
        $certpath = $egMWQuizzerCertificateDir . $hash . $code;
        $certuri = $egMWQuizzerCertificateUri . $hash . $code;
        if (!preg_match('#^[a-z]+://#is', $certuri))
        {
            if ($certuri{0} != '/')
                $certuri = "/$certuri";
            $certuri = $wgServer . $certuri;
        }

        if (!$this->drawCertificate($certpath, $ticket, $test, $testresult))
            return false;

        $code = self::xelement('img', array('src' => "$certpath.thumb.jpg"));
        $code = self::xelement('a', array('href' => "$certpath.jpg", 'target' => '_blank'), $cell);
        $testhref = Title::newFromText('Special:MediawikiQuizzer/'.$test['test_id']);
        $testtry = wfMsg('mwquizzer-try', $test['test_name']);
        $code .= "\n" . self::xelement('a', array('href' => $testhref, 'target' => '_blank'), $testtry);

        $html = self::xelement('table', NULL, self::xelement('tr', NULL,
            self::xelement('td', NULL, $code) .
            self::xelement('td', NULL, wfMsg('mwquizzer-congratulations') . self::xelement('pre', NULL, $code))
        ));

        return $html;
    }

    /* TUTOR mode tests display all incorrect answered questions with
       correct answers and explanations after testing. */
    function getTutorHtml($ticket, $test, $testresult)
    {
        $cnt = 0;
        foreach ($test['questions'] as $q)
        {
            $num = $testresult['answers'][$q['qn_hash']];
            if ($num && $q['choices'][$num]['ch_correct'])
                continue;
            $cnt++;
            $correct = $q['correct_choices'][0];
            $html .= Xml::element('hr');
            $html .= self::xelement('a', array('name' => "q$k"), '', false);
            $html .= self::xelement('h3', NULL, wfMsg('mwquizzer-question', $k+1));
            $html .= self::xelement('div', array('class' => 'mwq-question'), $q['qn_text']);
            $html .= self::xelement('h4', NULL, wfMsg('mwquizzer-right-answer'));
            $html .= self::xelement('div', array('class' => 'mwq-right-answer'), $correct['ch_text']);
            if ($q['qn_explanation'])
            {
                $html .= self::xelement('h4', NULL, wfMsg('mwquizzer-explanation'));
                $html .= self::xelement('div', array('class' => 'mwq-explanation'), $q['qn_explanation']);
            }
            if ($num)
            {
                $html .= self::xelement('h4', NULL, wfMsg('mwquizzer-your-answer'));
                $html .= self::xelement('div', array('class' => 'mwq-your-answer'), $q['choices'][$num]['ch_text']);
            }
        }
        if ($cnt)
            $html = $this->getToc($qn) . $html;
        return $html;
    }
}
