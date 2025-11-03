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
        $configuredPhoneId = config('services.whatsapp_business.id');

        app()->singleton('customDataForBlade', fn() => $payload);

        // Пробегаем по всем entry/changes, чтобы не пропустить батч-вебхуки
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                Log::channel('whatsapp_business_webhook')->info('Webhook received:', $change);

                $value = $change['value'] ?? null;
                if (!$value || !isset($value['metadata']['phone_number_id'])) {
                    continue; // пропускаем некорректные события
                }

                $phoneId = $value['metadata']['phone_number_id'];

                // Проверяем, что это наш номер
                if ((string) $phoneId !== (string) $configuredPhoneId) {
                    Log::channel('whatsapp_business_webhook')->warning(
                        'Ignored webhook from another phone_number_id',
                        ['received' => $phoneId, 'expected' => $configuredPhoneId]
                    );
                    continue; // игнорируем чужие номера
                }

                // Определяем тип события
                $eventType = null;

                if (!empty($value['messages'])) {
                    $type = $value['messages'][0]['type'] ?? null;

                    if (in_array($type, ['text', 'image', 'document', 'video', 'audio', 'location', 'contacts', 'button'])) {
                        $eventType = 'messageReceived';
                    } elseif ($type === 'reaction') {
                        $eventType = 'messageReaction';
                    }
                } elseif (!empty($value['statuses'])) {
                    $eventType = 'messageStatus';
                }

                // Вызываем обработчик, если он есть
                if ($eventType && method_exists($this, $eventType)) {
                    $result = $this->$eventType($change);

                    if ($result && ($result['success'] ?? false)) {
                        broadcast(new MessageEvent($result))->toOthers();
                    }
                } else {
                    // Логируем необработанные типы
                    $msgType = $value['messages'][0]['type'] ?? null;
                    $suffix = $msgType ? (': ' . $msgType) : '';
                    report(new \Exception('Undefined event type' . ($eventType ? $suffix : '')));
                    Log::channel('whatsapp_business_webhook')->info('Undefined event type', $change);
                }
            }
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * @throws ResponseException
     */
    public function messageReceived($change)
    {
        $value = $change['value'];
        $messageData = $value['messages'][0];

        $messengerId = $messageData['id'];
        $phone = $messageData['from'];

        if ($messageData['type'] === 'text') {
            $content = $messageData['text']['body'];
        } elseif ($messageData['type'] === 'image') {
            $content = $messageData['image']['caption'] ?? '';
            $mediaId = $messageData['image']['id'];
            $mimeType = $messageData['image']['mime_type'];
            $fileName = $mediaId . '.' . explode('/', $mimeType)[1];

            $media = $this->createFileByMediaId($mediaId, $fileName, $messageData['type']);
        } elseif ($messageData['type'] === 'document') {
            $content = '';
            $mediaId = $messageData['document']['id'];
            $fileName = $messageData['document']['filename'];

            $media = $this->createFileByMediaId($mediaId, $fileName, $messageData['type']);
        } elseif ($messageData['type'] === 'video') {
            $content = $messageData['video']['caption'] ?? '';
            $mediaId = $messageData['video']['id'];
            $mimeType = $messageData['video']['mime_type'];
            $fileName = $mediaId . '.' . explode('/', $mimeType)[1];

            $media = $this->createFileByMediaId($mediaId, $fileName, $messageData['type']);
        } elseif ($messageData['type'] === 'audio') {
            $content = '';
            $mediaId = $messageData['audio']['id'];
            $mimeType = strtok($messageData['audio']['mime_type'], ';');
            $fileName = $mediaId . '.' . explode('/', $mimeType)[1];

            $media = $this->createFileByMediaId($mediaId, $fileName, $messageData['type']);
        } elseif ($messageData['type'] === 'location') {
            $lat = $messageData['location']['latitude'];
            $long = $messageData['location']['longitude'];
            $content = "https://www.google.com/maps?q=$lat,$long";
        } elseif ($messageData['type'] == 'contacts') {
            $content = $messageData['contacts'][0]['name']['formatted_name'] . ' (' . $messageData['contacts'][0]['phones'][0]['phone'] . ')';
        } elseif ($messageData['type'] == 'button') {
            $content = $messageData['button']['text'];
        }

        $timestamp = $messageData['timestamp'];
        $replyMessageId = $messageData['context']['id'] ?? null;

        return $this->updateMessageReceived(
            intval($phone),
            strval($messengerId),
            strval($content ?? ''),
            intval($timestamp),
            null,
            $replyMessageId ?? null,
            false,
            $media ?? null,
            [
                'room_name' => $value['contacts'][0]['profile']['name'] ?? $value['contacts'][0]['wa_id'],
                'avatar' => 'empty-avatar.png',
                'username' => $value['contacts'][0]['profile']['name'] ?? null,
                'phone' => $value['contacts'][0]['wa_id'] ?? null,
                'status_state' => 'offline',
                'status_lastChanged' => 0
            ]
        );
    }


    public function messageStatus($change)
    {
        $statusData = $change['value']['statuses'][0];

        $messengerId = $statusData['id'];
        $message = $this->message->where('messenger_id', $messengerId)->first(); //todo add filter by channel

        if ($message) {
            $room = $this->roomOfChannel->where('chat_id', $message->chat_id)->first();

            return match ($statusData['status']) {
                'sent' => $this->updateMessageStatusSaved($room->chat_id, $messengerId),
                'delivered' => $this->updateMessageStatusDistributed($room->chat_id, $messengerId),
                'read' => $this->updateMessageStatusSeen($room->chat_id, $message->from_me, $messengerId),
                default => ['success' => false],
            };
        } else {
            return ['success' => false]; //todo надо ли возвращать не 200й статус чтобы сервер заново прислал событие?
        }
    }

    public function messageReaction($change)
    {
        $value = $change['value'];

        $messageData = $value['messages'][0];

        $messengerId = $messageData['reaction']['message_id'];

        $currentMessage = $this->message->where('messenger_id', $messengerId)->first();

        if (!$currentMessage) {
            return ['success' => false];
        }

        $currentMessageData = $currentMessage->toArray();

        $emoji = data_get($value, 'messages.0.reaction.emoji') ?? null;

        $currentMessageData['reactions'] = $this->mergeReactions($currentMessageData['reactions'], $emoji, $currentMessage->chat_id, !$emoji);

        return $this->updateMessageEdited($currentMessage->chat_id, $currentMessage, $currentMessageData);
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
