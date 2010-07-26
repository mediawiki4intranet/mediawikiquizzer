<?php

class MediawikiQuizzerPage extends SpecialPage
{
    function MediawikiQuizzerPage()
    {
        global $IP, $wgScriptPath, $wgUser, $wgParser, $wgEmergencyContact;
        SpecialPage::SpecialPage('MediawikiQuizzer');

        $this->dbr =& wfGetDB(DB_SLAVE);
        $this->dbw =& wfGetDB(DB_MASTER);
        $this->localParser = clone $wgParser;
        $this->parserOptions = ParserOptions::newFromUser($wgUser);
        $this->parserOptions->setEditSection(false);
        $this->parserOptions->setTidy(false);

        $this->BaseDir = "$IP/images/diplomas";
        $this->BaseDir = str_replace("\\","/",$this->BaseDir);
        $this->BaseURI = "$wgScriptPath/images/diplomas";

        $dir = dirname(__FILE__) . '/';
        $this->DiplomaTemplateName = $dir . "diploma.png";

        $this->adminEmail = $wgEmergencyContact;
    }

    function parseBlock($str)
    {
        global $wgTitle, $wgUser;
        $parserOutput = $this->localParser->parse(trim($str),  $wgTitle, $this->parserOptions);
        $str = $parserOutput->getText();
        if (substr($str,0,3) == '<p>')
            $str = substr($str,3);
        return $str;
    }

    function msg($code = "")
    {
        return wfMsg("mediawikiquizzer-" . $code);
    }

    function calculate_props(&$mytest)
    {
        $mytest["randscore"] = 0.0;
        $mytest["maxscore"] = 0;
        foreach($mytest["questions"] as $q_num => $q)
        {
            $mytest["randscore"] += (0.0+$q['right_answers'])/(0.0+$q['choices_num']);
            $mytest["maxscore"]++;
        }
    }

    function load_test($id_test = null)
    {
        global $wgOut;
        $mytest = array();
        $res = $this->dbr->select('mwq_test', '*', array('mwt_id' => $id_test), __METHOD__);
        $test = $this->dbr->fetchObject($res);
        if (!$test)
        {
            $wgOut->addHTML($this->msg("test-undefined"));
            return null;
        }
        $mytest['test'] = $test;

        $res = $this->dbr->select(
            array('mwq_question', 'mwq_question_stats'),
            'mwq_question.*, mws_try, mws_success',
            array('mwq_id_test' => $id_test),
            __METHOD__,
            array(),
            array('mwq_question_stats' => 'mws_code=mwq_code')
        );
        if ($this->dbr->numRows($res) <= 0)
        {
            $wgOut->addHTML($this->msg("no-questions"));
            return null;
        }

        $mytest['questions'] = array();
        while ($question = $this->dbr->fetchObject($res))
        {
            $q = array(
                'question'      => $question,
                'choices'       => array(),
                'choices_num'   => 0,
                'right_answers' => 0,
            );
            $plain_text_question = $question->mwq_question . "\n";
            $lc = $this->dbr->select('mwq_choice', '*', array('mwc_id_test' => $id_test, 'mwc_num' => $question->mwq_num), __METHOD__);
            while ($choice = $this->dbr->fetchObject($lc))
            {
                $q['choices_num']++;
                $q['choices'][$choice->mwc_pos] = $choice;
                $plain_text_question .= $choice->mwc_choice;
                if ($choice->mwc_answer > 0)
                {
                    $q['right_answers']++;
                    $plain_text_question .= "|*";
                }
                $plain_text_question .= "\n";
            }

            if (rtrim($question->mwq_code) != "")
                $q['code'] = $question->mwq_code;
            else
            {
                $q['code'] = md5($plain_text_question);
                $this->dbw->update('mwq_question',
                    array('mwq_code' => $q['code']),
                    array('mwq_id_test' => $id_test, 'mwq_num' => $num),
                    __METHOD__
                );
            }

            $q['penalty'] = 1/(1.0*($q['choices_num']-$q['right_answers']));
            $mytest['questions'][$question->mwq_num] = $q;
        }
        $this->calculate_props($mytest);
        return $mytest;
    }

    function get_table_of_contents($n)
    {
        if (!($n > 0))
            return "";
        $s = "";
        for ($i = 0; $i <= $n/10; $i++)
        {
            $s .= "<tr>";
            for ($j = 1; $j <= 10; $j++)
            {
                $qv = $i*10+$j;
                $s .= "<td style='margin:0.5em;text-align:center;border-width:1px;width:3em;border-style:solid;'>";
                if ($j <= $n-$i*10)
                    $s .= "<a href='#q$qv'>$qv</a>";
                $s .= "</td>";
            }
            $s .= "</tr>";
        }
        $s = <<<EOT
<table style="font-size:120%;border-width:1px;border-style:solid;border-color:black;border-collapse:collapse"
$s
</table>
EOT;
        return $s;
    }

    function setdiplomafilename($code)
    {
        $strHash  = $code;
        $oldumask = umask(0);
        $strDir   = $this->BaseDir;
        if (!is_dir($strDir))
            mkdir($strDir, 0777);
        $strURI = $this->BaseURI;
        $strDir .= "/" . $strHash{0};
        $strURI .= "/" . $strHash{0};
        if (!is_dir($strDir))
            mkdir($strDir, 0777);
        $strDir .= "/" . substr($strHash, 0, 2);
        $strURI .= "/" . substr($strHash, 0, 2);
        if (!is_dir($strDir))
            mkdir($strDir, 0777);
        $strDir .= "/" . $strHash;
        $strURI .= "/" . $strHash;
        $this->diplomaFilename = $strDir . ".jpg";
        $this->diplomaFilenameThumbnail = $strDir . ".thumb.jpg";
        $this->diplomaTextFilename = $strDir . ".txt";
        $this->diplomaURI = $strURI . ".jpg";
        $this->diplomaURIThumbnail = $strURI . ".thumb.jpg";
    }

    function execute($par = null)
    {
        global $wgOut, $wgRequest, $wgTitle, $wgLang, $wgServer, $wgScriptPath;
        $id_test = $wgRequest->getValue('id_test');

        wfLoadExtensionMessages('MediawikiQuizzer');

        $wgOut->setPagetitle('MediawikiQuizzers');
        if (!isset($id_test))
        {
            $wgOut->addHTML($this->msg("no-test-id"));
            return;
        }

        $id_test = $id_test+0;
        $test = $this->load_test($id_test);
        if (!$test)
            return;

        $tsnow = wfTimestampNow();
        $nowdate = $wgLang->date($tsnow,true);
        $nowtime = $wgLang->time($tsnow,true);

        $numSessionID = preg_replace("[\D]", "", session_id());
        extract($wgRequest->getValues('mode'));

        $ticket = rand(1,1000000);
        $variant = rand(1,999);
        $action = $wgTitle->escapeLocalUrl("id_test=$id_test") . "&mode=check";
        if (!isset($mode))
        {
            // Generate questions form.
            if ($test['test']->mwt_shuffle_questions > 0)
                shuffle($test['questions']);
            if ($test['test']->mwt_q_limit > 0)
                array_splice($test['questions'], $test['test']->mwt_q_limit);
            $this->calculate_props($test);
            $wgOut->addHTML($this->get_table_of_contents(count($test['questions'])));

            $li_q = 0;
            $out = "";
            foreach ($test['questions'] as $q_num => $q)
            {
                $question = $q['question'];
                $li_q += 1;
                $out .= <<<EOT
<hr><h3 id="q{$li_q}">{$this->msg("question")} {$li_q} </h3>
EOT;
                $out .= $this->parseBlock("\n\n".$question->question."\n\n");
                if ($test['test']->mwt_shuffle_choices > 0)
                    shuffle($q['choices']);
                $li_c = 0;
                $out .= "<input type='hidden' name='q[$li_q]' value='$question->num'><ol>";
                foreach($q["choices"] as $pos => $choice)
                {
                    $li_c += 1;
                    $out .= "<li><input name='a[{$question->num}]' value='{$choice->pos}' type='radio' >";
                    $out .= $this->parseBlock($choice->choice);
                    $out .= "</li>";
                }
                $out .= "</ol>";
            }

            $prompt = "";
            $randscore = $test['randscore'];
            $maxscore = $test['maxscore'];
            $prompt .= $this->msg("prompt") . "<input type='text' name='prompt' value=''>";
            $out = $test['test']->mwt_intro . <<<EOT
<script language="JavaScript">
BackColor = "white";
ForeColor = "navy";
CountActive = true;
CountStepper = 1;
LeadingZero = true;
DisplayFormat = "Прошло %%H%%:%%M%%:%%S%%.";
FinishMessage = "It is finally here!";
</script>
<script language="JavaScript" src="$wgScriptPath/extensions/mediawikiquizzer/countdown.js"></script>
<form action='$action' method='POST'>
<input type='hidden' name='ticket' value='$ticket' />
<input type='hidden' name='tsstart' value='$tsnow' />
<input type='hidden' name='randscore' value='$randscore' />
<input type='hidden' name='maxscore' value='$maxscore' />
{$prompt}
{$out}
<input name='action' value='Ok' type='submit' />
</form>
EOT;
            $wgOut->setPagetitle($test['test']->mwt_name . ":" . $this->msg("questions"));
            $wgOut->addHTML($out);
            return;
        }

        if ($mode == "print")
        {
            $out = "";
            $wgOut->setPagetitle($test['test']->mwt_name . ":" . $this->msg("questions"));
            $out .= "<h2>" . $this->msg("question-sheet") . "</h2> <p> " . $this->msg("variant") . " $variant <p> " . $test['test']->mwt_intro . " \n\n";
            $sheet .= "<h2>" . $this->msg("test-sheet") . "</h2> <p>" . $this->msg("variant") . " $variant <p>";
            $answer .= "<h2>" . $this->msg("answer-sheet") . "</h2> <p>" . $this->msg("variant") . " $variant <p>";
            if ($test['test']->mwt_shuffle_questions > 0)
                shuffle($test["questions"]);
            if ($test['test']->mwt_limit > 0)
                array_splice($test["questions"], $test['test']->mwt_limit);
            $li_q = 0;
            $colstyle = 'text-align:center;border-width:1px;border-style:solid;border-color:black';
            $style = 'align="center" width="50%" style="border-width:1px;border-style:solid;border-color:black;border-collapse:collapse"';
            $sheet .= "<table $style><th style='width:2em'>№</th><th style='width:6em;$colstyle'>" . $this->msg("answer") . "</th><th style='width:10em;$colstyle'>" . $this->msg("remark") . "</th></tr>";
            $answer .= "<table $style><th style='width:2em'>№</th><th style='width:3em;$colstyle'>" . $this->msg("answer") . "</th><th style='width:10em;$colstyle'>" . $this->msg("statistics") . "</th><th style='$colstyle'>". $this->msg("label") ."</th></tr>";
            foreach ($test["questions"] as $q_num => $q)
            {
                $question = $q["question"];
                $li_q += 1;
                $sheet .= "<tr><td  style='$colstyle'> $li_q </td><td style='$colstyle'></td>";
                $answer .= "<tr><td style='$colstyle'> $li_q </td><td style='$colstyle' >";
                $out .= '<table style="border-width:1px;border-style:solid;border-color:black">';
                $out .= "<tr><td style='$colstyle'>Q:$li_q</td><td>" . $this->parseBlock($question->question) . "</td></tr>\n";
                if ($test['test']->mwt_shuffle_choices)
                    shuffle($q["choices"]);
                $li_c = 0;
                foreach($q["choices"] as $pos => $choice)
                {
                    $li_c += 1;
                    $out .= "<tr><td style='$colstyle'>$li_c</td><td>" . $this->parseBlock($choice->choice) . "</td></tr>\n";
                    if ($choice->answer)
                        $answer.=" $li_c ";
                }
                $answer .= "</td><td style='$colstyle'>";
                if ($question->try > 0)
                    $answer .= $question->success . "/" . $question->try ." ≈ ". round($question->success*100/$question->try) ."%";
                $answer .= "</td><td style='$colstyle'>" . $this->parseBlock($question->label) . "</td></td></tr>";
                $answer .= "</td></tr>";
                $sheet .= "<td style='$colstyle'></td></tr>";
                $out .= "</table><p>&nbsp;<p>";
            }
            $sheet .= '</table><p>';
            $answer .= '</table><p>';
            $wgOut->addHTML($out);
            $wgOut->addHTML("<hr>");
            $wgOut->addHTML($sheet);
            $wgOut->addHTML("<hr>");
            $wgOut->addHTML($answer);
            return;
        }

        if ($mode == "check")
        {
            extract($wgRequest->getValues('ticket'));
            extract($wgRequest->getValues('tsstart'));
            extract($wgRequest->getValues('randscore'));
            extract($wgRequest->getValues('maxscore'));
            $startdate = $wgLang->date($tsstart,true);
            $starttime = $wgLang->time($tsstart,true);

            $ip = wfGetIP();
            $cache = wfGetCache(CACHE_ANYTHING);
            $cachekey = wfMemcKey('mwquizzer', $id_test, $ip, $ticket);
            if ($data = $cache->get($cachekey))
            {
                $action = $wgTitle->escapeLocalUrl("id_test=$id_test");
                $out = <<<EOT
<p>{$this->msg('variant-already-seen')}</p>
<p>
<a href="{$action}">{$this->msg("try")} «{$test['test']->mwt_name}»!</a>
<hr>
$data
EOT;
                $wgOut->addHTML($out);
                return;
            }

            $points = 0;
            $out = "";
            $mailmessage = "";
            $answers = $_POST['a'];
            $questions = $_POST['q'];

            $wgOut->setPagetitle($test['test']->mwt_name . " :" . $this->msg("results"));
            $out .= "\n" . $test['test']->mwt_intro;

            $wgOut->addHTML($this->get_table_of_contents(count($questions)));

            $score = $ranswers = 0;
            while (list($q_num, $q_id) = each($questions))
            {
                $q = $test['questions'][$q_id];
                $question = $q['question'];

                $user_choice_pos = $answers[$q_id];
                $ls_user_choice = "";
                if (isset($user_choice_pos))
                {
                    $user_choice = $q['choices'][$user_choice_pos];
                    $ls_user_choice = $user_choice->choice;
                }

                $txt_right_choice = $html_right_choice = "";
                foreach($q['choices'] as $n => $choice)
                {
                    if ($choice->answer > 0)
                    {
                        $txt_right_choice .= "\n {$choice->choice} ";
                        $html_right_choice .= "<li>" . $this->parseBlock($choice->choice) . "</li>";
                    }
                }

                $this->dbw->query(
                    "INSERT INTO mwquizzer.question_stats(code,try) VALUES ('" . $q['code'] .
                    "',1) ON DUPLICATE KEY UPDATE try = try+1, dtm = CURRENT_TIMESTAMP()");
                if (isset($user_choice_pos) && $user_choice->answer > 0)
                {
                    $score++;
                    $ranswers++;
                    $this->dbw->update('mwq_question_stats',
                        array('success=success+1'),
                        array('code' => $q['code']),
                        __METHOD__
                    );
                }
                else
                {
                    $score -= $q["penalty"];
                    $mailmessage .= <<<EOT
================================================================================
{$this->msg("question")} {$q_num} | {$question->label} | {$question->success}/{$question->try}
--------------------------------------------------------------------------------
{$question->question}

{$this->msg("right-answer")}
{$txt_right_choice}
--------------------------------------------------------------------------------
{$this->msg("your-answer")}: {$ls_user_choice}
.
≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈

EOT;
                    if ($test['test']->mwt_mode == "TUTOR")
                    {
                        $out .= <<<EOT
<hr><h3 id="q{$q_num}">{$this->msg("question")} {$q_num} </h3>
{$this->parseBlock($question->question)}
<h4>{$this->msg("right-answer")}</h4>
<ol>
{$html_right_choice}
</ol>
EOT;
                        if (trim($question->explanation) != "")
                        {
                            $out .= "<h4>" . $this->msg("explanation") . "</h4>";
                            $out .= "<p>". $this->parseBlock($question->explanation);
                        }
                        if ($ls_user_choice != "")
                        {
                            $out .= "<h4>" . $this->msg("your-answer") . "</h4>";
                            $out .= "<p>". $this->parseBlock($ls_user_choice);
                        }
                    }
                }
            }
            $out .= "<h2>" . $this->msg("summary") . "</h2>";
            $percent_score = round(($score/$maxscore)*100, 1);
            $percent_answers = round(($ranswers/$maxscore)*100, 1);
            $ranswers = round($ranswers,0.1);
            $score = round($score,0.1);
            $summary = <<<EOT
<table style="font-size: 120%; border: 1px solid black; border-collapse: collapse">
<tr><td style="margin: 0.5em; text-align: center; border: 1px solid black; width: 40%">
 {$this->msg("right-answers")}
</td><td style="margin: 0.5em; text-align: center; border: 1px solid black; width: 40%">
 {$this->msg("score")}
</td></tr>
<tr><td style="margin: 0.5em; text-align: center; border: 1px solid black; width: 40%">
 <b>{$ranswers}</b> ≈ {$percent_answers}%
</td><td style="margin: 0.5em; text-align: center; border: 1px solid black; width: 40%">
 <b>{$score}</b> ≈ {$percent_score}%
</td></tr>
</table>
<p> {$this->msg("random-score")} {$randscore} </p>
EOT;
            $out .= $summary;
            $prompt = $_POST['prompt'];
            $mailmessage = <<<EOT
Quiz:      {$test['test']->mwtname}  /* Id: {$id_test} */
Who:       {$prompt}
TimeStart: {$startdate} {$starttime}
TimeEnd:   {$nowdate} {$nowtime}
IP:        {$ip}
Answers:   {$ranswers}
Score:     {$score}
{$this->msg("right-answers")}: {$ranswers} ≈ {$percent_answers}%
{$this->msg("score")}: {$score} ≈ {$percent_score}%
{$this->msg("random-score")}: {$randscore}

{$mailmessage}
EOT;
            if ($percent_score > $test['test']->mwtok_percent)
            {
                if ($prompt)
                {
                    $text = "$prompt $nowdate ".$test['test']->mwt_name." $ip\n";
                    $code = md5($text);
                    $this->setdiplomafilename($code);
                    $text = "$mailmessage\n" . str_repeat('-', 50) . "\n$text\n" . str_repeat('-', 50) . "\n";
                    $obj = fopen($this->diplomaTextFilename, 'w');
                    if ($obj)
                    {
                        fwrite($obj, $text);
                        fclose($obj);
                    }
                    $image = new Imagick($this->DiplomaTemplateName);
                    $draw = new ImagickDraw();
                    $res = $draw->setFontFamily("Times");
                    $draw->setFontSize(28);
                    $draw->setTextAlignment(imagick::ALIGN_CENTER);
                    $draw->setFillColor("#FF64AC");
                    //$draw->setStrokeColor("#FF64AC");
                    $draw->annotation(400, 420, $d);
                    $draw->setFillColor("#062BFE");
                    $draw->setFontSize(36);
                    $draw->annotation(400, 190, $prompt);
                    $draw->annotation(400, 330, $test['test']->mwt_name);
                    $draw->setFontSize(18);
                    $draw->annotation(400, 370, $test['test']->mwt_intro);
                    try
                    {
                        $draw->setFontFamily("Arial");
                    }
                    catch (ImagickDrawException $e)
                    {
                        try
                        {
                            $draw->setFontFamily("Helvetica");
                        } catch (ImagickDrawException $e) {};
                    }
                    $draw->setFontSize(10);
                    $draw->setFillOpacity(0.3);
                    $draw->annotation(140, 440, $code);
                    $image->drawImage($draw);
                    $image->setCompressionQuality(80);
                    $image->writeImage($this->diplomaFilename);
                    $image->thumbnailImage(160,0);
                    $image->writeImage($this->diplomaFilenameThumbnail);
                    $action = $wgTitle->escapeLocalUrl("id_test=$id_test");
                    $out .= <<<EOT
<hr />
<table><tr><td>
 <a href="{$this->diplomaURI}" target="_diplomas_">
  <img src="{$this->diplomaURIThumbnail}" />
 </a>
</td><td>
 {$this->msg("congratulations")}
 <pre>
  &lt;a href="{$wgServer}{$this->diplomaURI}"&gt;
   &lt;img src="{$wgServer}{$this->diplomaURIThumbnail}" /&gt;
  &lt;/a&gt;
  &lt;a href="{$action}"&gt;{$this->msg("try")} {$test['test']->mwt_name}!&lt;/a&gt;
 </pre>
</td></tr></table>
EOT;
                }
            }
            $cache->add($cachekey, $out, 86400);
            $to = new MailAddress($this->adminEmail);
            $sender = new MailAddress($this->adminEmail);
            $mailResult = UserMailer::send($to , $sender , "[Quiz] «".$test['test']->mwt_name."» $ip => $percent_score%", $mailmessage);
            $wgOut->addHTML($out);
            return;
        }
    }
}