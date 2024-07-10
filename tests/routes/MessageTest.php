<?php

namespace routes;

use PHPUnit\Framework\TestCase;
use Tina4\MailboxReader;
use Tina4\Message;
use Tina4\MessageAttachment;

class MessageTest extends TestCase
{
    function testConnectInbox()
    {
        $reader = new MailboxReader("sleekapp.co.za", "143", "sleekapp", "bixxif-vofjoD-dodpy9");
        $messages = $reader->getMessages("");
        /**
         * @var $message Message
         */
        foreach ($messages as $message ) {
            /**
             * @var $attachment MessageAttachment
             */
            foreach ($message->attachments as $attachment) {
                $attachment->name;
            }

        }
        $this->assertIsArray($messages, "InboxReader->getMessages should fetch an array");
    }

}