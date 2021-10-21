<?php

// Search form in a navbar


// must be run within Dokuwiki
use ComboStrap\Bootstrap;
use ComboStrap\PluginUtility;
use ComboStrap\Site;

if (!defined('DOKU_INC')) die();


class syntax_plugin_combo_search extends DokuWiki_Syntax_Plugin
{

    function getType()
    {
        return 'substition';
    }

    function getPType()
    {
        return 'normal';
    }

    function getAllowedTypes()
    {
        return array();
    }

    function getSort()
    {
        return 201;
    }


    function connectTo($mode)
    {

        $this->Lexer->addSpecialPattern('<' . self::getTag() . '[^>]*>', $mode, 'plugin_' . PluginUtility::PLUGIN_BASE_NAME . '_' . $this->getPluginComponent());

    }

    function handle($match, $state, $pos, Doku_Handler $handler)
    {

        switch ($state) {

            case DOKU_LEXER_SPECIAL :
                $init = array(
                    'ajax' => true,
                    'autocomplete' => true
                );
                $match = substr($match, strlen($this->getPluginComponent()) + 1, -1);
                $parameters = array_merge($init, PluginUtility::parseAttributes($match));
                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ATTRIBUTES => $parameters
                );

        }
        return array();

    }

    /**
     * Render the output
     * @param string $format
     * @param Doku_Renderer $renderer
     * @param array $data - what the function handle() return'ed
     * @return boolean - rendered correctly? (however, returned value is not used at the moment)
     * @see DokuWiki_Syntax_Plugin::render()
     *
     *
     */
    function render($format, Doku_Renderer $renderer, $data)
    {

        if ($format == 'xhtml') {

            /** @var Doku_Renderer_xhtml $renderer */
            $state = $data[PluginUtility::STATE];
            switch ($state) {
                case DOKU_LEXER_SPECIAL :

                    global $lang;
                    global $ACT;
                    global $QUERY;

                    // don't print the search form if search action has been disabled
                    if (!actionOK('search')) return false;

                    $renderer->doc .= '<form action="' . wl() . '" accept-charset="utf-8" id="dw__search" method="get" role="search" class="search form-inline ';
                    $parameters = $data[PluginUtility::ATTRIBUTES];
                    if (array_key_exists("class", $parameters)) {
                        $renderer->doc .= ' ' . $parameters["class"];
                    }
                    $renderer->doc .= '">' . DOKU_LF;
                    $renderer->doc .= '<input type="hidden" name="do" value="search" />';
                    $id = PluginUtility::getPageId();
                    $renderer->doc .= "<input type=\"hidden\" name=\"id\" value=\"$id\" />";
                    $inputSearchId = 'qsearch__in';

                    // https://getbootstrap.com/docs/5.0/getting-started/accessibility/#visually-hidden-content
                    //
                    $visuallyHidden = "sr-only";
                    $bootStrapVersion = Bootstrap::getBootStrapMajorVersion();
                    if ($bootStrapVersion == Bootstrap::BootStrapFiveMajorVersion) {
                        $visuallyHidden = "visually-hidden";
                    }
                    $renderer->doc .= "<label class=\"$visuallyHidden\" for=\"$inputSearchId\">Search Term</label>";
                    $renderer->doc .= '<input name="q" type="text" tabindex="1"';
                    if ($ACT == 'search') $renderer->doc .= ' value="' . htmlspecialchars($QUERY) . '" ';
                    $renderer->doc .= ' placeholder="' . $lang['btn_search'] . '..." ';
                    if (!$parameters['autocomplete']) $renderer->doc .= 'autocomplete="off" ';
                    $renderer->doc .= 'id="' . $inputSearchId . '" accesskey="f" class="edit form-control" title="[F]"/>';
                    if ($parameters['ajax']) $renderer->doc .= '<div id="qsearch__out" class="ajax_qsearch JSpopup"></div>';
                    $renderer->doc .= '</form>';
                    break;
            }
            return true;
        }

        // unsupported $mode
        return false;
    }

    public static function getTag()
    {
        list(/* $t */, /* $p */, /* $n */, $c) = explode('_', get_called_class(), 4);
        return (isset($c) ? $c : '');
    }

}

