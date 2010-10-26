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
        MagicWord::get('noeditsection')->matchAndRemove($text);
        $options = clone $wgParser->mOptions;
        $options->mNumberHeadings = false;
        $options->mEditSection = true;
        $html = $wgParser->parse("__NOTOC__\n$text", $article->getTitle(), $options, true, false)->getText();
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

    /* Transform quiz field value according to its type */
    static function transformFieldValue($field, $value)
    {
        $t = self::$test_field_types[$field];
        if ($t > 0) /* not an HTML code */
        {
            $value = trim(strip_tags($value));
            if ($t == 2) /* mode */
                $value = strpos(strtolower($value), 'tutor') !== false ? 'TUTOR' : 'TEST';
            elseif ($t == 3) /* boolean */
            {
                $re = str_replace('/', '\\/', wfMsgNoTrans('mwquizzer-parse-true'));
                $value = preg_match("/$re/uis", $value) ? 1 : 0;
            }
            elseif ($t == 4) /* integer */
                $value = intval($value);
            /* else ($t == 1) // just a string */
        }
        else
            $value = trim($value);
        return $value;
    }

    static function textlog($s)
    {
        if (is_object($s))
            $s = DOMParseUtils::saveChildren($s);
        return trim(str_replace("\n", " ", strip_tags($s)));
    }

    /* Check last question for correctness */
    static function checkLastQuestion(&$questions, &$log)
    {
        $lq = $questions[count($questions)-1];
        $ncorrect = 0;
        $ok = false;
        if ($lq['choices'])
            foreach ($lq['choices'] as $lc)
                if ($lc['ch_correct'])
                    $ncorrect++;
        if (!$lq['choices'] || !count($lq['choices']))
            $log .= "[ERROR] No choices defined for question: ".self::textlog($lq['qn_text'])."\n";
        elseif ($ncorrect >= $lq['choices'])
            $log .= "[ERROR] All choices are correct for question: ".self::textlog($lq['qn_text'])."\n";
        elseif (!$ncorrect)
            $log .= "[ERROR] No correct choices for question: ".self::textlog($lq['qn_text'])."\n";
        else
            $ok = true;
        if (!$ok)
            array_pop($questions);
    }

    /* states: */
    const ST_OUTER = 0;     /* Outside everything */
    const ST_QUESTION = 1;  /* Inside question */
    const ST_PARAM_DD = 2;  /* Waiting for <dd> with quiz field value */
    const ST_CHOICE = 3;    /* Inside single choice section */
    const ST_CHOICES = 4;   /* Inside multiple choices section */

    /* parseQuiz() using a state machine :-) */
    static function parseQuiz2($html)
    {
        self::getRegexps();
        $log = '';
        $document = DOMParseUtils::loadDOM($html);
        /* Stack: [ [ Element, ChildIndex, AlreadyProcessed, AppendStrlen ] , [ ... ] ] */
        $stack = array(array($document->documentElement, 0, false, 0));
        $st = self::ST_OUTER;   /* State index */
        $append = NULL;         /* Array(&$str) or NULL. When array(&$str), content is appended to $str. */
        /* Variables: */
        $q = array();           /* Questions */
        $quiz = array();        /* Quiz field => value */
        $field = '';            /* Current parsed field */
        $correct = 0;           /* Is current choice(s) section for correct choices */
        $lastAnchor = '';       /* Last remembered <a name=""></a> */
        /* Loop through all elements: */
        while ($stack)
        {
            list($p, $i, $h, $l) = $stack[count($stack)-1];
            if ($i >= $p->childNodes->length)
            {
                array_pop($stack);
                if ($append && !$h)
                {
                    /* Remove children from the end of $append[0]
                       and append element itself */
                    $append[0] = substr($append[0], 0, $l) . $document->saveXML($p);
                }
                elseif ($h && $stack)
                {
                    /* Propagate "Already processed" value */
                    $stack[count($stack)-1][2] = true;
                }
                continue;
            }
            $stack[count($stack)-1][1]++;
            $e = $p->childNodes->item($i);
            if ($e->nodeType == XML_ELEMENT_NODE)
            {
                $fall = false;
                if (preg_match('/^h(\d)$/is', $e->nodeName, $m))
                {
                    $level = $m[1];
                    $log_el = str_repeat('=', $level);
                    /* Remove editsection links */
                    $editsection = NULL;
                    if ($e->childNodes->length)
                    {
                        $span = $e->childNodes->item(0);
                        if ($span->nodeName == 'span' && $span->getAttribute('class') == 'editsection')
                        {
                            $e->removeChild($span);
                            $editsection = $document->saveXML($span);
                        }
                    }
                    $log_el = $log_el . self::textlog($e) . $log_el;
                    /* Match question/parameter section title */
                    $chk = DOMParseUtils::checkNode($e, self::$regexps[0], true);
                    if ($chk)
                    {
                        /* Question section */
                        if ($chk[1][0][0])
                        {
                            if ($q)
                                self::checkLastQuestion($q, $log);
                            /* Question section - found */
                            $log .= "[INFO] Begin question section: $log_el\n";
                            $st = self::ST_QUESTION;
                            $q[] = array(
                                'qn_label' => DOMParseUtils::saveChildren($chk[0], true),
                                'qn_anchor' => $lastAnchor,
                                'qn_editsection' => $editsection,
                            );
                            $append = array(&$q[count($q)-1]['qn_text']);
                            $lastAnchor = '';
                        }
                        /* Quiz parameter */
                        elseif ($st == self::ST_OUTER || $st == self::ST_PARAM_DD)
                        {
                            $st = self::ST_OUTER;
                            $field = '';
                            foreach ($chk[1] as $i => $c)
                            {
                                if ($c[0])
                                {
                                    $field = self::$test_keys[$i-1];
                                    break;
                                }
                            }
                            if ($field)
                            {
                                /* Parameter - found */
                                $log .= "[INFO] Begin quiz field \"$field\" section: $log_el\n";
                                $append = array(&$quiz["test_$field"]);
                            }
                            else
                            {
                                /* This should never happen ! */
                                $line = __FILE__.':'.__LINE__;
                                $log .= "[ERROR] MYSTICAL BUG: Unknown quiz field at $line in: $log_el\n";
                            }
                        }
                        else
                        {
                            /* INFO: Parameter section inside question section / choice section */
                            $log .= "[WARN] Field section must come before questions: $log_el\n";
                        }
                    }
                    elseif ($st == self::ST_QUESTION || $st == self::ST_CHOICE || $st == self::ST_CHOICES)
                    {
                        $chk = DOMParseUtils::checkNode($e, self::$regexps[2], true);
                        if ($chk)
                        {
                            /* Question sub-section */
                            $sid = '';
                            foreach ($chk[1] as $i => $c)
                            {
                                if ($c[0])
                                {
                                    $sid = self::$qn_keys[$i];
                                    break;
                                }
                            }
                            if (!$sid)
                            {
                                /* This should never happen ! */
                                $line = __FILE__.':'.__LINE__;
                                $log .= "[ERROR] MYSTICAL BUG: Unknown question field at $line in: $log_el\n";
                            }
                            elseif ($sid == 'comments')
                            {
                                /* Question comments */
                                $log .= "[INFO] Begin question comments: $log_el\n";
                                $st = self::ST_QUESTION;
                                $append = NULL;
                            }
                            elseif ($sid == 'explanation' || $sid == 'label')
                            {
                                /* Question field */
                                $log .= "[INFO] Begin question $sid: $log_el\n";
                                $st = self::ST_QUESTION;
                                $append = array(&$q[count($q)-1]["qn_$sid"]);
                            }
                            else
                            {
                                /* Some kind of choice(s) section */
                                $correct = $sid == 'correct' || $sid == 'corrects' ? 1 : 0;
                                $lc = $correct ? 'correct choice' : 'choice';
                                if ($sid == 'correct' || $sid == 'choice')
                                {
                                    $log .= "[INFO] Begin single $lc section: $log_el\n";
                                    $q[count($q)-1]['choices'][] = array('ch_correct' => $correct);
                                    $st = self::ST_CHOICE;
                                    $append = array(&$q[count($q)-1]['choices'][count($q[count($q)-1]['choices'])-1]['ch_text']);
                                }
                                else
                                {
                                    $log .= "[INFO] Begin multiple $lc section: $log_el\n";
                                    $st = self::ST_CHOICES;
                                    $append = NULL;
                                }
                            }
                        }
                        else
                        {
                            /* INFO: unknown heading inside question */
                            $log .= "[WARN] Unparsed heading inside question: $log_el\n";
                            $fall = true;
                        }
                    }
                    else
                    {
                        /* INFO: unknown heading */
                        $log .= "[WARN] Unparsed heading outside question: $log_el\n";
                        $fall = true;
                    }
                }
                /* <dt> for a parameter */
                elseif (($st == self::ST_OUTER || $st == self::ST_PARAM_DD) && $e->nodeName == 'dt')
                {
                    $chk = DOMParseUtils::checkNode($e, self::$regexps[1], true);
                    $log_el = '; ' . trim(strip_tags(DOMParseUtils::saveChildren($e))) . ':';
                    if ($chk)
                    {
                        $st = self::ST_OUTER;
                        $field = '';
                        foreach ($chk[1] as $i => $c)
                        {
                            if ($c[0])
                            {
                                $field = self::$test_keys[$i-1];
                                break;
                            }
                        }
                        if ($field)
                        {
                            /* Parameter - found */
                            $log .= "[INFO] Begin definition list item for quiz field \"$field\": $log_el\n";
                            $st = self::ST_PARAM_DD;
                        }
                        else
                        {
                            /* This should never happen ! */
                            $line = __FILE__.':'.__LINE__;
                            $log .= "[ERROR] MYSTICAL BUG: Unknown quiz field at $line in: $log_el\n";
                        }
                    }
                    else
                    {
                        /* INFO: unknown <dt> key */
                        $log .= "[WARN] Unparsed definition list item: $log_el\n";
                        $fall = true;
                    }
                }
                elseif ($st == self::ST_PARAM_DD && $e->nodeName == 'dd')
                {
                    /* Value for $field */
                    $value = self::transformFieldValue($field, DOMParseUtils::saveChildren($e));
                    $log .= "[INFO] Quiz $field = ".self::textlog($value)."\n";
                    $quiz["test_$field"] = $value;
                    $st = self::ST_OUTER;
                    $field = '';
                }
                elseif ($st == self::ST_CHOICE && ($e->nodeName == 'ul' || $e->nodeName == 'ol') &&
                    $e->childNodes->length == 1 && !$append[0])
                {
                    /* <ul>/<ol> with single <li> inside choice */
                    $log .= "[INFO] Stripping single-item list from single-choice section";
                    $e = $e->childNodes->item(0);
                    $chk = DOMParseUtils::checkNode($e, wfMsgNoTrans('mwquizzer-parse-correct'), true);
                    if ($chk)
                    {
                        $e = $chk[0];
                        $n = count($q[count($q)-1]['choices']);
                        $q[count($q)-1]['choices'][$n-1]['ch_correct'] = 1;
                        $log .= "[INFO] Correct choice marker is present in single-item list";
                    }
                    $append[0] .= trim(DOMParseUtils::saveChildren($e));
                }
                elseif ($st == self::ST_CHOICE && $e->nodeName == 'p')
                {
                    if ($append[0])
                        $append[0] .= '<br />';
                    $append[0] .= trim(DOMParseUtils::saveChildren($e));
                }
                elseif ($st == self::ST_CHOICES && $e->nodeName == 'li')
                {
                    $chk = DOMParseUtils::checkNode($e, wfMsgNoTrans('mwquizzer-parse-correct'), true);
                    $c = $correct;
                    if ($chk)
                    {
                        $e = $chk[0];
                        $c = 1;
                    }
                    $children = DOMParseUtils::saveChildren($e);
                    $log .= "[INFO] Parsed ".($c ? "correct " : "")."choice: ".trim(strip_tags($children))."\n";
                    $q[count($q)-1]['choices'][] = array(
                        'ch_correct' => $c,
                        'ch_text' => trim($children),
                    );
                }
                elseif ($e->nodeName == 'a' && $e->childNodes->length == 0)
                {
                    /* Remember anchor names */
                    if (!($lastAnchor = $e->getAttribute('name')))
                        $fall = true;
                }
                else
                    $fall = true;
                if ($fall)
                {
                    /* Save position inside append-string to remove
                       children before appending the element itself */
                    $stack[] = array($e, 0, false, $append ? strlen($append[0]) : 0);
                }
                else
                    $stack[count($stack)-1][2] = true;
            }
            elseif ($append && $e->nodeType == XML_TEXT_NODE && trim($e->nodeValue))
                $append[0] .= $e->nodeValue;
        }
        if ($q)
            self::checkLastQuestion($q, $log);
        $quiz['questions'] = $q;
        $quiz['test_log'] = $log;
        return $quiz;
    }

    /* Parse $text and update data of the quiz linked to article title */
    static function updateQuiz($article, $text)
    {
        $html = self::parse($article, $text);
        $quiz = self::parseQuiz2($html);
        $quiz['test_log'] = "[INFO] Article revision: ".$article->getLatest()."\n".$quiz['test_log'];
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
            $hash = mb_strtolower(preg_replace('/\s+/s', '', $hash));
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

    /* A helper for updating many rows at once (MySQL-specific) */
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
    /* Default OK% */
    const DEFAULT_OK_PERCENT = 80;

    static $modes = array(
        'show' => 1,
        'check' => 1,
        'print' => 1,
        'review' => 1,
    );

    /* Display parse log and quiz actions for parsed quiz article */
    static function quizArticleInfo($test_id)
    {
        global $wgOut, $wgScriptPath;
        wfLoadExtensionMessages('MediawikiQuizzer');
        $wgOut->addExtensionStyle("$wgScriptPath/extensions/".basename(dirname(__FILE__))."/mwquizzer.css");
        /* Load the test without questions */
        $quiz = self::loadTest($test_id, NULL, true);
        if (!$quiz)
            return;
        $s = Title::newFromText('Special:MediawikiQuizzer');
        $actions = array(
            'try'   => $s->getFullUrl(array('id' => $test_id)),
            'print' => $s->getFullUrl(array('id' => $test_id, 'mode' => 'print')),
        );
        $wgOut->addHTML(wfMsg('mwquizzer-actions', $quiz['test_name'], $actions['try'], $actions['print']));
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

    /* Identical to Xml::element, but does no htmlspecialchars() on $contents */
    static function xelement($element, $attribs = null, $contents = '', $allowShortTag = true)
    {
        if (is_null($contents))
            return Xml::openElement($element, $attribs);
        elseif ($contents == '')
            return Xml::element($element, $attribs, $contents, $allowShortTag);
        return Xml::openElement($element, $attribs) . $contents . Xml::closeElement($element);
    }

    /*********************/
    /* GENERIC FUNCTIONS */
    /*********************/

    /* Constructor */
    function __construct()
    {
        global $IP, $wgScriptPath, $wgUser, $wgParser, $wgEmergencyContact;
        SpecialPage::SpecialPage('MediawikiQuizzer');
    }

    /* SPECIAL PAGE ENTRY POINT */
    function execute($par = null)
    {
        global $wgOut, $wgRequest, $wgTitle, $wgLang, $wgServer, $wgScriptPath;
        $args = $wgRequest->getValues();
        wfLoadExtensionMessages('MediawikiQuizzer');
        $wgOut->addExtensionStyle("$wgScriptPath/extensions/".basename(dirname(__FILE__))."/mwquizzer.css");

        $is_adm = MediawikiQuizzer::isTestAdmin();
        $mode = $args['mode'];

        $id = $par;
        if (!$id)
            $id = $args['id'];
        if (!$id)
            $id = $args['id_test']; // backward compatibility

        if (!self::$modes[$mode])
            $mode = $is_adm && !$id ? 'review' : 'show';

        if ($mode == 'check')
        {
            /* Check mode requires loading of a specific variant, so don't load random one */
            $this->checkTest($args);
            return;
        }
        elseif ($mode == 'review')
        {
            /* Review mode is available only to test administrators */
            if ($is_adm)
                $this->review($args);
            else
                $wgOut->showErrorPage('mwquizzer-review-denied-title', 'mwquizzer-review-denied-text');
            return;
        }

        /* Allow viewing ticket variant with specified key for print mode */
        $variant = NULL;
        if ($args['ticket_id'] && $args['ticket_key'] && $mode == 'print' &&
            ($ticket = self::loadTicket($args['ticket_id'], $args['ticket_key'])))
        {
            $id = $ticket['tk_test_id'];
            $variant = $ticket['tk_variant'];
        }

        /* Raise error when no test is specified for mode=print or mode=show */
        if (!$id)
        {
            $wgOut->showErrorPage('mwquizzer-no-test-id-title', 'mwquizzer-no-test-id-text');
            return;
        }

        /* Load random or specific test variant */
        $test = self::loadTest($id, $variant);
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
        elseif (!$var && $q['correct_count'] >= count($q['choices']))
        {
            /* All choices for this question are defined as correct, skip it */
            wfDebug(__CLASS__.": Skipping $hash, all choices defined as correct!\n");
        }
        else
        {
            if ($q['ch_order'])
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
                $q['score_incorrect'] = -$q['correct_count'] / (count($q['choices']) - $q['correct_count']);
            }
            return true;
        }
        return false;
    }

    /* Load a test from database. Optionally shuffle/limit questions and answers,
       compute variant ID (sequence hash) and scores. */
    static function loadTest($id, $variant = NULL, $without_questions = false)
    {
        global $wgOut;
        $dbr = wfGetDB(DB_SLAVE);

        $result = $dbr->select('mwq_test', '*', array('test_id' => $id), __METHOD__);
        $test = $dbr->fetchRow($result);
        $dbr->freeResult($result);

        if (!$test)
            return NULL;

        // decode entities inside test_name as it is used inside HTML <title>
        $test['test_name'] = html_entity_decode($test['test_name']);

        // default OK%
        if ($test['ok_percent'] <= 0)
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
                $q['correct_tries']/$q['tries'] >= $test['test_autofilter_correct_percent']/100.0)
            {
                /* Statistics tells us this question is too simple, skip it */
                wfDebug(__CLASS__.': Skipping '.$q['qn_hash'].', because correct percent = '.$q['correct_tries'].'/'.$q['tries'].' >= '.$test['test_autofilter_correct_percent']."%\n");
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
                $q['choiceByNum'][$choice['ch_num']]['index'] = count($q['choices']);
                $q['correct_choices'][] = &$q['choiceByNum'][$choice['ch_num']];
            }
        }
        if (!self::finalizeQuestionRow($q, $variant && true, $test['test_shuffle_choices']))
            unset($rows[$q['qn_hash']]);
        unset($q);
        $dbr->freeResult($result);

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

    /*************/
    /* SHOW MODE */
    /*************/

    /* Get a table with question numbers linked to the appropriate questions */
    function getToc($n, $trues = false)
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
                elseif ($trues && !$trues[$k])
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
    function getQuestionList($questions, $inputs = false, $editsection = false)
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
            'mode'       => 'check',
        ));

        $form = '';
        $form .= wfMsg('mwquizzer-prompt') . '&nbsp;' . Xml::input('prompt', 20);
        $form .= self::xelement('p', NULL, Xml::submitButton(wfMsg('mwquizzer-submit')));
        $form .= $this->getQuestionList($test['questions'], true);
        $form .= Xml::element('hr');
        $form .= Xml::submitButton(wfMsg('mwquizzer-submit'));
        $form = self::xelement('form', array('action' => $action, 'method' => 'POST'), $form);

        $html = $this->getToc(count($test['questions']));
        if ($test['test_intro'])
            $html .= self::xelement('div', array('class' => 'mwq-intro'), $test['test_intro']);
        $html .= wfMsg('mwquizzer-variant-msg', $test['variant_hash_crc32']);
        $html .= Xml::element('hr');
        $html .= $this->getCounterJs();
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
     * Check list is shown only to test administrators and users who can read
     * the test source article according to HaloACL rights, if HaloACL is enabled.
     * CSS page-break styles are specified so you can print this page.
     */
    function printTest($test, $args)
    {
        global $wgOut;
        $html = '';

        $title = Title::newFromText('Quiz:'.$test['id']);
        $is_adm = MediawikiQuizzer::isTestAdmin() ||
            $title && method_exists($title, 'userCanReadEx') && $title->userCanReadEx();

        /* TestInfo */
        $ti = wfMsg('mwquizzer-variant-msg', $test['variant_hash_crc32']);
        if ($test['test_intro'])
            $ti = self::xelement('div', array('class' => 'mwq-intro'), $test['test_intro']) . $ti;

        /* Display question list (with editsection links for admins) */
        $html .= self::xelement('h2', array('style' => 'page-break-before: always'), wfMsg('mwquizzer-question-sheet'));
        $html .= $ti;
        $html .= $this->getQuestionList($test['questions'], false, $args['edit'] && $is_adm);

        /* Display questionnaire */
        $html .= Xml::element('hr', array('style' => 'page-break-after: always'), '');
        $html .= self::xelement('h2', NULL, wfMsg('mwquizzer-test-sheet'));
        $html .= $ti;
        $html .= $this->getCheckList($test, $args, false);

        if ($is_adm)
        {
            /* Display check-list to users who can read source article */
            $html .= self::xelement('h2', array('style' => 'page-break-after: before'), wfMsg('mwquizzer-answer-sheet'));
            $html .= $ti;
            $html .= $this->getCheckList($test, $args, true);
        }
        $html .= Xml::element('hr', array('style' => 'page-break-after: always'), '');

        $wgOut->setPageTitle(wfMsg('mwquizzer-print-pagetitle', $test['test_name']));
        $wgOut->addHTML($html);
    }

    /* Display a table with question numbers, correct answers, statistics and labels when $checklist is TRUE
       Display a table with question numbers and two blank columns - "answer" and "remark" when $checklist is FALSE */
    function getCheckList($test, $args, $checklist = false)
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
            $row = array($k+1);
            if ($checklist)
            {
                /* build a list of correct choice indexes in the shuffled array */
                $correct_indexes = array();
                foreach ($q['correct_choices'] as $c)
                    $correct_indexes[] = $c['index'];
                $row[] = implode(', ', $correct_indexes);
                $row[] = $q['tries'] ? $q['correct_tries'] . '/' . $q['tries'] . ' ≈ ' . round($q['correct_tries'] * 100.0 / $q['tries']) . '%' : '';
                $row[] = $q['qn_label'];
            }
            else
                array_push($row, '', '');
            $table .= '<tr><td>'.implode('</td><td>', $row).'</td></tr>';
        }
        $table = self::xelement('table', array('class' => $checklist ? 'mwq-checklist' : 'mwq-questionnaire'), $table);
        return $table;
    }

    /**************/
    /* CHECK MODE */
    /**************/

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
    function checkAnswers($test, $ticket)
    {
        $answers = array();
        $rows = array();
        foreach ($test['questions'] as $i => $q)
        {
            $n = $_POST['a'][$i];
            if ($n)
            {
                $n--;
                $is_correct = $q['choices'][$n]['ch_correct'] ? 1 : 0;
                $answers[$q['qn_hash']] = $q['choices'][$n]['ch_num'];
                /* Build rows for saving answers into database */
                $rows[] = array(
                    'cs_ticket'        => $ticket['tk_id'],
                    'cs_question_hash' => $q['qn_hash'],
                    'cs_choice_num'    => $q['choices'][$n]['ch_num'],
                    'cs_correct'       => $is_correct,
                );
            }
        }
        $dbw = wfGetDB(DB_MASTER);
        $dbw->insert('mwq_choice_stats', $rows, __METHOD__);
        return $answers;
    }

    /* Calculate scores based on $testresult['answers'] ($hash => $num) */
    function calculateScores(&$testresult, &$test)
    {
        foreach ($testresult['answers'] as $hash => $n)
        {
            $c = $test['questionByHash'][$hash]['choiceByNum'][$n]['ch_correct'] ? 1 : 0;
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
            $testresult['seen'] = true;
        }
        else
        {
            /* Else check POSTed answers */
            $testresult['answers'] = self::checkAnswers($test, $ticket);
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
        $test = self::loadTest($ticket['tk_test_id'], $ticket['tk_variant']);
        $testresult = self::checkOrLoadResult($ticket, $test, $args);
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
            $num = $testresult['answers'][$q['qn_hash']];
            if (!$num || !$q['choiceByNum'][$num]['ch_correct'])
            {
                $qn_text = trim(strip_tags($q['qn_text']));
                $ch_correct = trim(strip_tags($q['correct_choices'][0]['ch_text']));
                /* TODO (?) format this as HTML and send HTML emails */
                $lab = trim(strip_tags($q['qn_label']));
                if ($lab)
                    $lab .= ' | ';
                if ($num)
                {
                    $ch_user = trim(strip_tags($q['choiceByNum'][$num]['ch_text']));
                    $ch_user = "--------------------------------------------------------------------------------\n$msg_y: [№$num] $ch_user\n";
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
$ch_user≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈
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
            array('ip',             $ticket['tk_ip']),
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

    /* Check mode: check selected choices if not already checked, 
       display results and completion certificate */
    function checkTest($args)
    {
        global $wgOut, $wgTitle;

        $ticket = self::loadTicket($args['ticket_id'], $args['ticket_key']);
        if (!$ticket)
        {
            if ($args['id'])
            {
                $test = self::loadTest($args['id']);
                $name = $test['test_name'];
                $href = $wgTitle->getFullUrl(array('id' => $test['test_id']));
            }
            $wgOut->showErrorPage('mwquizzer-check-no-ticket-title', 'mwquizzer-check-no-ticket-text', array($name, $href));
            return;
        }

        $test = self::loadTest($ticket['tk_test_id'], $ticket['tk_variant']);
        $testresult = self::checkOrLoadResult($ticket, $test, $args);

        $html = '';
        if ($testresult['seen'])
        {
            $href = $wgTitle->getFullUrl(array('id' => $test['test_id']));
            $html .= wfMsg('mwquizzer-variant-already-seen', $href);
        }

        if ($test['test_intro'])
            $html .= self::xelement('div', array('class' => 'mwq-intro'), $test['test_intro']);

        $html .= wfMsg('mwquizzer-variant-msg', $test['variant_hash_crc32']);

        $html .= $this->getResultHtml($ticket, $test, $testresult);

        if ($testresult['passed'] && ($ticket['tk_displayname'] || $ticket['tk_user_id']))
        {
            $html .= Xml::element('hr');
            $html .= $this->getCertificateHtml($ticket, $test, $testresult);
        }

        if ($test['test_mode'] == 'TUTOR')
            $html .= $this->getTutorHtml($ticket, $test, $testresult);

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
        $row = self::xelement('th', NULL, wfMsg('mwquizzer-right-answers'))
             . self::xelement('th', NULL, wfMsg('mwquizzer-score-long'));
        $html .= self::xelement('tr', NULL, $row);
        $row = self::wrapResult($testresult['correct_count'], $testresult['correct_percent'])
             . self::wrapResult($testresult['score'], $testresult['score_percent']);
        $html .= self::xelement('tr', NULL, $row);
        $html = self::xelement('table', array('class' => 'mwq-result'), $html);
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
        global $egMWQuizzerCertificateDir, $egMWQuizzerCertificateUri, $wgServer, $wgScriptPath;
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
    function getTutorHtml($ticket, $test, $testresult)
    {
        $items = array();
        foreach ($test['questions'] as $k => $q)
        {
            $num = $testresult['answers'][$q['qn_hash']];
            if ($num && $q['choiceByNum'][$num]['ch_correct'])
                continue;
            $items[$k] = true;
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
                $html .= self::xelement('div', array('class' => 'mwq-your-answer'), $q['choiceByNum'][$num]['ch_text']);
            }
        }
        if ($items)
            $html = $this->getToc(count($test['questions']), $items) . $html;
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
        $html .= wfMsg('mwquizzer-pages');
        $html .= implode(' ', $pages);
        $html = self::xelement('p', array('class' => 'mwq-pages'), $html);
        return $html;
    }

    /* Select tickets from database */
    static function selectTickets($args)
    {
        $dbr = wfGetDB(DB_SLAVE);
        $info = array('mode' => 'review');
        $where = array('tk_end_time IS NOT NULL', 'tk_test_id=test_id');
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
        if ($d = $args['start_time_min'])
        {
            $info['start_time_min'] = $d;
            $where[] = "tk_start_time>=".wfTimestamp($d, TS_MW);
        }
        if ($d = $args['start_time_max'])
        {
            $info['start_time_max'] = $d;
            $where[] = "tk_start_time<=".wfTimestamp($d, TS_MW);
        }
        if ($d = $args['end_time_min'])
        {
            $info['end_time_min'] = $d;
            $where[] = "tk_end_time>=".wfTimestamp($d, TS_MW);
        }
        if ($d = $args['end_time_max'])
        {
            $info['end_time_max'] = $d;
            $where[] = "tk_end_time<=".wfTimestamp($d, TS_MW);
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
    static function getTicketTable($tickets)
    {
        global $wgTitle, $wgUser;
        $skin = $wgUser->getSkin();
        $html = '';
        $tr = '';
        foreach (explode(' ', 'ticket-id quiz variant user start end duration ip score correct') as $i)
            $tr .= self::xelement('th', NULL, wfMsg("mwquizzer-$i"));
        $html .= self::xelement('tr', NULL, $tr);
        // ID[LINK] TEST[LINK] VARIANT_CRC32 USER START END DURATION IP
        foreach ($tickets as $i => $t)
        {
            $tr = array();
            /* 1. Ticket ID + link to standard results page */
            $tr[] = self::xelement('a', array('href' => $wgTitle->getFullUrl(array(
                'mode' => 'check',
                'ticket_id' => $t['tk_id'],
                'ticket_key' => $t['tk_key'],
            ))), $t['tk_id']);
            /* 2. Quiz name + link to its page + link to quiz form */
            if ($t['test_id'])
            {
                if ($t['test_name'])
                    $name = $t['test_name'];
                else
                    $name = $t['test_id'];
                $testtry = $wgTitle->getFullUrl(array('id' => $t['test_id']));
                $testhref = Title::newFromText('Quiz:'.$t['tk_test_id'])->getFullUrl();
                $tr[] = self::xelement('a', array('href' => $testhref), $name) . ' (' .
                    self::xelement('a', array('href' => $testtry), wfMsg('mwquizzer-try')) . ')';
            }
            /* Or 2. Quiz ID in the case when it is not found */
            else
                $tr[] = self::xelement('s', array('class' => 'mwq-dead'), $t['tk_test_id']);
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
            if ($t['tk_user_id'])
                $tr[] = $skin->link(Title::newFromText('User:'.$t['tk_user_text'], $t['tk_displayname']));
            elseif ($t['tk_displayname'])
                $tr[] = $t['tk_displayname'];
            else
                $tr[] = wfMsg('mwquizzer-anonymous');
            /* 5. Start time in YYYY-MM-DD HH:MM:SS format */
            $tr[] = wfTimestamp(TS_DB, $t['tk_start_time']);
            /* 6. End time in YYYY-MM-DD HH:MM:SS format */
            $tr[] = wfTimestamp(TS_DB, $t['tk_end_time']);
            /* 7. Test duration in Xd HH:MM:SS format */
            $duration = wfTimestamp(TS_UNIX, $t['tk_end_time']) - wfTimestamp(TS_UNIX, $t['tk_start_time']);
            $d = $duration > 86400 ? intval($duration / 86400) . 'd ' : '';
            $tr[] = $d . gmdate('H:i:s', $duration % 86400);
            /* 8. User IP */
            $tr[] = $t['tk_user_ip'];
            /* 9. Score and % */
            $tr[] = self::wrapResult($t['tk_score'], $t['tk_score_percent'], '');
            /* 10. Correct answers count and % */
            $tr[] = self::wrapResult($t['tk_correct'], $t['tk_correct_percent'], '');
            /* Format HTML row */
            $row = '';
            foreach ($tr as $i => &$td)
            {
                $attr = $i == 8 || $i == 9 ? array('class' => $t['tk_pass'] ? 'mwq-pass' : 'mwq-fail') : NULL;
                $row .= self::xelement('td', $attr, $td);
            }
            $html .= self::xelement('tr', NULL, $row);
        }
        $html = self::xelement('table', array('class' => 'mwq-review'), $html);
        return $html;
    }

    /* Get HTML form for selecting tickets */
    static function selectTicketForm($info)
    {
        global $wgTitle;
        $html = '';
        $html .= Xml::hidden('mode', 'review');
        $html .= Xml::inputLabel(wfMsg('mwquizzer-variant').': ', 'variant_hash_crc32', 'variant_hash_crc32', 10, $info['variant_hash_crc32']) . '<br />';
        $html .= Xml::inputLabel(wfMsg('mwquizzer-user').': ', 'user_text', 'user_text', 30, $info['user_text']) . '<br />';
        $html .= Xml::inputLabel(wfMsg('mwquizzer-start').': ', 'start_time_min', 'start_time_min', 19, $info['start_time_min']);
        $html .= Xml::inputLabel(wfMsg('mwquizzer-to'), 'start_time_max', 'start_time_max', 19, $info['start_time_max']) . '<br />';
        $html .= Xml::inputLabel(wfMsg('mwquizzer-end').': ', 'end_time_min', 'end_time_min', 19, $info['end_time_min']);
        $html .= Xml::inputLabel(wfMsg('mwquizzer-to'), 'end_time_max', 'end_time_max', 19, $info['end_time_max']) . '<br />';
        $html .= Xml::inputLabel(wfMsg('mwquizzer-perpage').': ', 'perpage', 'perpage', 5, $info['perpage']) . '<br />';
        $html .= Xml::submitButton(wfMsg('mwquizzer-select-tickets'));
        $html = self::xelement('form', array('method' => 'GET', 'action' => $wgTitle->getFullUrl(), 'class' => 'mwq-select-tickets'), $html);
        return $html;
    }

    /* Review closed tickets */
    function review($args)
    {
        global $wgOut;
        $html = '';
        $result = self::selectTickets($args, $dbr);
        $html .= self::selectTicketForm($result['info']);
        if ($result['total'])
            $html .= self::xelement('p', NULL, wfMsg('mwquizzer-ticket-count', $result['total'], 1 + $result['page']*$result['perpage'], count($result['tickets'])));
        else
            $html .= self::xelement('p', NULL, wfMsg('mwquizzer-no-tickets'));
        $html .= self::getTicketTable($result['tickets']);
        $html .= self::getPages($result['info'], ceil($result['total'] / $result['perpage']), $result['page']);
        $wgOut->setPageTitle(wfMsg('mwquizzer-review-pagetitle'));
        $wgOut->addHTML($html);
    }
}
