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
$egMWQuizzerIntraACLAdminGroup = false;

// If set to a list of usernames, users with these names are also treated as test administrators
$egMWQuizzerAdmins = array('WikiSysop');

// Path to diploma.png successful test completion "certificate" file
$egMWQuizzerCertificateTemplate = $dir . 'diploma.png';

// Subdirectory of $wgUploadDirectory where the generated "certificates" are placed
$egMWQuizzerCertificateSubDir = '/generated/diplomas';

// Path to this directory
$egMWQuizzerCertificateUri = "images/generated/diplomas";

// Percent of correct question completion to consider it "easy" (green hint)
$egMWQuizzerEasyQuestionCompl = 80;

// Percent of correct question completion to consider it "hard" (red hint)
$egMWQuizzerHardQuestionCompl = 30;

// Content language used to parse tests, in addition to English, which is always used
$egMWQuizzerContLang = false;

/* END DEFAULT SETTINGS */

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
$wgHooks['ArticlePurge'][] = 'MediawikiQuizzer::ArticlePurge';
$wgHooks['ArticleViewHeader'][] = 'MediawikiQuizzer::ArticleViewHeader';
$wgHooks['DoEditSectionLink'][] = 'MediawikiQuizzer::DoEditSectionLink';
$wgExtensionFunctions[] = 'MediawikiQuizzer::init';
$wgGroupPermissions['secretquiz']['secretquiz'] = true;

class MediawikiQuizzer
{
    static $disableQuestionInfo = false;
    static $updated = array();

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
        global $wgVersion, $wgHooks;
        $wgExtraNamespaces[NS_QUIZ] = $wgCanonicalNamespaceNames[NS_QUIZ] = 'Quiz';
        $wgExtraNamespaces[NS_QUIZ_TALK] = $wgCanonicalNamespaceNames[NS_QUIZ_TALK] = 'Quiz_talk';
        $wgNamespaceAliases['Quiz'] = NS_QUIZ;
        $wgNamespaceAliases['Quiz_talk'] = NS_QUIZ_TALK;
        if ($wgVersion < '1.14')
            $wgHooks['NewRevisionFromEditComplete'][] = 'MediawikiQuizzer::NewRevisionFromEditComplete';
        else
            $wgHooks['ArticleEditUpdates'][] = 'MediawikiQuizzer::ArticleEditUpdates';
    }

    // Hook for maintenance/update.php
    static function LoadExtensionSchemaUpdates($updater)
    {
        global $wgDBtype;
        $dir = dirname(__FILE__);
        if ($updater)
        {
            $updater->addExtensionUpdate(array('addTable', 'mwq_test', $dir.'/mwquizzer-tables.'.$wgDBtype.'.sql', true));
            if ($wgDBtype == 'mysql')
            {
                $updater->addExtensionUpdate(array('addField', 'mwq_test', 'test_page_title', $dir.'/mwquizzer-patch-test_id.sql', true));
                $updater->addExtensionUpdate(array('addField', 'mwq_test', 'test_user_details', $dir.'/mwquizzer-patch-user_details.sql', true));
                $updater->addExtensionUpdate(array('addField', 'mwq_ticket', 'tk_reviewed', $dir.'/mwquizzer-patch-tk_reviewed.sql', true));
                $updater->addExtensionUpdate(array('addField', 'mwq_choice_stats', 'cs_text', $dir.'/mwquizzer-patch-freetext.sql', true));
                $updater->addExtensionUpdate(array('addField', 'mwq_test', 'test_secret', $dir.'/mwquizzer-patch-test_secret.sql', true));
            }
            elseif ($wgDBtype == 'postgres')
                $updater->addExtensionUpdate(array('addField', 'mwq_test', 'test_secret', $dir.'/mwquizzer-patch-test_secret-postgres.sql', true));
        }
        else
        {
            global $wgExtNewTables, $wgExtNewFields;
            $wgExtNewTables[] = array('mwq_test', $dir.'/mwquizzer-tables.'.$wgDBtype.'.sql');
            if ($wgDBtype == 'mysql')
            {
                $wgExtNewFields[] = array('mwq_test', 'test_page_title', $dir.'/mwquizzer-patch-test_id.sql');
                $wgExtNewFields[] = array('mwq_test', 'test_user_details', $dir.'/mwquizzer-patch-user_details.sql');
                $wgExtNewFields[] = array('mwq_ticket', 'tk_reviewed', $dir.'/mwquizzer-patch-tk_reviewed.sql');
                $wgExtNewFields[] = array('mwq_choice_stats', 'cs_text', $dir.'/mwquizzer-patch-freetext.sql');
                $wgExtNewFields[] = array('mwq_test', 'test_secret', $dir.'/mwquizzer-patch-test_secret.sql');
            }
            elseif ($wgDBtype == 'postgres')
                $wgExtNewFields[] = array('mwq_test', 'test_secret', $dir.'/mwquizzer-patch-test_secret-postgres.sql');
        }
        return true;
    }

    // Quiz update hook, updates the quiz on every save, even when no new revision was created
    static function ArticleSaveComplete($article, $user, $text, $summary, $minoredit)
    {
        global $wgVersion;
        if (isset(self::$updated[$article->getId()]))
            return true;
        if ($article->getTitle()->getNamespace() == NS_QUIZ)
        {
            if (self::isQuiz($article->getTitle()))
            {
                // Reload new revision id
                $article = new Article($article->getTitle());
                MediawikiQuizzerUpdater::updateQuiz($article, $text);
            }
            // Update quizzes which include updated article
            foreach (self::getQuizLinksTo($article->getTitle()) as $template)
            {
                $article = new Article($template);
                MediawikiQuizzerUpdater::updateQuiz($article, $wgVersion < '1.18' ? $article->getContent() : $article->getRawText());
            }
            self::$updated[$article->getId()] = true;
        }
        return true;
    }

    // Another quiz update hook, for action=purge
    static function ArticlePurge($article)
    {
        global $wgVersion;
        self::ArticleSaveComplete($article, NULL, $wgVersion < '1.18' ? $article->getContent() : $article->getRawText(), NULL, NULL);
        return true;
    }

    // Another quiz update hook, for MW < 1.14, called when a new revision is created
    static function NewRevisionFromEditComplete($article, $rev, $baseID, $user)
    {
        self::ArticlePurge($article);
        return true;
    }

    // Another quiz update hook, for MW >= 1.14, called when a new revision is created
    static function ArticleEditUpdates($article, $editInfo, $changed)
    {
        self::ArticleSaveComplete($article, NULL, $editInfo->newText, NULL, NULL);
        return true;
    }

    // Quiz display hook
    static function ArticleViewHeader($article, &$outputDone, &$pcache)
    {
        global $wgOut;
        if (self::isQuiz($t = $article->getTitle()))
            MediawikiQuizzerPage::quizArticleInfo($t);
        return true;
    }

    // Hook for displaying statistics near question titles
    static function DoEditSectionLink($skin, $nt, $section, $tooltip, &$result)
    {
        if (!self::$disableQuestionInfo && $nt->getNamespace() == NS_QUIZ)
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
                        // Make closure only inside NS_QUIZ
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
