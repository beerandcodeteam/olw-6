<?php

namespace App\Notifications\Channels;

class WhatsAppMessage
{
    public $content;
    public $contentSid;
    public $variables;

    public function content($content)
    {
        $this->content = $content;
        return $this;
    }

    public function contentSid($contentSid)
    {
        $this->contentSid = $contentSid;
        return $this;
    }

    public function variables($variables)
    {
        $this->variables = json_encode($variables);
        return $this;
    }
}
