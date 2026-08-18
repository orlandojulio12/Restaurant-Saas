<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Services\PlanService;
use App\Services\WhatsappBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsappController extends Controller
{
    public function __construct(
        private readonly WhatsappBotService $bot,
        private readonly PlanService $plans,
    ) {}

    /**
     * Handshake de verificación de Meta.
     */
    public function verify(Request $request)
    {
        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token === config('services.whatsapp.verify_token')) {
            return response($challenge, 200);
        }

        return response()->json(['error' => 'Forbidden'], 403);
    }

    /**
     * Entrada de mensajes.
     *
     * Siempre responde 200: cualquier otro código hace que Meta reintente el
     * envío en bucle. Los problemas se registran en el log, no en la respuesta.
     */
    public function webhook(Request $request): JsonResponse
    {
        try {
            $mensajes = $this->mensajes($request->all());
        } catch (\Throwable $e) {
            Log::error('WhatsApp: payload ilegible.', ['error' => $e->getMessage()]);

            return response()->json(['status' => 'ok']);
        }

        foreach ($mensajes as $mensaje) {
            try {
                $this->procesar($mensaje);
            } catch (\Throwable $e) {
                Log::error('WhatsApp: fallo procesando un mensaje.', [
                    'error'   => $e->getMessage(),
                    'mensaje' => $mensaje,
                ]);
            }
        }

        return response()->json(['status' => 'ok']);
    }

    private function procesar(array $mensaje): void
    {
        $restaurant = $this->restaurantePorNumero($mensaje['to']);

        if (!$restaurant) {
            Log::warning('WhatsApp: llegó un mensaje a un número sin restaurante asociado.', [
                'numero' => $mensaje['to'],
            ]);

            return;
        }

        // El bot es una función de plan: si no está incluida, no se contesta.
        if (!$this->plans->allows($restaurant, 'whatsapp')) {
            return;
        }

        $this->bot->handle($restaurant, $mensaje['from'], $mensaje['text'], $mensaje['id']);
    }

    /**
     * Extrae los mensajes de texto del payload de Meta.
     *
     * El formato viene muy anidado y un mismo webhook puede traer varios; los
     * avisos de estado (entregado, leído) no llevan 'messages' y se ignoran.
     *
     * @return array<int, array{id: string, from: string, to: string, text: string}>
     */
    private function mensajes(array $payload): array
    {
        $salida = [];

        // Todo el payload es entrada externa: se comprueba la forma en cada
        // nivel en vez de confiar en que Meta mande lo esperado.
        foreach ($this->comoLista($payload['entry'] ?? null) as $entry) {
            foreach ($this->comoLista($entry['changes'] ?? null) as $change) {
                $value  = is_array($change['value'] ?? null) ? $change['value'] : [];
                $numero = $value['metadata']['display_phone_number'] ?? null;

                foreach ($this->comoLista($value['messages'] ?? null) as $mensaje) {
                    // Solo texto: audio, imágenes y ubicaciones aún no se manejan.
                    if (($mensaje['type'] ?? null) !== 'text') {
                        continue;
                    }

                    $salida[] = [
                        'id'   => $mensaje['id'] ?? '',
                        'from' => $mensaje['from'] ?? '',
                        'to'   => (string) $numero,
                        'text' => $mensaje['text']['body'] ?? '',
                    ];
                }
            }
        }

        return $salida;
    }

    /**
     * Devuelve el valor solo si es una lista de arrays; si no, lista vacía.
     */
    private function comoLista(mixed $valor): array
    {
        if (!is_array($valor)) {
            return [];
        }

        return array_values(array_filter($valor, 'is_array'));
    }

    /**
     * Resuelve el restaurante por el número que recibió el mensaje.
     *
     * Es lo que hace multi-inquilino al bot: cada restaurante atiende por su
     * propio número. Se comparan solo los dígitos porque Meta puede mandarlo
     * con prefijos o separadores distintos a los guardados.
     */
    private function restaurantePorNumero(string $numero): ?Restaurant
    {
        $digitos = preg_replace('/\D+/', '', $numero);

        if ($digitos === '') {
            return null;
        }

        return Restaurant::with('plan')
            ->where('is_active', true)
            ->whereNotNull('whatsapp_number')
            ->get(['id', 'plan_id', 'name', 'currency', 'whatsapp_number', 'timezone'])
            ->first(fn($r) => preg_replace('/\D+/', '', (string) $r->whatsapp_number) === $digitos);
    }
}
