<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\GenericNotification;
use App\Notifications\MenuNotification;
use App\Notifications\ScheduleListNotification;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;

class ConversationalService
{

    protected User $user;
    protected $client;
    protected array $commands = [
        '!menu' => 'showMenu',
        '!agenda' => 'showSchedule',
        '!insights' => 'showInsights',
        '!update' => 'showUpdate'
    ];

    public function __construct()
    {
        if(config('app.env') === 'testing') {
            $this->client = new ClientFake([
                CreateResponse::fake([
                    'choices' => [
                        [
                            'text' => 'awesome!',
                        ],
                    ],
                ]),
            ]);
        } else {
            $this->client = \OpenAI::client(config('openai.auth_token'));
        }
    }

    public function setUser(User $user): void
    {
        $this->user = $user;
    }

    public function handleIncomingMessage($data)
    {
        $message = $data['Body'];

        if (array_key_exists(strtolower($message), $this->commands)) {
            $handler = $this->commands[strtolower($message)];
            return $this->{$handler}();
        }

        $now = now();

        if (empty($this->user->memory)) {
            $messages = [
                ["role" => "user", "content" => "Aja como um assistente pessoal, hoje é $now, se for necessário faça mais perguntas para poder entender melhor a situação"],
                ["role" => "user", "content" => $message]
            ];
        } else {
            $messages = $this->user->memory;
            $messages[] = ["role" => "user", "content" => $message];
        }

        $this->talkToGpt($messages);
    }

    public function showMenu()
    {
        $this->user->notify(new MenuNotification());
    }

    public function showSchedule()
    {
        $tasks = $this->user->tasks()->where('due_at', '>', now())
            ->orderBy('due_at')
            ->get();

        $this->user->notify(new ScheduleListNotification($tasks, $this->user->name));
    }

    public function showInsights()
    {
        $now = now();
        $messages = [
            ['role' => 'user', 'content' => "Aja como um assistente pessoal, hoje é $now, entenda o historico de rotinas do usuario e gere insights sobre sua rotina, onde ele pode melhorar o que ele pode fazer para otimizar"],
            ['role' => 'function', 'name' => 'getUserRoutine', 'content' => $this->getUserRoutine()->toJson()],
        ];

        $this->talkToGpt($messages);
    }

    public function showUpdate()
    {
        $tasks = $this->user->tasks()->where('due_at', '>', now())->get();

        $message = "Aqui esta a lista de tarefas que você pode atualizar: \n\n";

        $message .= $tasks->reduce(function($carry, $item) {
            return "{$carry}\n*ID: {$item->id}*: {$item->description}  às {$item->due_at->format('H:i')} no dia {$item->due_at->format('d/m')} e lembrete definido para {$item->reminder_at->format('d/m/Y H:i:s')}\n";
        });


        $message .= "\nDigite o ID da tarefa e o que voce deseja atualizar";


        $this->user->memory = [
            ['role' => 'assistant', 'content' => $message],
        ];
        $this->user->save();

        $this->user->notify(new GenericNotification($message));
    }

    public function getUserRoutine($days = 30)
    {
        return $this->user->tasks()->where('due_at', '>', now()->subDays($days))->get();
    }

    public function createUserTask($description, $due_at, $meta, $additional_info = "", $reminder_at = "")
    {
        $task = $this->user->tasks()->create([
            'description' => $description,
            'due_at' => $due_at,
            'meta' => $meta,
            'additional_info' => $additional_info,
            'reminder_at' => $reminder_at
        ]);

        return $task;
    }

    public function updateUserTask(...$params)
    {
        $task = $this->user->tasks()->find($params['taskid']);

        if ($task) {
            return $task->update($params);
        }

        return "Task nao encontrada, usuario pode ter passado um id incorreto";
    }

    public function talkToGpt($messages, $clearMemory = false)
    {

        $result = $this->client->chat()->create([
            'model' => 'gpt-4o',
            'messages' => $messages,
            'functions' => [
                [
                    'name' => 'createUserTask',
                    'description' => 'Cria uma tarefa para um usuario',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'description' => [
                                'type' => 'string',
                                'description' => 'Nome da tarefa solicitada pelo usuario'
                            ],
                            'due_at' => [
                                'type' => 'string',
                                'description' => 'Data e hora da tarefa solicitada pelo usuario no formato Y-m-d H:i:s'
                            ],
                            'meta' => [
                                'type' => 'string',
                                'description' => 'Metadados da tarefa solicitada pelo usuario que o chatgpt ache interessante para posteriormente gerar insights sobre a rotina do usuario este campo é obrigatorio. Ex: Reuniao de negocios; Discussao de projetos'
                            ],
                            'reminder_at' => [
                                'type' => 'string',
                                'description' => 'Data e hora do lembrete da tarefa em si no formato Y-m-d H:i:s'
                            ],
                            'additional_info' => [
                                'type' => 'string',
                                'description' => 'Informacoes adicionais que podem ou nao serem solicitadas ao usuario'
                            ]
                        ]
                    ],
                    'required' => ['description', 'due_at', 'meta']
                ],
                [
                    'name' => 'updateUserTask',
                    'description' => 'atualiza uma tarefa para um usuario',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'taskid' => [
                                'type' => 'integer',
                                'description' => 'ID da tarefa do usuario'
                            ],
                            'description' => [
                                'type' => 'string',
                                'description' => 'Nome da tarefa solicitada pelo usuario'
                            ],
                            'due_at' => [
                                'type' => 'string',
                                'description' => 'Data e hora da tarefa solicitada pelo usuario no formato Y-m-d H:i:s'
                            ],
                            'meta' => [
                                'type' => 'string',
                                'description' => 'Meta dados para a tarefa que o chat gpt ache interessante para posteriormente gerar insigths sobre produtividade do usuario Ex: Reunião de trabalho; discussão de projeto, Nunca pergunte sobre este campo, deduza sozinho'
                            ],
                            'reminder_at' => [
                                'type' => 'string',
                                'description' => 'Data e hora da do aviso que a tarefa esta chegando no formato Y-m-d H:i:s'
                            ],
                            'additional_info' => [
                                'type' => 'string',
                                'description' => 'Informações adicionais quem podem ou não serem solicitadas ao usuário'
                            ]
                        ]
                    ],
                    'required' => ['taskid', 'description', 'due_at', 'meta']
                ],
                [
                    'name' => 'getUserRoutine',
                    'description' => 'recupera as tarefas de um usuario dos ultimos dias passados por parametro, sendo 30 dias o padrao',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'days' => [
                                'type' => 'integer',
                                'description' => 'Quantidade de dias atras para recuperar as tarefas'
                            ],
                        ]
                    ],
                ]
            ]
        ]);

        if (!isset($result->choices[0]->message->functionCall)) {

            if (!$clearMemory) {
                $messages[] = $result->choices[0]->message;
                $this->user->memory = $messages;
            } else {
                $this->user->memory = null;
            }

            $this->user->save();

            return $this->user->notify(new GenericNotification($result->choices[0]->message->content));
        }

        $functionaName = $result->choices[0]->message->functionCall->name;
        $arguments = json_decode($result->choices[0]->message->functionCall->arguments, true);

        $messages[] = [
            'role' => 'assistant',
            'content' => "",
            "function_call" => [
                "name" => $functionaName,
                "arguments" => $result->choices[0]->message->functionCall->arguments
            ]
        ];

        if (!method_exists($this, $functionaName)) {
            throw new \Exception("function {$functionaName} not found");
        }

        $result = $this->{$functionaName}(...$arguments);

        $messages[] = ['role' => 'function', 'name' => $functionaName,  'content' => json_encode($result)];
        $this->talkToGpt($messages, 1);
    }
}
