<?php
/**
 * Plugin minimap : Displays mini-map for namespace
 *
 * @license GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author  Nicolas GERARD
 */

use ComboStrap\SnippetManager;
use ComboStrap\LinkUtility;
use ComboStrap\PluginUtility;

if (!defined('DOKU_INC')) die();


class syntax_plugin_combo_minimap extends DokuWiki_Syntax_Plugin
{

    const MINIMAP_TAG_NAME = 'minimap';
    const INCLUDE_DIRECTORY_PARAMETERS = 'includedirectory';
    const SHOW_HEADER = 'showheader';
    const NAMESPACE_KEY_ATT = 'namespace';



    function connectTo($aMode)
    {
        $pattern = '<' . self::MINIMAP_TAG_NAME . '[^>]*>';
        $this->Lexer->addSpecialPattern($pattern, $aMode, PluginUtility::getModeFromTag($this->getPluginComponent()));
    }

    function getSort()
    {
        /**
         * One less than the old one
         */
        return 149;
    }

    /**
     * No p element please
     * @return string
     */
    function getPType()
    {
        return 'block';
    }

    function getType()
    {
        // The spelling is wrong but this is a correct value
        // https://www.dokuwiki.org/devel:syntax_plugins#syntax_types
        return 'substition';
    }

    /**
     *
     * The handle function goal is to parse the matched syntax through the pattern function
     * and to return the result for use in the renderer
     * This result is always cached until the page is modified.
     * @param string $match
     * @param int $state
     * @param int $pos
     * @param Doku_Handler $handler
     * @return array|bool
     * @see DokuWiki_Syntax_Plugin::handle()
     *
     */
    function handle($match, $state, $pos, Doku_Handler $handler)
    {

        switch ($state) {

            // As there is only one call to connect to in order to a add a pattern,
            // there is only one state entering the function
            // but I leave it for better understanding of the process flow
            case DOKU_LEXER_SPECIAL :

                // Parse the parameters
                $match = substr($match, 8, -1); //9 = strlen("<minimap")

                // Init
                $parameters = array();
                $parameters['substr'] = 1;
                $parameters[self::INCLUDE_DIRECTORY_PARAMETERS] = $this->getConf(self::INCLUDE_DIRECTORY_PARAMETERS);
                $parameters[self::SHOW_HEADER] = $this->getConf(self::SHOW_HEADER);


                // /i not case sensitive
                $attributePattern = "\\s*(\w+)\\s*=\\s*[\'\"]{1}([^\`\"]*)[\'\"]{1}\\s*";
                $result = preg_match_all('/' . $attributePattern . '/i', $match, $matches);
                if ($result != 0) {
                    foreach ($matches[1] as $key => $parameterKey) {
                        $parameter = strtolower($parameterKey);
                        $value = $matches[2][$key];
                        if (in_array($parameter, [self::SHOW_HEADER, self::INCLUDE_DIRECTORY_PARAMETERS])) {
                            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                        }
                        $parameters[$parameter] = $value;
                    }
                }
                // Cache the values
                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ATTRIBUTES=> $parameters
                );

        }

        return false;
    }


    function render($mode, Doku_Renderer $renderer, $data)
    {

        // The $data variable comes from the handle() function
        //
        // $mode = 'xhtml' means that we output html
        // There is other mode such as metadata where you can output data for the headers (Not 100% sure)
        if ($mode == 'xhtml') {

            /** @var Doku_Renderer_xhtml $renderer */


            $state=$data[PluginUtility::STATE];

            // As there is only one call to connect to in order to a add a pattern,
            // there is only one state entering the function
            // but I leave it for better understanding of the process flow
            switch ($state) {

                case DOKU_LEXER_SPECIAL :


                    PluginUtility::getSnippetManager()->attachCssSnippetForBar(self::MINIMAP_TAG_NAME);


                    global $ID;
                    global $INFO;
                    $callingId = $ID;
                    // If mini-map is in a sidebar, we don't want the ID of the sidebar
                    // but the ID of the page.
                    if ($INFO != null) {
                        $callingId = $INFO['id'];
                    }

                    $attributes = $data[PluginUtility::ATTRIBUTES];
                    $nameSpacePath = getNS($callingId); // The complete path to the directory
                    if (array_key_exists(self::NAMESPACE_KEY_ATT, $attributes)) {
                        $nameSpacePath = $attributes[self::NAMESPACE_KEY_ATT];
                    }
                    $currentNameSpace = curNS($callingId); // The name of the container directory
                    $includeDirectory = $attributes[self::INCLUDE_DIRECTORY_PARAMETERS];
                    $pagesOfNamespace = $this->getNamespaceChildren($nameSpacePath, $sort = 'natural', $listdirs = $includeDirectory);

                    // Set the two possible home page for the namespace ie:
                    //   - the name of the containing map ($homePageWithContainingMapName)
                    //   - the start conf parameters ($homePageWithStartConf)
                    global $conf;
                    $parts = explode(':', $nameSpacePath);
                    $lastContainingNameSpace = $parts[count($parts) - 1];
                    $homePageWithContainingMapName = $nameSpacePath . ':' . $lastContainingNameSpace;
                    $startConf = $conf['start'];
                    $homePageWithStartConf = $nameSpacePath . ':' . $startConf;

                    // Build the list of page
                    $miniMapList = '<ul class="list-group">';
                    $pageNum = 0;
                    $startPageFound = false;
                    $homePageFound = false;
                    //$pagesCount = count($pagesOfNamespace); // number of pages in the namespace
                    foreach ($pagesOfNamespace as $pageArray) {

                        // The title of the page
                        $title = '';

                        // If it's a directory
                        if ($pageArray['type'] == "d") {

                            $pageId = $this->getNamespaceStartId($pageArray['id']);

                        } else {

                            $pageNum++;
                            $pageId = $pageArray['id'];

                        }
                        $link = new LinkUtility($pageId);


                        /**
                         * Set special name and title
                         */
                        // If debug mode
                        if ($attributes['debug']) {
                            $link->setTitle($link->getTitle().' (' . $pageId . '|' . $pageNum . ')');
                        }

                        // Suppress the parts in the name with the regexp defines in the 'suppress' params
                        if ($attributes['suppress']) {
                            $substrPattern = '/' . $attributes['suppress'] . '/i';
                            $replacement = '';
                            $name = preg_replace($substrPattern, $replacement, $link->getName());
                            $link->setName($name);
                        }

                        // See in which page we are
                        // The style will then change
                        $active = '';
                        if ($callingId == $pageId) {
                            $active = 'active';
                        }

                        // Not all page are printed
                        // sidebar are not for instance

                        // Are we in the root ?
                        if ($pageArray['ns']) {
                            $nameSpacePathPrefix = $pageArray['ns'] . ':';
                        } else {
                            $nameSpacePathPrefix = '';
                        }
                        $print = true;
                        if ($pageArray['id'] == $nameSpacePathPrefix . $currentNameSpace) {
                            // If the start page exists, the page with the same name
                            // than the namespace must be shown
                            if (page_exists($nameSpacePathPrefix . $startConf)) {
                                $print = true;
                            } else {
                                $print = false;
                            }
                            $homePageFound = true;
                        } else if ($pageArray['id'] == $nameSpacePathPrefix . $startConf) {
                            $print = false;
                            $startPageFound = true;
                        } else if ($pageArray['id'] == $nameSpacePathPrefix . $conf['sidebar']) {
                            $pageNum -= 1;
                            $print = false;
                        };


                        // If the page must be printed, build the link
                        if ($print) {

                            // Open the item tag
                            $miniMapList .= "<li class=\"list-group-item " . $active . "\">";

                            // Add a glyphicon if it's a directory
                            if ($pageArray['type'] == "d") {
                                $miniMapList .= "<span class=\"nicon_folder_open\" aria-hidden=\"true\"></span> ";
                            }

                            $miniMapList .= $link->renderOpenTag($renderer);
                            $miniMapList .= $link->getName();
                            $miniMapList .= $link->renderClosingTag();


                            // Close the item
                            $miniMapList .= "</li>";

                        }

                    }
                    $miniMapList .= '</ul>'; // End list-group


                    // Build the panel header
                    $miniMapHeader = "";
                    $startId = "";
                    if ($startPageFound) {
                        $startId = $homePageWithStartConf;
                    } else {
                        if ($homePageFound) {
                            $startId = $homePageWithContainingMapName;
                        }
                    }

                    $panelHeaderContent = "";
                    if ($startId == "") {
                        if ($attributes[self::SHOW_HEADER] == true) {
                            $panelHeaderContent = 'No Home Page found';
                        }
                    } else {
                        $startLink = new LinkUtility($startId);
                        $panelHeaderContent = $startLink->renderOpenTag($renderer);
                        $panelHeaderContent .= $startLink->getName();
                        $panelHeaderContent .= $startLink->renderClosingTag();
                        // We are not counting the header page
                        $pageNum--;
                    }

                    if ($panelHeaderContent != "") {
                        $miniMapHeader .= '<div class="panel-heading">' . $panelHeaderContent . '  <span class="label label-primary">' . $pageNum . ' pages</span></div>';
                    }

                    if ($attributes['debug']) {
                        $miniMapHeader .= '<div class="panel-body">' .
                            '<B>Debug Information:</B><BR>' .
                            'CallingId: (' . $callingId . ')<BR>' .
                            'Suppress Option: (' . $attributes['suppress'] . ')<BR>' .
                            '</div>';
                    }

                    // Header + list
                    $renderer->doc .= '<div id="minimap__plugin"><div class="panel panel-default">'
                        . $miniMapHeader
                        . $miniMapList
                        . '</div></div>';
                    break;
            }

            return true;
        }
        return false;

    }

    /**
     * Return all pages and/of sub-namespaces (subdirectory) of a namespace (ie directory)
     * Adapted from feed.php
     *
     * @param $namespace The container of the pages
     * @param string $sort 'natural' to use natural order sorting (default); 'date' to sort by filemtime
     * @param $listdirs - Add the directory to the list of files
     * @return array An array of the pages for the namespace
     */
    function getNamespaceChildren($namespace, $sort = 'natural', $listdirs = false)
    {
        require_once(DOKU_INC . 'inc/search.php');
        global $conf;

        $ns = ':' . cleanID($namespace);
        // ns as a path
        $ns = utf8_encodeFN(str_replace(':', '/', $ns));

        $data = array();

        // Options of the callback function search_universal
        // in the search.php file
        $search_opts = array(
            'depth' => 1,
            'pagesonly' => true,
            'listfiles' => true,
            'listdirs' => $listdirs,
            'firsthead' => true
        );
        // search_universal is a function in inc/search.php that accepts the $search_opts parameters
        search($data, $conf['datadir'], 'search_universal', $search_opts, $ns, $lvl = 1, $sort);

        return $data;
    }

    /**
     * Return the id of the start page of a namespace
     *
     * @param $id an id of a namespace (directory)
     * @return string the id of the home page
     */
    function getNamespaceStartId($id)
    {

        global $conf;

        $id = $id . ":";

        if (page_exists($id . $conf['start'])) {
            // start page inside namespace
            $homePageId = $id . $conf['start'];
        } elseif (page_exists($id . noNS(cleanID($id)))) {
            // page named like the NS inside the NS
            $homePageId = $id . noNS(cleanID($id));
        } elseif (page_exists($id)) {
            // page like namespace exists
            $homePageId = substr($id, 0, -1);
        } else {
            // fall back to default
            $homePageId = $id . $conf['start'];
        }
        return $homePageId;
    }

    /**
     * @param $get_called_class
     * @return string
     */
    public static function getTagName($get_called_class)
    {
        list(/* $t */, /* $p */, $c) = explode('_', $get_called_class, 3);
        return (isset($c) ? $c : '');
    }

    /**
     * @return string - the tag
     */
    public static function getTag()
    {
        return self::getTagName(get_called_class());
    }


}
