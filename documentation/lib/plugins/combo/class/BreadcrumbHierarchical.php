<?php
/**
 * Copyright (c) 2020. ComboStrap, Inc. and its affiliates. All Rights Reserved.
 *
 * This source code is licensed under the GPL license found in the
 * COPYING  file in the root directory of this source tree.
 *
 * @license  GPL 3 (https://www.gnu.org/licenses/gpl-3.0.en.html)
 * @author   ComboStrap <support@combostrap.com>
 *
 */

namespace ComboStrap;


class BreadcrumbHierarchical
{
    const CANONICAL = "breadcrumb-hierarchical";

    /**
     * Hierarchical breadcrumbs (you are here)
     *
     * This will return the Hierarchical breadcrumbs.
     *
     * Config:
     *    - $conf['youarehere'] must be true
     *    - add $lang['youarehere'] if $printPrefix is true
     *
     * Metadata comes from here
     * https://developers.google.com/search/docs/data-types/breadcrumb
     *
     * @return string
     */
    static function render()
    {

        global $conf;

        // check if enabled
        if (!$conf['youarehere']) return "";

        // print intermediate namespace links
        $htmlOutput = '<div class="branch rplus">' . PHP_EOL;

        // Breadcrumb head
        $htmlOutput .= '<nav aria-label="breadcrumb">' . PHP_EOL;
        $htmlOutput .= '<ol class="breadcrumb">' . PHP_EOL;

        // Home
        $htmlOutput .= '<li class="breadcrumb-item">' . PHP_EOL;
        $page = $conf['start'];
        $pageTitle = tpl_pagetitle($page, true);
        $htmlOutput .= tpl_link(wl($page), 'Home', 'title="' . $pageTitle . '"', $return = true);
        $htmlOutput .= '</li>' . PHP_EOL;

        // Print the parts if there is more than one
        global $ID;
        $idParts = explode(':', $ID);
        $countPart = count($idParts);
        if ($countPart > 1) {

            // Print the parts without the last one ($count -1)
            $pagePart = "";
            $currentParts = [];
            for ($i = 0; $i < $countPart - 1; $i++) {

                $currentPart = $idParts[$i];
                $currentParts[] = $currentPart;

                /**
                 * We pass the value to the page variable
                 * because the resolve part will change it
                 *
                 * resolve will also resolve to the home page
                 */

                $page = implode(DokuPath::PATH_SEPARATOR,$currentParts).":";
                $exist = null;
                resolve_pageid(getNS($ID), $page, $exist, "", true);

                $pageTitle = tpl_pagetitle($page, true);
                $linkContent = $pageTitle;
                $htmlOutput .= '<li class="breadcrumb-item">';
                // html_wikilink because the page has the form pagename: and not pagename:pagename
                if ($exist) {
                    $htmlOutput .= tpl_link(wl($page), $linkContent, 'title="' . $pageTitle . '"', $return = true);
                } else {
                    $htmlOutput .= ucfirst($currentPart);
                }

                $htmlOutput .= '</li>' . PHP_EOL;

            }
        }

        // close the breadcrumb
        $htmlOutput .= '</ol>' . PHP_EOL;
        $htmlOutput .= '</nav>' . PHP_EOL;
        $htmlOutput .= "</div>" . PHP_EOL;


        return $htmlOutput;

    }

}
