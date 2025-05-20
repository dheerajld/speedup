<?php

namespace App\Services;

use App\Models\Notification;
use Carbon\Carbon;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FcmNotification;

class FcmNotificationService
{
    protected $messaging;

    public function __construct()
    {
        $factory = (new Factory)->withServiceAccount(config('services.firebase.credentials'));
        $this->messaging = $factory->createMessaging();
    }

    /**
     * Send and save notification
     *
     * @param string $title
     * @param string $body
     * @param string $type
     * @param int $recipientId
     * @param string $recipientType
     * @param string $deviceToken
     * @param array|null $data
     * @return void
     */
    public function sendAndSave($title, $body, $type, $recipientId, $recipientType, $deviceToken, $data = null)
    {
        try {
            $notification = FcmNotification::create($title, $body);
            $message = CloudMessage::withTarget('token', $deviceToken)
                ->withNotification($notification)
                ->withData($data ?? []);
            $this->messaging->send($message);
        } catch (\Exception $e) {
            // Log the FCM error but continue
            \Log::error('FCM send failed: ' . $e->getMessage());
        }
    
        // Always save to DB, even if FCM fails
        Notification::create([
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'recipient_id' => $recipientId,
            'recipient_type' => $recipientType,
            'data' => $data,
            'sent_at' => Carbon::now(),
        ]);
    }
}
