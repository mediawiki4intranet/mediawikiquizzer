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
