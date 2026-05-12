<?php

declare(strict_types=1);

namespace AiSdk\Providers;

use AiSdk\Contracts\AIProviderInterface;
use AiSdk\Http\HttpClient;
use InvalidArgumentException;
use RuntimeException;

/**
 * NvidiaProvider
 *
 * Implementación del AIProviderInterface para la API de Nvidia NIM.
 *
 * Soporta modelos alojados por Nvidia compatibles con la estructura OpenAI:
 * - meta/llama-3.1-70b-instruct (por defecto)
 * - meta/llama-3.1-405b-instruct
 * - mistralai/mixtral-8x22b-instruct-v0.1
 * - y muchos otros disponibles en build.nvidia.com
 *
 * Documentación de la API:
 * https://build.nvidia.com/
 *
 * @package AiSdk\Providers
 * @version 1.0.0
 */
final class NvidiaProvider implements AIProviderInterface
{
    /**
     * URL base de la API de Chat Completions de Nvidia.
     */
    private const API_URL = 'https://integrate.api.nvidia.com/v1/chat/completions';

    /**
     * Modelo por defecto si no se especifica ninguno.
     */
    private const DEFAULT_MODEL = 'meta/llama-3.1-70b-instruct';

    /**
     * Temperatura por defecto para la generación de texto.
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
     * @var string API Key de Nvidia (formato: nvapi-...).
     */
    private string $apiKey;

    /**
     * @var string Modelo de Nvidia a usar.
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
     * Constructor del NvidiaProvider.
     *
     * @param string          $apiKey      API Key de Nvidia. Obtener en: https://build.nvidia.com/
     * @param string          $model       Modelo a usar. Por defecto: 'meta/llama-3.1-70b-instruct'.
     * @param float           $temperature Temperatura de generación (0.0 = determinista, 2.0 = muy creativo).
     * @param int             $maxTokens   Máximo de tokens en la respuesta.
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
                'NvidiaProvider: La API Key no puede estar vacía. '
                . 'Obtén una en: https://build.nvidia.com/'
            );
        }

        if (trim($model) === '') {
            throw new InvalidArgumentException(
                'NvidiaProvider: El nombre del modelo no puede estar vacío.'
            );
        }

        if ($temperature < 0.0 || $temperature > 2.0) {
            throw new InvalidArgumentException(
                "NvidiaProvider: La temperatura debe estar entre 0.0 y 2.0. Recibido: {$temperature}"
            );
        }

        if ($maxTokens < 1) {
            throw new InvalidArgumentException(
                "NvidiaProvider: maxTokens debe ser al menos 1. Recibido: {$maxTokens}"
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
     * Realiza una petición a la API de Chat Completions de Nvidia NIM.
     *
     * Estructura de la petición:
     * ```json
     * {
     *   "model": "meta/llama-3.1-70b-instruct",
     *   "messages": [{"role": "system", "content": "..."}, ...],
     *   "temperature": 0.7,
     *   "max_tokens": 4096
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
        return 'Nvidia';
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
                'NvidiaProvider: El nombre del modelo no puede estar vacío.'
            );
        }

        $this->model = trim($model);
    }

    /**
     * Extrae el texto de la respuesta de la API de Nvidia.
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
                "NvidiaProvider: No se pudo extraer contenido de la respuesta. "
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
                'NvidiaProvider: El array de mensajes no puede estar vacío.'
            );
        }

        foreach ($messages as $index => $message) {
            if (!is_array($message) || !isset($message['role'], $message['content'])) {
                throw new InvalidArgumentException(
                    "NvidiaProvider: El mensaje en el índice {$index} debe tener 'role' y 'content'."
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
        return true;
    }
}
