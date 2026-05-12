<?php

declare(strict_types=1);

namespace AiSdk\Providers;

use AiSdk\Contracts\AIProviderInterface;
use AiSdk\Http\HttpClient;
use InvalidArgumentException;
use RuntimeException;

/**
 * ClaudeProvider
 *
 * Implementación del AIProviderInterface para la API de Anthropic Claude.
 *
 * DIFERENCIAS con el formato estándar:
 * 1. El system prompt va como campo separado 'system' (no en el array 'messages')
 * 2. Requiere el header 'anthropic-version'
 * 3. El campo 'max_tokens' es OBLIGATORIO (no opcional)
 * 4. Los mensajes deben alternarse user/assistant (no pueden ser dos 'user' seguidos)
 *
 * Modelos soportados:
 * - claude-3-5-sonnet-20241022  (más capaz, recomendado)
 * - claude-3-5-haiku-20241022   (más rápido y económico)
 * - claude-3-opus-20240229      (máxima capacidad, más lento)
 * - claude-3-sonnet-20240229
 * - claude-3-haiku-20240307     (más rápido de Claude 3)
 *
 * Documentación de la API:
 * https://docs.anthropic.com/en/api/messages
 *
 * @package AiSdk\Providers
 * @version 1.0.0
 */
final class ClaudeProvider implements AIProviderInterface
{
    /**
     * URL de la API de mensajes de Anthropic.
     */
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    /**
     * Versión de la API de Anthropic requerida en el header.
     */
    private const API_VERSION = '2023-06-01';

    /**
     * Modelo por defecto.
     */
    private const DEFAULT_MODEL = 'claude-3-5-sonnet-20241022';

    /**
     * Temperatura por defecto.
     */
    private const DEFAULT_TEMPERATURE = 0.7;

    /**
     * Máximo de tokens por defecto.
     * OBLIGATORIO en la API de Claude.
     */
    private const DEFAULT_MAX_TOKENS = 8096;

    /**
     * @var HttpClient Cliente HTTP.
     */
    private HttpClient $httpClient;

    /**
     * @var string API Key de Anthropic (formato: sk-ant-...).
     */
    private string $apiKey;

    /**
     * @var string Modelo de Claude.
     */
    private string $model;

    /**
     * @var float Temperatura de generación.
     */
    private float $temperature;

    /**
     * @var int Máximo de tokens (OBLIGATORIO en Claude).
     */
    private int $maxTokens;

    /**
     * Constructor del ClaudeProvider.
     *
     * @param string          $apiKey      API Key de Anthropic. Formato: sk-ant-api03-...
     *                                     Obtener en: https://console.anthropic.com/settings/keys
     * @param string          $model       Modelo de Claude. Por defecto: 'claude-3-5-sonnet-20241022'.
     * @param float           $temperature Temperatura de generación (0.0 - 1.0 en Claude).
     * @param int             $maxTokens   Máximo de tokens en la respuesta (requerido por la API).
     * @param HttpClient|null $httpClient  Cliente HTTP (opcional).
     *
     * @throws InvalidArgumentException Si la API Key está vacía o la temperatura es inválida.
     */
    public function __construct(
        string $apiKey,
        string $model = self::DEFAULT_MODEL,
        float $temperature = self::DEFAULT_TEMPERATURE,
        int $maxTokens = self::DEFAULT_MAX_TOKENS,
        ?HttpClient $httpClient = null
    ) {
        if (trim($apiKey) === '') {
            throw new InvalidArgumentException(
                'ClaudeProvider: La API Key no puede estar vacía. '
                . 'Obtén una en: https://console.anthropic.com/settings/keys'
            );
        }

        // Claude acepta temperaturas de 0.0 a 1.0 (no hasta 2.0 como OpenAI)
        if ($temperature < 0.0 || $temperature > 1.0) {
            throw new InvalidArgumentException(
                "ClaudeProvider: La temperatura debe estar entre 0.0 y 1.0 para la API de Anthropic. "
                . "Recibido: {$temperature}"
            );
        }

        $this->apiKey      = trim($apiKey);
        $this->model       = trim($model);
        $this->temperature = $temperature;
        $this->maxTokens   = $maxTokens;
        $this->httpClient  = $httpClient ?? new HttpClient();
    }

    /**
     * {@inheritdoc}
     *
     * Estructura de petición de Claude:
     * ```json
     * {
     *   "model": "claude-3-5-sonnet-20241022",
     *   "max_tokens": 8096,
     *   "system": "Eres un asistente...",
     *   "messages": [
     *     {"role": "user", "content": "Hola"},
     *     {"role": "assistant", "content": "¡Hola!"}
     *   ],
     *   "temperature": 0.7
     * }
     * ```
     *
     * Headers requeridos por Anthropic:
     * - x-api-key: [tu-api-key]
     * - anthropic-version: 2023-06-01
     */
    public function chat(array $messages): string
    {
        if (empty($messages)) {
            throw new InvalidArgumentException(
                'ClaudeProvider: El array de mensajes no puede estar vacío.'
            );
        }

        // Extraer el system prompt (Claude lo requiere fuera del array de mensajes)
        [$systemPrompt, $claudeMessages] = $this->extractSystemPrompt($messages);

        $headers = [
            'x-api-key'         => $this->apiKey,
            'anthropic-version' => self::API_VERSION,
            'anthropic-beta'    => 'prompt-caching-2024-07-31', // Activar prompt caching
        ];

        $body = [
            'model'       => $this->model,
            'max_tokens'  => $this->maxTokens,
            'messages'    => $claudeMessages,
            'temperature' => $this->temperature,
        ];

        // Solo añadir 'system' si hay un prompt de sistema
        if ($systemPrompt !== null) {
            $body['system'] = $systemPrompt;
        }

        $response = $this->httpClient->post(
            url: self::API_URL,
            headers: $headers,
            body: $body
        );

        return $this->extractContent($response);
    }

    /**
     * {@inheritdoc}
     */
    public function getProviderName(): string
    {
        return 'Claude';
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
                'ClaudeProvider: El nombre del modelo no puede estar vacío.'
            );
        }

        $this->model = trim($model);
    }

    /**
     * Extrae el system prompt del array de mensajes.
     *
     * Claude requiere que el system prompt se pase como un campo separado
     * 'system' en el cuerpo de la petición, no dentro del array 'messages'.
     *
     * También valida que los mensajes se alternen correctamente (user/assistant).
     *
     * @param array $messages Mensajes en formato estándar.
     *
     * @return array{0: string|null, 1: array} Tupla de [systemPrompt|null, mensajesFiltrados].
     *
     * @throws InvalidArgumentException Si hay mensajes del sistema múltiples o roles inválidos.
     */
    private function extractSystemPrompt(array $messages): array
    {
        $systemPrompts  = [];
        $filteredMessages = [];

        foreach ($messages as $message) {
            $role = $message['role'] ?? '';

            if ($role === 'system') {
                $systemPrompts[] = $message['content'] ?? '';
            } elseif (in_array($role, ['user', 'assistant'], true)) {
                $filteredMessages[] = [
                    'role'    => $role,
                    'content' => $message['content'] ?? '',
                ];
            } else {
                throw new InvalidArgumentException(
                    "ClaudeProvider: Rol de mensaje no reconocido: '{$role}'. "
                    . "Roles válidos: system, user, assistant."
                );
            }
        }

        // Si hay múltiples mensajes de sistema, concatenarlos
        $systemPromptText = !empty($systemPrompts)
            ? implode("\n\n", $systemPrompts)
            : null;

        $systemPrompt = null;
        if ($systemPromptText !== null) {
            $systemPrompt = [
                [
                    'type' => 'text',
                    'text' => $systemPromptText,
                    'cache_control' => ['type' => 'ephemeral']
                ]
            ];
        }

        // Validar que el primer mensaje no sea del asistente
        if (!empty($filteredMessages) && ($filteredMessages[0]['role'] ?? '') === 'assistant') {
            throw new InvalidArgumentException(
                'ClaudeProvider: El primer mensaje no puede ser del asistente (role: assistant). '
                . 'Debe comenzar con un mensaje de usuario (role: user).'
            );
        }

        return [$systemPrompt, $filteredMessages];
    }

    /**
     * Extrae el texto de la respuesta de la API de Claude.
     *
     * Estructura de respuesta de Claude:
     * ```json
     * {
     *   "id": "msg_...",
     *   "type": "message",
     *   "role": "assistant",
     *   "content": [{"type": "text", "text": "Respuesta aquí..."}],
     *   "stop_reason": "end_turn",
     *   "usage": {"input_tokens": 100, "output_tokens": 50}
     * }
     * ```
     *
     * @param array $response Respuesta decodificada de la API.
     *
     * @return string Texto generado.
     *
     * @throws RuntimeException Si la respuesta no tiene la estructura esperada.
     */
    private function extractContent(array $response): string
    {
        // Claude devuelve un array de bloques de contenido (puede ser texto, código, etc.)
        $contentBlocks = $response['content'] ?? [];

        if (empty($contentBlocks)) {
            $stopReason = $response['stop_reason'] ?? 'unknown';
            throw new RuntimeException(
                "ClaudeProvider: La respuesta no contiene bloques de contenido. "
                . "stop_reason: {$stopReason}"
            );
        }

        // Extraer y concatenar todos los bloques de tipo 'text'
        $textParts = [];

        foreach ($contentBlocks as $block) {
            if (($block['type'] ?? '') === 'text' && isset($block['text'])) {
                $textParts[] = (string) $block['text'];
            }
        }

        if (empty($textParts)) {
            throw new RuntimeException(
                'ClaudeProvider: No se encontraron bloques de texto en la respuesta de la API.'
            );
        }

        return implode('', $textParts);
    }

    /**
     * {@inheritdoc}
     */
    public function isServerAvailable(): bool
    {
        return true;
    }
}
