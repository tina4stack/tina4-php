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
use renderer_plugin_combo_analytics;

/**
 * Class MenuItem
 * *
 * @package ComboStrap
 *
 * Inspiration:
 * https://raw.githubusercontent.com/splitbrain/dokuwiki-plugin-dw2pdf/master/MenuItem.php
 */
class AnalyticsMenuItem extends AbstractItem {
    const ITEM_ID = renderer_plugin_combo_analytics::RENDERER_NAME_MODE . "_item_id";


    /** @var string do action for this plugin */
    protected $type = 'export_'. renderer_plugin_combo_analytics::RENDERER_NAME_MODE;



    /**
     *
     * @return string
     */
    public function getLabel() {
        return "Analytics";
    }

    public function getLinkAttributes($classprefix = 'menuitem ')
    {
        $linkAttributes = parent::getLinkAttributes($classprefix);
        $linkAttributes['id']= self::ITEM_ID;
        return $linkAttributes;
    }

    public function getTitle()
    {
        return "Show the ComboStrap analytics";
    }

    public function getSvg()
    {
        /** @var string icon file */
        return Resources::getImagesDirectory() . '/file-chart.svg';
    }


}
