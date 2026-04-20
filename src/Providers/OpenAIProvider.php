<?php

declare(strict_types=1);

namespace AiSdk\Providers;

use AiSdk\Contracts\AIProviderInterface;
use AiSdk\Http\HttpClient;
use InvalidArgumentException;
use RuntimeException;

/**
 * OpenAIProvider
 *
 * Implementación del AIProviderInterface para la API de OpenAI.
 *
 * Soporta todos los modelos de la familia GPT disponibles en la
 * API de Chat Completions de OpenAI:
 * - gpt-4o (recomendado, mejor relación calidad/precio)
 * - gpt-4o-mini
 * - gpt-4-turbo
 * - gpt-4
 * - gpt-3.5-turbo
 *
 * Documentación de la API:
 * https://platform.openai.com/docs/api-reference/chat
 *
 * @package AiSdk\Providers
 * @version 1.0.0
 */
final class OpenAIProvider implements AIProviderInterface
{
    /**
     * URL base de la API de Chat Completions de OpenAI.
     */
    private const API_URL = 'https://api.openai.com/v1/chat/completions';

    /**
     * Modelo por defecto si no se especifica ninguno.
     */
    private const DEFAULT_MODEL = 'gpt-4o';

    /**
     * Temperatura por defecto para la generación de texto.
     * 0.7 proporciona un buen balance entre creatividad y coherencia.
     */
    private const DEFAULT_TEMPERATURE = 0.7;

    /**
     * Número máximo de tokens en la respuesta por defecto.
     */
    private const DEFAULT_MAX_TOKENS = 4096;

    /**
     * @var HttpClient Cliente HTTP para realizar las peticiones.
     */
    private HttpClient $httpClient;

    /**
     * @var string API Key de OpenAI (formato: sk-...).
     */
    private string $apiKey;

    /**
     * @var string Modelo de OpenAI a usar.
     */
    private string $model;

    /**
     * @var float Temperatura para la generación (0.0 - 2.0).
     */
    private float $temperature;

    /**
     * @var int Número máximo de tokens en la respuesta.
     */
    private int $maxTokens;

    /**
     * Constructor del OpenAIProvider.
     *
     * @param string     $apiKey      API Key de OpenAI. Obtener en: https://platform.openai.com/api-keys
     * @param string     $model       Modelo a usar. Por defecto: 'gpt-4o'.
     * @param float      $temperature Temperatura de generación (0.0 = determinista, 2.0 = muy creativo).
     * @param int        $maxTokens   Máximo de tokens en la respuesta.
     * @param HttpClient|null $httpClient  Instancia de HttpClient (opcional, para testing/inyección).
     *
     * @throws InvalidArgumentException Si la API Key está vacía o el modelo no es válido.
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
                'OpenAIProvider: La API Key no puede estar vacía. '
                . 'Obtén una en: https://platform.openai.com/api-keys'
            );
        }

        if (trim($model) === '') {
            throw new InvalidArgumentException(
                'OpenAIProvider: El nombre del modelo no puede estar vacío.'
            );
        }

        if ($temperature < 0.0 || $temperature > 2.0) {
            throw new InvalidArgumentException(
                "OpenAIProvider: La temperatura debe estar entre 0.0 y 2.0. Recibido: {$temperature}"
            );
        }

        if ($maxTokens < 1) {
            throw new InvalidArgumentException(
                "OpenAIProvider: maxTokens debe ser al menos 1. Recibido: {$maxTokens}"
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
     * Realiza una petición a la API de Chat Completions de OpenAI.
     *
     * Estructura de la petición:
     * ```json
     * {
     *   "model": "gpt-4o",
     *   "messages": [{"role": "system", "content": "..."}, ...],
     *   "temperature": 0.7,
     *   "max_tokens": 4096
     * }
     * ```
     *
     * Estructura de la respuesta:
     * ```json
     * {
     *   "choices": [{
     *     "message": {"role": "assistant", "content": "..."},
     *     "finish_reason": "stop"
     *   }],
     *   "usage": {"prompt_tokens": 100, "completion_tokens": 50}
     * }
     * ```
     */
    public function chat(array $messages): string
    {
        $this->validateMessages($messages);

        $headers = [
            'Authorization' => "Bearer {$this->apiKey}",
        ];

        $body = [
            'model'       => $this->model,
            'messages'    => $messages,
            'temperature' => $this->temperature,
            'max_tokens'  => $this->maxTokens,
        ];

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
        return 'OpenAI';
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
                'OpenAIProvider: El nombre del modelo no puede estar vacío.'
            );
        }

        $this->model = trim($model);
    }

    /**
     * Extrae el texto de la respuesta de la API de OpenAI.
     *
     * @param array $response Respuesta decodificada de la API.
     *
     * @return string Texto generado por el modelo.
     *
     * @throws RuntimeException Si la respuesta no tiene la estructura esperada.
     */
    private function extractContent(array $response): string
    {
        $content = $response['choices'][0]['message']['content'] ?? null;

        if ($content === null) {
            $finishReason = $response['choices'][0]['finish_reason'] ?? 'unknown';
            throw new RuntimeException(
                "OpenAIProvider: No se pudo extraer contenido de la respuesta. "
                . "finish_reason: {$finishReason}"
            );
        }

        return (string) $content;
    }

    /**
     * Valida que el array de mensajes tenga el formato correcto.
     *
     * @param array $messages Mensajes a validar.
     *
     * @throws InvalidArgumentException Si el formato es incorrecto.
     */
    private function validateMessages(array $messages): void
    {
        if (empty($messages)) {
            throw new InvalidArgumentException(
                'OpenAIProvider: El array de mensajes no puede estar vacío.'
            );
        }

        foreach ($messages as $index => $message) {
            if (!is_array($message) || !isset($message['role'], $message['content'])) {
                throw new InvalidArgumentException(
                    "OpenAIProvider: El mensaje en el índice {$index} debe tener 'role' y 'content'."
                );
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isServerAvailable(): bool
    {
        // En proveedores cloud, asumimos disponibilidad si hay red.
        // Podría mejorarse haciendo una petición 'ping' a la API.
        return true;
    }
}
