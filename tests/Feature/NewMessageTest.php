<?php

use App\Models\User;
use App\Models\Task;
use App\Services\ConversationalService;
use App\Services\StripeService;
use App\Services\UserServices;
use App\Notifications\MenuNotification;
use App\Notifications\ScheduleListNotification;
use Illuminate\Support\Facades\Notification;
use App\Notifications\NewUserNotification;
function generateTwilioSignature(string $url, array $data): string
{
    $validator = new \Twilio\Security\RequestValidator(config('twilio.auth_token'));
    return $validator->computeSignature($url, $data);
}

// WhatsAppController Tests
test('new message creates user if not exists', function () {
    $phone = '5579988064629';
    $profileName = 'Test User';

    $request = [
        'From' => 'whatsapp:+' . $phone,
        'ProfileName' => $profileName,
        'WaId' => $phone,
        'To' => config('twilio.whatsapp_from'),
    ];

    $signature = generateTwilioSignature(
        config('twilio.new_message_url'),
        $request
    );

    $response = $this->withHeaders([
        'X-Twilio-Signature' => $signature
    ])->postJson('/api/new_message', $request);

    $response->assertStatus(200);
    $this->assertDatabaseHas('users', [
        'phone' => "+".$phone,
        'name' => $profileName
    ]);
});
//
test('unsubscribed user receives payment request', function () {
    Notification::fake();
    $user = User::factory()->create([
        'phone' => '+5579988064629'
    ]);

    $request = [
        'From' => 'whatsapp:+5579988064629',
        'Body' => 'Hello',
        'WaId' => '5579988064629',
        'To' => config('twilio.whatsapp_from'),
    ];

    $signature = generateTwilioSignature(
        config('twilio.new_message_url'),
        $request
    );

    $response = $this->withHeaders([
        'X-Twilio-Signature' => $signature
    ])->postJson('/api/new_message', $request);

    $response = $this->postJson('/api/new_message', $request);

    $response->assertStatus(200);
    Notification::assertSentTo($user, NewUserNotification::class);
});

// ConversationalService Tests
test('handle menu command', function () {
    Notification::fake();

    $user = User::factory()->create();
    $service = new ConversationalService();

    $service->setUser($user);
    $service->handleIncomingMessage(['Body' => '!menu']);
    Notification::assertSentTo($user, MenuNotification::class);
});

test('handle agenda command', function () {
    Notification::fake();

    $user = User::factory()->create();
    $task = Task::factory()->create([
        'user_id' => $user->id,
        'due_at' => now()->addDay()
    ]);

    $service = new ConversationalService();
    $service->setUser($user);
    $service->handleIncomingMessage(['Body' => '!agenda']);

    Notification::assertSentTo($user, ScheduleListNotification::class);
});

test('handle insights command', function () {
    Notification::fake();

    $user = User::factory()->create();
    $task = Task::factory()->create([
        'user_id' => $user->id,
        'due_at' => now()->addDay()
    ]);

    $service = new ConversationalService();
    $service->setUser($user);
    $service->handleIncomingMessage(['Body' => '!insights']);

    Notification::assertSentTo($user, \App\Notifications\GenericNotification::class);
});

test('creates task successfully', function () {
    $user = User::factory()->create();
    $service = new ConversationalService();
    $service->setUser($user);

    $taskData = [
        'description' => 'Test Task',
        'due_at' => now()->addDay()->format('Y-m-d H:i:s'),
        'meta' => 'Meeting',
        'reminder_at' => now()->addHours(23)->format('Y-m-d H:i:s')
    ];

    $task = $service->createUserTask(...$taskData);

    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'user_id' => $user->id,
        'description' => $taskData['description']
    ]);
});

test('update task successfully', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create([
        'user_id' => $user->id,
        'description' => 'Descrição Antiga',
        'due_at' => now()->addDay(),
    ]);

    $updatedData = [
        'taskid' => $task->id,
        'description' => 'Descrição Atualizada',
        'due_at' => now()->addDays(2),
        'meta' => 'Atualização',
        'reminder_at' => now()->addDay(),
    ];

    $service = new ConversationalService();
    $service->setUser($user);

    $updatedTask = $service->updateUserTask(...$updatedData);

    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'user_id' => $user->id,
        'description' => $updatedData['description'],
        'meta' => $updatedData['meta'],
    ]);

    $this->assertDatabaseMissing('tasks', [
        'id' => $task->id,
        'description' => 'Descrição Antiga',
    ]);
});
