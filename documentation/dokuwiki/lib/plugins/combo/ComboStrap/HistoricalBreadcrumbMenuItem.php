<?php
/**
 * Copyright (c) 2021. ComboStrap, Inc. and its affiliates. All Rights Reserved.
 *
 * This source code is licensed under the GPL license found in the
 * COPYING  file in the root directory of this source tree.
 *
 * @license  GPL 3 (https://www.gnu.org/licenses/gpl-3.0.en.html)
 * @author   ComboStrap <support@combostrap.com>
 *
 */

namespace ComboStrap;


use dokuwiki\Menu\Item\AbstractItem;

/**
 * Class MenuItem
 * *
 * @package ComboStrap
 *
 * To be able to debug, disable the trigger data attribute
 * The popover will stay on the page
 */
class HistoricalBreadcrumbMenuItem extends AbstractItem
{


    const RECENT_PAGES_VISITED = "Recent Pages Visited";

    /**
     * This unique name should not be in the {@link \action_plugin_combo_historicalbreadcrumb}
     * to avoid circular reference
     */
    const HISTORICAL_BREADCRUMB_NAME = "historical-breadcrumb";


    /**
     *
     * @return string
     */
    public function getLabel()
    {
        return self::RECENT_PAGES_VISITED;
    }

    public function getLinkAttributes($classprefix = 'menuitem ')
    {

        $linkAttributes = parent::getLinkAttributes($classprefix);
        $linkAttributes['href'] = "#";
        $dataAttributeNamespace = Bootstrap::getDataNamespace();
        $linkAttributes["data{$dataAttributeNamespace}-toggle"] = "popover";
        $linkAttributes["data{$dataAttributeNamespace}-html"] = "true";
        global $lang;
        $linkAttributes["data{$dataAttributeNamespace}-title"] = $lang['breadcrumb'];


        $pages = array_reverse(breadcrumbs());

        /**
         * All page should be shown,
         * also the actual
         * because when the user is going
         * in admin mode, it's an easy way to get back
         */
        $actualPageId = array_keys($pages)[0];
        $actualPageName = array_shift($pages);
        $html = $this->createLink($actualPageId,$actualPageName, self::HISTORICAL_BREADCRUMB_NAME ."-home");

        $html .= '<ol>' . PHP_EOL;
        foreach ($pages as $id => $name) {

            $html .= '<li>';
            $html .= $this->createLink($id,$name);
            $html .= '</li>' . PHP_EOL;

        }
        $html .= '</ol>' . PHP_EOL;
        $html .= '</nav>' . PHP_EOL;
        $linkAttributes["data{$dataAttributeNamespace}-content"] = $html;

        // Dismiss on next click
        // To debug, just comment this line
        $linkAttributes["data{$dataAttributeNamespace}-trigger"] = "focus";

        // See for the tabindex
        // https://getbootstrap.com/docs/5.1/components/popovers/#dismiss-on-next-click
        $linkAttributes['tabindex'] = "0";

        $linkAttributes["data{$dataAttributeNamespace}custom-class"] = "historical-breadcrumb";
        return $linkAttributes;
    }

    public function getTitle()
    {
        /**
         * The title (unfortunately) is deleted from the anchor
         * and is used as header in the popover
         */
        return self::RECENT_PAGES_VISITED;
    }

    public function getSvg()
    {
        /** @var string icon file */
        return Resources::getImagesDirectory() . '/history.svg';
    }

    /**
     * @param $id
     * @param $name
     * @param null $class
     * @return string
     */
    public function createLink($id,$name,$class=null)
    {
        $page = Page::createPageFromId($id);
        if ($name == "start") {
            $name = "Home Page";
        } else {
            $name = $page->getTitleNotEmpty();
        }
        $tagAttributes = null;
        if($class!=null){
            $tagAttributes = TagAttributes::createEmpty();
            $tagAttributes->addClassName($class);
        }
        $link = LinkUtility::createFromPageId($id,$tagAttributes);

        return $link->renderOpenTag() . $name . $link->renderClosingTag();
    }


}
