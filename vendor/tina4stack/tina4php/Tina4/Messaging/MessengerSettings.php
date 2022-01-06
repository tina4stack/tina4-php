<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * This is the settings and configuration for sending messages from Tina4
 * @package Tina4
 */
class MessengerSettings
{
    public $bulkSMSUsername = "";
    public $bulkSMSPassword = "";
    public $bulkSMSUrl = "http://bulksms.2way.co.za:5567/eapi/submission/send_sms/2/2.0";
    public $smtpServer = "localhost";
    public $smtpUsername = "";
    public $smtpPassword = "";
    public $smtpPort = 25;
    public $usePHPMailer = false;
    public $useTwigTemplates = true;
    public $templatePath = "messenger";
//off the templates or public folder

    /**
     * MessengerSettings constructor.
     * @param false $usePHPMailer
     */
    public function __construct($usePHPMailer = false)
    {
        $this->usePHPMailer = $usePHPMailer;
    }
}
