<?php

/*
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * @author Stas Fomin <stas-fomin@yandex.ru>
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 */

if (!defined('MEDIAWIKI'))
    die();

require_once("mediawikiquizzer.i18n.php");

$wgExtensionCredits['specialpage'][] = array(
    'name'        => 'MediawikiQuizzer',
    'author'      => ' Stas Fomin ',
    'version'     => '1.32 (2010-03-24)',
    'description' => 'Quiz System for MediaWiki',
    'url'         => 'http://lib.custis.ru/index.php/MediawikiQuizzer'
);

$dir = dirname(__FILE__) . '/';
$wgExtensionMessagesFiles['MediawikiQuizzer'] = $dir . 'mediawikiquizzer.i18n.php';
$wgSpecialPages['MediawikiQuizzer'] = 'MediawikiQuizzerPage';
$wgAutoloadClasses['MediawikiQuizzerPage'] = $dir . 'mediawikiquizzer.class.php';
$wgAutoloadClasses['MediawikiQuizzerUpdater'] = $dir . 'mediawikiquizzer.class.php';
$wgAutoloadClasses['DOMParseUtils'] = $dir . 'DOMParseUtils.php';
$wgHooks['LoadExtensionSchemaUpdates'][] = 'MediawikiQuizzer::LoadExtensionSchemaUpdates';
$wgHooks['ArticleSaveComplete'][] = 'MediawikiQuizzer::ArticleSaveComplete';
$wgHooks['ArticleViewHeader'][] = 'MediawikiQuizzer::ArticleViewHeader';
$wgExtensionFunctions[] = 'MediawikiQuizzer::init';

/* DEFAULT SETTINGS GO HERE */

if (!$egMWQuizzerAdmins)
    $egMWQuizzerAdmins = array('WikiSysop');

if (!$egMWQuizzerCertificateTemplate)
    $egMWQuizzerCertificateTemplate = $dir . 'diploma.png';

if (!$egMWQuizzerCertificateDir)
    $egMWQuizzerCertificateDir = str_replace("\\", "/", dirname(dirname(realpath($dir)))."/images/generated/diplomas");

if (!$egMWQuizzerCertificateUri)
    $egMWQuizzerCertificateUri = "images/generated/diplomas";

class MediawikiQuizzer
{
    static function isTestAdmin()
    {
        global $wgUser, $egMWQuizzerAdmins;
        return $wgUser->getId() && in_array($wgUser->getName(), $egMWQuizzerAdmins);
    }

    static function isQuiz($t)
    {
        return $t && $t->getNamespace() == NS_QUIZ && strpos($t->getText(), '/') === false;
    }

    static function setupNamespace($index)
    {
        $index = $index & ~1;
        define('NS_QUIZ', $index);
        define('NS_QUIZ_TALK', $index+1);
    }

    static function init()
    {
        if (!defined('NS_QUIZ'))
            die("Please add the following line:\nMediawikiQuizzer::setupNamespace(XXX);\nto your LocalSettings.php, where XXX is an available integer index for Quiz namespace");
        global $wgExtraNamespaces, $wgCanonicalNamespaceNames, $wgNamespaceAliases, $wgParser;
        $wgExtraNamespaces[NS_QUIZ] = $wgCanonicalNamespaceNames[NS_QUIZ] = 'Quiz';
        $wgExtraNamespaces[NS_QUIZ_TALK] = $wgCanonicalNamespaceNames[NS_QUIZ_TALK] = 'Quiz_talk';
        $wgNamespaceAliases['Quiz'] = NS_QUIZ;
        $wgNamespaceAliases['Quiz_talk'] = NS_QUIZ_TALK;
    }

    static function LoadExtensionSchemaUpdates()
    {
        global $wgExtNewTables, $wgExtNewFields;
        $wgExtNewTables[] = array('mwq_test', dirname(__FILE__).'/mwquizzer-tables.sql');
        $wgExtNewFields[] = array('mwq_question', 'qn_anchor', dirname(__FILE__).'/patch-question-anchor.sql');
        $wgExtNewFields[] = array('mwq_ticket', 'tk_score', dirname(__FILE__).'/patch-ticket-score.sql');
        return true;
    }

    static function ArticleSaveComplete(&$article, &$user, $text, $summary, $minoredit)
    {
        if (self::isQuiz($article->getTitle()))
            MediawikiQuizzerUpdater::updateQuiz($article, $text);
        return true;
    }

    static function ArticleViewHeader(&$article, &$outputDone, &$pcache)
    {
        global $wgOut;
        if (self::isQuiz($t = $article->getTitle()))
            MediawikiQuizzerPage::quizArticleInfo($t->getText());
        return true;
    }
}
