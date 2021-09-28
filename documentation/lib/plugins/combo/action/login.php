<?php
/**
 * Action Component
 * Add a button in the edit toolbar
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Nicolas GERARD
 */

use ComboStrap\Identity;
use ComboStrap\LogUtility;
use ComboStrap\PluginUtility;
use ComboStrap\Snippet;
use dokuwiki\Menu\Item\Login;

if (!defined('DOKU_INC')) die();
require_once(__DIR__ . '/../class/PluginUtility.php');

/**
 * Class action_plugin_combo_login
 *
 * $conf['rememberme']
 */
class action_plugin_combo_login extends DokuWiki_Action_Plugin
{


    const CANONICAL = Identity::CANONICAL;
    const TAG = "login";
    const FORM_LOGIN_CLASS = "form-" . self::TAG;

    const CONF_ENABLE_LOGIN_FORM = "enableLoginForm";


    function register(Doku_Event_Handler $controller)
    {
        /**
         * To modify the form and add class
         *
         * Deprecated object passed by the event but still in use
         * https://www.dokuwiki.org/devel:event:html_loginform_output
         */
        if (PluginUtility::getConfValue(self::CONF_ENABLE_LOGIN_FORM, 1)) {
            $controller->register_hook('HTML_LOGINFORM_OUTPUT', 'BEFORE', $this, 'handle_login_html', array());
        }

        /**
         * Event using the new object but only in use in
         * the {@link https://codesearch.dokuwiki.org/xref/dokuwiki/lib/plugins/authad/action.php authad plugin}
         * (ie login against active directory)
         *
         * https://www.dokuwiki.org/devel:event:form_login_output
         */
        // $controller->register_hook('FORM_LOGIN_OUTPUT', 'BEFORE', $this, 'handle_login_html', array());


    }

    function handle_login_html(&$event, $param)
    {

        /**
         * The Login page is created via buffer
         * We print before the forms
         * to avoid a FOUC
         */
        $loginCss = Snippet::createCssSnippet(self::TAG);
        $content = $loginCss->getContent();
        $class = $loginCss->getClass();
        $cssHtml = <<<EOF
<style class="$class">
$content
</style>
EOF;
        print $cssHtml;


        /**
         * @var Doku_Form $form
         */
        $form = &$event->data;
        $form->params["class"] = self::FORM_LOGIN_CLASS;


        /**
         * Heading
         */
        $newFormContent[] = Identity::getHeaderHTML($form, self::FORM_LOGIN_CLASS);

        /**
         * Field
         */
        foreach ($form->_content as $field) {
            if (!is_array($field)) {
                continue;
            }
            $fieldName = $field["name"];
            if ($fieldName == null) {
                // this is not an input field
                if ($field["type"] == "submit") {
                    /**
                     * This is important to keep the submit element intact
                     * for forms integration such as captcha
                     * They search the submit button to insert before it
                     */
                    $classes = "btn btn-primary btn-block";
                    if (isset($field["class"])) {
                        $field["class"] = $field["class"] . " " . $classes;
                    } else {
                        $field["class"] = $classes;
                    }
                    $newFormContent[] = $field;
                }
                continue;
            }
            switch ($fieldName) {
                case "u":
                    $loginText = $field["_text"];
                    $loginValue = $field["value"];
                    $loginHTMLField = <<<EOF
<div class="form-floating">
    <input type="text" id="inputUserName" class="form-control" placeholder="$loginText" required="required" autofocus="" name="u" value="$loginValue">
    <label for="inputUserName">$loginText</label>
</div>
EOF;
                    $newFormContent[] = $loginHTMLField;
                    break;
                case "p":
                    $passwordText = $field["_text"];
                    $passwordFieldHTML = <<<EOF
<div class="form-floating">
    <input type="password" id="inputPassword" class="form-control" placeholder="$passwordText" required="required" name="p">
    <label for="inputPassword">$passwordText</label>
</div>
EOF;
                    $newFormContent[] = $passwordFieldHTML;
                    break;
                case "r":
                    $rememberText = $field["_text"];
                    $rememberValue = $field["value"];
                    $rememberMeHtml = <<<EOF
<div class="checkbox rememberMe">
    <label><input type="checkbox" id="remember__me" name="r" value="$rememberValue"> $rememberText</label>
</div>
EOF;
                    $newFormContent[] = $rememberMeHtml;
                    break;
                default:
                    $tag = self::TAG;
                    LogUtility::msg("The $tag field name ($fieldName) is unknown", LogUtility::LVL_MSG_ERROR, self::CANONICAL);


            }
        }


        $registerHtml = action_plugin_combo_registration::getRegisterLinkAndParagraph();
        if(!empty($registerHtml)){
            $newFormContent[] = $registerHtml;
        }
        $resendPwdHtml = action_plugin_combo_resend::getResendPasswordParagraphWithLinkToFormPage();
        if(!empty($resendPwdHtml)){
            $newFormContent[] = $resendPwdHtml;
        }

        /**
         * Set the new in place of the old one
         */
        $form->_content = $newFormContent;

        return true;


    }


    /**
     * Login
     * @return string
     */
    public static function getLoginParagraphWithLinkToFormPage()
    {

        $loginPwLink = (new Login())->asHtmlLink('', false);
        global $lang;
        $loginText = $lang['btn_login'];
        return <<<EOF
<p class="login">$loginText ? : $loginPwLink</p>
EOF;

    }
}

