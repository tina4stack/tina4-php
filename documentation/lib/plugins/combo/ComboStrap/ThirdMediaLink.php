<?php


namespace ComboStrap;

/**
 * Class ThirdMediaLink
 * @package ComboStrap
 * Not yet implemented but used to
 * returns a media link object and not null
 * otherwise, we get an error
 */
class ThirdMediaLink extends MediaLink
{

    public function renderMediaTag()
    {
        $msg = "The media with the mime (" . $this->getMime() . ") is not yet implemented";
        LogUtility::msg($msg,LogUtility::LVL_MSG_ERROR);
        return $msg;
    }

    public function getAbsoluteUrl()
    {
        LogUtility::msg("The media with the mime (".$this->getMime().") is not yet implemented",LogUtility::LVL_MSG_ERROR);
        return "https://combostrap.com/media/not/yet/implemented";
    }

    public function getMediaWidth()
    {
        LogUtility::msg("The media with the mime (".$this->getMime().") is not yet implemented",LogUtility::LVL_MSG_ERROR);
        return null;
    }

    public function getMediaHeight()
    {
        LogUtility::msg("The media with the mime (".$this->getMime().") is not yet implemented",LogUtility::LVL_MSG_ERROR);
        return null;
    }
}
