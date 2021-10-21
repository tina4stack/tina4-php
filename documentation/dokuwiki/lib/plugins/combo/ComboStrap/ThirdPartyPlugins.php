<?php


namespace ComboStrap;

/**
 * Class Plugins
 * @package ComboStrap
 * A class to hold the plugin name
 */
class ThirdPartyPlugins
{

    /**
     * Imagemapping
     * https://github.com/i-net-software/dokuwiki-plugin-imagemap
     * It takes over standard dokuwiki links and media
     * We then don't take over
     */
     const IMAGE_MAPPING_NAME = "imagemapping";

    /**
     * Move Plugin
     */
    const MOVE_NAME = "move";
    const SQLITE_NAME = "sqlite";


}
