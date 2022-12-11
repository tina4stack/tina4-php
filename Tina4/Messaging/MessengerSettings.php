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
    public string $bulkSMSUsername = "";
    public string $bulkSMSPassword = "";
    public string $bulkSMSUrl = "http://bulksms.2way.co.za:5567/eapi/submission/send_sms/2/2.0";
    public string $smtpServer = "localhost";
    public string $smtpUsername = "";
    public string $smtpPassword = "";
    public int $smtpPort = 25;
    public bool $usePHPMailer = false;
    public bool $useTwigTemplates = true;
    public string $templatePath = "messenger";
//off the templates or public folder

    /**
     * MessengerSettings constructor.
     * @param bool $usePHPMailer
     */
    public function __construct(bool $usePHPMailer = false)
    {
        $this->usePHPMailer = $usePHPMailer;
    }
}
