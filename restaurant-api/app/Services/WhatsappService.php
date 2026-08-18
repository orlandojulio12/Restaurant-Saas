<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente de la Meta Cloud API. Solo se ocupa de enviar; la conversación la
 * lleva WhatsappBotService.
 */
class WhatsappService
{
    private const VERSION = 'v21.0';

    private string $token;
    private string $phoneId;

    public function __construct()
    {
        $this->token   = (string) config('services.whatsapp.token', '');
        $this->phoneId = (string) config('services.whatsapp.phone_id', '');
    }

    public function isConfigured(): bool
    {
        return $this->token !== '' && $this->phoneId !== '';
    }

    /**
     * Envía un texto libre. Devuelve si salió.
     *
     * Nunca lanza: un fallo de la API de Meta no debe tumbar el webhook, que
     * tiene que responder 200 para que Meta no lo reintente en bucle.
     */
    public function sendText(string $to, string $message): bool
    {
        if (!$this->isConfigured()) {
            Log::warning('WhatsApp: falta WHATSAPP_TOKEN o WHATSAPP_PHONE_ID; no se envía nada.');

            return false;
        }

        try {
            $response = Http::withToken($this->token)
                ->timeout(10)
                ->post("https://graph.facebook.com/" . self::VERSION . "/{$this->phoneId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'recipient_type'    => 'individual',
                    'to'                => $to,
                    'type'              => 'text',
                    'text'              => ['preview_url' => false, 'body' => $message],
                ]);

            if ($response->failed()) {
                Log::error('WhatsApp: la API rechazó el mensaje.', [
                    'to'     => $to,
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('WhatsApp: fallo de red al enviar.', ['to' => $to, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
