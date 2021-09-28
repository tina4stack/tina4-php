<?php

use ComboStrap\LogUtility;
use ComboStrap\MetadataUtility;
use ComboStrap\PluginUtility;
use ComboStrap\Page;

require_once(__DIR__ . '/../class/PluginUtility.php');

/**
 * Disqus integration
 * https://combostrap.com/disqus
 */
class syntax_plugin_combo_disqus extends DokuWiki_Syntax_Plugin
{

    const CONF_DEFAULT_ATTRIBUTES = 'disqusDefaultAttributes';

    const ATTRIBUTE_SHORTNAME = "shortname";
    const ATTRIBUTE_IDENTIFIER = 'id';
    const ATTRIBUTE_TITLE = 'title';
    const ATTRIBUTE_URL = 'url';

    const TAG = 'disqus';

    const META_DISQUS_IDENTIFIER = "disqus_identifier";
    const ATTRIBUTE_CATEGORY = "category";

    /**
     * Syntax Type.
     *
     * Needs to return one of the mode types defined in $PARSER_MODES in parser.php
     * @see https://www.dokuwiki.org/devel:syntax_plugins#syntax_types
     */
    function getType()
    {
        return 'substition';
    }

    /**
     * Syntax Type.
     *
     * Needs to return one of the mode types defined in $PARSER_MODES in parser.php
     * @see DokuWiki_Syntax_Plugin::getType()
     */
    function getPType()
    {
        return 'block';
    }

    /**
     * Plugin priority
     *
     * @see Doku_Parser_Mode::getSort()
     *
     * the mode with the lowest sort number will win out
     */
    function getSort()
    {
        return 160;
    }

    /**
     * Create a pattern that will called this plugin
     *
     * @param string $mode
     * @see Doku_Parser_Mode::connectTo()
     */
    function connectTo($mode)
    {
        $pattern = PluginUtility::getEmptyTagPattern(self::TAG);
        $this->Lexer->addSpecialPattern($pattern, $mode, PluginUtility::getModeFromTag($this->getPluginComponent()));
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


        $attributes = PluginUtility::getTagAttributes($match);
        return array($attributes);


    }

    /**
     * Render the output
     * @param string $format
     * @param Doku_Renderer $renderer
     * @param array $data - what the function handle() return'ed
     * @return boolean - rendered correctly? (however, returned value is not used at the moment)
     * @see DokuWiki_Syntax_Plugin::render()
     *
     */
    function render($format, Doku_Renderer $renderer, $data)
    {

        switch ($format) {

            case 'xhtml':

                list($attributes) = $data;
                /** @var Doku_Renderer_xhtml $renderer */

                $page = Page::createRequestedPageFromEnvironment();

                /**
                 * Disqus configuration
                 * https://help.disqus.com/en/articles/1717084-javascript-configuration-variables
                 */
                $default = PluginUtility::getTagAttributes($this->getConf(self::CONF_DEFAULT_ATTRIBUTES));
                $attributes = PluginUtility::mergeAttributes($attributes, $default);
                $forumShortName = $attributes[self::ATTRIBUTE_SHORTNAME];
                if (empty($forumShortName)) {
                    LogUtility::msg("The disqus forum shortName should not be empty", LogUtility::LVL_MSG_ERROR, self::TAG);
                    return false;
                }
                $forumShortName = hsc($forumShortName);

                $disqusIdentifier = MetadataUtility::getMeta(self::META_DISQUS_IDENTIFIER);
                if (empty($disqusIdentifier)) {

                    $disqusIdentifier = $attributes[self::ATTRIBUTE_IDENTIFIER];
                    if (empty($disqusIdentifier)) {
                        $disqusIdentifier = $page->getId();
                    }

                    $canonical = $page->getCanonical();
                    if (!empty($canonical)) {
                        $disqusIdentifier = $canonical;
                    }
                    MetadataUtility::setMeta(self::META_DISQUS_IDENTIFIER, $disqusIdentifier);
                }
                $disqusConfig = "this.page.identifier = \"$disqusIdentifier\";";

                $url = $attributes[self::ATTRIBUTE_URL];
                if (empty($url)) {
                    $url = $page->getCanonicalUrlOrDefault();
                }
                $disqusConfig .= "this.page.url = $url;";


                $title = $attributes[self::ATTRIBUTE_TITLE];
                if (empty($title)) {
                    $title = action_plugin_combo_metatitle::getTitle();
                    if (!empty($title)) {
                        $disqusConfig .= "this.page.title = $title;";
                    }
                }

                $category = $attributes[self::ATTRIBUTE_CATEGORY];
                if (empty($category)) {
                    $disqusConfig .= "this.page.category_id = $category;";
                }


                /**
                 * The javascript
                 */
                $renderer->doc .= <<<EOD
<script charset="utf-8" type="text/javascript">

    // Configuration

    // The disqus_config should be a var to give it the global scope
    // Otherwise, disqus will see no config
    // noinspection ES6ConvertVarToLetConst
    var disqus_config = function () {
        $disqusConfig
    };

    // Embed the library
    (function() {
        const d = document, s = d.createElement('script');
        s.src = 'https://$forumShortName.disqus.com/embed.js';
        s.setAttribute('data-timestamp', (+new Date()).toString());
        (d.head || d.body).appendChild(s);
    })();

</script>
<noscript><a href="https://disqus.com/home/discussion/$forumShortName/$disqusIdentifier/">View the discussion thread.</a></noscript>
EOD;
                // The tag
                $renderer->doc .= '<div id="disqus_thread"></div>';

                return true;
                break;
            case 'metadata':

        }
        return false;

    }


}

