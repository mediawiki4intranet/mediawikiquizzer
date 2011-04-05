<?php

/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * @author Stas Fomin <stas-fomin@yandex.ru>
 * @author Vitaliy Filippov <vitalif@mail.ru>
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 */

if (!defined('MEDIAWIKI'))
    die();
$dir = dirname(__FILE__) . '/';

/* DEFAULT SETTINGS GO HERE */

// If set, this value is treated as IntraACL/HaloACL "Test admin" group name
// This must be a complete name, with "Group/" prefix
// See http://wiki.4intra.net/IntraACL for extension details
if (!$egMWQuizzerIntraACLAdminGroup)
    $egMWQuizzerIntraACLAdminGroup = false;

// If set to a list of usernames, users with these names are also treated as test administrators
if (!$egMWQuizzerAdmins)
    $egMWQuizzerAdmins = array('WikiSysop');

// Path to diploma.png successful test completion "certificate" file
if (!$egMWQuizzerCertificateTemplate)
    $egMWQuizzerCertificateTemplate = $dir . 'diploma.png';

// Directory where the generated "certificates" are placed
if (!$egMWQuizzerCertificateDir)
    $egMWQuizzerCertificateDir = str_replace("\\", "/", dirname(dirname(realpath($dir)))."/images/generated/diplomas");

// Path to this directory
if (!$egMWQuizzerCertificateUri)
    $egMWQuizzerCertificateUri = "images/generated/diplomas";

// Percent of correct question completion to consider it "easy" (green hint)
if (!$egMWQuizzerEasyQuestionCompl)
    $egMWQuizzerEasyQuestionCompl = 80;

// Percent of correct question completion to consider it "hard" (red hint)
if (!$egMWQuizzerHardQuestionCompl)
    $egMWQuizzerHardQuestionCompl = 30;

/* END DEFAULT SETTINGS */

require_once("mediawikiquizzer.i18n.php");

$wgExtensionCredits['specialpage'][] = array(
    'name'        => 'MediawikiQuizzer',
    'author'      => ' Stas Fomin, Vitaliy Filippov ',
    'version'     => '1.4 (2011-04-04)',
    'description' => 'Quiz System for MediaWiki',
    'url'         => 'http://wiki.4intra.net/MediawikiQuizzer'
);

$wgExtensionMessagesFiles['MediawikiQuizzer'] = $dir . 'mediawikiquizzer.i18n.php';
$wgSpecialPages['MediawikiQuizzer'] = 'MediawikiQuizzerPage';
$wgAutoloadClasses += array(
    'MediawikiQuizzerPage'    => $dir . 'mediawikiquizzer.class.php',
    'MediawikiQuizzerUpdater' => $dir . 'mediawikiquizzer.updater.php',
    'DOMParseUtils'           => $dir . 'DOMParseUtils.php',
);
$wgHooks['LoadExtensionSchemaUpdates'][] = 'MediawikiQuizzer::LoadExtensionSchemaUpdates';
$wgHooks['ArticleSaveComplete'][] = 'MediawikiQuizzer::ArticleSaveComplete';
$wgHooks['ArticleViewHeader'][] = 'MediawikiQuizzer::ArticleViewHeader';
$wgHooks['DoEditSectionLink'][] = 'MediawikiQuizzer::DoEditSectionLink';
$wgExtensionFunctions[] = 'MediawikiQuizzer::init';

class MediawikiQuizzer
{
    static $disableQuestionInfo = false;

    // Returns true if current user is a test administrator
    // and has all privileges for test system
    static function isTestAdmin()
    {
        global $wgUser, $egMWQuizzerAdmins, $egMWQuizzerIntraACLAdminGroup;
        if (!$wgUser->getId())
            return false;
        if (in_array('bureaucrat', $wgUser->getGroups()))
            return true;
        if ($egMWQuizzerAdmins && in_array($wgUser->getName(), $egMWQuizzerAdmins))
            return true;
        if ($egMWQuizzerIntraACLAdminGroup && class_exists('HACLGroup'))
        {
            $intraacl_group = HACLGroup::newFromName($egMWQuizzerIntraACLAdminGroup, false);
            if ($intraacl_group && $intraacl_group->hasUserMember($wgUser, true))
                return true;
        }
        return false;
    }

    // Returns true if Title $t corresponds to an article which defines a quiz
    static function isQuiz(Title $t)
    {
        return $t && $t->getNamespace() == NS_QUIZ && strpos($t->getText(), '/') === false;
    }

    // Setup MediaWiki namespace for Quizzer
    static function setupNamespace($index)
    {
        $index = $index & ~1;
        define('NS_QUIZ', $index);
        define('NS_QUIZ_TALK', $index+1);
    }

    // Initialize extension
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

    // Hook for maintenance/update.php
    static function LoadExtensionSchemaUpdates()
    {
        global $wgExtNewTables, $wgExtNewFields;
        $wgExtNewTables[] = array('mwq_test', dirname(__FILE__).'/mwquizzer-tables.sql');
        $wgExtNewFields[] = array('mwq_question', 'qn_anchor', dirname(__FILE__).'/patch-question-anchor.sql');
        $wgExtNewFields[] = array('mwq_ticket', 'tk_score', dirname(__FILE__).'/patch-ticket-score.sql');
        $wgExtNewFields[] = array('mwq_test', 'LONGBLOB test_log', dirname(__FILE__).'/patch-longblob.sql');
        return true;
    }

    // Quiz update hook
    static function ArticleSaveComplete(&$article, &$user, $text, $summary, $minoredit)
    {
        if (self::isQuiz($article->getTitle()))
            MediawikiQuizzerUpdater::updateQuiz($article, $text);
        /* Update quizzes which include updated article */
        foreach (self::getQuizLinksTo($article->getTitle()) as $template)
        {
            $article = new Article($template);
            MediawikiQuizzerUpdater::updateQuiz($article, $article->getContent());
        }
        return true;
    }

    // Quiz display hook
    static function ArticleViewHeader(&$article, &$outputDone, &$pcache)
    {
        global $wgOut;
        if (self::isQuiz($t = $article->getTitle()))
            MediawikiQuizzerPage::quizArticleInfo($t->getText());
        return true;
    }

    // Hook for displaying statistics near question titles
    static function DoEditSectionLink($skin, $nt, $section, $tooltip, &$result)
    {
        if (!self::$disableQuestionInfo && self::isQuiz($nt))
            MediawikiQuizzerPage::quizQuestionInfo($nt, $section, $result);
        return true;
    }

    // Get quizzes which include given title
    // Now does not return quizzes which include given title through
    // an article which is not inside Quiz namespace for performance reasons
    static function getQuizLinksTo($title)
    {
        $id_seen = array();
        $quiz_links = array();
        $dbr = wfGetDB(DB_SLAVE);

        $where = array(
            'tl_namespace' => $title->getNamespace(),
            'tl_title' => $title->getDBkey(),
        );

        do
        {
            $res = $dbr->select(
                array('page', 'templatelinks'),
                array('page_namespace', 'page_title', 'page_id', 'page_len', 'page_is_redirect' ),
                $where + array(
                    'page_namespace' => NS_QUIZ,
                    'page_is_redirect' => 0,
                    'tl_from=page_id',
                ),
                __METHOD__
            );

            $where['tl_namespace'] = NS_QUIZ;
            $where['tl_title'] = array();

            if (!$dbr->numRows($res))
                break;

            foreach ($res as $row)
            {
                if ($titleObj = Title::makeTitle($row->page_namespace, $row->page_title))
                {
                    if ($titleObj->getNamespace() == NS_QUIZ && !$id_seen[$row->page_id])
                    {
                        /* Make closure only inside NS_QUIZ */
                        $where['tl_title'][] = $titleObj->getDBkey();
                        $id_seen[$row->page_id] = 1;
                    }
                    $quiz_links[] = $titleObj;
                }
            }

            $dbr->freeResult($res);
        } while (count($where['tl_title']));

        return $quiz_links;
    }
}
