<?php
/**
 * Backend API - Generador SQL Estructurado
 * Recibe peticiones JSON del frontend, gestiona el estado y devuelve la respuesta.
 */

require_once __DIR__ . '/../../autoload.php';

header('Content-Type: application/json');

use AiSdk\AIClient;
use AiSdk\Modes\ChatModes;
use AiSdk\Providers\OllamaProvider;

// 1. Leer JSON de entrada
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['pregunta'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Input inválido o pregunta vacía']);
    exit;
}

$pregunta = $input['pregunta'];
if (strlen($pregunta) > 2000) {
    http_response_code(400);
    echo json_encode(['error' => 'La pregunta excede el límite permitido.']);
    exit;
}
$esquema = $input['esquema'] ?? '';
$historial = $input['historial'] ?? [];
$isFollowUp = !empty($historial);

try {
    // 2. Configurar el cliente (puedes cambiar a OpenAI, Gemini, Nvidia, etc.)
    $provider = new OllamaProvider(
        model: 'llama3:latest',
        baseUrl: 'http://localhost:11434'
    );
    $client = new AIClient($provider);
    $chatModes = new ChatModes();

    // 3. Determinar si es una inicialización de sesión o un seguimiento
    if ($isFollowUp) {
        // En seguimiento, no necesitamos reenviar el esquema, el modelo lo deduce del historial
        $mensajes = $chatModes->buildQueryEstructuradaFollowUp($historial, $pregunta);
    } else {
        if (empty($esquema)) {
            http_response_code(400);
            echo json_encode(['error' => 'Se requiere el esquema para la primera consulta']);
            exit;
        }
        // En inicialización, inyectamos el esquema
        $mensajes = $chatModes->buildQueryEstructuradaSessionInit($esquema, $pregunta);
    }

    // 4. Enviar los mensajes al proveedor
    $respuestaIA = $client->chat($mensajes);

    // 5. Intentamos decodificar la respuesta JSON (este modo asegura salida JSON)
    $jsonResponse = json_decode($respuestaIA, true);
    
    // 6. Preparar los nuevos mensajes para agregarlos al historial en el frontend
    if ($isFollowUp) {
        $nuevoUserMsg = ['role' => 'user', 'content' => $pregunta];
    } else {
        // En la primera consulta, el mensaje del usuario incluye el esquema DB y la pregunta
        $nuevoUserMsg = $mensajes[1]; 
    }
    
    $nuevoAsstMsg = ['role' => 'assistant', 'content' => $respuestaIA];

    // 7. Responder al frontend
    echo json_encode([
        'success' => true, 
        'data' => $jsonResponse ?: ['raw' => $respuestaIA], // En caso de que no sea JSON válido
        'nuevo_historial' => [$nuevoUserMsg, $nuevoAsstMsg]
    ]);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
