<?php

namespace App\Notifications;

use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\Channels\WhatsAppMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ScheduleListNotification extends Notification
{
    use Queueable;

    protected $message = "Aqui está sua agenda, {{name}}! 📅

Essas são as próximas tarefas e compromissos que você tem programados:

{{tasks}}

Qualquer coisa que precise ajustar ou adicionar, é só me avisar! 😉";
    protected $tasks = [];
    protected $name;

    /**
     * Create a new notification instance.
     */
    public function __construct($tasks, $name)
    {
        $this->tasks = $tasks->reduce(function($carry, $item) {
            return "{$carry}\n🕗 {$item->description} às {$item->due_at->format('H:i')} no dia {$item->due_at->format('d/m')}\n";
        });
        $this->name = $name;

        $this->message = str_replace("{{name}}", $this->name, $this->message);
        $this->message = str_replace("{{tasks}}", $this->tasks, $this->message);
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [WhatsAppChannel::class];
    }

    public function toWhatsApp()
    {
        return (new WhatsAppMessage)
            ->content($this->message);
    }
}
