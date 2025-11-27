<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\WatiService;
use App\Services\FlightService;
use Illuminate\Support\Facades\Log;

class WatiController extends Controller
{
    protected $watiService;
    protected $flightService;

    public function __construct(WatiService $watiService, FlightService $flightService)
    {
        $this->watiService = $watiService;
        $this->flightService = $flightService;
    }

    /**
     * Handle incoming webhook from Wati.
     */
    public function handleWebhook(Request $request)
    {
        Log::info('Wati Webhook Received:', $request->all());

        $waId = $request->input('waId');
        $text = $request->input('text');
        $type = $request->input('type');

        if (!$waId) {
            return response()->json(['status' => 'error', 'message' => 'No waId provided'], 400);
        }

        // Restrict access to a specific phone number
        $allowedPhoneNumber = env('ALLOWED_PHONE_NUMBER');
        if ($allowedPhoneNumber && $waId !== $allowedPhoneNumber) {
            Log::info("Access denied for phone number: $waId");
            return response()->json(['status' => 'success', 'message' => 'Access denied']);
        }

        if ($type === 'text' || $request->has('text')) {
            $this->processMessage($waId, $text);
        }

        return response()->json(['status' => 'success']);
    }

    protected function processMessage($waId, $text)
    {
        $text = strtolower(trim($text));

        if (str_contains($text, 'hola') || str_contains($text, 'inicio')) {
            $response = "¡Hola! Bienvenido a L&L, tu agencia de viajes. ✈️\n¿En qué puedo ayudarte hoy?\n1. Buscar vuelos (Ej: 'Vuelo Madrid a Paris')\n2. Estado de mi vuelo (Ej: 'Estado IB1234')\n3. Contactar agente";
            $this->watiService->sendMessage($waId, $response);
        } elseif (str_contains($text, 'vuelo') && str_contains($text, ' a ')) {
            // Simple parsing: "vuelo [origin] a [destination]"
            $parts = explode(' a ', $text);
            if (count($parts) >= 2) {
                // Extract origin from the first part (remove "vuelo" and trim)
                $originPart = explode('vuelo', $parts[0]);
                $origin = trim(end($originPart));
                $destination = trim($parts[1]);

                $flights = $this->flightService->searchFlights($origin, $destination);
                
                $response = "He encontrado estos vuelos para ti de $origin a $destination:\n";
                foreach ($flights as $flight) {
                    $response .= "- {$flight['airline']} ({$flight['flight_number']}): {$flight['departure']} - {$flight['price']}\n";
                }
                $this->watiService->sendMessage($waId, $response);
            } else {
                 $this->watiService->sendMessage($waId, "Por favor, indica el origen y destino. Ej: 'Vuelo Madrid a Paris'");
            }
        } elseif (str_contains($text, 'estado')) {
            // Simple parsing: "estado [flight_number]"
            $parts = explode('estado', $text);
            $flightNumber = trim(end($parts));
            
            if (!empty($flightNumber)) {
                $status = $this->flightService->getFlightStatus($flightNumber);
                $this->watiService->sendMessage($waId, $status);
            } else {
                $this->watiService->sendMessage($waId, "Por favor, indica el número de vuelo. Ej: 'Estado IB1234'");
            }
        } elseif (str_contains($text, 'agente')) {
            $response = "Un agente se pondrá en contacto contigo en breve. 👨‍💻";
            $this->watiService->sendMessage($waId, $response);
        } else {
            $response = "Lo siento, no entendí eso. ¿Podrías repetir o elegir una de las opciones?\n1. Buscar vuelos\n2. Estado de mi vuelo\n3. Contactar agente";
            $this->watiService->sendMessage($waId, $response);
        }
    }
}
