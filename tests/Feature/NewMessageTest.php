<?php

use App\Models\User;
use App\Models\Task;
use App\Notifications\NewUserNotification;
use Illuminate\Support\Facades\Notification;
use App\Services\ConversationalService;
use App\Notifications\MenuNotification;
use App\Notifications\ScheduleListNotification;
use App\Notifications\GenericNotification;
function generateTwilioSignature($url, $data) {
    $validator = new \Twilio\Security\RequestValidator(config('twilio.auth_token'));
    return $validator->computeSignature($url, $data);
}

test('new message creates user if not exists', function () {
    $phone = "5547989025033";
    $profileName = "Teste User";

    $request = [
        'From' => 'whatsapp:+' . $phone,
        'ProfileName' => $profileName,
        'WaId' => $phone,
        'To' => config('twilio.from')
    ];

    $signature = generateTwilioSignature(config('twilio.new_message_url'), $request);
    $response = $this->withHeaders([
        'X-Twilio-Signature' => $signature,
    ])->postJson('/api/new_message', $request);

    $response->assertStatus(200);
    $this->assertDatabaseHas('users', [
        'phone' => "+" . $phone,
        'name' => $profileName,
    ]);
});

test('unsubscribed user receives payment link', function() {
    Notification::fake();
    $user = User::factory()->create();

    $request = [
        'From' => 'whatsapp:' . $user->phone,
        'ProfileName' => $user->name,
        'WaId' => str_replace("+", "", $user->phone),
        'To' => config('twilio.from')
    ];

    $signature = generateTwilioSignature(config('twilio.new_message_url'), $request);
    $response = $this->withHeaders([
        'X-Twilio-Signature' => $signature,
    ])->postJson('/api/new_message', $request);

    $response->assertStatus(200);
    Notification::assertSentTo($user, NewUserNotification::class);
});

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

    $service = new ConversationalService();
    $service->setUser($user);
    $service->handleIncomingMessage(['Body' => '!agenda']);
    Notification::assertSentTo($user, ScheduleListNotification::class);
});

test('handle insights command', function () {
    Notification::fake();
    $user = User::factory()->create();
    $tasks = Task::factory()->create([
        'user_id' => $user->id,
        'due_at' => now()->addDay()
    ]);

    $service = new ConversationalService();
    $service->setUser($user);
    $service->handleIncomingMessage(['Body' => '!insights']);
    Notification::assertSentTo($user, GenericNotification::class);
});

test('creates tasks successfully', function () {
    $user = User::factory()->create();
    $service = new ConversationalService();
    $service->setUser($user);

    $task = [
        'description' => "Test task",
        'due_at' => now()->addDay(),
        'meta' => 'Test',
        'reminder_at' => now()->addHours(5),
    ];

    $task = $service->createUserTask(...$task);

    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'user_id' => $user->id,
        'description' => "Test task",
    ]);
});

test('updates tasks successfully', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create([
        'user_id' => $user->id,
        'description' => 'Descrição Antiga',
        'due_at' => now()->addDay(),
    ]);

    $updateData = [
        'taskid' => $task->id,
        'description' => "Descricao Atualizada",
        'due_at' => now()->addDays(2),
        'meta' => 'Atualizacao',
        'reminder_at' => now()->addHours(5),
    ];

    $service = new ConversationalService();
    $service->setUser($user);

    $service->updateUserTask(...$updateData);

    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'user_id' => $user->id,
        'description' => $updateData['description'],
        'meta' => $updateData['meta'],
    ]);

    $this->assertDatabaseMissing('tasks', [
        'id' => $task->id,
        'description' => 'Descrição Antiga',
    ]);
});
