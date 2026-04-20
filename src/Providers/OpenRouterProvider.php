<?php

declare(strict_types=1);

namespace AiSdk\Providers;

use AiSdk\Contracts\AIProviderInterface;
use AiSdk\Http\HttpClient;
use InvalidArgumentException;
use RuntimeException;

/**
 * OpenRouterProvider
 *
 * Implementación del AIProviderInterface para OpenRouter.
 *
 * OpenRouter es un agregador de modelos de IA que proporciona acceso
 * a cientos de modelos (GPT-4, Claude, Llama, Mistral, etc.) mediante
 * una API compatible con el estándar de OpenAI (formato idéntico).
 *
 * VENTAJAS:
 * - Una sola API Key para acceder a todos los modelos
 * - Fallback automático entre modelos
 * - Precios competitivos y transparentes
 * - Soporte para modelos open-source y propietarios
 *
 * Modelos populares en OpenRouter (formato: proveedor/modelo):
 * - openai/gpt-4o
 * - anthropic/claude-3.5-sonnet
 * - google/gemini-1.5-pro
 * - meta-llama/llama-3.1-405b-instruct
 * - mistralai/mistral-large
 * - deepseek/deepseek-r1
 * - qwen/qwen-2.5-72b-instruct
 *
 * Documentación: https://openrouter.ai/docs
 *
 * @package AiSdk\Providers
 * @version 1.0.0
 */
final class OpenRouterProvider implements AIProviderInterface
{
    /**
     * URL base de la API de OpenRouter (compatible con OpenAI).
     */
    private const API_URL = 'https://openrouter.ai/api/v1/chat/completions';

    /**
     * Modelo por defecto en OpenRouter.
     */
    private const DEFAULT_MODEL = 'openai/gpt-4o';

    /**
     * Temperatura por defecto.
     */
    private const DEFAULT_TEMPERATURE = 0.7;

    /**
     * Máximo de tokens por defecto.
     */
    private const DEFAULT_MAX_TOKENS = 4096;

    /**
     * @var HttpClient Cliente HTTP.
     */
    private HttpClient $httpClient;

    /**
     * @var string API Key de OpenRouter (formato: sk-or-...).
     */
    private string $apiKey;

    /**
     * @var string Modelo a usar en formato 'proveedor/modelo'.
     */
    private string $model;

    /**
     * @var float Temperatura de generación.
     */
    private float $temperature;

    /**
     * @var int Máximo de tokens.
     */
    private int $maxTokens;

    /**
     * @var string Nombre de la aplicación para el header HTTP-Referer.
     *             OpenRouter lo usa para estadísticas y rate limiting.
     */
    private string $appName;

    /**
     * @var string URL de la aplicación para el header HTTP-Referer.
     */
    private string $appUrl;

    /**
     * Constructor del OpenRouterProvider.
     *
     * @param string          $apiKey      API Key de OpenRouter. Obtener en: https://openrouter.ai/settings/keys
     * @param string          $model       Modelo en formato 'proveedor/modelo'. Por defecto: 'openai/gpt-4o'.
     * @param float           $temperature Temperatura de generación.
     * @param int             $maxTokens   Máximo de tokens en la respuesta.
     * @param string          $appName     Nombre de tu aplicación (para headers de OpenRouter).
     * @param string          $appUrl      URL de tu aplicación (para headers de OpenRouter).
     * @param HttpClient|null $httpClient  Cliente HTTP (opcional).
     *
     * @throws InvalidArgumentException Si la API Key está vacía.
     */
    public function __construct(
        string $apiKey,
        string $model = self::DEFAULT_MODEL,
        float $temperature = self::DEFAULT_TEMPERATURE,
        int $maxTokens = self::DEFAULT_MAX_TOKENS,
        string $appName = 'PHP-AI-SDK',
        string $appUrl = 'https://github.com/php-ai-sdk',
        ?HttpClient $httpClient = null
    ) {
        if (trim($apiKey) === '') {
            throw new InvalidArgumentException(
                'OpenRouterProvider: La API Key no puede estar vacía. '
                . 'Obtén una en: https://openrouter.ai/settings/keys'
            );
        }

        $this->apiKey      = trim($apiKey);
        $this->model       = trim($model);
        $this->temperature = $temperature;
        $this->maxTokens   = $maxTokens;
        $this->appName     = $appName;
        $this->appUrl      = $appUrl;
        $this->httpClient  = $httpClient ?? new HttpClient();
    }

    /**
     * {@inheritdoc}
     *
     * OpenRouter es compatible con el formato de la API de OpenAI,
     * por lo que la estructura de la petición es idéntica.
     *
     * Headers adicionales requeridos por OpenRouter:
     * - HTTP-Referer: URL de tu aplicación
     * - X-Title: Nombre de tu aplicación
     *
     * Opcionalmente se puede añadir:
     * - route: para forzar un proveedor específico
     * - transforms: para aplicar transformaciones al prompt
     */
    public function chat(array $messages): string
    {
        if (empty($messages)) {
            throw new InvalidArgumentException(
                'OpenRouterProvider: El array de mensajes no puede estar vacío.'
            );
        }

        $this->validateMessages($messages);

        $headers = [
            'Authorization' => "Bearer {$this->apiKey}",
            'HTTP-Referer'  => $this->appUrl,
            'X-Title'       => $this->appName,
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
        return 'OpenRouter';
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
                'OpenRouterProvider: El nombre del modelo no puede estar vacío. '
                . 'Formato requerido: proveedor/modelo (ej: openai/gpt-4o)'
            );
        }

        $this->model = trim($model);
    }

    /**
     * Extrae el texto de la respuesta de la API de OpenRouter.
     *
     * OpenRouter usa el mismo formato de respuesta que OpenAI.
     *
     * @param array $response Respuesta decodificada.
     *
     * @return string Texto generado.
     *
     * @throws RuntimeException Si la respuesta no tiene la estructura esperada.
     */
    private function extractContent(array $response): string
    {
        // OpenRouter puede incluir información de errores específicos del proveedor
        if (isset($response['error'])) {
            $errorMsg  = $response['error']['message'] ?? 'Error desconocido';
            $errorCode = $response['error']['code']    ?? 'N/A';
            throw new RuntimeException(
                "OpenRouterProvider: Error del proveedor [{$errorCode}]: {$errorMsg}"
            );
        }

        $content = $response['choices'][0]['message']['content'] ?? null;

        if ($content === null) {
            $finishReason = $response['choices'][0]['finish_reason'] ?? 'unknown';
            throw new RuntimeException(
                "OpenRouterProvider: No se pudo extraer contenido de la respuesta. "
                . "finish_reason: {$finishReason}. "
                . "Verifica que el modelo '{$this->model}' esté disponible en OpenRouter."
            );
        }

        return (string) $content;
    }

    /**
     * Valida el formato de los mensajes.
     *
     * @param array $messages Mensajes a validar.
     *
     * @throws InvalidArgumentException Si el formato es incorrecto.
     */
    private function validateMessages(array $messages): void
    {
        foreach ($messages as $index => $message) {
            if (!is_array($message) || !isset($message['role'], $message['content'])) {
                throw new InvalidArgumentException(
                    "OpenRouterProvider: El mensaje en el índice {$index} "
                    . "debe tener 'role' y 'content'."
                );
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isServerAvailable(): bool
    {
        return true;
    }
}
