<?php

declare(strict_types=1);

/**
 * chat.php
 *
 * Endpoint de conversación con caché activo.
 *
 * Flujo:
 * 1. Recibe `cache_name` (obtenido de init_cache.php), `pregunta` e `historial`.
 * 2. Configura el GeminiProvider con el cacheName → el sistema NO reenvía el
 *    system prompt ni el esquema, solo el historial de conversación y la pregunta.
 * 3. Devuelve la respuesta estructurada JSON del modelo.
 *
 * Ahorro de tokens por petición:
 * - Sin caché: tokens(system_prompt + esquema + historial + pregunta)
 * - Con caché: tokens(historial + pregunta)  [el caché se cobra a precio reducido]
 */

require_once __DIR__ . '/../../../autoload.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido. Use POST.']);
    exit;
}

use AiSdk\Providers\GeminiProvider;

// ─── Leer input ───────────────────────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Cuerpo de solicitud inválido (se esperaba JSON).']);
    exit;
}

$cacheName = trim($input['cache_name'] ?? '');
$mode      = $input['mode']      ?? 'cache';   // 'cache' | 'session'
$pregunta  = trim($input['pregunta'] ?? '');
$historial = $input['historial']     ?? [];
$esquema   = trim($input['esquema']  ?? '');    // Solo usado en modo 'session'

// Validaciones comunes
if (empty($pregunta)) {
    http_response_code(400);
    echo json_encode(['error' => 'El campo "pregunta" no puede estar vacío.']);
    exit;
}

if (strlen($pregunta) > 2000) {
    http_response_code(400);
    echo json_encode(['error' => 'La pregunta excede el límite de 2000 caracteres.']);
    exit;
}

// Validar según el modo
if ($mode === 'cache' && empty($cacheName)) {
    http_response_code(400);
    echo json_encode(['error' => 'Modo "cache" requiere el campo "cache_name". Inicializa el caché primero.']);
    exit;
}

if ($mode === 'session' && empty($esquema) && empty($historial)) {
    http_response_code(400);
    echo json_encode(['error' => 'Modo "session" requiere "esquema" en la primera consulta.']);
    exit;
}

if (!is_array($historial)) {
    $historial = [];
}

// Limitar historial a 20 turnos (40 mensajes)
if (count($historial) > 40) {
    $historial = array_slice($historial, -40);
}

// ─── API Key ──────────────────────────────────────────────────────────────────
$apiKey = getenv('GEMINI_API_KEY') ?: '';

if (empty($apiKey)) {
    $configFile = __DIR__ . '/../config.php';
    if (file_exists($configFile)) {
        $config = require $configFile;
        $apiKey  = $config['gemini_api_key'] ?? '';
    }
}

if (empty($apiKey)) {
    http_response_code(500);
    echo json_encode(['error' => 'API Key de Gemini no configurada.']);
    exit;
}

// ─── Construir mensajes según el modo ────────────────────────────────────────
use AiSdk\Modes\ChatModes;

$chatModes = new ChatModes();
$isFirstTurn = empty($historial);

if ($mode === 'cache') {
    // MODO CACHÉ: el system prompt y el esquema ya están en la API de Gemini.
    // Solo se envía historial + pregunta actual.
    $messages = array_merge(
        $historial,
        [['role' => 'user', 'content' => $pregunta]]
    );
} elseif ($isFirstTurn) {
    // MODO SESIÓN — primer turno: se envía esquema + pregunta juntos (una sola vez).
    $messages = $chatModes->buildQueryEstructuradaSessionInit($esquema, $pregunta);
} else {
    // MODO SESIÓN — turno de seguimiento: solo historial + pregunta.
    $messages = $chatModes->buildQueryEstructuradaFollowUp($historial, $pregunta);
}

// ─── Ejecutar la llamada a Gemini ─────────────────────────────────────────────
try {
    $provider = new GeminiProvider(
        apiKey: $apiKey,
        model: 'gemini-1.5-flash',
        temperature: 0.1,
        maxOutputTokens: 2048
    );

    if ($mode === 'cache') {
        // Activar el caché: chat() usará cachedContent en lugar de systemInstruction
        $provider->setCacheName($cacheName);
    }

    $respuestaIA = $provider->chat($messages);

    // Parsear la respuesta JSON estructurada
    $jsonResponse = json_decode($respuestaIA, true);

    // Preparar los mensajes para actualizar el historial en el frontend.
    // En modo sesión en el primer turno, el mensaje de usuario incluye el esquema.
    if ($mode === 'session' && $isFirstTurn) {
        $nuevoUserMsg = $messages[1]; // mensaje con esquema+pregunta del buildQueryEstructuradaSessionInit
    } else {
        $nuevoUserMsg = ['role' => 'user', 'content' => $pregunta];
    }
    $nuevoAsstMsg = ['role' => 'assistant', 'content' => $respuestaIA];

    echo json_encode([
        'success'         => true,
        'mode'            => $mode,
        'data'            => $jsonResponse ?: ['raw' => $respuestaIA],
        'nuevo_historial' => [$nuevoUserMsg, $nuevoAsstMsg],
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error'   => $e->getMessage(),
        'detalle' => $mode === 'cache'
            ? 'Verifica que el cache_name sea válido y no haya expirado.'
            : 'Error al procesar la consulta en modo sesión.',
    ]);
}

