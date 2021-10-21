<?php

use ComboStrap\TplUtility;
use dokuwiki\plugin\config\core\Configuration;

require_once(__DIR__ . '/../class/TplUtility.php');

/**
 *
 *
 * @group template_strap
 * @group templates
 */
class tplUtilityTest extends DokuWikiTest
{

    public function setUp()
    {

        global $conf;
        parent::setUp();
        $conf ['template'] = 'strap';

        /**
         * static variable bug in the {@link tpl_getConf()}
         * that does not load the configuration twice
         */
        TplUtility::reloadConf();

    }

    /**
     * Test the {@link \Combostrap\TplUtility::getStylesheetsForMetadataConfiguration()} function
     */
    public function testGetStylesheetsForMetadataConfiguration()
    {

        // Local file created by the users with their own stylesheet
        $destination = __DIR__ . '/../bootstrap/bootstrapLocal.json';
        // If we debug, it may not be deleted
        if (file_exists($destination)) {
            unlink($destination);
        }

        // Default
        $configurationList = TplUtility::getStylesheetsForMetadataConfiguration();
        $distributionStylesheet = 51;
        $this->assertEquals($distributionStylesheet, sizeof($configurationList), "Number of stylesheet");


        copy(__DIR__ . '/resources/bootstrapLocal.json', $destination);
        $configurationList = TplUtility::getStylesheetsForMetadataConfiguration();
        $styleSheetWithCustom = $distributionStylesheet + 1;
        $this->assertEquals($styleSheetWithCustom, sizeof($configurationList), "There is one stylesheet more");
        unlink($destination);


    }

    public function testGetStyleSheetAndBootstrapVersionConf()
    {
        $stylesheet = "bootstrap.16col";
        $boostrapVersion = "4.5.0";
        TplUtility::setConf(TplUtility::CONF_BOOTSTRAP_VERSION_STYLESHEET, $boostrapVersion . TplUtility::BOOTSTRAP_VERSION_STYLESHEET_SEPARATOR . $stylesheet);
        $actualStyleSheet = TplUtility::getStyleSheetConf();
        $this->assertEquals($stylesheet, $actualStyleSheet);
        $actualBootStrapVersion = TplUtility::getBootStrapVersion();
        $this->assertEquals($boostrapVersion, $actualBootStrapVersion);
    }


    /**
     * Test the {@link \Combostrap\TplUtility::buildBootstrapMetas()} function
     * that returns the needed bootstrap resources
     * @throws Exception
     */
    public function test_buildBootstrapMetas()
    {
        $boostrapVersion = "4.5.0";
        $metas = TplUtility::buildBootstrapMetas($boostrapVersion);
        $this->assertEquals(4, sizeof($metas));
        $this->assertEquals("bootstrap.min.css", $metas["css"]["file"]);

        TplUtility::setConf(TplUtility::CONF_BOOTSTRAP_VERSION_STYLESHEET, $boostrapVersion . TplUtility::BOOTSTRAP_VERSION_STYLESHEET_SEPARATOR . "16col");
        $metas = TplUtility::buildBootstrapMetas($boostrapVersion);
        $this->assertEquals(4, sizeof($metas));
        $this->assertEquals("bootstrap.16col.min.css", $metas["css"]["file"]);

        TplUtility::setConf(TplUtility::CONF_BOOTSTRAP_VERSION_STYLESHEET, $boostrapVersion . TplUtility::BOOTSTRAP_VERSION_STYLESHEET_SEPARATOR . "simplex");
        $metas = TplUtility::buildBootstrapMetas($boostrapVersion);
        $this->assertEquals(4, sizeof($metas));
        $this->assertEquals("bootstrap.simplex.min.css", $metas["css"]["file"]);
        $this->assertEquals("https://cdn.jsdelivr.net/npm/bootswatch@4.5.0/dist/simplex/bootstrap.min.css", $metas["css"]["url"]);

    }

    /**
     * Rtl supports
     */
    public function test_buildBootstrapMetasWithRtl()
    {
        global $lang;
        $lang["direction"] = "rtl";

        $boostrapVersion = "5.0.1";
        $metas = TplUtility::buildBootstrapMetas($boostrapVersion);
        $this->assertEquals(2, sizeof($metas));
        $this->assertEquals("bootstrap.rtl.min.css", $metas["css"]["file"]);


        TplUtility::setConf(TplUtility::CONF_BOOTSTRAP_VERSION_STYLESHEET, $boostrapVersion . TplUtility::BOOTSTRAP_VERSION_STYLESHEET_SEPARATOR . "simplex");
        $metas = TplUtility::buildBootstrapMetas($boostrapVersion);
        $this->assertEquals(2, sizeof($metas));
        $this->assertEquals("bootstrap.simplex.min.css", $metas["css"]["file"]);
        $this->assertEquals("https://cdn.jsdelivr.net/npm/bootswatch@5.0.1/dist/simplex/bootstrap.min.css", $metas["css"]["url"]);

    }


    /**
     * Testing the {@link TplUtility::renderSlot()}
     */
    public function testBarCache()
    {

        $sidebarName = "sidebar";
        $sidebarId = ":" . $sidebarName;
        saveWikiText($sidebarId, "=== title ===", "");
        $metadata = p_read_metadata($sidebarId);
        p_save_metadata($sidebarName, $metadata);
        global $ID;
        $ID = ":namespace:whatever";
        $data = TplUtility::renderSlot($sidebarName);
        $this->assertNotEmpty($data);
        /**
         * TODO:  We should test that the file are not the same with bar plugin that shows the files of a namespace
         * The test was done manually
         */

    }

    /**
     * Test that a wiki with an old header configuration
     * is saved to the old value
     *
     * The functionality scan for children page
     * with the same name and if found set the new configuration
     * when we try to get the value
     */
    public function testUpdateConfigurationWithOldValue()
    {

        /**
         * A switch to update the configuration
         * (Not done normally due to the hard coded constant DOKU_DATA. See more at {@link TplUtility::updateConfiguration()}
         */
        global $_REQUEST;
        $_REQUEST[TplUtility::COMBO_TEST_UPDATE] = true;

        /**
         * Creating a page in a children directory
         * with the old configuration
         */
        $oldConf = TplUtility::CONF_HEADER_OLD;
        $expectedValue = TplUtility::CONF_HEADER_OLD_VALUE;
        saveWikiText("ns:" . $oldConf, "Header page with the old", 'Script Test base');

        $strapName = "strap";
        $strapKey = TplUtility::CONF_HEADER_SLOT_PAGE_NAME;

        $value = TplUtility::getHeaderSlotPageName();
        $this->assertEquals($expectedValue, $value);

        $configuration = new Configuration();
        $settings = $configuration->getSettings();
        $key = "tpl____${strapName}____" . $strapKey;

        $setting = $settings[$key];
        $this->assertEquals(true, isset($setting));

        $formsOutput = $setting->out("conf");
        $formsOutputExpected = <<<EOF
\$conf['tpl']['$strapName']['$strapKey'] = '$expectedValue';

EOF;

        $this->assertEquals($formsOutputExpected, $formsOutput);


        global $config_cascade;
        $config = end($config_cascade['main']['local']);
        $conf = [];
        /** @noinspection PhpIncludeInspection */
        include $config;
        $this->assertEquals($expectedValue, $conf["tpl"]["strap"][$strapKey], "Good value in config");

        /**
         * The conf has been messed up
         * See {@link TplUtility::updateConfiguration()} for information
         */
        unset($_REQUEST[TplUtility::COMBO_TEST_UPDATE]);
        self::setUpBeforeClass();

    }

    public function testUpdateConfigurationForANewInstallation()
    {

        /**
         * A switch to update the configuration
         * (Not done normally due to the hard coded constant DOKU_DATA. See more at {@link TplUtility::updateConfiguration()}
         */
        global $_REQUEST;
        $_REQUEST[TplUtility::COMBO_TEST_UPDATE] = true;

        $expectedValue = "slot_header";
        $strapName = "strap";
        $strapKey = TplUtility::CONF_HEADER_SLOT_PAGE_NAME;

        $value = TplUtility::getHeaderSlotPageName();
        $this->assertEquals($expectedValue, $value);

        $configuration = new Configuration();
        $settings = $configuration->getSettings();
        $key = "tpl____${strapName}____" . $strapKey;

        $setting = $settings[$key];
        $this->assertEquals(true, isset($setting));

        $formsOutput = $setting->out("conf");
        $formsOutputExpected = <<<EOF
\$conf['tpl']['$strapName']['$strapKey'] = '$expectedValue';

EOF;

        $this->assertEquals($formsOutputExpected, $formsOutput);

        global $config_cascade;
        $config = end($config_cascade['main']['local']);
        $conf = [];
        /** @noinspection PhpIncludeInspection */
        include $config;
        $this->assertEquals($expectedValue, $conf["tpl"]["strap"][$strapKey], "Good value in config");

        /**
         * The conf has been messed up
         * See {@link TplUtility::updateConfiguration()} for information
         */
        unset($_REQUEST[TplUtility::COMBO_TEST_UPDATE]);
        self::setUpBeforeClass();

    }


}
