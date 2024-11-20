<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\NewUserNotification;
use App\Services\ConversationalService;
use App\Services\StripeService;
use App\Services\UserServices;
use Illuminate\Http\Request;

class WhatsAppController extends Controller
{
    public function __construct(protected UserServices $userServices, protected StripeService $stripeService, protected ConversationalService $conversationalService)
    {
    }

    public function newMessage(Request $request)
    {
        $phone = "+" . $request->post('WaId');
        $user = User::where('phone', $phone)->first();

        if (!$user) {
            $user = $this->userServices->store($request->all());
        }

        if (!$user->subscribed()) {
            return $this->stripeService->payment($user);
        }

        $user->last_whatsapp_at = now();
        $user->save();

        $this->conversationalService->setUser($user);
        $this->conversationalService->handleIncomingMessage($request->all());
    }
}
