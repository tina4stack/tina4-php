<?php

namespace ComboStrap;

use action_plugin_combo_urlmanager;

include_once(__DIR__ . "/PagesIndex.php");

/**
 * Class UrlManagerBestEndPage
 *
 * A class that implements the BestEndPage Algorithm for the {@link action_plugin_combo_urlmanager urlManager}
 */
class UrlManagerBestEndPage
{

    /**
     * If the number of names part that match is greater or equal to
     * this configuration, an Id Redirect is performed
     * A value of 0 disable and send only HTTP redirect
     */
    const CONF_MINIMAL_SCORE_FOR_REDIRECT = 'BestEndPageMinimalScoreForIdRedirect';
    const CONF_MINIMAL_SCORE_FOR_REDIRECT_DEFAULT = '0';


    /**
     * @param $pageId
     * @return array - the best poge id and its score
     * The score is the number of name that matches
     */
    public static function getBestEndPageId($pageId)
    {

        $result = array();
        $pageName = noNS($pageId);

        $pagesWithSameName = PagesIndex::pagesWithSameName($pageName, $pageId);
        if (count($pagesWithSameName) > 0) {

            // Default value
            $bestScore = 0;
            $bestPage = $pagesWithSameName[0];

            // The name of the dokuwiki id
            $pageIdNames = explode(':', $pageId);

            // Loop
            foreach ($pagesWithSameName as $targetPageId => $pageTitle) {

                $targetPageIdNames = explode(':', $targetPageId);
                $targetPageIdScore = 0;
                for ($i = 1; $i <= sizeof($pageIdNames); $i++) {
                    $pageIdName = $pageIdNames[sizeof($pageIdNames) - $i];
                    $indexTargetPage = sizeof($targetPageIdNames) - $i;
                    if ($indexTargetPage < 0) {
                        break;
                    }
                    $targetPageIdName = $targetPageIdNames[$indexTargetPage];
                    if ($targetPageIdName == $pageIdName) {
                        $targetPageIdScore++;
                    } else {
                        break;
                    }

                }
                if ($targetPageIdScore > $bestScore) {
                    $bestScore = $targetPageIdScore;
                    $bestPage = $targetPageId;
                }

            }

            $result = array(
                $bestPage,
                $bestScore
            );

        }
        return $result;

    }


    /**
     * @param $pageId
     * @return array with the best page and the type of redirect
     */
    public static function process($pageId)
    {

        $return = array();
        global $conf;
        $minimalScoreForARedirect = $conf['plugin'][PluginUtility::PLUGIN_BASE_NAME][self::CONF_MINIMAL_SCORE_FOR_REDIRECT];

        list($bestPageId, $bestScore) = self::getBestEndPageId($pageId);
        if ($bestPageId != null) {
            $redirectType = action_plugin_combo_urlmanager::REDIRECT_HTTP;
            if ($minimalScoreForARedirect != 0 && $bestScore >= $minimalScoreForARedirect) {
                $redirectType = action_plugin_combo_urlmanager::REDIRECT_ID;
            }
            $return = array(
                $bestPageId,
                $redirectType
            );
        }
        return $return;

    }
}
