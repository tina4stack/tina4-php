<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

class MailboxReader
{
    public string $hostName;
    public int $port;
    public string $username;
    public string $password;
    public $mailbox;

    public function __construct(string $hostName, int $port, string $username="", string $password="")
    {
        $this->hostName = $hostName;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
    }

    final public function getMessages(string $mailBox="INBOX", bool $read=true): array
    {
        $this->mailbox = @imap_open("{{$this->hostName}:{$this->port}/notls}{$mailBox}", $this->username, $this->password);
        $checkMailBox = @imap_check($this->mailbox);
        $headers = @imap_fetch_overview($this->mailbox,"1:{$checkMailBox->Nmsgs}",0);
        $messages = [];
        foreach ($headers as $id => $headerInfo)
        {
            $wasRead = $headerInfo->seen === 1;
            if ( $read === $wasRead) {
                $mId = $id+1;
                $message = new Message();
                $message->toAddress = $headerInfo->to;
                $message->fromAddress = $headerInfo->from;
                $message->subject = $headerInfo->subject;
                $message->headers = @imap_fetchheader($this->mailbox, $mId);
                $message->content = @imap_body ($this->mailbox, $mId);
                $message->structure = @imap_fetchstructure($this->mailbox, $mId);

                $endWhile = false;
                $stack = [];
                $i = 0;
                $parts = $message->structure->parts ?? null;
                //get attachments
                if (!empty($parts)) {
                    $message->content = "";
                    while (!$endWhile) {
                        if (empty($parts[$i])) {
                            if (count($stack) > 0) {
                                $parts = $stack[count($stack) - 1]["p"];
                                $i = $stack[count($stack) - 1]["i"] + 1;
                                array_pop($stack);
                            } else {
                                $endWhile = true;
                            }
                        }

                        if (!$endWhile) {
                            /* Create message part first (example '1.2.3') */
                            $partString = "";
                            foreach ($stack as $s) {
                                $partString .= ($s["i"] + 1) . ".";
                            }
                            $partString .= ($i + 1);

                            if (strtoupper($parts[$i]->disposition ?? "") === "ATTACHMENT") { /* Attachment */
                                $attachment = new MessageAttachment();
                                $attachment->attachmentName = $parts[$i]->parameters[0]->value;
                                $attachment->attachmentType = "ATTACHMENT";
                                $attachment->attachmentData = base64_decode(@imap_fetchbody($this->mailbox, $mId, $partString));

                                $message->attachments[] = $attachment;
                            } elseif (strtoupper($parts[$i]->subtype ?? "") === "PLAIN") { /* Message */
                                $message->content .= @imap_fetchbody($this->mailbox, $mId, $partString);
                            }
                        }

                        if (!empty($parts[$i]->parts)  && $parts[$i]->parts) {
                            $stack[] = ["p" => $parts, "i" => $i];
                            $parts = $parts[$i]->parts;
                            $i = 0;
                        } else {
                            $i++;
                        }
                    } /* while */
                }


                $messages[] = $message;
            }
        }

        return $messages;
    }
}