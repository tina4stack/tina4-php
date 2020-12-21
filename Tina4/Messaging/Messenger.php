<?php

namespace Tina4;
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4 (Andre van Zuydam)
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Class Messenger
 * Useful for sending emails, requires a MessengerSettings class
 * @package Tina4
 */
class Messenger
{
    /**
     * Messenger constructor.
     * @param MessengerSettings|null $settings
     */
    public function __construct(MessengerSettings $settings = null)
    {
        if (!empty($settings)) {
            $this->settings = $settings;
        } else {
            $this->settings = new MessengerSettings();
        }
    }

    /**
     * A function that will send a confirmation email to the user.
     *
     * The sendMail function takes on a number of params and sends and email to a receipient.
     *
     * @param mixed $recipients array This can be a String or Array, the String should be ; delimited email@test.com;emai2@test2.com or  ["name" => "Test", "email" => "email@email.com"]
     * @param $subject string The subject for the email
     * @param mixed $message array/string The message to send to the Receipient - can be ["template" => "twigFile", "data" => Array or Object]
     * @param $fromName string The name of the person sending the message
     * @param $fromAddress string The address of the person sending the message
     * @param $attachments array An Array of file paths to be attached in the form array ["name" => "File Description", "path" => "/path/to/file" ]
     * @param $bcc array
     * @return Boolean true, false
     * @throws \Twig\Error\LoaderError
     */
    public function sendEmail($recipients, string $subject, $message, string $fromName, string $fromAddress, $attachments = null, $bcc = null)
    {
        //define the headers we want passed. Note that they are separated with \r\n
        $boundary_rel = md5(uniqid(time(), true));
        $boundary_alt = md5(uniqid(time(), true));
        $eol = PHP_EOL;
        $headers = "MIME-Version: 1.0{$eol}From:{$fromName}<{$fromAddress}>{$eol}Reply-To:{$fromAddress}{$eol}";
        $headers .= "Content-Type: multipart/related; boundary={$boundary_rel}{$eol}";
        $headers .= "--{$boundary_rel}{$eol}Content-Type: multipart/alternative; boundary={$boundary_alt}{$eol}";

        if (is_array($message) && $this->settings->useTwigTemplates) {
            //We are using twig so we need to render the message
            if (file_exists($this->settings->templatePath . "/" . $message["template"])) {
                $message = renderTemplate($this->settings->templatePath . "/" . $message["template"], $message["data"]);
            } else {
                $message = renderTemplate($message["template"], $message["data"]);
            }
        }


        if (is_array($recipients) && !$this->settings->usePHPMailer) {
            $tempRecipients = [];
            foreach ($recipients as $id => $recipient) {
                $tempRecipients[] = "{$recipient["name"]}<{$recipient["email"]}>";
            }
            $recipients = join(",", $tempRecipients);
        }


        try {
            if (!file_exists($_SERVER["DOCUMENT_ROOT"] . "/messenger/spool")) {
                mkdir($_SERVER["DOCUMENT_ROOT"] . "/messenger/spool", 0755, true);
            }
            file_put_contents($_SERVER["DOCUMENT_ROOT"] . "/messenger/spool/email_" . date("d_m_Y_h_i_s") . ".eml", $headers . $message);

            if (!$this->settings->usePHPMailer) {
                Debug::message("Sending email using PHP mail");
                $message = $this->prepareHtmlMail($message, $eol, "--" . $boundary_rel, "--" . $boundary_alt);

                if (!empty($this->settings->smtpPort)) {
                    ini_set("smtp_port", $this->settings->smtpPort);
                }
                if (!empty($this->settings->smtpServer)) {
                    ini_set("SMTP", $this->settings->smtpServer);
                }
                ini_set("sendmail_from", $fromAddress);

                $mailSent = @mail($recipients, $subject, $message, $headers);
            } else {
                //Check if class exists
                if (!class_exists("PHPMailer\PHPMailer\PHPMailer")) {
                    if (!empty($_SERVER)) {
                        die("<h3>Install PHP Mailer for emailing to work</h3><pre>composer require phpmailer/phpmailer</pre>");
                    } else {
                        die("Install PHP Mailer - composer require phpmailer/phpmailer");
                    }
                } else {

                    $phpMailer = new \PHPMailer\PHPMailer\PHPMailer(true);
                    try {
                        //Server settings
                        if (TINA4_DEBUG) {
                            $phpMailer->SMTPDebug = \PHPMailer\PHPMailer\SMTP::DEBUG_LOWLEVEL;                      // Enable verbose debug output
                        }
                        $phpMailer->isSMTP();                                            // Send using SMTP
                        $phpMailer->Host = $this->settings->smtpServer;                    // Set the SMTP server to send through
                        $phpMailer->SMTPAuth = true;                                   // Enable SMTP authentication
                        $phpMailer->Username = $this->settings->smtpUsername;                     // SMTP username
                        $phpMailer->Password = $this->settings->smtpPassword;                               // SMTP password
                        $phpMailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;         // Enable TLS encryption; `PHPMailer::ENCRYPTION_SMTPS` encouraged
                        $phpMailer->Port = $this->settings->smtpPort;                                    // TCP port to connect to, use 465 for `PHPMailer::ENCRYPTION_SMTPS` above
                        $phpMailer->SMTPOptions = [
                            'ssl' => [
                                'verify_peer' => false,
                                'verify_peer_name' => false,
                                'allow_self_signed' => true
                            ]
                        ];

                        //Recipients
                        $phpMailer->setFrom($fromAddress, $fromName);

                        foreach ($recipients as $id => $recipient) {
                            $phpMailer->addAddress($recipient["email"], $recipient["name"]);     // Add a recipient
                        }

                        $phpMailer->addReplyTo($fromAddress, $fromName);

                        if (!empty($bcc)) {
                            foreach ($bcc as $id => $recipient) {
                                $phpMailer->addBCC($recipient["email"], $recipient["name"]);     // Add a BCC recipient
                            }
                        }

                        if (!empty($attachments)) {
                            foreach ($attachments as $id => $attachment) {
                                $phpMailer->addAttachment($attachment["path"], $attachment["name"]);
                            }
                        }
                        // Content
                        $phpMailer->isHTML(true);                                  // Set email format to HTML
                        $phpMailer->Subject = $subject;
                        $phpMailer->Body = $message;
                        $phpMailer->AltBody = str_replace("<br>", "\n", strip_tags($message, "<br>"));

                        $mailSent = $phpMailer->send();

                    } catch (\Exception $e) {
                        $mailSent = false;
                        Debug::message("Messenger Error:" . $e->getMessage());
                    }
                }
            }
        } catch (\Exception $e) {
            $mailSent = false;
        }

        if ($mailSent) {
            Debug::message("Message sending successful");
        } else {
            Debug::message("Message sending failed");
        }

        return $mailSent ? true : false;
    }

    /**
     * @param $html
     * @param $eol
     * @param $boundary_rel
     * @param $boundary_alt
     * @return string
     */
    public function prepareHtmlMail($html, $eol, $boundary_rel, $boundary_alt)
    {
        preg_match_all('~<img.*?src=.([\/.a-z0-9:;,+=_-]+).*?>~si', $html, $matches);

        $i = 0;
        $paths = array();

        foreach ($matches[1] as $img) {
            $img_old = $img;

            if (strpos($img, "http://") === false) {
                $paths[$i]['img'] = $img;
                $content_id = md5($img);
                $html = str_replace($img_old, 'cid:' . $content_id, $html);
                $paths[$i++]['cid'] = $content_id;
            }
        }

        $multipart = '';
        $multipart .= "{$boundary_alt}{$eol}";
        $multipart .= "Content-Type: text/plain; charset=UTF-8{$eol}{$eol}{$eol}";
        $multipart .= "{$boundary_alt}{$eol}";
        $multipart .= "Content-Type: text/html; charset=UTF-8{$eol}{$eol}";
        $multipart .= "{$html}{$eol}{$eol}";
        $multipart .= "{$boundary_alt}--{$eol}";


        foreach ($paths as $key => $path) {
            $message_part = "";

            $img_data = explode(",", $path["img"]);

            $imgdata = base64_decode($img_data[1]);

            $f = finfo_open();

            $mime_type = finfo_buffer($f, $imgdata, FILEINFO_MIME_TYPE);

            $filename = "image_{$key}";
            switch ($mime_type) {
                case "image/jpeg":
                case "image/png":
                case "image/gif":
                default:
                    $filename .= ".jpg";
                    break;
            }

            $message_part .= "Content-Type: {$mime_type}; name=\"{$filename}\"{$eol}";
            $message_part .= "Content-Disposition: inline; filename=\"{$filename}\"{$eol}";
            $message_part .= "Content-Transfer-Encoding: base64{$eol}";
            $message_part .= "Content-ID: <{$path['cid']}>{$eol}";
            $message_part .= "X-Attachment-Id: {$path['cid']}{$eol}{$eol}";
            $message_part .= $img_data[1];
            $multipart .= "{$boundary_rel}{$eol}" . $message_part . "{$eol}";
        }

        $multipart .= "{$boundary_rel}--";

        return $multipart;
    }

    /**
     * Alias of send SMS
     * @param string $mobileno Mobile contact number
     * @param string $message Message to be sent
     * @param string $countryPrefix Prefix to determine country of origin
     */
    public function sendText($mobileNo, $message = "", $countryPrefix = "01")
    {
        $this->sendSMS($mobileNo, $message, $countryPrefix);
    }

    /**
     * Send SMS
     * @param String $mobileNo Mobile contact number
     * @param String $message Message to be sent
     * @param String $countryPrefix Prefix to determine country of origin e.g. 1 - america, 27 - south africa
     * @return String Result of SMS send
     */
    function sendSMS($mobileNo, $message = "", $countryPrefix = "27")
    {
        $cellphone = $this->formatMobile($mobileNo, $countryPrefix);
        $curl = curl_init($this->settings->bulkSMSURL);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, 'concat_text_sms_max_parts=4&allow_concat_text_sms=1&username=' . $this->bulkSMSUsername . '&password=' . $this->bulkSMSPassword . '&message=' . $message . '&msisdn=' . $cellphone);
        $request = curl_exec($curl);
        curl_close($curl);
        return stripos($request, "IN_PROGRESS") !== false;
    }

    /**
     * Format the Mobile Number
     * @param String $cellphone Mobile number to send with
     * @param String $countryPrefix Prefix to determine country of origin e.g. 1 - america, 27 - south africa
     * @return string
     */
    public function formatMobile($cellphone, $countryPrefix = "27")
    {
        $ilen = strlen($cellphone);
        $tmpCel = '';
        $i = 0;
        while ($i < $ilen) {
            $val = substr($cellphone, $i, 1);
            if (is_numeric($val)) {
                $tmpcel = $tmpCel . substr($cellphone, $i, 1);
            }
            $i++;
        }

        $tmpcel = trim($tmpcel);
        if (substr($tmpcel, 0, 1) === "0") {
            $tmpcel = substr_replace($tmpcel, $countryPrefix, 0, 1);
        } else if (strlen($tmpcel) < 11) {
            $tmpcel = $countryPrefix . $tmpcel;
        }

        if ((strlen($tmpcel) < 11) || (strlen($tmpcel) > 11)) {
            return "Failed";
        } else {
            return $tmpcel;
        }
    }
}