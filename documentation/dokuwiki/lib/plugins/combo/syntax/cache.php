<?php


use ComboStrap\CacheManager;
use ComboStrap\Iso8601Date;
use ComboStrap\LogUtility;
use ComboStrap\PluginUtility;
use ComboStrap\TagAttributes;


require_once(__DIR__ . '/../ComboStrap/PluginUtility.php');

/**
 * Load the cron dependency
 * https://github.com/dragonmantank/cron-expression
 */
require_once(__DIR__ . '/../vendor/autoload.php');

/**
 * Set the cache of the bar
 * Ie add the possibility to add a time
 * over {@link \dokuwiki\Parsing\ParserMode\Nocache}
 */
class syntax_plugin_combo_cache extends DokuWiki_Syntax_Plugin
{


    const TAG = "cache";

    const PARSING_STATUS = "status";
    const PARSING_STATE_SUCCESSFUL = "successful";
    const PARSING_STATE_UNSUCCESSFUL = "unsuccessful";

    const EXPIRATION_ATTRIBUTE = "expiration";


    const CANONICAL = "cache";


    function getType()
    {
        return 'protected';
    }

    /**
     * How Dokuwiki will add P element
     *
     *  * 'normal' - The plugin can be used inside paragraphs (inline)
     *  * 'block'  - Open paragraphs need to be closed before plugin output - block should not be inside paragraphs
     *  * 'stack'  - Special case. Plugin wraps other paragraphs. - Stacks can contain paragraphs
     *
     * @see DokuWiki_Syntax_Plugin::getPType()
     */
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

        $this->Lexer->addSpecialPattern(PluginUtility::getVoidElementTagPattern(self::TAG), $mode, PluginUtility::getModeFromTag($this->getPluginComponent()));

    }


    function handle($match, $state, $pos, Doku_Handler $handler)
    {

        switch ($state) {


            case DOKU_LEXER_SPECIAL :

                $attributes = TagAttributes::createFromTagMatch($match);
                $value = $attributes->getValue(self::EXPIRATION_ATTRIBUTE);
                $status = self::PARSING_STATE_SUCCESSFUL;
                $date = "";
                try {
                    $cron = Cron\CronExpression::factory($value);
                    $date = $cron->getNextRunDate()->format(Iso8601Date::getFormat());
                } catch (InvalidArgumentException $e) {
                    $status = self::PARSING_STATE_UNSUCCESSFUL;
                }

                return array(
                    PluginUtility::STATE => $state,
                    self::PARSING_STATUS => $status,
                    PluginUtility::PAYLOAD => $value,
                    PluginUtility::ATTRIBUTES => [CacheManager::DATE_CACHE_EXPIRATION_META_KEY => $date]
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

        switch ($format) {

            case 'xhtml':
                if ($data[self::PARSING_STATUS] !== self::PARSING_STATE_SUCCESSFUL) {
                    $cronExpression = $data[PluginUtility::PAYLOAD];
                    LogUtility::msg("The expression ($cronExpression) is not a valid expression", LogUtility::LVL_MSG_ERROR,self::CANONICAL);
                }
                break;
            case 'metadata':

                if ($data[self::PARSING_STATUS] != self::PARSING_STATE_SUCCESSFUL) {
                    return false;
                }

                /** @var Doku_Renderer_metadata $renderer */
                $attributes = $data[PluginUtility::ATTRIBUTES];
                global $ID;
                p_set_metadata($ID, $attributes);
                break;

            case renderer_plugin_combo_analytics::RENDERER_FORMAT:
                if ($data[self::PARSING_STATUS] != self::PARSING_STATE_SUCCESSFUL) {
                    return false;
                }
                /** @var renderer_plugin_combo_analytics $renderer */
                $attributes = $data[PluginUtility::ATTRIBUTES];
                foreach ($attributes as $key => $value) {
                    $renderer->setMeta($key, $value);
                }
                break;


        }
        // unsupported $mode
        return false;
    }


}

