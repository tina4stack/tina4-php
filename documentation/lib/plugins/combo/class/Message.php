<?php

namespace ComboStrap;

use dokuwiki\Extension\Plugin;

class Message
{


    const SIGNATURE_CLASS = "signature";
    const TAG = "message";
    private $content = "";
    private $type = self::TYPE_CLASSIC;

    const TYPE_CLASSIC = 'Classic';
    const TYPE_WARNING = 'Warning';

    /**
     * @var Plugin
     */
    private $plugin;
    private $signatureCanonical;
    private $signatureName;
    /**
     * @var TagAttributes
     */
    private $tagAttributes;

    /**
     * @param Plugin $plugin
     */
    public function __construct($plugin = null)
    {
        $this->plugin = $plugin;
        $this->tagAttributes = TagAttributes::createEmpty("message")
            ->addClassName("alert")
            ->addHtmlAttributeValue("role", "alert");
    }


    public function addContent($message)
    {
        $this->content .= $message;
    }

    public function setType($type)
    {
        $this->type = $type;
    }

    public function setSignatureCanonical($canonical)
    {
        $this->signatureCanonical = $canonical;
    }

    public function setClass($class)
    {
        $this->tagAttributes->addClassName($class);
    }

    public function getContent()
    {
        return $this->content;
    }

    public function getType()
    {
        return $this->type;
    }

    public function setSignatureName($signatureName)
    {
        $this->signatureName = $signatureName;
    }

    /**
     * Used when sending message and in the main content
     * @return string
     */
    public function toHtml()
    {

        PluginUtility::getSnippetManager()->upsertCssSnippetForRequest(self::TAG);
        $message = "";
        if ($this->getContent() <> "") {

            if ($this->getType() == Message::TYPE_CLASSIC) {
                $this->tagAttributes->addClassName("alert-success");
            } else {
                $this->tagAttributes->addClassName("alert-warning");
            }

            $message = $this->tagAttributes->toHtmlEnterTag("div");
            $message .= $this->getContent();

            /**
             * If this is a test call without a plugin
             * we have no plugin attached
             */
            $firedByLang = "This message was fired by the ";
            if($this->plugin!=null){
                $firedByLang = $this->plugin->getLang('message_come_from');
            }

            $message .= '<div class="' . self::SIGNATURE_CLASS . '">' . $firedByLang . PluginUtility::getUrl($this->signatureCanonical, $this->signatureName, false) . '</div>';
            $message .= '</div>';

            /**
             * In dev, to spot the XHTML compliance error
             */
            if (PluginUtility::isDevOrTest()){
                 $isXml = XmlUtility::isXml($message);
                 if (!$isXml){
                     LogUtility::msg("This message is not xml compliant ($message)");
                     $message =<<<EOF
<div class='alert alert-warning'>
    <p>This message is not xml compliant</p>
    <pre>$message</pre>
</div>
EOF;
                 }
            }

        }
        return $message;
    }

}
