<?php



/**
 * Set ComboStrap as generator
 */
class action_plugin_combo_metagenerator extends DokuWiki_Action_Plugin
{


    const META_KEY = "generator";

    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'handleGenerator', array());
    }

    function handleGenerator(&$event, $param)
    {
        $info = $this->getInfo();
        $event->data['meta'][] = array("name" => self::META_KEY, "content" => "ComboStrap v".$info['version']." (".$info['date'].")");

    }


}
