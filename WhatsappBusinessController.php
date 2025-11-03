<?php

namespace App\Http\Controllers;

use App\Events\MessageEvent;
use App\Models\File;
use App\Models\Message;
use App\Models\Room;
use App\Models\Template;
use App\Responses\DeleteMessagesToApiResponse;
use App\Responses\EditMessageToApiResponse;
use App\Responses\MessageReactionToApiResponse;
use App\Responses\NewRoomToApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Netflie\WhatsAppCloudApi\Message\Media\MediaID;
use Netflie\WhatsAppCloudApi\Message\Template\Component;
use Netflie\WhatsAppCloudApi\Response\ResponseException;
use Netflie\WhatsAppCloudApi\WhatsAppCloudApi;
use Netflie\WhatsAppCloudApi\WebHook;
use Netflie\WhatsAppCloudApi\WebHook\Notification\Button;
use Netflie\WhatsAppCloudApi\WebHook\Notification\Contact as ContactNotification;
use Netflie\WhatsAppCloudApi\WebHook\Notification\Flow;
use Netflie\WhatsAppCloudApi\WebHook\Notification\Interactive;
use Netflie\WhatsAppCloudApi\WebHook\Notification\Location as LocationNotification;
use Netflie\WhatsAppCloudApi\WebHook\Notification\Media as MediaNotification;
use Netflie\WhatsAppCloudApi\WebHook\Notification\MessageNotification;
use Netflie\WhatsAppCloudApi\WebHook\Notification\Reaction as ReactionNotification;
use Netflie\WhatsAppCloudApi\WebHook\Notification\StatusNotification;
use Netflie\WhatsAppCloudApi\WebHook\Notification\System as SystemNotification;
use Netflie\WhatsAppCloudApi\WebHook\Notification\Text as TextNotification;
use Netflie\WhatsAppCloudApi\WebHook\Notification\Unknown as UnknownNotification;
use App\Responses\SendMediaToApiResponse;
use App\Responses\SendMessageToApiResponse;
use App\Responses\UpdateReadHistoryResponse;
use App\Services\ExtendedWhatsAppCloudApi;

class WhatsappBusinessController extends AbstractMessengerController
{
    protected const CHANNEL = 'whatsapp_business';

    protected WhatsAppCloudApi $whatsappBusiness;

    protected function getChannel(): string
    {
        return self::CHANNEL;
    }

    public function __construct()
    {
        parent::__construct();
        $this->room = new Room();
        $this->message = new Message();

        $this->whatsappBusiness = new ExtendedWhatsAppCloudApi([
            'from_phone_number_id' => config('services.whatsapp_business.id'),
            'access_token' => config('services.whatsapp_business.access_token'),
            'graph_version' => 'v20.0',
            'business_id' => config('services.whatsapp_business.business_id'),
        ]);
    }

    public function verifyWebhook()
    {
        $webhook = new WebHook();

        echo $webhook->verify($_GET, config('services.whatsapp_business.access_token'));
    }

    public function handle(Request $request)
    {
        $payload = $request->input();
        $configuredPhoneId = (string) config('services.whatsapp_business.id');

        app()->singleton('customDataForBlade', fn() => $payload);

        $webhook = new WebHook();
        $notifications = $webhook->readAll($payload);

        foreach ($notifications as $notification) {
            if (!$notification instanceof \Netflie\WhatsAppCloudApi\WebHook\Notification) {
                continue;
            }

            if ((string) $notification->businessPhoneNumberId() !== $configuredPhoneId) {
                Log::channel('whatsapp_business_webhook')->warning(
                    'Ignored webhook from another phone_number_id',
                    [
                        'received' => $notification->businessPhoneNumberId(),
                        'expected' => $configuredPhoneId,
                        'notification' => get_class($notification),
                    ]
                );

                continue;
            }

            if ($notification instanceof StatusNotification) {
                $result = $this->messageStatus($notification);
            } elseif ($notification instanceof ReactionNotification) {
                $result = $this->messageReaction($notification);
            } elseif ($notification instanceof MessageNotification) {
                $result = $this->messageReceived($notification);
            } else {
                Log::channel('whatsapp_business_webhook')->info('Undefined notification type', [
                    'notification' => get_class($notification),
                ]);
                continue;
            }

            if ($result && ($result['success'] ?? false)) {
                broadcast(new MessageEvent($result))->toOthers();
            }
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * @throws ResponseException
     */
    public function messageReceived(MessageNotification $notification)
    {
        $messengerId = $notification->id();
        $customer = $notification->customer();
        $phone = $customer?->phoneNumber();

        $content = '';
        $media = null;

        switch (true) {
            case $notification instanceof TextNotification:
                $content = $notification->message();
                break;
            case $notification instanceof MediaNotification:
                $content = $notification->caption();
                $mimeTypeRaw = $notification->mimeType();
                $mimeType = $mimeTypeRaw !== '' ? explode(';', $mimeTypeRaw)[0] : null;
                $fileName = $notification->filename() ?: $this->buildFilenameFromMimeType($notification->imageId(), $mimeType);
                $media = $this->createFileByMediaId(
                    $notification->imageId(),
                    $fileName,
                    $this->guessMediaType($mimeType)
                );
                break;
            case $notification instanceof LocationNotification:
                $lat = $notification->latitude();
                $long = $notification->longitude();
                $content = "https://www.google.com/maps?q=$lat,$long";
                break;
            case $notification instanceof ContactNotification:
                $phones = $notification->phones();
                $firstPhone = $phones[0]['phone'] ?? '';
                $content = trim($notification->formattedName() . ($firstPhone ? ' (' . $firstPhone . ')' : ''));
                break;
            case $notification instanceof Button:
                $content = $notification->text();
                break;
            case $notification instanceof Interactive:
                $content = $notification->title();
                break;
            case $notification instanceof Flow:
                $content = $notification->body();
                break;
            case $notification instanceof SystemNotification:
                $content = $notification->message();
                break;
            case $notification instanceof UnknownNotification:
            default:
                $content = '';
        }

        $timestamp = $notification->receivedAt()->getTimestamp();
        $replyMessageId = $notification->replyingToMessageId();

        return $this->updateMessageReceived(
            intval($phone ?? 0),
            strval($messengerId),
            strval($content ?? ''),
            intval($timestamp),
            null,
            $replyMessageId ?? null,
            $notification->isForwarded(),
            $media ?? null,
            $this->buildRoomOptions($customer)
        );
    }


    public function updateMessageReceived(
        int $chat_id,
        string $messenger_id,
        string $content,
        int $timestamp,
        ?int $fileId,
        ?string $replyMessageId,
        bool $system = false,
        ?array $media = null,
        array $roomOptions = [],
    ): array {
        $room = $this->roomOfChannel->where('chat_id', $chat_id)->first();
        if (!$room) {
            $room = $this->newRoom($chat_id, false, $timestamp, $messenger_id, null, $roomOptions);
            // event(new ChatStartedByClient($room)); // TODO: пока нет необходимости
        }
        if ($replyMessageId) {
            $replyMessageId = $this->message->getIdByMessengerId($replyMessageId);
        }

        if ($media) {
            $fileController = new FileController();
            $fileId = $fileController->saveFile($media['type'], $media['media'], $room->id);
        }

        $newMessage = $this->message->create([
            'room_id' => $room->id,
            'chat_id' => $chat_id,
            'messenger_id' => $messenger_id,
            'content' => $content,
            'sender_id' => $chat_id,
            'username' => $room->room_name,
            'date' => date('Y-m-d H:i:s'),
            'timestamp' => $timestamp,
            'index_id' => $timestamp,
            'from_me' => false,
            'saved' => true,
            'distributed' => true,
            'seen' => $system,
            'system' => $system,
            'deleted' => false,
            'failure' => false,
            'avatar' => $room->avatar,
            'reply_message_id' => $replyMessageId,
            'file_id' => $fileId
        ]);

        $this->updateRoomWithLastMessage($room, $newMessage);
        $room->refresh();

        $formattedData = $this->formatMessageData($newMessage, $room);

        return array_merge(['_' => 'updateNewMessage'], $formattedData);
    }


    public function messageStatus(StatusNotification $notification)
    {
        $messengerId = $notification->id();
        $message = $this->message->where('messenger_id', $messengerId)->first(); //todo add filter by channel

        if ($message) {
            $room = $this->roomOfChannel->where('chat_id', $message->chat_id)->first();

            return match ($notification->status()) {
                'sent' => $this->updateMessageStatusSaved($room->chat_id, $messengerId),
                'delivered' => $this->updateMessageStatusDistributed($room->chat_id, $messengerId),
                'read' => $this->updateMessageStatusSeen($room->chat_id, $message->from_me, $messengerId),
                default => ['success' => false],
            };
        } else {
            return ['success' => false]; //todo надо ли возвращать не 200й статус чтобы сервер заново прислал событие?
        }
    }


    public function updateMessageStatusSaved(string $chat_id, string $messenger_id): array
    {
        $room_id = $this->roomOfChannel->where('chat_id', $chat_id)->first()->id; //todo safe call

        $message = $this->message
            ->where('room_id', $room_id)
            ->where('messenger_id', $messenger_id)
            ->first();

        if (!$message) {
            return UpdateReadHistoryResponse::error('message not found')->toArray();
        }

        $message->update(['saved' => true]);

        $room = $this->room->where('id', $room_id)->first();
        if (!$room) {
            return UpdateReadHistoryResponse::error('room not found in updateMessageStatusSaved')->toArray();
        }

        return UpdateReadHistoryResponse::success($room, [$message->id], 'saved')
            ->setEvent('updateMessageStatus')
            ->toArray();
    }


    public function updateMessageStatusDistributed(string $chat_id, string $messenger_id): array
    {
        $room_id = $this->roomOfChannel->where('chat_id', $chat_id)->first()->id; //todo safe call

        $message = $this->message
            ->where('room_id', $room_id)
            ->where('messenger_id', $messenger_id)
            ->first();

        if (!$message) {
            return UpdateReadHistoryResponse::error('message not found')->toArray();
        }

        $message->update(['distributed' => true, 'saved' => true]);

        $room = $this->room->where('id', $room_id)->first();
        if (!$room) {
            return UpdateReadHistoryResponse::error('room not found in updateMessageStatusDistributed')->toArray();
        }

        return UpdateReadHistoryResponse::success($room, [$message->id], 'distributed')
            ->setEvent('updateMessageStatus')
            ->toArray();
    }


    public function updateMessageStatusSeen(
        string $chat_id,
        bool $from_me,
        ?string $maxMessengerId = null,
        ?int $unread_count = null
    ): array {
        $room = $this->roomOfChannel->where('chat_id', $chat_id)->first();
        if (!$room) {
            return UpdateReadHistoryResponse::error('room not found in updateMessageStatusSeen')->toArray();
        }

        if ($room->channel !== 'telegram' && $from_me === false) {
            $room->update([
                'status_state' => 'offline',
                'status_lastChanged' => time(), // или now()->unix()
            ]);
        }

        $messageIds = $this->message->markSeenUnreadMessages($room->id, $from_me, $maxMessengerId);

        if (!$from_me && !empty($messageIds)) {
            if ($unread_count === null) {
                $read_count = min($room->unread_count, count($messageIds));
                $room->decrement('unread_count', $read_count);
            } else {
                $room->update(['unread_count' => $unread_count]);
            }
        }

        $room->refresh();

        return UpdateReadHistoryResponse::success($room, $messageIds, 'seen')
            ->setEvent('updateMessageStatus')
            ->toArray();
    }


    public function messageReaction(ReactionNotification $notification)
    {
        $messengerId = $notification->messageId();

        $currentMessage = $this->message->where('messenger_id', $messengerId)->first();

        if (!$currentMessage) {
            return ['success' => false];
        }

        $currentMessageData = $currentMessage->toArray();

        $emoji = $notification->emoji() ?: null;

        $currentMessageData['reactions'] = $this->mergeReactions($currentMessageData['reactions'], $emoji, $currentMessage->chat_id, !$emoji);

        return $this->updateMessageEdited($currentMessage->chat_id, $currentMessage, $currentMessageData);
    }

    private function buildFilenameFromMimeType(string $mediaId, ?string $mimeType): string
    {
        $normalizedMime = $mimeType ?: 'application/octet-stream';
        $parts = explode('/', $normalizedMime);
        $extension = $parts[1] ?? 'bin';

        return $mediaId . '.' . $extension;
    }

    private function guessMediaType(?string $mimeType): string
    {
        $normalizedMime = strtolower((string) $mimeType);

        return match (true) {
            Str::startsWith($normalizedMime, 'image/') => 'image',
            Str::startsWith($normalizedMime, 'video/') => 'video',
            Str::startsWith($normalizedMime, 'audio/') => 'audio',
            Str::startsWith($normalizedMime, 'application/') => 'document',
            default => 'document',
        };
    }

    private function buildRoomOptions(?\Netflie\WhatsAppCloudApi\WebHook\Notification\Support\Customer $customer): array
    {
        $name = $customer?->name();
        $waId = $customer?->id();

        return [
            'room_name' => $name ?? $waId ?? 'WhatsApp contact',
            'avatar' => 'empty-avatar.png',
            'username' => $name,
            'phone' => $waId,
            'status_state' => 'offline',
            'status_lastChanged' => 0,
        ];
    }



    // Methods called from FrontEnd
    public function sendTemplate(string $chat_id, string $template_id, array $template_variables): SendMessageToApiResponse
    {
        $template = Template::find($template_id);
        if (!$template) {
            return sendMessageToApiResponse::error('Template not found');
        }

        $body = [];
        foreach ($template_variables as $value) {
            $body[] = ['type' => 'text', 'text' => $value];
        }

        $components = new Component([], $body, []);

        $response = $this->whatsappBusiness->sendTemplate($chat_id, $template->tag, $template->meta['language'], $components);

        $response = $response->decodedBody();

        if (isset($response['messages'][0]['id'])) {
            return sendMessageToApiResponse::success($response['messages'][0]['id'], now()->timestamp);
        } else {
            return sendMessageToApiResponse::error('Failed to send template');
        }
    }

    public function importTemplates(): array
    {
        $response = $this->whatsappBusiness->getTemplates();

        $templates = $response['data'];

        foreach ($templates as $template) {
            $message = '';

            foreach ($template['components'] as $key => $component) {
                if (in_array($component['type'], ['HEADER', 'BODY', 'FOOTER'])) {
                    $message .= ($key === 0 ? '' : PHP_EOL . "______" . PHP_EOL) . $component['text'];
                } else if ($component['type'] === 'BUTTONS') {
                    $message .= PHP_EOL . PHP_EOL;

                    foreach ($component['buttons'] as $button) {
                        $message .= PHP_EOL . '[' . $button['text'] . ']';
                    }
                }
            }

            Template::create([
                'tag' => $template['name'],
                'text' => $message,
                'is_system' => false,
                'category_id' => null,
                'type' => 'wb',
                'status' => $template['status'] === 'APPROVED',
                'meta' => $template
            ]);
        }

        return ['success' => true, 'message' => 'Templates imported', 'count' => count($templates)];
    }


    protected function sendMessageToApi(string $to, string $body, string $reply_to_msg_id, string $referenceId): sendMessageToApiResponse
    {
        if ($reply_to_msg_id) {
            $response = $this->whatsappBusiness->replyTo($reply_to_msg_id)->sendTextMessage($to, $body);
        } else {
            $response = $this->whatsappBusiness->sendTextMessage($to, $body);
        }

        $response = $response->decodedBody();

        if (isset($response['messages'][0]['id'])) {
            return sendMessageToApiResponse::success($response['messages'][0]['id'], now()->timestamp);
        } else {
            return sendMessageToApiResponse::error();
        }
    }


    protected function sendMediaToApi(string $path, string $name, string $to, string $caption, string $replyToMsgId, string $referenceId): SendMediaToApiResponse
    {
        $document_id = $this->uploadMedia($path);

        $response = $this->whatsappBusiness->sendDocument($to, $document_id, $name, $caption);

        if ($response->httpStatusCode() == 200) {
            $response = $response->decodedBody();

            if (isset($response['messages'][0]['id'])) {
                return SendMediaToApiResponse::success($response['messages'][0]['id'], now()->timestamp);
            }
        }

        return SendMediaToApiResponse::error();
    }


    public function readHistoryToApi($chatId, $maxId): array
    {
        $response = $this->whatsappBusiness->markMessageAsRead($maxId);

        return ['success' => (bool)$response->decodedBody()['success']];
    }

    protected function newRoomToApi(string $chatId, array $options = []): NewRoomToApiResponse
    {
        return NewRoomToApiResponse::success(
            $options['room_name'] ?? '',
            $options['avatar'] ?? 'empty-avatar.png',
            $options['username'] ?? '',
            $options['phone'] ?? null,
            $options['status_state'] ?? 'offline',
            $options['status_lastChanged'] ?? 0
        );
    }

    protected function deleteMessagesToApi(array $messages): deleteMessagesToApiResponse
    {
        return deleteMessagesToApiResponse::error('Method not available!');
    }

    public function createFileByMediaId($mediaId, $fileName, $type): array|null
    {

        $response = $this->whatsappBusiness->downloadMedia($mediaId);

        if ($response->httpStatusCode() != 200) {
            return null;
        }

        $fileContent = $response->body();

        $filePath = 'files/temp/' . $fileName;

        Storage::disk('local')->put($filePath, $fileContent);

        return [
            'type' => $type,
            'media' => $filePath
        ];
    }

    public function sendMessageReactionToApi(string $chatId, string $messengerId, string $reactionUnicode, bool $remove, int $from_me): MessageReactionToApiResponse
    {
        $response = $this->whatsappBusiness->sendReaction($chatId, $messengerId, $remove ? '' : $reactionUnicode);

        $response = $response->decodedBody();

        if (isset($response['messages'][0]['id'])) {
            return MessageReactionToApiResponse::success();
        } else {
            return MessageReactionToApiResponse::error();
        }
    }

    public function saveContactToApi($firstName, $lastName, $phone, $messengerId): array
    {
        return [];
    }

    protected function editMessageToApi(string $chatId, string $messengerId, string $newContent): EditMessageToApiResponse
    {
        return EditMessageToApiResponse::error('Method not available!');
    }


    public function setTyping($roomId, Request $request): array|JsonResponse
    {
        $room = $this->roomOfChannel->where('id', $roomId)->first();;
        $messenger_id = $room->lastMessage?->messenger_id;

        if ($messenger_id) {
            $this->whatsappBusiness->setTyping($messenger_id);
        }

        $this->sendBroadcast([
            '_' => 'updateUserTyping',
            'user_id' => strval($request->user()->id),
            'room_id' => strval($roomId),
            'success' => !empty($room_id)
        ]);

        return ['roomId' => $roomId, 'success' => true];
    }

    public function getRooms(Request $request): JsonResponse
    {
        return response()->json();
    }

    public function getMessages(Request $request): JsonResponse
    {
        return response()->json();
    }


    /**
     * @throws ResponseException
     */
    public function businessProfile($data): \Netflie\WhatsAppCloudApi\Response
    {
        return $this->whatsappBusiness->businessProfile($data);
    }


    /**
     * @throws ResponseException
     */
    public function uploadMedia($media_path): MediaID
    {
        $response = $this->whatsappBusiness->uploadMedia($media_path);

        $mediaId = json_decode($response->body(), true)['id'];

        return new CustomMediaID($mediaId);
    }
}
