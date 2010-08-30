<?php

/* Maintenance script for re-parsing quizzes after extension update. */

$options = array('quick');
require_once(dirname(__FILE__).'/../../maintenance/commandLine.inc');
require_once(dirname(__FILE__).'/../../maintenance/counter.php');

if (!defined('NS_QUIZ'))
    die("MediaWikiQuizzer is disabled on this wiki.");

print "Going to reparse all articles inside Quiz namespace.
This is needed after updating MediaWikiQuizzer extension.
Depending on the size of your database this may take a while!
";

if (!isset($options['quick']))
{
    print "Abort with control-c in the next five seconds... ";
    for ($i = 6; $i >= 1;)
    {
        print_c($i, --$i);
        sleep(1);
    }
    echo "\n";
}

$titles = array();
$dbw = wfGetDB(DB_MASTER);
$result = $dbw->select('page', 'page_id', array('page_namespace' => NS_QUIZ, "INSTR(page_title,'/')=0"));
while ($row = $dbw->fetchRow($result))
    $titles[] = Title::newFromId($row[0]);
$dbw->freeResult($result);

$wgLang->fixUpSettings();
foreach ($titles as $t)
{
    print $t->getPrefixedText()."...\n";
    if ($a = new Article($t))
        $a->doEdit($a->getContent(), 'Re-parse MWQuizzer quiz', EDIT_FORCE_BOT);
}
