<?php

namespace Tina4;
/**
 * Class MessengerSettings
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
    public $templatePath = "messenger"; //off the templates or assets folder

    /**
     * MessengerSettings constructor.
     * @param false $usePHPMailer
     */
    public function __construct($usePHPMailer = false)
    {
        $this->usePHPMailer = $usePHPMailer;
    }
}