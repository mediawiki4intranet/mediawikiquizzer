<?php

/**
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

require_once dirname(__FILE__).'/urandom.php';

/**
 * MediawikiQuizzerPage implements the special page Special:MediawikiQuizzer.
 * It has the following modes of execution:
 * (a) mode=show, handled by showTest()
 *     Displays a testing form with random test variant.
 * (b) mode=check, handled by checkTest()
 *     Handles "Send results" click on testing form - saves results
 *     into database and displays user results, completion certificate,
 *     and optionally the list of correct/incorrect articles if test type
 *     is TUTOR or if &showtut=1 and user is admin or has read access
 *     to the quiz article.
 * (c) mode=print, handled by printTest()
 *     Displays printable version of a random or given variant of the test.
 *     Correct answers are displayed to administrators and also to users who
 *     have read access to the quiz article.
 * (d) mode=review, handled by review()
 *     Test results review mode. Administrators have access to all test results,
 *     other users only have access to results of tests to source of which they
 *     have read access.
 */

class MediawikiQuizzerPage extends SpecialPage
{
    /* Default OK% */
    const DEFAULT_OK_PERCENT = 80;

    static $modes = array(
        'show' => 1,
        'check' => 1,
        'print' => 1,
        'review' => 1,
        'qr' => 1,
        'getticket' => 1,
    );

    static $questionInfoCache = array();

    static $is_adm = NULL;

    /**
     * Methods used in hooks outside Special:MediawikiQuizzer
     */

    // Display parse log and quiz actions for parsed quiz article
    static function quizArticleInfo($test_title)
    {
        global $wgOut, $wgScriptPath;
        $wgOut->addExtensionStyle("$wgScriptPath/extensions/".basename(dirname(__FILE__))."/mwquizzer-page.css");
        /* Load the test without questions */
        $quiz = self::loadTest(array('name' => $test_title), NULL, true);
        if (!$quiz)
            return;
        $s = Title::newFromText('Special:MediawikiQuizzer');
        $actions = array(
            'try'   => $s->getFullUrl(array('id' => $quiz['test_id']) + ($quiz['test_secret'] ? array('mode' => 'getticket') : array())),
            'print' => $s->getFullUrl(array('id' => $quiz['test_id'], 'mode' => 'print')),
        );
        $wgOut->addHTML(wfMsg(($quiz['test_secret'] ? 'mwquizzer-actions-secret' : 'mwquizzer-actions'), $quiz['test_name'], $actions['try'], $actions['print']));
        /* Display log */
        $log = $quiz['test_log'];
        if ($log)
        {
            $html = '';
            $a = self::xelement('a', array(
                'href' => 'javascript:void(0)',
                'onclick' => "document.getElementById('mwq-parselog').style.display='';document.getElementById('mwq-show-parselog').style.display='none'",
            ), wfMsg('mwquizzer-show-parselog'));
            $html .= self::xelement('p', array('id' => 'mwq-show-parselog'), $a);
            $log = explode("\n", $log);
            foreach ($log as &$s)
            {
                if (preg_match('/^\s*\[([^\]]*)\]\s*/s', $s, $m))
                {
                    $s = substr($s, strlen($m[0]));
                    if (mb_strlen($s) > 120)
                        $s = mb_substr($s, 0, 117) . '...';
                    $s = str_repeat(' ', 5-strlen($m[1])) . self::xelement('span', array('class' => 'mwq-log-'.strtolower($m[1])), '['.$m[1].']') . ' ' . $s;
                }
            }
            $log = self::xelement('pre', NULL, implode("\n", $log));
            $a = self::xelement('a', array(
                'href' => 'javascript:void(0)',
                'onclick' => "document.getElementById('mwq-parselog').style.display='none';document.getElementById('mwq-show-parselog').style.display=''",
            ), wfMsg('mwquizzer-hide-parselog'));
            $log = self::xelement('p', array('id' => 'mwq-hide-parselog'), $a) . $log;
            $html .= self::xelement('div', array('id' => 'mwq-parselog', 'style' => 'display: none'), $log);
            $wgOut->addHTML($html);
        }
    }

    // Get HTML for one question statistics message
    static function questionStatsHtml($correct, $complete)
    {
        global $egMWQuizzerEasyQuestionCompl, $egMWQuizzerHardQuestionCompl;
        $style = '';
        if ($complete)
        {
            $percent = intval(100*$correct/$complete);
            $stat = wfMsg('mwquizzer-complete-stats', $correct,
                $complete, $percent);
            if ($complete > 4)
            {
                if ($percent >= $egMWQuizzerEasyQuestionCompl)
                    $style = ' style="color: white; background: #080;"';
                elseif ($percent <= $egMWQuizzerHardQuestionCompl)
                    $style = ' style="color: white; background: #a00;"';
            }
            if ($style)
                $stat = '&nbsp;'.$stat.'&nbsp;';
        }
        else
            $stat = wfMsg('mwquizzer-no-complete-stats');
        $stat = '<span class="editsection"'.$style.'>'.$stat.'</span>';
        return $stat;
    }

    // Display quiz question statistics near editsection link
    static function quizQuestionInfo($title, $section, &$result)
    {
        $k = $title->getPrefixedDBkey();
        /* Load questions taken from this article into cache, if not yet */
        if (!isset(self::$questionInfoCache[$k]))
        {
            $dbr = wfGetDB(DB_SLAVE);
            $r = $dbr->select(
                array('mwq_question', 'mwq_choice_stats'),
                '*, COUNT(cs_correct) complete_count, SUM(cs_correct) correct_count',
                array(
                    'qn_anchor IS NOT NULL', 'qn_hash=cs_question_hash',
                    'qn_anchor LIKE '.$dbr->addQuotes("$k|%"),
                    // Old questions which are really not present in article
                    // text may reside in the DB, filter them out:
                    'EXISTS (SELECT * FROM mwq_question_test WHERE qt_question_hash=qn_hash)',
                ),
                __FUNCTION__,
                array('GROUP BY' => 'qn_hash')
            );
            foreach ($r as $obj)
            {
                preg_match('/\\|(\d+)$/', $obj->qn_anchor, $m);
                self::$questionInfoCache[$k][$m[1]] = $obj;
            }
            if (empty(self::$questionInfoCache[$k]))
                self::$questionInfoCache[$k] = NULL;
        }
        preg_match('/\d+/', $section, $m);
        $sectnum = $m[0];
        /* Append colored statistic hint to editsection span */
        if (self::$questionInfoCache[$k] && !empty(self::$questionInfoCache[$k][$sectnum]))
        {
            $obj = self::$questionInfoCache[$k][$sectnum];
            $result .= self::questionStatsHtml($obj->correct_count, $obj->complete_count);
        }
    }

    /* Identical to Xml::element, but does no htmlspecialchars() on $contents */
    static function xelement($element, $attribs = null, $contents = '', $allowShortTag = true)
    {
        if (is_null($contents))
            return Xml::openElement($element, $attribs);
        elseif ($contents == '')
            return Xml::element($element, $attribs, $contents, $allowShortTag);
        return Xml::openElement($element, $attribs) . $contents . Xml::closeElement($element);
    }

    /**
     * Methods used on special page
     */

    /* Constructor */
    function __construct()
    {
        global $IP, $wgScriptPath, $wgUser, $wgParser, $wgEmergencyContact;
        parent::__construct('MediawikiQuizzer');
    }

    /* Check if the user is an administrator for the test $name */
    static function isAdminForTest($name)
    {
        if (self::$is_adm === NULL)
            self::$is_adm = MediawikiQuizzer::isTestAdmin();
        if (self::$is_adm)
            return true;
        if ($name || !is_object($name) && strlen($name))
        {
            if (is_object($name))
                $title = $name;
            else
                $title = Title::newFromText($name, NS_QUIZ);
            if ($title && $title->exists() && $title->userCan('read'))
                return true;
        }
        return false;
    }

    /* SPECIAL PAGE ENTRY POINT */
    function execute($par = null)
    {
        global $wgOut, $wgRequest, $wgTitle, $wgLang, $wgServer, $wgScriptPath, $wgUser;
        $args = $_GET+$_POST;
        $wgOut->addExtensionStyle("$wgScriptPath/extensions/".basename(dirname(__FILE__))."/mwquizzer.css");

        $mode = isset($args['mode']) ? $args['mode'] : '';

        // Do not create Title from name because it will lead to permission errors
        // for unauthorized users in case of IntraACL Quiz: namespace protection
        $id = false;
        if ($par)
            $id = array('name' => $par);
        elseif (isset($args['id']))
            $id = array('id' => $args['id']);
        elseif (isset($args['id_test']))
            $id = array('name' => $args['id_test']); // backward compatibility

        $is_adm = self::isAdminForTest(NULL);
        $default_mode = false;
        if (!isset(self::$modes[$mode]))
        {
            $default_mode = true;
            $mode = $is_adm && !$id ? 'review' : 'show';
        }

        if ($mode == 'check')
        {
            /* Check mode requires loading of a specific variant, so don't load random one */
            $this->checkTest($args);
            return;
        }
        elseif ($mode == 'qr')
        {
            $this->qrCode($args);
            return;
        }
        elseif ($mode == 'review')
        {
            /* Review mode is available to test administrators and users who have access to source */
            if (!isset($args['quiz_name']))
                $args['quiz_name'] = '';
            if (self::isAdminForTest($args['quiz_name']))
                $this->review($args);
            else
            {
                $wgOut->setRobotPolicy('noindex,nofollow');
                $wgOut->setArticleRelated(false);
                $wgOut->enableClientCache(false);
                $wgOut->addWikiMsg(isset($args['quiz_name']) ? 'mwquizzer-review-denied-quiz' : 'mwquizzer-review-denied-all');
                $wgOut->addHTML($this->getSelectTestForReviewForm($args));
                $wgOut->setPageTitle(wfMsg('mwquizzer-review-denied-title'));
            }
            return;
        }

        /* Allow viewing ticket variant with specified key for print mode */
        $variant = $answers = $ticket = NULL;
        if (($mode == 'print' || $mode == 'show') &&
            !empty($args['ticket_id']) && !empty($args['ticket_key']) &&
            ($ticket = self::loadTicket($args['ticket_id'], $args['ticket_key'])))
        {
            $id = array('id' => $ticket['tk_test_id']);
            $variant = $ticket['tk_variant'];
            $answers = self::loadAnswers($ticket['tk_id']);
            if ($mode == 'show' && $ticket['tk_end_time'])
            {
                $this->checkTest($args);
                return;
            }
        }

        /* Raise error when no test is specified for mode=print or mode=show */
        if (!$id)
        {
            $wgOut->setRobotPolicy('noindex,nofollow');
            $wgOut->setArticleRelated(false);
            $wgOut->enableClientCache(false);
            if ($default_mode)
            {
                $wgOut->addWikiMsg('mwquizzer-review-option');
                $wgOut->addHTML($this->getSelectTestForReviewForm($args));
            }
            else
                $wgOut->addWikiMsg('mwquizzer-no-test-id-text');
            $wgOut->setPageTitle(wfMsg('mwquizzer-no-test-id-title'));
            return;
        }

        /* Load random or specific test variant */
        $test = self::loadTest($id, $variant);
        if (!$test || $test['test_secret'] && !$ticket &&
            !self::isAdminForTest($test['test_id']) && !$wgUser->isAllowed('secretquiz'))
        {
            $wgOut->showErrorPage('mwquizzer-test-not-found-title', 'mwquizzer-test-not-found-text');
            return;
        }

        if ($mode == 'print')
            $this->printTest($test, $args, $answers);
        elseif ($mode == 'getticket')
            self::showTicket($test);
        else
            self::showTest($test, $ticket, $args);
    }

    /* Return HTML content for "Please select test to review results" form */
    static function getSelectTestForReviewForm($args)
    {
        global $wgTitle;
        $form = '';
        $form .= wfMsg('mwquizzer-quiz').': ';
        $name = isset($args['quiz_name']) ? $args['quiz_name'] : '';
        $form .= self::xelement('input', array('type' => 'text', 'name' => 'quiz_name', 'value' => $name)) . ' ';
        $form .= Xml::submitButton(wfMsg('mwquizzer-select-tickets'));
        $form = self::xelement('form', array('action' => $wgTitle->getLocalUrl(array('mode' => 'review')), 'method' => 'POST'), $form);
        return $form;
    }

    /* Question must have at least 1 correct and 1 incorrect choice */
    static function finalizeQuestionRow(&$q, $var, $shuffle)
    {
        $hash = $q['qn_hash'];
        if (!$var && !count($q['choices']))
        {
            /* No choices defined for this question, skip it */
            wfDebug(__CLASS__.": Skipping $hash, no choices!\n");
        }
        elseif (!$var && $q['correct_count'] <= 0)
        {
            /* No correct choices defined for this question, skip it */
            wfDebug(__CLASS__.": Skipping $hash, no correct choices!\n");
        }
        else
        {
            if (isset($q['ch_order']))
            {
                /* Reorder choices according to saved variant */
                $nc = array();
                foreach ($q['ch_order'] as $num)
                    $nc[] = &$q['choiceByNum'][$num];
                $q['choices'] = $nc;
                unset($q['ch_order']);
            }
            elseif ($shuffle)
            {
                /* Or else optionally shuffle choices */
                shuffle($q['choices']);
            }
            /* Calculate scores */
            if ($q['correct_count'])
            {
                // add 1/n for correct answers
                $q['score_correct'] = 1;
                // subtract 1/(m-n) for incorrect answers, so universal mean would be 0
                $q['score_incorrect'] = $q['correct_count'] < count($q['choices']) ? -$q['correct_count'] / (count($q['choices']) - $q['correct_count']) : 0;
            }
            foreach ($q['choices'] as $i => &$c)
                $c['index'] = $i+1;
            return true;
        }
        return false;
    }

    /**
     * Load a test from database. Optionally shuffle/limit questions and answers,
     * compute variant ID (sequence hash) and scores.
     * $cond = array('id' => int $testId)
     * or $cond = array('name' => string $testName)
     * or $cond = array('name' => Title $testTitle)
     */
    static function loadTest($cond, $variant = NULL, $without_questions = false)
    {
        global $wgOut;
        $dbr = wfGetDB(DB_SLAVE);

        if (!empty($cond['id']))
            $where = array('test_id' => $cond['id']);
        elseif (!empty($cond['name']))
        {
            if ($cond['name'] instanceof Title)
                $cond['name'] = $cond['name']->getText();
            else
                $cond['name'] = str_replace('_', ' ', $cond['name']);
            $where = array('test_page_title' => $cond['name']);
        }
        $result = $dbr->select('mwq_test', '*', $where, __METHOD__);
        $test = $dbr->fetchRow($result);
        $dbr->freeResult($result);

        if (!$test)
            return NULL;

        $id = $test['test_id'];

        // decode entities inside test_name as it is used inside HTML <title>
        $test['test_name'] = html_entity_decode($test['test_name']);

        // default OK%
        if (!isset($test['ok_percent']) || $test['ok_percent'] <= 0)
            $test['ok_percent'] = self::DEFAULT_OK_PERCENT;

        // do not load questions if $without_questions == true
        if ($without_questions)
            return $test;

        if ($variant)
        {
            $variant = @unserialize($variant);
            if (!is_array($variant))
                $variant = NULL;
            else
            {
                $qhashes = array();
                foreach ($variant as $q)
                    $qhashes[] = $q[0];
            }
        }

        $fields = 'mwq_question.*, IFNULL(COUNT(cs_correct),0) tries, IFNULL(SUM(cs_correct),0) correct_tries';
        $tables = array('mwq_question', 'mwq_choice_stats', 'mwq_question_test');
        $where = array();
        $options = array('GROUP BY' => 'qn_hash', 'ORDER BY' => 'qt_num');
        $joins = array(
            'mwq_choice_stats' => array('LEFT JOIN', array('cs_question_hash=qn_hash')),
            'mwq_question_test' => array('INNER JOIN', array('qt_question_hash=qn_hash', 'qt_test_id' => $id)),
        );

        if ($variant)
        {
            /* Select questions with known hashes for loading a specific variant.
               This is needed because quiz set of questions can change over time,
               but we want to always display the known variant. */
            $where['qn_hash'] = $qhashes;
            $joins['mwq_question_test'][0] = 'LEFT JOIN';
        }

        /* Read questions: */
        $result = $dbr->select($tables, $fields, $where, __METHOD__, $options, $joins);
        if ($dbr->numRows($result) <= 0)
            return NULL;

        $rows = array();
        while ($q = $dbr->fetchRow($result))
        {
            if (!$q['correct_tries'])
                $q['correct_tries'] = 0;
            if (!$q['tries'])
                $q['tries'] = 0;

            if (!$variant && $test['test_autofilter_min_tries'] > 0 &&
                $q['tries'] >= $test['test_autofilter_min_tries'] &&
                $q['correct_tries']/$q['tries'] >= $test['test_autofilter_success_percent']/100.0)
            {
                /* Statistics tells us this question is too simple, skip it */
                wfDebug(__CLASS__.': Skipping '.$q['qn_hash'].', because correct percent = '.$q['correct_tries'].'/'.$q['tries'].' >= '.$test['test_autofilter_success_percent']."%\n");
                continue;
            }
            $q['choices'] = array();
            $q['correct_count'] = 0;
            $rows[$q['qn_hash']] = $q;
        }

        /* Optionally shuffle and limit questions */
        if (!$variant && ($test['test_shuffle_questions'] || $test['test_limit_questions']))
        {
            $new = $rows;
            if ($test['test_shuffle_questions'])
                shuffle($new);
            if ($test['test_limit_questions'])
                array_splice($new, $test['test_limit_questions']);
            $rows = array();
            foreach ($new as $q)
                $rows[$q['qn_hash']] = $q;
        }
        elseif ($variant)
        {
            $new = array();
            foreach ($variant as $q)
            {
                if ($rows[$q[0]])
                {
                    $rows[$q[0]]['ch_order'] = $q[1];
                    $new[$q[0]] = &$rows[$q[0]];
                }
            }
            $rows = $new;
        }

        /* Read choices: */
        if ($rows)
        {
            $result = $dbr->select(
                'mwq_choice', '*', array('ch_question_hash' => array_keys($rows)),
                __METHOD__, array('ORDER BY' => 'ch_question_hash, ch_num')
            );
            $q = NULL;
            while ($choice = $dbr->fetchRow($result))
            {
                if (!$q)
                    $q = &$rows[$choice['ch_question_hash']];
                elseif ($q['qn_hash'] != $choice['ch_question_hash'])
                {
                    if (!self::finalizeQuestionRow($q, $variant && true, $test['test_shuffle_choices']))
                        unset($rows[$q['qn_hash']]);
                    $q = &$rows[$choice['ch_question_hash']];
                }
                $q['choiceByNum'][$choice['ch_num']] = $choice;
                $q['choices'][] = &$q['choiceByNum'][$choice['ch_num']];
                if ($choice['ch_correct'])
                {
                    $q['correct_count']++;
                    $q['correct_choices'][] = &$q['choiceByNum'][$choice['ch_num']];
                }
            }
            if (!self::finalizeQuestionRow($q, $variant && true, $test['test_shuffle_choices']))
                unset($rows[$q['qn_hash']]);
            unset($q);
            $dbr->freeResult($result);
        }

        /* Finally, build question array for the test */
        $test['questions'] = array();
        foreach ($rows as $q)
        {
            $test['questionByHash'][$q['qn_hash']] = $q;
            $test['questions'][] = &$test['questionByHash'][$q['qn_hash']];
        }

        // a variant ID is computed using hashes of selected questions and sequences of their answers
        $variant = array();
        foreach ($test['questions'] as $q)
        {
            $v = array($q['qn_hash']);
            foreach ($q['choices'] as $c)
                $v[1][] = $c['ch_num'];
            $variant[] = $v;
        }
        $test['variant_hash'] = serialize($variant);
        $test['variant_hash_crc32'] = sprintf("%u", crc32($test['variant_hash']));
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

    // Get average correct count for the test
    function getAverage($test)
    {
        $dbr = wfGetDB(DB_SLAVE);
        $res = $dbr->query('SELECT AVG(a) a FROM ('.$dbr->selectSQLtext(
            array('mwq_ticket', 'mwq_choice_stats'),
            'SUM(cs_correct)/COUNT(cs_correct)*100 a',
            array('tk_id=cs_ticket', 'tk_test_id' => $test['test_id']),
            __METHOD__,
            array('GROUP BY' => 'cs_ticket')
        ).') t', __METHOD__);
        $row = $res->fetchObject();
        return round($row->a);
    }

    /*************/
    /* SHOW MODE */
    /*************/

    /* Get a table with question numbers linked to the appropriate questions */
    static function getToc($n, $trues = false)
    {
        if ($n <= 0)
            return '';
        $s = '';
        for ($k = 0; $k < $n;)
        {
            $row = '';
            for ($j = 0; $j < 10; $j++, $k++)
            {
                $args = NULL;
                if ($k >= $n)
                    $text = '';
                elseif ($trues && in_array($k, $trues) && !$trues[$k])
                {
                    $text = $k+1;
                    $args = array('class' => 'mwq-noitem');
                }
                else
                    $text = self::xelement('a', array('href' => "#q$k"), $k+1);
                $row .= self::xelement('td', $args, $text);
            }
            $s .= self::xelement('tr', NULL, $row);
        }
        $s = self::xelement('table', array('class' => 'mwq-toc'), $s);
        return $s;
    }

    /* Get HTML ordered list with questions, choices,
       optionally radio-buttons for selecting them when $inputs is TRUE,
       and optionally edit question section links when $editsection is TRUE. */
    static function getQuestionList($questions, $inputs = false, $editsection = false)
    {
        $html = '';
        foreach ($questions as $k => $q)
        {
            $html .= Xml::element('hr');
            $html .= self::xelement('a', array('name' => "q$k"), '', false);
            $h = wfMsg('mwquizzer-question', $k+1);
            if ($editsection)
                $h .= $q['qn_editsection'];
            $html .= self::xelement('h3', NULL, $h);
            $html .= self::xelement('div', array('class' => 'mwq-question'), $q['qn_text']);
            $choices = '';
            if ($q['correct_count'] >= count($q['choices']))
            {
                /* This is a free-text question */
                if ($inputs)
                    $html .= wfMsg('mwquizzer-freetext').' '.self::xelement('input', array('name' => "a[$k]", 'type' => 'text'));
            }
            else
            {
                foreach($q['choices'] as $i => $c)
                {
                    if ($inputs)
                    {
                        /* Question hashes and choice numbers are hidden from user.
                           They are taken from ticket during check. */
                        $h = Xml::element('input', array(
                            'name' => "a[$k]",
                            'type' => 'radio',
                            'value' => $i+1,
                        )) . '&nbsp;' . $c['ch_text'];
                    }
                    else
                        $h = $c['ch_text'];
                    $choices .= self::xelement('li', array('class' => 'mwq-choice'), $h);
                }
                $html .= self::xelement('ol', array('class' => 'mwq-choices'), $choices);
            }
        }
        return $html;
    }

    /* Get javascript code for HH:MM:SS counter */
    static function getCounterJs()
    {
        global $wgScriptPath;
        $format = wfMsg('mwquizzer-counter-format');
        $already = wfMsg('mwquizzer-refresh-to-retry');
        return <<<EOT
<script type="text/javascript">
var BackColor = "white";
var ForeColor = "navy";
var CountActive = true;
var CountStepper = 1;
var LeadingZero = true;
var DisplayFormat = "$format";
var FinishMessage = "";
$(window).unload(function() {
    // Prevent fast unload to bfcache
});
$(window).load(function()
{
    if (document.getElementById('_submitted').value && confirm('$already'))
    {
        window.location.href = window.location.href;
    }
});
</script>
<script type="text/javascript" src="$wgScriptPath/extensions/mediawikiquizzer/countdown.js"></script>
EOT;
    }

    /* Create a ticket and a secret key for testing, and remember the variant */
    static function createTicket($test, $start)
    {
        global $wgUser;
        $key = unpack('H*', urandom(16));
        $key = $key[1];
        $userid = $wgUser->getId();
        if (!$userid)
            $userid = NULL;
        $dbw = wfGetDB(DB_MASTER);
        $ticket = array(
            'tk_id'          => $dbw->nextSequenceValue('mwq_ticket_tk_id_seq'),
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

    static function formDef($test)
    {
        $fields = trim($test['test_user_details']);
        $found_name = false;
        $formdef = array();
        if ($fields)
        {
            if ($fields{0} != '[' ||
                ($formdef = @json_decode($test['test_user_details'], true)) === NULL)
            {
                $def = array();
                foreach (explode(',', $fields) as $f)
                {
                    $formdef[] = array('name' => $f, 'type' => 'text', 'mandatory' => true);
                }
            }
        }
        return $formdef;
    }

    static function showTicket($test)
    {
        global $wgOut, $wgTitle;
        $ticket = self::createTicket($test, NULL);
        $link = $wgTitle->getFullUrl(array(
            'id'         => $test['test_id'],
            'ticket_id'  => $ticket['tk_id'],
            'ticket_key' => $ticket['tk_key'],
        ));
        $wgOut->setPageTitle(wfMsg('mwquizzer-pagetitle', $test['test_name']));
        $wgOut->addHTML(
            wfMsg('mwquizzer-ticket-link').': <a href="'.$link.'">'.htmlspecialchars($link).'</a>'
        );
    }

    /* Display main form for testing */
    static function showTest($test, $ticket, $args, $empty = false)
    {
        global $wgTitle, $wgOut, $wgRequest;

        if (!$ticket)
            $ticket = self::createTicket($test, wfTimestampNow(TS_MW));
        elseif ($ticket['tk_end_time'])
            die('BUG: ticket is already answered');
        elseif (!$ticket['tk_start_time'])
        {
            global $wgUser;
            $userid = $wgUser->getId();
            if (!$userid)
                $userid = NULL;
            $update = array(
                'tk_start_time' => wfTimestampNow(TS_MW),
                'tk_user_id'     => $userid,
                'tk_user_text'   => $wgUser->getName(),
                'tk_user_ip'     => wfGetIP(),
            );
            $ticket = array_merge($ticket, $update);
            $dbw = wfGetDB(DB_MASTER);
            $dbw->update('mwq_ticket', $update, array('tk_id' => $ticket['tk_id']), __METHOD__);
        }
        $action = $wgTitle->getFullUrl(array(
            'id'         => $test['test_id'],
            'ticket_id'  => $ticket['tk_id'],
            'ticket_key' => $ticket['tk_key'],
            'mode'       => 'check',
        ));

        $form = '';
        $formdef = self::formDef($test);
        $found_name = false;
        $mandatory = '<span style="color:red" title="'.wfMsg('mwquizzer-prompt-needed').'">*</span>';
        if ($formdef)
        {
            foreach ($formdef as $i => $field)
            {
                if ($field['type'] == 'name')
                {
                    if ($found_name)
                    {
                        $field['type'] = 'text';
                    }
                    $found_name = true;
                }
                if ($field['type'] == 'name' || $field['type'] == 'text')
                {
                    $nm = $field['type'] == 'name' ? 'prompt' : "detail_$i";
                    $form .= '<tr><th><label for="'.$nm.'">' . trim($field['name']) . ($field['mandatory'] ? $mandatory : '') .
                        ':</label></th><td><input type="text" name="'.$nm.'" id="'.$nm.'" size="40" value="'.htmlspecialchars(@$args[$nm]).'" /></td></tr>';
                }
                elseif ($field['type'] == 'html')
                {
                    $form .= '<tr><td colspan="2">'.$field['name'].'</td></tr>';
                }
                elseif ($field['type'] == 'checkbox')
                {
                    $form .= '<tr><td colspan="2"><input type="checkbox" name="detail_'.$i.'" id="detail_'.$i.
                        '" value="'.htmlspecialchars($field['value']).'"'.(@$args["detail_$i"] ? ' checked="checked"' : '').' /> '.
                        '<label for="detail_'.$i.'">'.($field['mandatory'] && empty($field['multiple']) ? $mandatory.' ' : '').
                        ($field['value'] == '1' ? $field['name'] : $field['value']).
                        '</label></td></tr>';
                }
            }
        }
        if (!$found_name)
        {
            // Prompt user displayname
            $form = '<tr><th>' . wfMsg('mwquizzer-prompt') . $mandatory . ':</th><td>'.
                Xml::input('prompt', 30, @$args['prompt']) . '</td></tr>' .
                $form;
        }
        $form = '<table class="mwq-form">'.$form.'</table>';
        if ($empty)
        {
            $form = '<p class="error">'.wfMsg('mwquizzer-empty').'</p>'.$form;
        }
        $form .= self::xelement('p', NULL, Xml::submitButton(wfMsg('mwquizzer-submit')));
        if (empty($args['a']))
        {
            $form .= self::getQuestionList($test['questions'], true);
            $form .= Xml::element('hr');
            $form .= Xml::submitButton(wfMsg('mwquizzer-submit'));
        }
        else
        {
            // Include hidden answers if the form is already submitted
            $form .= Xml::input('a_values', false, json_encode($args['a'], JSON_UNESCAPED_UNICODE), array('type' => 'hidden'));
        }
        $form .= '<input type="hidden" name="_submitted" id="_submitted" value="" />';
        $form = self::xelement('form', array('action' => $action, 'method' => 'POST', 'onsubmit' => 'this._submitted.value = 1;'), $form);

        $html = self::getToc(count($test['questions']));
        if ($test['test_intro'])
        {
            $html .= self::xelement('div', array('class' => 'mwq-intro'), $test['test_intro']);
        }
        $html .= wfMsg('mwquizzer-variant-msg', $test['variant_hash_crc32']);
        $html .= Xml::element('hr');
        $html .= self::getCounterJs();
        $html .= $form;

        $wgOut->setPageTitle(wfMsg('mwquizzer-pagetitle', $test['test_name']));
        $wgOut->addHTML($html);
    }

    /**************/
    /* PRINT MODE */
    /**************/

    /* Display a "dump" for the test:
     * - all questions without information about correct answers
     * - a printable empty table for filling it with answer numbers
     * - a table similar to the previous, but filled with correct answer numbers and question labels ("check-list")
     *   (question label is intended to briefly describe question subject)
     * Check list is shown only to test administrators and users who can read the quiz source article.
     * Note that read access to articles included into the quiz are not checked.
     * CSS page-break styles are specified so you can print this page.
     */
    static function printTest($test, $args, $answers = NULL)
    {
        global $wgOut;
        $html = '';

        $is_adm = self::isAdminForTest($test['test_id']);

        /* TestInfo */
        $ti = wfMsg('mwquizzer-variant-msg', $test['variant_hash_crc32']);
        if ($test['test_intro'])
        {
            $ti = self::xelement('div', array('class' => 'mwq-intro'), $test['test_intro']) . $ti;
        }

        /* Display question list (with editsection links for admins) */
        $html .= self::xelement('h2', NULL, wfMsg('mwquizzer-question-sheet'));
        $html .= $ti;
        $html .= self::getQuestionList($test['questions'], false, !empty($args['edit']) && $is_adm);

        /* Display questionnaire */
        $html .= Xml::element('hr', array('style' => 'page-break-after: always'), '');
        $html .= self::xelement('h2', NULL, wfMsg('mwquizzer-test-sheet'));
        $html .= $ti;
        $html .= self::getCheckList($test, $args, false);

        /* Display questionnaire filled with user's answers */
        if ($answers !== NULL)
        {
            $html .= Xml::element('hr', array('style' => 'page-break-after: always'), '');
            $html .= self::xelement('h2', NULL, wfMsg('mwquizzer-user-answers'));
            $html .= wfMsg('mwquizzer-variant-msg', $test['variant_hash_crc32']);
            $html .= self::getCheckList($test, $args, false, $answers);
        }

        if ($is_adm)
        {
            /* Display check-list to users who can read source article */
            $html .= self::xelement('h2', array('style' => 'page-break-before: always'), wfMsg('mwquizzer-answer-sheet'));
            $html .= $ti;
            $html .= self::getCheckList($test, $args, true);
        }

        $wgOut->setPageTitle(wfMsg('mwquizzer-print-pagetitle', $test['test_name']));
        $wgOut->addHTML($html);
    }

    /* Display a table with question numbers, correct answers, statistics and labels when $checklist is TRUE
       Display a table with question numbers and two blank columns - "answer" and "remark" when $checklist is FALSE
       Display a table with question numbers and user answers when $answers is specified */
    static function getCheckList($test, $args, $checklist = false, $answers = NULL)
    {
        $table = '';
        $table .= self::xelement('th', array('class' => 'mwq-tn'), wfMsg('mwquizzer-table-number'));
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
            $row = '<td>'.($k+1).'</td>';
            if ($checklist)
            {
                /* build a list of correct choice indexes in the shuffled array (or texts for free-text questions) */
                $correct_indexes = array();
                foreach ($q['correct_choices'] as $c)
                {
                    $correct_indexes[] = $q['correct_count'] < count($q['choices']) ? $c['index'] : $c['ch_text'];
                }
                $row .= '<td>'.htmlspecialchars(implode(', ', $correct_indexes)).'</td>';
                if ($q['tries'])
                {
                    $row .= '<td>' . $q['correct_tries'] . '/' . $q['tries'] .
                        ' ≈ ' . round($q['correct_tries'] * 100.0 / $q['tries']) . '%</td>';
                }
                else
                    $row .= '<td></td>';
                $row .= '<td>'.$q['qn_label'].'</td>';
            }
            elseif ($answers && !empty($answers[$q['qn_hash']]))
            {
                $ans = $answers[$q['qn_hash']];
                $ch = !empty($ans['cs_choice_num']) ? $q['choiceByNum'][$ans['cs_choice_num']] : NULL;
                $row .= '<td>'.($ch ? $ch['index'] : $ans['cs_text']).'</td><td'.($ans['cs_correct'] ? '' : ' class="mwq-fail-bd"').'>'.
                    wfMsg('mwquizzer-is-'.($ans['cs_correct'] ? 'correct' : 'incorrect')).'</td>';
            }
            else
                $row .= '<td></td><td></td>';
            $table .= '<tr>'.$row.'</tr>';
        }
        $table = self::xelement('table', array('class' => $checklist ? 'mwq-checklist' : 'mwq-questionnaire'), $table);
        return $table;
    }

    /**************/
    /* CHECK MODE */
    /**************/

    /* Load saved answer numbers from database */
    static function loadAnswers($ticket_id)
    {
        $answers = array();
        $dbr = wfGetDB(DB_SLAVE);
        $result = $dbr->select('mwq_choice_stats', '*', array(
            'cs_ticket' => $ticket_id,
        ), __FUNCTION__);
        while ($row = $dbr->fetchRow($result))
            $answers[$row['cs_question_hash']] = $row;
        $dbr->freeResult($result);
        return $answers;
    }

    /* Load answers from POST data, save them into DB and return as the result */
    static function checkAnswers($test, $ticket)
    {
        $answers = array();
        $rows = array();
        if (!empty($_POST['a_values']))
        {
            $_POST['a'] = json_decode($_POST['a_values'], true);
        }
        foreach ($test['questions'] as $i => $q)
        {
            if (!empty($_POST['a'][$i]))
            {
                $n = $_POST['a'][$i];
                if ($q['correct_count'] >= count($q['choices']))
                {
                    // Free-text question
                    $n = trim($n);
                    $is_correct = false;
                    foreach ($q['choices'] as $ch)
                        if ($n === $ch['ch_text'])
                            $is_correct = true;
                    $text = $n;
                    $num = 0;
                }
                else
                {
                    $is_correct = $q['choices'][$n-1]['ch_correct'] ? 1 : 0;
                    $text = NULL;
                    $num = $q['choices'][$n-1]['ch_num'];
                }
                /* Build rows for saving answers into database */
                $rows[] = $answers[$q['qn_hash']] = array(
                    'cs_ticket'        => $ticket['tk_id'],
                    'cs_question_hash' => $q['qn_hash'],
                    'cs_choice_num'    => $num,
                    'cs_text'          => $text,
                    'cs_correct'       => $is_correct,
                );
            }
        }
        $dbw = wfGetDB(DB_MASTER);
        $dbw->insert('mwq_choice_stats', $rows, __METHOD__);
        return $answers;
    }

    /* Calculate scores based on $testresult['answers'] ($hash => $num) */
    static function calculateScores(&$testresult, &$test)
    {
        foreach ($testresult['answers'] as $hash => $row)
        {
            $c = $row['cs_correct'] ? 1 : 0;
            $testresult['correct_count'] += $c;
            $testresult['score'] += $test['questionByHash'][$hash][$c ? 'score_correct' : 'score_incorrect'];
        }
        $testresult['correct_percent'] = round($testresult['correct_count']/count($test['questions'])*100, 1);
        $testresult['score_percent'] = round($testresult['score']/$test['max_score']*100, 1);
        $testresult['passed'] = $testresult['score_percent'] >= $test['test_ok_percent'];
    }

    /* Either check an unchecked ticket, or load results from the database
       if the ticket is already checked */
    static function checkOrLoadResult(&$ticket, $test, $args)
    {
        global $wgUser;
        $testresult = array(
            'correct_count' => 0,
            'score'         => 0,
        );

        $updated = false;
        if ($ticket['tk_end_time'])
        {
            /* Ticket already checked, load answers from database */
            $testresult['answers'] = self::loadAnswers($ticket['tk_id']);
            $testresult['details'] = $ticket['tk_details'] ? json_decode($ticket['tk_details'], true) : array();
            $testresult['seen'] = true;
        }
        else
        {
            /* Else check POSTed answers */
            $empty = trim(@$_REQUEST['prompt']) === '';
            $formdef = self::formDef($test);
            $values = array();
            if ($formdef)
            {
                // Check for empty form fields
                foreach ($formdef as $i => $field)
                {
                    if ($field['type'] == 'name' || $field['type'] == 'html')
                    {
                        // Don't check name field (= prompt)
                    }
                    elseif (empty($_REQUEST["detail_$i"]) && $field['mandatory'] &&
                        (empty($field['multiple']) || empty($values[trim($field['name'])])))
                    {
                        $empty = true;
                    }
                    elseif (!$field['mandatory'] || $field['type'] != 'checkbox' || !empty($field['multiple']))
                    {
                        // Saving mandatory non-multiple checkboxes is pointless, they're just a confirmation of something and always true
                        $n = trim($field['name']);
                        $v = !empty($_REQUEST["detail_$i"]) ? $_REQUEST["detail_$i"] : '';
                        if ($field['type'] == 'checkbox' && $v)
                        {
                            $v = $field['value'];
                        }
                        if (!empty($values[$n]))
                        {
                            if ($v)
                            {
                                $values[$n] .= ", $v";
                            }
                        }
                        else
                        {
                            $values[$n] = $v;
                        }
                    }
                }
                if ($empty)
                {
                    // Ask user to fill fields if some of them are empty
                    self::showTest($test, $ticket, $args, true);
                    return false;
                }
            }
            $testresult['details'] = $values;
            $testresult['answers'] = self::checkAnswers($test, $ticket);
            $testresult['seen'] = false;
            /* Need to send mail and update ticket in the DB */
            $updated = true;
        }

        /* Calculate scores */
        self::calculateScores($testresult, $test);

        if ($updated)
        {
            /* Update ticket */
            $userid = $wgUser->getId();
            if (!$userid)
                $userid = NULL;
            $update = array(
                'tk_end_time'        => wfTimestampNow(TS_MW),
                'tk_displayname'     => $args['prompt'],
                'tk_user_id'         => $userid,
                'tk_user_text'       => $wgUser->getName(),
                'tk_user_ip'         => wfGetIP(),
                /* Testing result to be shown in the table.
                   Nothing relies on these values. */
                'tk_score'           => $testresult['score'],
                'tk_score_percent'   => $testresult['score_percent'],
                'tk_correct'         => $testresult['correct_count'],
                'tk_correct_percent' => $testresult['correct_percent'],
                'tk_pass'            => $testresult['passed'] ? 1 : 0,
                'tk_details'         => $testresult['details'] ? json_encode($values, JSON_UNESCAPED_UNICODE) : '',
            );
            $ticket = array_merge($ticket, $update);
            $dbw = wfGetDB(DB_MASTER);
            $dbw->update('mwq_ticket', $update, array('tk_id' => $ticket['tk_id']), __METHOD__);
            /* Send mail with test results to administrator(s) */
            self::sendMail($ticket, $test, $testresult);
        }

        return $testresult;
    }

    /* Recalculate scores for a completed ticket */
    static function recalcTicket(&$ticket)
    {
        if ($ticket['tk_end_time'] === NULL)
            return;
        $test = self::loadTest(array('id' => $ticket['tk_test_id']), $ticket['tk_variant']);
        $testresult = self::checkOrLoadResult($ticket, $test, array());
        $update = array(
            /* Testing result to be shown in the table.
               Nothing relies on these values. */
            'tk_score'           => $testresult['score'],
            'tk_score_percent'   => $testresult['score_percent'],
            'tk_correct'         => $testresult['correct_count'],
            'tk_correct_percent' => $testresult['correct_percent'],
            'tk_pass'            => $testresult['passed'] ? 1 : 0,
        );
        $ticket = array_merge($ticket, $update);
        $dbw = wfGetDB(DB_MASTER);
        $dbw->update('mwq_ticket', $update, array('tk_id' => $ticket['tk_id']), __METHOD__);
    }

    /* Build email text */
    static function buildMailText($ticket, $test, $testresult)
    {
        $msg_r = wfMsg('mwquizzer-right-answer');
        $msg_y = wfMsg('mwquizzer-your-answer');
        $text = '';
        foreach ($test['questions'] as $i => $q)
        {
            $msg_q = wfMsg('mwquizzer-question', $i+1);
            if (isset($testresult['answers'][$q['qn_hash']]))
                $row = $testresult['answers'][$q['qn_hash']];
            else
                $row = NULL;
            if (!$row || !$row['cs_correct'])
            {
                $qn_text = trim(strip_tags($q['qn_text']));
                $ch_correct = trim(strip_tags($q['correct_choices'][0]['ch_text']));
                /* TODO (?) format this as HTML and send HTML emails */
                $lab = trim(strip_tags($q['qn_label']));
                if ($lab)
                    $lab .= ' | ';
                if ($row)
                {
                    $ch_user = !empty($row['cs_choice_num']) ? "[№" . $row['cs_choice_num'] . "] ".trim(strip_tags($q['choiceByNum'][$row['cs_choice_num']]['ch_text'])) : $row['cs_text'];
                    $ch_user = "--------------------------------------------------------------------------------\n$msg_y: $ch_user\n";
                }
                else
                    $ch_user = '';
                $text .= <<<EOT
================================================================================
$msg_q | $lab$q[correct_tries]/$q[tries]
--------------------------------------------------------------------------------
$qn_text

$msg_r
$ch_correct
${ch_user}≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈
EOT;
            }
        }
        /* TODO (?) format this as HTML and send HTML emails */
        $values = array(
            array('quiz',           "$test[test_name] /* Id: $test[test_id] */"),
            array('variant',        $test['variant_hash_crc32']),
            array('who',            $ticket['tk_displayname'] ? $ticket['tk_displayname'] : $ticket['tk_user_text']),
            array('user',           $ticket['tk_user_text']),
            array('start',          $ticket['tk_start_time']),
            array('end',            $ticket['tk_end_time']),
            array('ip',             $ticket['tk_user_ip']),
            array('right-answers',  "$testresult[correct_count] ≈ $testresult[correct_percent]% (random: $test[random_correct])"),
            array('score',          "$testresult[score] ≈ $testresult[score_percent]%"),
        );
        $len = 0;
        foreach ($values as &$v)
        {
            $v[2] = wfMsg('mwquizzer-'.$v[0]);
            $v[3] = mb_strlen($v[2]);
            if ($v[3] > $len)
                $len = $v[3];
        }
        $header = '';
        foreach ($values as &$v)
            $header .= $v[2] . ': ' . str_repeat(' ', $len-$v[3]) . $v[1] . "\n";
        $text = $header . $text;
        $text = "<pre>\n$text\n</pre>";
        return $text;
    }

    /* Send emails with test results to administrators */
    static function sendMail($ticket, $test, $testresult)
    {
        global $egMWQuizzerAdmins, $wgEmergencyContact;
        /* TODO (?) send mail without correct answers to user */
        $text = self::buildMailText($ticket, $test, $testresult);
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
    static function loadTicket($id, $key)
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

    // Format some ticket properties for display
    static function formatTicket($t)
    {
        global $wgUser;
        $r = array();
        if ($t['tk_user_id'])
            $r['name'] = $wgUser->getSkin()->link(Title::newFromText('User:'.$t['tk_user_text']), $t['tk_displayname'] ? $t['tk_displayname'] : $t['tk_user_text']);
        elseif ($t['tk_displayname'])
            $r['name'] = $t['tk_displayname'];
        else
            $r['name'] = wfMsg('mwquizzer-anonymous');
        $r['duration'] = wfTimestamp(TS_UNIX, $t['tk_end_time']) - wfTimestamp(TS_UNIX, $t['tk_start_time']);
        $d = $r['duration'] > 86400 ? intval($r['duration'] / 86400) . 'd ' : '';
        $r['duration'] = $d . gmdate('H:i:s', $r['duration'] % 86400);
        $r['start'] = wfTimestamp(TS_DB, $t['tk_start_time']);
        $r['end'] = wfTimestamp(TS_DB, $t['tk_end_time']);
        return $r;
    }

    /* Draws a QR code with ticket check link */
    function qrCode($args)
    {
        global $wgTitle, $IP;
        $ticket = self::loadTicket($args['ticket_id'], $args['ticket_key']);
        if (!$ticket)
        {
            $wgOut->showErrorPage('mwquizzer-check-no-ticket-title', 'mwquizzer-check-no-ticket-text', array($name, $href));
            return;
        }
        require_once(dirname(__FILE__).'/phpqrcode.php');
        if (is_writable("$IP/images"))
        {
            global $QR_CACHE_DIR, $QR_CACHEABLE;
            $dir = "$IP/images/generated/qrcode/";
            if (!file_exists($dir))
            {
                mkdir($dir, 0777, true);
            }
            $QR_CACHEABLE = true;
            $QR_CACHE_DIR = $dir;
        }
        QRcode::png($wgTitle->getFullUrl(array(
            'ticket_id' => $args['ticket_id'],
            'ticket_key' => $args['ticket_key'],
            'mode' => 'check',
        )));
        exit;
    }

    /* Check mode: check selected choices if not already checked,
       display results and completion certificate */
    function checkTest($args)
    {
        global $wgOut, $wgTitle, $wgUser;

        $ticket = self::loadTicket($args['ticket_id'], $args['ticket_key']);
        if (!$ticket)
        {
            if ($args['id'])
            {
                $test = self::loadTest(array('id' => $args['id']));
                $name = $test['test_name'];
                $href = $wgTitle->getFullUrl(array('id' => $test['test_id']));
            }
            $wgOut->showErrorPage('mwquizzer-check-no-ticket-title', 'mwquizzer-check-no-ticket-text', array($name, $href));
            return;
        }

        $test = self::loadTest(array('id' => $ticket['tk_test_id']), $ticket['tk_variant']);
        $testresult = self::checkOrLoadResult($ticket, $test, $args);
        if (!$testresult)
        {
            // checkOrLoadResult had shown the detail form - user must fill in additional fields
            return;
        }

        $html = '';
        if ($testresult['seen'])
        {
            $html .= wfMsg('mwquizzer-variant-already-seen').' ';
        }
        $href = $wgTitle->getFullUrl(array('id' => $test['test_id']));
        if (!$test['test_secret'] || self::isAdminForTest($test['test_id']) || $wgUser->isAllowed('secretquiz'))
        {
            $html .= wfMsg('mwquizzer-try-another', $href);
        }

        if ($test['test_intro'])
        {
            $html .= self::xelement('div', array('class' => 'mwq-intro mwq-intro-finish'), $test['test_intro']);
        }

        $f = self::formatTicket($ticket);
        $html .= wfMsg('mwquizzer-ticket-details',
            $f['name'], $f['start'], $f['end'], $f['duration']
        );

        $is_adm = self::isAdminForTest($test['test_id']);
        if ($is_adm)
        {
            if ($ticket['tk_reviewed'])
            {
                $html .= '<p>'.wfMsg('mwquizzer-ticket-reviewed').'</p>';
            }
            else
            {
                wfGetDB(DB_MASTER)->update(
                    'mwq_ticket', array('tk_reviewed' => 1),
                    array('tk_id' => $ticket['tk_id']), __METHOD__
                );
            }
        }

        $detail = $ticket['tk_details'] ? json_decode($ticket['tk_details'], true) : array();
        if ($detail)
        {
            $html .= '<ul>';
            foreach ($detail as $k => $v)
            {
                $html .= '<li>'.htmlspecialchars($k).': '.htmlspecialchars($v).'</li>';
            }
            $html .= '</ul>';
        }

        if ($is_adm)
        {
            // Average result for admins
            $html .= self::xelement('p', NULL, wfMsg('mwquizzer-test-average', self::getAverage($test)));
        }

        // Variant number
        $html .= wfMsg('mwquizzer-variant-msg', $test['variant_hash_crc32']);

        // Result
        $html .= $this->getResultHtml($ticket, $test, $testresult);

        if ($testresult['passed'] && ($ticket['tk_displayname'] || $ticket['tk_user_id']))
        {
            $html .= Xml::element('hr', array('style' => 'clear: both'));
            $html .= $this->getCertificateHtml($ticket, $test, $testresult);
        }

        /* Display answers also for links from result review table (showtut=1)
           to users who are admins or have read access to quiz source */
        if ($test['test_mode'] == 'TUTOR' || !empty($args['showtut']) && $is_adm)
        {
            $html .= $this->getTutorHtml($ticket, $test, $testresult, $is_adm);
        }

        $wgOut->setPageTitle(wfMsg('mwquizzer-check-pagetitle', $test['test_name']));
        $wgOut->addHTML($html);
    }

    /* A cell with <span>$n</span> ≈ $p% */
    static function wrapResult($n, $p, $e = 'td')
    {
        $cell = self::xelement('span', array('class' => 'mwq-count'), $n);
        $cell .= ' ≈ ' . $p . '%';
        return $e ? self::xelement($e, NULL, $cell) : $cell;
    }

    /* Get HTML code for result table (answers/score count/percent) */
    function getResultHtml($ticket, $test, $testresult)
    {
        global $wgTitle;
        $row = self::xelement('th', NULL, wfMsg('mwquizzer-right-answers'))
             . self::xelement('th', NULL, wfMsg('mwquizzer-score-long'));
        $html = self::xelement('tr', NULL, $row);
        $row = self::wrapResult($testresult['correct_count'], $testresult['correct_percent'])
             . self::wrapResult($testresult['score'], $testresult['score_percent']);
        $html .= self::xelement('tr', NULL, $row);
        $html = self::xelement('table', array('class' => 'mwq-result'), $html);
        // QR code
        $html = '<img style="float: left; margin: 0 8px 8px 0" src="'.$wgTitle->getLocalUrl(array(
            'ticket_id' => $ticket['tk_id'],
            'ticket_key' => $ticket['tk_key'],
            'mode' => 'qr',
        )).'" />'.$html;
        $html = self::xelement('h2', NULL, wfMsg('mwquizzer-results')) . $html;
        $html .= self::xelement('p', array('class' => 'mwq-rand'), wfMsg('mwquizzer-random-correct', round($test['random_correct'], 1)));
        return $html;
    }

    /* Draw an image with test completion certificate */
    function drawCertificate($certpath, $ticket, $test, $testresult)
    {
        global $egMWQuizzerCertificateTemplate;

        if (!file_exists($egMWQuizzerCertificateTemplate))
            return false;

        if (file_exists("$certpath.jpg") && file_exists("$certpath.thumb.jpg"))
            return true;

        $username = $ticket['tk_displayname'] ? $ticket['tk_displayname'] : $ticket['tk_user_text'];
        $name = preg_replace('/ {2,}/s', ' ', strip_tags($test['test_name']));
        $intro = preg_replace('/ {2,}/s', ' ', strip_tags($test['test_intro']));

        try
        {
            $image = new Imagick($egMWQuizzerCertificateTemplate);
            $draw = new ImagickDraw();
            try { $draw->setFont("Segoe-Print"); }
            catch (Exception $e) { $draw->setFont("Times-New-Roman"); }
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
        global $egMWQuizzerCertificateSubDir, $egMWQuizzerCertificateUri, $wgServer, $wgScriptPath;
        global $wgUploadDirectory;

        $egMWQuizzerCertificateDir = $wgUploadDirectory . $egMWQuizzerCertificateSubDir;

        $code = $ticket['tk_key'] . '-' . $ticket['tk_id'];

        $hash = '/' . substr($code, 0, 1) . '/' . substr($code, 0, 2) . '/';
        if (!is_dir($egMWQuizzerCertificateDir . $hash))
            mkdir($egMWQuizzerCertificateDir . $hash, 0777, true);
        $certuri = $egMWQuizzerCertificateUri;
        if ($certuri{0} != '/')
            $certuri = $wgScriptPath . '/' . $certuri;
        $certpath = $egMWQuizzerCertificateDir . $hash . $code;
        $certuri .= $hash . $code;
        if (!preg_match('#^[a-z]+://#is', $certuri))
        {
            if ($certuri{0} != '/')
                $certuri = "/$certuri";
            $certuri = $wgServer . $certuri;
        }

        if (!$this->drawCertificate($certpath, $ticket, $test, $testresult))
            return false;

        $code = self::xelement('img', array('src' => "$certuri.thumb.jpg"));
        $code = self::xelement('a', array('href' => "$certuri.jpg", 'target' => '_blank'), $code);
        $testhref = Title::newFromText('Special:MediawikiQuizzer/'.$test['test_id']);
        $testtry = wfMsg('mwquizzer-try', $test['test_name']);
        $code .= "<br />" . self::xelement('a', array('href' => $testhref, 'target' => '_blank'), $testtry);

        $html = self::xelement('table', NULL, self::xelement('tr', NULL,
            self::xelement('td', array('class' => 'mwq-congrats'), $code) .
            self::xelement('td', array('class' => 'mwq-congrats-src'), wfMsg('mwquizzer-congratulations') . self::xelement('pre', array('style' => 'white-space: normal'), htmlspecialchars($code)))
        ));

        return $html;
    }

    /* TUTOR mode tests display all incorrect answered questions with
       correct answers and explanations after testing. */
    function getTutorHtml($ticket, $test, $testresult, $is_adm = false)
    {
        $items = array();
        $html = '';
        foreach ($test['questions'] as $k => $q)
        {
            $row = @$testresult['answers'][$q['qn_hash']];
            if ($row && $row['cs_correct'])
                continue;
            $items[$k] = true;
            $correct = $q['correct_choices'][0];
            $html .= Xml::element('hr');
            $html .= self::xelement('a', array('name' => "q$k"), '', false);
            if ($is_adm)
                $stats = self::questionStatsHtml($q['correct_tries'], $q['tries']);
            else
                $stats = '';
            $html .= self::xelement('h3', NULL, wfMsg('mwquizzer-question', $k+1) . $stats);
            $html .= self::xelement('div', array('class' => 'mwq-question'), $q['qn_text']);
            $html .= self::xelement('h4', NULL, wfMsg('mwquizzer-right-answer'));
            $html .= self::xelement('div', array('class' => 'mwq-right-answer'), $correct['ch_text']);
            if ($q['qn_explanation'])
            {
                $html .= self::xelement('h4', NULL, wfMsg('mwquizzer-explanation'));
                $html .= self::xelement('div', array('class' => 'mwq-explanation'), $q['qn_explanation']);
            }
            if ($row)
            {
                $html .= self::xelement('h4', NULL, wfMsg('mwquizzer-your-answer'));
                $html .= self::xelement('div', array('class' => 'mwq-your-answer'), !empty($row['cs_choice_num']) ? $q['choiceByNum'][$row['cs_choice_num']]['ch_text'] : $row['cs_text']);
            }
        }
        if ($items)
        {
            $html = self::getToc(count($test['questions']), $items) .
                Xml::element('div', array('style' => 'clear: both'), false) .
                $html;
        }
        return $html;
    }

    /***************/
    /* REVIEW MODE */
    /***************/

    /* Get HTML page list */
    static function getPages($args, $npages, $curpage)
    {
        global $wgTitle;
        if ($npages <= 1)
            return '';
        $pages = array();
        if ($curpage > 0)
            $pages[] = self::xelement('a', array('href' => $wgTitle->getFullUrl(array('page' => $curpage-1)+$args)), '‹');
        for ($i = 0; $i < $npages; $i++)
        {
            if ($i != $curpage)
                $pages[] = self::xelement('a', array('href' => $wgTitle->getFullUrl(array('page' => $i)+$args)), $i+1);
            else
                $pages[] = self::xelement('b', array('class' => 'mwq-curpage'), $i+1);
        }
        if ($curpage < $npages-1)
            $pages[] = self::xelement('a', array('href' => $wgTitle->getFullUrl(array('page' => $curpage+1)+$args)), '›');
        $html = wfMsg('mwquizzer-pages');
        $html .= implode(' ', $pages);
        $html = self::xelement('p', array('class' => 'mwq-pages'), $html);
        return $html;
    }

    /* Select tickets from database */
    static function selectTickets($args)
    {
        $dbr = wfGetDB(DB_SLAVE);
        $info = array(
            'mode' => 'review',
            'quiz_name' => '',
            'variant_hash_crc32' => '',
            'user_text' => '',
            'start_time_min' => '',
            'start_time_max' => '',
            'end_time_min' => '',
            'end_time_max' => '',
        );
        $where = array('tk_end_time IS NOT NULL', 'tk_test_id=test_id');
        $args = $args+array(
            'quiz_name'          => '',
            'variant_hash_crc32' => '',
            'user_text'          => '',
            'start_time_min'     => '',
            'start_time_max'     => '',
            'end_time_min'       => '',
            'end_time_max'       => '',
            'perpage'            => '',
            'page'               => '',
        );
        if (isset($args['quiz_name']) && ($t = Title::newFromText('Quiz:'.$args['quiz_name'])))
        {
            $where['test_page_title'] = $t->getText();
            $info['quiz_name'] = $t->getText();
        }
        $crc = $args['variant_hash_crc32'];
        if (preg_match('/^\d+$/s', $crc))
        {
            $where[] = "crc32(tk_variant)=$crc";
            $info['variant_hash_crc32'] = $crc;
        }
        if ($u = $args['user_text'])
        {
            $info['user_text'] = $u;
            $u = $dbr->addQuotes($u);
            $where[] = "(INSTR(tk_user_text,$u)>0 OR INSTR(tk_displayname,$u)>0)";
        }
        if (($d = $args['start_time_min']) && ($ts = wfTimestamp(TS_MW, $d)))
        {
            $info['start_time_min'] = $d;
            $where[] = "tk_start_time>=$ts";
        }
        if (($d = $args['start_time_max']) && ($ts = wfTimestamp(TS_MW, $d)))
        {
            $info['start_time_max'] = $d;
            $where[] = "tk_start_time<=$ts";
        }
        if (($d = $args['end_time_min']) && ($ts = wfTimestamp(TS_MW, $d)))
        {
            $info['end_time_min'] = $d;
            $where[] = "tk_end_time>=$ts";
        }
        if (($d = $args['end_time_max']) && ($ts = wfTimestamp(TS_MW, $d)))
        {
            $info['end_time_max'] = $d;
            $where[] = "tk_end_time<=$ts";
        }
        $tickets = array();
        if (!($perpage = $args['perpage']))
            $perpage = 20;
        $info['perpage'] = $perpage;
        $page = $args['page'];
        if ($page <= 0)
            $page = 0;
        $result = $dbr->select(array('mwq_ticket', 'mwq_test'), '*', $where, __FUNCTION__, array(
            'ORDER BY' => 'tk_start_time DESC',
            'LIMIT' => $perpage,
            'OFFSET' => $perpage * $page,
            'SQL_CALC_FOUND_ROWS',
        ));
        while ($row = $dbr->fetchRow($result))
        {
            /* Recalculate scores */
            if ($row['tk_end_time'] !== NULL && $row['tk_score'] === NULL)
                self::recalcTicket($row);
            $tickets[] = $row;
        }
        $dbr->freeResult($result);
        $total = $dbr->query('SELECT FOUND_ROWS()');
        $total = $dbr->fetchRow($total);
        $total = $total[0];
        if ($page * $perpage >= $total)
            $page = intval($total / $perpage);
        return array(
            'info'    => $info,
            'page'    => $page,
            'perpage' => $perpage,
            'total'   => $total,
            'tickets' => $tickets,
        );
    }

    /* Get HTML table with tickets */
    static function getTicketTable($tickets, $showDetails = false)
    {
        global $wgTitle, $wgUser;
        $skin = $wgUser->getSkin();
        $tr = array();
        foreach (explode(' ', 'ticket-id quiz quiz-title variant user start end duration ip score correct') as $i)
        {
            $tr[] = self::xelement('th', NULL, wfMsg("mwquizzer-$i"));
        }
        $html = array($tr);
        $detailKeys = array();
        // ID[LINK] TEST_ID TEST[LINK] VARIANT_CRC32 USER START END DURATION IP
        foreach ($tickets as &$t)
        {
            $f = self::formatTicket($t);
            if ($showDetails)
            {
                $t['tk_details'] = $t['tk_details'] ? json_decode($t['tk_details'], true) : array();
                $detailKeys += $t['tk_details'];
            }
            $tr = array();
            /* 1. Ticket ID + link to standard results page */
            $tr[] = self::xelement('a', array('href' => $wgTitle->getFullUrl(array(
                'mode' => 'check',
                'showtut' => 1,
                'ticket_id' => $t['tk_id'],
                'ticket_key' => $t['tk_key'],
            ))), $t['tk_id']);
            /* 2. Quiz name + link to its page + link to quiz form */
            if ($t['test_id'])
            {
                $name = $t['test_page_title'];
                $testtry = $wgTitle->getFullUrl(array('id' => $t['test_id']));
                $testhref = Title::newFromText('Quiz:'.$t['test_page_title'])->getFullUrl();
                $tr[] = self::xelement('a', array('href' => $testhref), $name) . ' (' .
                    self::xelement('a', array('href' => $testtry), wfMsg('mwquizzer-try')) . ')';
                $tr[] = $t['test_name'] ?: $name;
            }
            /* Or 2. Quiz ID in the case when it is not found */
            else
            {
                $tr[] = self::xelement('s', array('class' => 'mwq-dead'), $t['tk_test_page_title']);
                $tr[] = '';
            }
            /* 3. Variant CRC32 + link to printable version of this variant */
            $a = sprintf("%u", crc32($t['tk_variant']));
            $href = $wgTitle->getFullUrl(array(
                'mode'       => 'print',
                'id'         => $t['tk_test_id'],
                'edit'       => 1,
                'ticket_id'  => $t['tk_id'],
                'ticket_key' => $t['tk_key'],
            ));
            $a = self::xelement('a', array('href' => $href), $a);
            $tr[] = $a;
            /* 4. User link and/or name/displayname */
            $tr[] = $f['name'];
            /* 5. Start time in YYYY-MM-DD HH:MM:SS format */
            $tr[] = $f['start'];
            /* 6. End time in YYYY-MM-DD HH:MM:SS format */
            $tr[] = $f['end'];
            /* 7. Test duration in Xd HH:MM:SS format */
            $tr[] = $f['duration'];
            /* 8. User IP */
            $tr[] = $t['tk_user_ip'];
            /* 9. Score and % */
            $tr[] = self::wrapResult($t['tk_score'], $t['tk_score_percent'], '');
            /* 10. Correct answers count and % */
            $tr[] = self::wrapResult($t['tk_correct'], $t['tk_correct_percent'], '');
            /* Format HTML row */
            $row = array();
            foreach ($tr as $i => &$td)
            {
                $attr = array();
                if ($i == 0 || $i == 1)
                {
                    $attr['style'] = 'text-align: center';
                    if ($i == 0 && !$t['tk_reviewed'])
                    {
                        // Mark non-reviewed rows
                        $attr['class'] = 'mwq-incoming';
                    }
                }
                elseif ($i == 8 || $i == 9)
                {
                    $attr['class'] = $t['tk_pass'] ? 'mwq-pass' : 'mwq-fail';
                }
                $row[] = self::xelement('td', $attr, $td);
            }
            $html[] = $row;
        }
        if ($showDetails)
        {
            $detailKeys = array_keys($detailKeys);
            foreach ($detailKeys as $k)
            {
                $html[0][] = self::xelement('th', NULL, htmlspecialchars($k));
            }
            foreach ($html as $i => &$row)
            {
                if ($i > 0)
                {
                    foreach ($detailKeys as $k)
                    {
                        $row[] = self::xelement('td', NULL, @$tickets[$i-1]['tk_details'][$k]);
                    }
                }
            }
        }
        foreach ($html as $i => &$row)
        {
            $row = self::xelement('tr', NULL, implode('', $row));
        }
        $html = self::xelement('table', array('class' => 'mwq-review'), implode('', $html));
        return $html;
    }

    /* Get HTML form for selecting tickets */
    static function selectTicketForm($info)
    {
        global $wgTitle;
        $html = '';
        $html .= Html::hidden('mode', 'review');
        $html .= Xml::inputLabel(wfMsg('mwquizzer-quiz').': ', 'quiz_name', 'quiz_name', 30, $info['quiz_name']) . '<br />';
        $html .= Xml::inputLabel(wfMsg('mwquizzer-variant').': ', 'variant_hash_crc32', 'variant_hash_crc32', 10, $info['variant_hash_crc32']) . '<br />';
        $html .= Xml::inputLabel(wfMsg('mwquizzer-user').': ', 'user_text', 'user_text', 30, $info['user_text']) . '<br />';
        $html .= Xml::inputLabel(wfMsg('mwquizzer-start').': ', 'start_time_min', 'start_time_min', 19, $info['start_time_min']);
        $html .= Xml::inputLabel(wfMsg('mwquizzer-to'), 'start_time_max', 'start_time_max', 19, $info['start_time_max']) . '<br />';
        $html .= Xml::inputLabel(wfMsg('mwquizzer-end').': ', 'end_time_min', 'end_time_min', 19, $info['end_time_min']);
        $html .= Xml::inputLabel(wfMsg('mwquizzer-to'), 'end_time_max', 'end_time_max', 19, $info['end_time_max']) . '<br />';
        $html .= Xml::inputLabel(wfMsg('mwquizzer-perpage').': ', 'perpage', 'perpage', 5, $info['perpage']) . ' &nbsp; ';
        $html .= Xml::checkLabel(wfMsg('mwquizzer-show-details'), 'show_details', 'show_details', $info['show_details']) . '<br />';
        $html .= Xml::submitButton(wfMsg('mwquizzer-select-tickets'));
        $html = self::xelement('form', array('method' => 'GET', 'action' => $wgTitle->getFullUrl(), 'class' => 'mwq-select-tickets'), $html);
        return $html;
    }

    /* Review closed tickets (completed attempts) */
    function review($args)
    {
        global $wgOut;
        $html = '';
        $result = self::selectTickets($args);
        $result['info']['show_details'] = !empty($args['show_details']);
        $html .= self::selectTicketForm($result['info']);
        if ($result['total'])
            $html .= self::xelement('p', NULL, wfMsg('mwquizzer-ticket-count', $result['total'], 1 + $result['page']*$result['perpage'], count($result['tickets'])));
        else
            $html .= self::xelement('p', NULL, wfMsg('mwquizzer-no-tickets'));
        $html .= self::getTicketTable($result['tickets'], !empty($args['show_details']));
        $html .= self::getPages($result['info'], ceil($result['total'] / $result['perpage']), $result['page']);
        $wgOut->setPageTitle(wfMsg('mwquizzer-review-pagetitle'));
        $wgOut->addHTML($html);
    }
}
