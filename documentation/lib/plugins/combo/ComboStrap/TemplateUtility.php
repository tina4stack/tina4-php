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
 * Class TemplateUtility
 * @package ComboStrap
 * See also {@link Template template engine} - not finished
 */
class TemplateUtility
{

    const VARIABLE_PREFIX = "$";

    static function renderStringTemplateForPageId($pageTemplate, $pageId)
    {


        $page = Page::createPageFromId($pageId);


        return self::renderStringTemplateForDataPage($pageTemplate, $page);

    }

    /**
     * @param $pageId
     * @return array|mixed|null
     * @deprecated 2021-07-02 see {@link Page::getH1()}
     */
    public static function getPageH1($pageId)
    {
        $h1 = p_get_metadata(cleanID($pageId), Analytics::H1, METADATA_DONT_RENDER);
        if (empty($h1)) {
            return self::getPageTitle($pageId);
        } else {
            return $h1;
        }
    }

    /**
     * This function is used on a lot of place
     *
     * Due to error with the title rendering, we have refactored
     *
     * @param $pageId
     * @return array|mixed|null
     * @deprecated 2021-07-02 see {@link Page::getTitle()}
     */
    public static function getPageTitle($pageId)
    {
        $name = $pageId;
        // The title of the page
        if (useHeading('navigation')) {

            // $title = $page['title'] can not be used to retrieve the title
            // because it didn't encode the HTML tag
            // for instance if <math></math> is used, the output must have &lgt ...
            // otherwise browser may add quote and the math plugin will not work
            // May be a solution was just to encode the output

            /**
             * Bug:
             * PHP Fatal error:  Allowed memory size of 134217728 bytes exhausted (tried to allocate 98570240 bytes)
             * in inc/Cache/CacheInstructions.php on line 44
             * It was caused by a recursion in the rendering, it seems in {@link p_get_metadata()}
             * The parameter METADATA_DONT_RENDER stops the recursion
             *
             * Don't use the below procedures, they all call the same function and render the meta
             *   * $name = tpl_pagetitle($pageId, true);
             *   * $title = p_get_first_heading($page['id']);
             *
             * In the <a href="https://www.dokuwiki.org/devel:metadata">documentation</a>,
             * the rendering mode that they advertised for the title is `METADATA_RENDER_SIMPLE_CACHE`
             *
             * We have chosen METADATA_DONT_RENDER (0)
             * because when we asks for the title of the directory,
             * it will render the whole tree (the directory of the directory)
             *
             *
             */
            $name = p_get_metadata(cleanID($pageId), 'title', METADATA_DONT_RENDER);

        }
        return $name;
    }

    /**
     * Replace placeholder
     * @param Call[] $namespaceTemplateInstructions
     * @param $pagePath
     * @return array
     */
    public static function renderInstructionsTemplateFromDataPage(array $namespaceTemplateInstructions, $pagePath)
    {
        $page = Page::createPageFromQualifiedPath($pagePath);
        $instructions = [];
        foreach ($namespaceTemplateInstructions as $call) {
            $newCall = clone $call;
            $instructions[] = $newCall->render($page)->toCallArray();
        }
        return $instructions;

    }

    public static function renderInstructionsTemplateFromDataArray(array $namespaceTemplateInstructions, array $array)
    {

        $instructions = [];
        foreach ($namespaceTemplateInstructions as $call) {
            $newCall = clone $call;
            $instructions[] = $newCall->renderFromData($array)->toCallArray();
        }
        return $instructions;

    }

    public static function renderStringTemplateForDataPage($stringTemplate, Page $page)
    {



        return TemplateUtility::renderStringTemplateFromDataArray($stringTemplate, TemplateUtility::getMetadataDataFromPage($page));

    }

    /**
     * Render a template string from a data array
     * @param $pageTemplate
     * @param array $array
     * @return string
     */
    public static function renderStringTemplateFromDataArray($pageTemplate, array $array)
    {

        $template = Template::create($pageTemplate);

        foreach ($array as $key => $val) {
            /**
             * Hack: Replace every " by a ' to be able to detect/parse the title/h1 on a pipeline
             * @see {@link \syntax_plugin_combo_pipeline}
             */
            $val = str_replace('"', "'", $val);
            $template->set($key, $val);
        }

        return $template->render();

    }

    public static function getMetadataDataFromPage(Page $page)
    {

        $array = $page->getMetadataStandard();
        /**
         * @deprecated for path
         */
        $array["id"] = $page->getId();

        /**
         * Override / add the user variable
         */
        foreach ($page->getPersistentMetadatas() as $metaKey => $metaValue) {

            $array[$metaKey] = $metaValue;

        }
        return $array;

    }


}
