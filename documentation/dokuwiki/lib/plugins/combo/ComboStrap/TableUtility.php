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

/**
 *
 * Class TableUtility
 * @package ComboStrap
 *
 * @see <a href="https://datatables.net/examples/styling/bootstrap4">DataTables (May be)</a>
 *
 */
class TableUtility
{

    const TABLE_SNIPPET_ID = "table";

    static function tableOpen($renderer, $pos)
    {

        $class = 'table';
        if ($pos !== null) {
            $sectionEditStartData = ['target' => 'table'];
            if (!defined('SEC_EDIT_PATTERN')) {
                // backwards-compatibility for Frusterick Manners (2017-02-19)
                $sectionEditStartData = 'table';
            }
            $class .= ' ' . $renderer->startSectionEdit($pos, $sectionEditStartData);
        }
        // table-responsive and
        $bootResponsiveClass = 'table-responsive';

        // Add non-fluid to not have a table that takes 100% of the space
        // Otherwise we can't have floating element at the right and the visual space is to big
        PluginUtility::getSnippetManager()->attachCssSnippetForRequest(self::TABLE_SNIPPET_ID);

        $bootTableClass = 'table table-non-fluid table-hover table-striped';

        $renderer->doc .= '<div class="' . $class . ' ' . $bootResponsiveClass . '"><table class="inline ' . $bootTableClass . '">' . DOKU_LF;
    }

}
