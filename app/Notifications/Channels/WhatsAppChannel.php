<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Twilio\Rest\Client;

class WhatsAppChannel
{
    public function send($notifiable, Notification $notification)
    {
        $message = $notification->toWhatsApp($notifiable);
        $to = $notifiable->routeNotificationFor('WhatsApp');
        $from = config('twilio.from');

        $twilio = new Client(config('twilio.account_sid'), config('twilio.auth_token'));

        if ($message->contentSid) {
            return $twilio->messages->create(
                'whatsapp:' . $to,
                [
                    "from" => 'whatsapp:' . $from,
                    "contentSid" => $message->contentSid,
                    "contentVariables" => $message->variables
                ]
            );
        }

        $messages = $this->splitMessage($message->content);
        $sends = [];
        foreach ($messages as $part) {
            $sends[] = $twilio->messages->create(
                'whatsapp:' . $to,
                [
                    'from' => "whatsapp:" . $from,
                    'body' => $part
                ]
            );
        }

        return $sends;

    }

    protected function splitMessage($message, $maxLength = 1600)
    {
        $parts = [];

        $lines = explode("\n", $message);
        $currentPart = '';

        foreach ($lines as $line) {
            if (mb_strlen($line) > $maxLength) {
                if (!empty($currentPart)) {
                    $parts[] = $currentPart;
                    $currentPart = '';
                }

                $words = explode(' ', $line);
                $tempLine = '';

                foreach ($words as $word) {
                    if (mb_strlen($tempLine . ' ' . $word) <= $maxLength) {
                        $tempLine .= (empty($tempLine) ? '' : ' ') . $word;
                    } else {
                        if (!empty($tempLine)) {
                            $parts[] = $tempLine;
                        }
                        if (mb_strlen($word) > $maxLength) {
                            $parts = array_merge($parts, str_split($word, $maxLength));
                        } else {
                            $tempLine = $word;
                        }
                    }
                }

                if (!empty($tempLine)) {
                    $currentPart = $tempLine;
                }

            } else {
                if (mb_strlen($currentPart . (!empty($currentPart) ? "\n" : '') . $line) > $maxLength) {
                    $parts[] = $currentPart;
                    $currentPart = $line;
                } else {
                    $currentPart .= (!empty($currentPart) ? "\n" : '') . $line;
                }
            }
        }

        if (!empty($currentPart)) {
            $parts[] = $currentPart;
        }

        return $parts;
    }
}
