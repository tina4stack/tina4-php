<?php

use ComboStrap\TplUtility;
use dokuwiki\plugin\config\core\ConfigParser;
use dokuwiki\plugin\config\core\Loader;

require_once(__DIR__ . '/../class/TplUtility.php');


/**
 * Test the settings.php file
 *
 * @group template_strap
 * @group templates
 */
class configTest extends DokuWikiTest
{

    const CONF_WITHOUT_DEFAULT = [TplUtility::CONF_FOOTER_SLOT_PAGE_NAME,
        TplUtility::CONF_HEADER_SLOT_PAGE_NAME,
        TplUtility::CONF_SIDEKICK_SLOT_PAGE_NAME,
        TplUtility::CONF_REM_SIZE
        ];

    public function setUp()
    {

        global $conf;
        parent::setUp();
        $conf['template'] = 'strap';
        /**
         * static variable bug in the {@link tpl_getConf()}
         * that does not load the configuration twice
         */
        TplUtility::reloadConf();


    }


    /**
     *
     * Test if we don't have any problem
     * in the file settings.php
     *
     * If there is, we got an error in the admin config page
     */
    public function test_base()
    {

        $request = new TestRequest();
        global $conf;
        $conf['useacl'] = 1;
        $user = 'admin';
        $conf['superuser'] = $user;

        // $_SERVER[] = $user;
        $request->setServer('REMOTE_USER', $user);

        $response = $request->get(array('do' => 'admin', 'page' => "config"), '/doku.php');

        // Simple
        /**
         * The conf identifier used as id in the pae configuration
         * and in array
         */
        $htmlId = "tpl____" . TplUtility::TEMPLATE_NAME . "____tpl_settings_name";
        $countListContainer = $response->queryHTML("#" . $htmlId)->count();
        $this->assertEquals(1, $countListContainer, "There should an element");

    }

    /**
     * Test to ensure that every conf['...'] entry
     * in conf/default.php has a corresponding meta['...'] entry in conf/metadata.php.
     */
    public function test_plugin_default()
    {
        $conf = array();
        $conf_file = __DIR__ . '/../conf/default.php';
        if (file_exists($conf_file)) {
            include($conf_file);
        }

        $meta = array();
        $meta_file = __DIR__ . '/../conf/metadata.php';
        if (file_exists($meta_file)) {
            include($meta_file);
        }


        $this->assertEquals(
            gettype($conf),
            gettype($meta),
            'Both ' . DOKU_PLUGIN . 'syntax/conf/default.php and ' . DOKU_PLUGIN . 'syntax/conf/metadata.php have to exist and contain the same keys.'
        );

        if (gettype($conf) != 'NULL' && gettype($meta) != 'NULL') {
            foreach ($conf as $key => $value) {
                $this->assertArrayHasKey(
                    $key,
                    $meta,
                    'Key $meta[\'' . $key . '\'] missing in ' . DOKU_PLUGIN . 'syntax/conf/metadata.php'
                );
            }

            foreach ($meta as $key => $value) {

                /**
                 * Known configuration without default
                 */
                if (in_array($key,
                    self::CONF_WITHOUT_DEFAULT
                )) {
                    continue;
                }
                $this->assertArrayHasKey(
                    $key,
                    $conf,
                    'Key $conf[\'' . $key . '\'] missing in ' . DOKU_PLUGIN . 'syntax/conf/default.php'
                );
            }
        }

        /**
         * English language
         */
        $lang = array();
        $settings_file = __DIR__ . '/../lang/en/settings.php';
        if (file_exists($settings_file)) {
            /** @noinspection PhpIncludeInspection */
            include($settings_file);
        }


        $this->assertEquals(
            gettype($conf),
            gettype($lang),
            'Both ' . DOKU_PLUGIN . 'syntax/conf/metadata.php and ' . DOKU_PLUGIN . 'syntax/lang/en/settings.php have to exist and contain the same keys.'
        );

        if (gettype($conf) != 'NULL' && gettype($lang) != 'NULL') {
            foreach ($conf as $key => $value) {
                $this->assertArrayHasKey(
                    $key,
                    $conf,
                    'Key $meta[\'' . $key . '\'] missing in ' . DOKU_PLUGIN . 'syntax/conf/metadata.php'
                );
            }

            foreach ($lang as $key => $value) {
                $this->assertArrayHasKey(
                    $key,
                    $lang,
                    'Key $lang[\'' . $key . '\'] missing in ' . DOKU_PLUGIN . 'syntax/lang/en/settings.php'
                );
            }
        }


        /**
         * The default value are read through parsing
         * by the config plugin.
         * You can't use variable in them.
         *
         * Yes that's fuck up but yeah
         * This test check that we can read them
         */
        $parser = new ConfigParser();
        $loader = new Loader($parser);
        $defaultConf = $loader->loadDefaults();
        $keyPrefix = 'tpl____strap____';
        $this->assertTrue(is_array($defaultConf));

        // plugin defaults
        foreach ($meta as $key => $value) {
            /**
             * Known configuration without default
             */
            if (in_array($key,
                self::CONF_WITHOUT_DEFAULT
            )) {
                continue;
            }
            $this->assertArrayHasKey(
                $keyPrefix . $key,
                $defaultConf,
                'Key $conf[\'' . $key . '\'] could not be parsed in ' . DOKU_PLUGIN . 'syntax/conf/default.php. Be sure to give only values and not variable.'
            );
        }


    }


}
