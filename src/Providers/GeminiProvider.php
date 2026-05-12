<?php

declare(strict_types=1);

namespace AiSdk\Providers;

use AiSdk\Contracts\AIProviderInterface;
use AiSdk\Http\HttpClient;
use InvalidArgumentException;
use RuntimeException;

/**
 * GeminiProvider
 *
 * Implementación del AIProviderInterface para la API de Google Gemini.
 *
 * IMPORTANTE: La API de Gemini tiene un formato de mensajes diferente al
 * estándar OpenAI. Este provider realiza la conversión automáticamente:
 * - OpenAI 'role: system'    → Se prepende al primer mensaje 'user'
 * - OpenAI 'role: user'      → Gemini 'role: user'
 * - OpenAI 'role: assistant' → Gemini 'role: model'
 *
 * Modelos soportados:
 * - gemini-1.5-pro           (más capaz, contexto 1M tokens)
 * - gemini-1.5-flash         (más rápido y económico)
 * - gemini-1.5-flash-8b      (ultra rápido)
 * - gemini-2.0-flash         (nueva generación)
 *
 * Documentación de la API:
 * https://ai.google.dev/api/generate-content
 *
 * @package AiSdk\Providers
 * @version 1.0.0
 */
final class GeminiProvider implements AIProviderInterface
{
    /**
     * URL base de la API de Gemini. El modelo y la clave se añaden dinámicamente.
     */
    private const API_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models';

    /**
     * Modelo por defecto.
     */
    private const DEFAULT_MODEL = 'gemini-1.5-flash';

    /**
     * Temperatura por defecto.
     */
    private const DEFAULT_TEMPERATURE = 0.7;

    /**
     * Máximo de tokens de salida por defecto.
     */
    private const DEFAULT_MAX_OUTPUT_TOKENS = 8192;

    /**
     * @var HttpClient Cliente HTTP.
     */
    private HttpClient $httpClient;

    /**
     * @var string API Key de Google AI Studio.
     */
    private string $apiKey;

    /**
     * @var string Modelo de Gemini.
     */
    private string $model;

    /**
     * @var float Temperatura de generación.
     */
    private float $temperature;

    /**
     * @var int Máximo de tokens de salida.
     */
    private int $maxOutputTokens;

    /**
     * @var string|null Nombre del recurso de caché activo (ej: cachedContents/1234).
     */
    private ?string $cacheName = null;

    /**
     * Constructor del GeminiProvider.
     *
     * @param string          $apiKey          API Key de Google AI Studio. Obtener en: https://aistudio.google.com
     * @param string          $model           Modelo de Gemini. Por defecto: 'gemini-1.5-flash'.
     * @param float           $temperature     Temperatura de generación (0.0 - 2.0).
     * @param int             $maxOutputTokens Máximo de tokens en la respuesta.
     * @param HttpClient|null $httpClient      Cliente HTTP (opcional).
     *
     * @throws InvalidArgumentException Si la API Key está vacía.
     */
    public function __construct(
        string $apiKey,
        string $model = self::DEFAULT_MODEL,
        float $temperature = self::DEFAULT_TEMPERATURE,
        int $maxOutputTokens = self::DEFAULT_MAX_OUTPUT_TOKENS,
        ?HttpClient $httpClient = null
    ) {
        if (trim($apiKey) === '') {
            throw new InvalidArgumentException(
                'GeminiProvider: La API Key no puede estar vacía. '
                . 'Obtén una en: https://aistudio.google.com/app/apikey'
            );
        }

        $this->apiKey          = trim($apiKey);
        $this->model           = trim($model);
        $this->temperature     = $temperature;
        $this->maxOutputTokens = $maxOutputTokens;
        $this->httpClient      = $httpClient ?? new HttpClient();
    }

    /**
     * {@inheritdoc}
     *
     * La API de Gemini usa un endpoint diferente por modelo y requiere la
     * API Key como parámetro de query string, no como Bearer token.
     *
     * Estructura de la petición Gemini:
     * ```json
     * {
     *   "contents": [
     *     {"role": "user", "parts": [{"text": "..."}]},
     *     {"role": "model", "parts": [{"text": "..."}]}
     *   ],
     *   "systemInstruction": {"parts": [{"text": "..."}]},
     *   "generationConfig": {
     *     "temperature": 0.7,
     *     "maxOutputTokens": 8192
     *   }
     * }
     * ```
     */
    public function chat(array $messages): string
    {
        if (empty($messages)) {
            throw new InvalidArgumentException(
                'GeminiProvider: El array de mensajes no puede estar vacío.'
            );
        }

        $apiUrl = sprintf(
            '%s/%s:generateContent?key=%s',
            self::API_BASE_URL,
            $this->model,
            $this->apiKey
        );

        // Extraer system prompt y convertir mensajes al formato Gemini
        [$systemInstruction, $geminiContents] = $this->convertMessages($messages);

        $body = [
            'contents'         => $geminiContents,
            'generationConfig' => [
                'temperature'     => $this->temperature,
                'maxOutputTokens' => $this->maxOutputTokens,
            ],
        ];

        // Gemini maneja el system prompt de forma separada
        if ($this->cacheName !== null) {
            // Si usamos caché, pasamos el ID y omitimos el systemInstruction 
            // ya que el contexto (esquema) ya está en la caché.
            $body['cachedContent'] = $this->cacheName;
        } elseif ($systemInstruction !== null) {
            $body['systemInstruction'] = [
                'parts' => [['text' => $systemInstruction]],
            ];
        }

        $response = $this->httpClient->post(
            url: $apiUrl,
            headers: [], // Gemini usa API Key en query string
            body: $body
        );

        return $this->extractContent($response);
    }

    /**
     * {@inheritdoc}
     */
    public function getProviderName(): string
    {
        return 'Gemini';
    }

    /**
     * {@inheritdoc}
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * {@inheritdoc}
     */
    public function setModel(string $model): void
    {
        if (trim($model) === '') {
            throw new InvalidArgumentException(
                'GeminiProvider: El nombre del modelo no puede estar vacío.'
            );
        }

        $this->model = trim($model);
    }

    /**
     * Establece manualmente un nombre de caché existente para ser usado en el chat.
     *
     * @param string|null $cacheName Nombre del recurso (ej: "cachedContents/xyz123").
     */
    public function setCacheName(?string $cacheName): self
    {
        $this->cacheName = $cacheName;
        return $this;
    }

    /**
     * Crea un nuevo contexto en caché en la API de Gemini usando una instrucción de sistema.
     * Útil para esquemas de base de datos grandes que se usarán varias veces.
     *
     * @param string $systemInstruction Texto a cachear (ej: esquema de BD).
     * @param int    $ttlMinutes        Tiempo de vida en minutos (por defecto 60).
     *
     * @return string El ID del caché generado (ej: "cachedContents/xyz123").
     *
     * @throws RuntimeException Si falla la creación en la API.
     */
    public function createContextCache(string $systemInstruction, int $ttlMinutes = 60): string
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/cachedContents?key={$this->apiKey}";
        
        // TTL format in seconds: "3600s"
        $ttlSeconds = $ttlMinutes * 60;
        
        $body = [
            // El modelo base debe especificarse en el formato models/nombre
            'model' => str_starts_with($this->model, 'models/') ? $this->model : "models/{$this->model}",
            'systemInstruction' => [
                'parts' => [['text' => $systemInstruction]],
            ],
            'ttl' => "{$ttlSeconds}s",
        ];

        $response = $this->httpClient->post($url, [], $body);

        if (!isset($response['name'])) {
            throw new RuntimeException("GeminiProvider: Error al crear la caché de contexto. Respuesta: " . json_encode($response));
        }

        $this->cacheName = $response['name'];
        return $this->cacheName;
    }

    /**
     * Convierte el array de mensajes del formato estándar OpenAI al formato Gemini.
     *
     * Conversiones realizadas:
     * - 'system' → Extraído como systemInstruction separado
     * - 'user'   → 'user' (igual)
     * - 'assistant' → 'model' (renombrado)
     *
     * @param array $messages Mensajes en formato OpenAI.
     *
     * @return array{0: string|null, 1: array} Tupla de [systemInstruction|null, geminiContents].
     */
    private function convertMessages(array $messages): array
    {
        $systemInstruction = null;
        $geminiContents    = [];

        foreach ($messages as $message) {
            $role    = $message['role'] ?? '';
            $content = $message['content'] ?? '';

            switch ($role) {
                case 'system':
                    // Gemini tiene un campo específico para instrucciones del sistema
                    $systemInstruction = $content;
                    break;

                case 'user':
                    $geminiContents[] = [
                        'role'  => 'user',
                        'parts' => [['text' => $content]],
                    ];
                    break;

                case 'assistant':
                    // En Gemini, el rol 'assistant' se llama 'model'
                    $geminiContents[] = [
                        'role'  => 'model',
                        'parts' => [['text' => $content]],
                    ];
                    break;

                default:
                    throw new InvalidArgumentException(
                        "GeminiProvider: Rol de mensaje no reconocido: '{$role}'. "
                        . "Roles válidos: system, user, assistant."
                    );
            }
        }

        // Gemini requiere que el último mensaje sea del usuario
        if (!empty($geminiContents)) {
            $lastContent = end($geminiContents);
            if (($lastContent['role'] ?? '') !== 'user') {
                throw new InvalidArgumentException(
                    'GeminiProvider: El último mensaje en la conversación debe ser del usuario (role: user).'
                );
            }
        }

        return [$systemInstruction, $geminiContents];
    }

    /**
     * Extrae el texto de la respuesta de la API de Gemini.
     *
     * Estructura de respuesta de Gemini:
     * ```json
     * {
     *   "candidates": [{
     *     "content": {
     *       "parts": [{"text": "Respuesta aquí..."}],
     *       "role": "model"
     *     },
     *     "finishReason": "STOP"
     *   }]
     * }
     * ```
     *
     * @param array $response Respuesta decodificada de la API.
     *
     * @return string Texto generado.
     *
     * @throws RuntimeException Si la respuesta no tiene la estructura esperada o fue bloqueada.
     */
    private function extractContent(array $response): string
    {
        // Verificar si la respuesta fue bloqueada por filtros de seguridad
        if (isset($response['promptFeedback']['blockReason'])) {
            $reason = $response['promptFeedback']['blockReason'];
            throw new RuntimeException(
                "GeminiProvider: La respuesta fue bloqueada por filtros de seguridad. "
                . "Razón: {$reason}"
            );
        }

        $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if ($text === null) {
            $finishReason = $response['candidates'][0]['finishReason'] ?? 'UNKNOWN';
            throw new RuntimeException(
                "GeminiProvider: No se pudo extraer contenido de la respuesta. "
                . "finishReason: {$finishReason}"
            );
        }

        return (string) $text;
    }

    /**
     * {@inheritdoc}
     */
    public function isServerAvailable(): bool
    {
        return true;
    }
}
