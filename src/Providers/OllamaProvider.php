<?php

declare(strict_types=1);

namespace AiSdk\Providers;

use AiSdk\Contracts\AIProviderInterface;
use AiSdk\Http\HttpClient;
use InvalidArgumentException;
use RuntimeException;

/**
 * OllamaProvider
 *
 * Implementación del AIProviderInterface para Ollama.
 *
 * Ollama permite ejecutar modelos de IA de forma local o en servidores
 * propios, sin necesidad de API Keys externas. Este provider soporta:
 *
 * MODO LOCAL:
 * - URL por defecto: http://localhost:11434
 * - Sin autenticación requerida
 * - Modelos locales instalados con 'ollama pull modelo'
 *
 * MODO CLOUD/REMOTO:
 * - URL personalizable para servidores remotos
 * - Soporte opcional de API Key para instancias protegidas
 * - Compatible con Ollama Cloud y despliegues en VPS
 *
 * Modelos populares disponibles en Ollama:
 * - llama3.2          (Meta, 3B/11B parámetros)
 * - llama3.1          (Meta, 8B/70B/405B)
 * - mistral           (Mistral AI, 7B)
 * - mixtral           (Mistral AI, 8x7B MoE)
 * - gemma2            (Google, 9B/27B)
 * - phi3.5            (Microsoft, 3.8B)
 * - qwen2.5           (Alibaba, múltiples tamaños)
 * - deepseek-r1       (DeepSeek, razonamiento)
 * - codellama         (Meta, especializado en código)
 *
 * Documentación: https://ollama.com/blog/openai-compatibility
 *
 * @package AiSdk\Providers
 * @version 1.0.0
 */
final class OllamaProvider implements AIProviderInterface
{
    /**
     * URL base por defecto para Ollama en modo local.
     */
    private const DEFAULT_BASE_URL = 'http://localhost:11434';

    /**
     * Endpoint de la API de chat de Ollama (compatible con OpenAI).
     */
    private const CHAT_ENDPOINT = '/api/chat';

    /**
     * Modelo por defecto.
     */
    private const DEFAULT_MODEL = 'llama3.2';

    /**
     * Temperatura por defecto.
     */
    private const DEFAULT_TEMPERATURE = 0.7;

    /**
     * @var HttpClient Cliente HTTP.
     */
    private HttpClient $httpClient;

    /**
     * @var string Modelo de Ollama a usar.
     */
    private string $model;

    /**
     * @var string URL base del servidor Ollama.
     */
    private string $baseUrl;

    /**
     * @var string|null API Key opcional para instancias de Ollama protegidas.
     */
    private ?string $apiKey;

    /**
     * @var float Temperatura de generación.
     */
    private float $temperature;

    /**
     * @var bool Si es true, espera la respuesta completa (no streaming).
     */
    private bool $stream;

    /**
     * Constructor del OllamaProvider.
     *
     * @param string          $model      Nombre del modelo instalado en Ollama.
     *                                    Para listarlo: ollama list
     * @param string          $baseUrl    URL base del servidor Ollama.
     *                                    Por defecto: 'http://localhost:11434'
     * @param string|null     $apiKey     API Key opcional para instancias protegidas.
     *                                    La mayoría de instancias locales no la requieren.
     * @param float           $temperature Temperatura de generación (0.0 - 2.0).
     * @param bool            $stream     Si true, solicita streaming (debe ser false para este SDK).
     * @param HttpClient|null $httpClient Cliente HTTP (opcional).
     *
     * @throws InvalidArgumentException Si el modelo está vacío o la URL es inválida.
     */
    public function __construct(
        string $model = self::DEFAULT_MODEL,
        string $baseUrl = self::DEFAULT_BASE_URL,
        ?string $apiKey = null,
        float $temperature = self::DEFAULT_TEMPERATURE,
        bool $stream = false,
        ?HttpClient $httpClient = null
    ) {
        if (trim($model) === '') {
            throw new InvalidArgumentException(
                'OllamaProvider: El nombre del modelo no puede estar vacío. '
                . 'Verifica los modelos disponibles con: ollama list'
            );
        }

        $cleanBaseUrl = rtrim(trim($baseUrl), '/');

        if (empty($cleanBaseUrl)) {
            throw new InvalidArgumentException(
                'OllamaProvider: La URL base no puede estar vacía.'
            );
        }

        $this->model       = trim($model);
        $this->baseUrl     = $cleanBaseUrl;
        $this->apiKey      = $apiKey !== null ? trim($apiKey) : null;
        $this->temperature = $temperature;
        $this->stream      = $stream;
        $this->httpClient  = $httpClient ?? new HttpClient();
    }

    /**
     * {@inheritdoc}
     *
     * Ollama soporta la API de chat compatible con OpenAI en:
     * POST /api/chat
     *
     * Estructura de la petición:
     * ```json
     * {
     *   "model": "llama3.2",
     *   "messages": [
     *     {"role": "system", "content": "..."},
     *     {"role": "user",   "content": "..."}
     *   ],
     *   "stream": false,
     *   "options": {
     *     "temperature": 0.7
     *   }
     * }
     * ```
     *
     * Estructura de la respuesta (stream: false):
     * ```json
     * {
     *   "model": "llama3.2",
     *   "message": {
     *     "role": "assistant",
     *     "content": "Respuesta aquí..."
     *   },
     *   "done": true,
     *   "done_reason": "stop"
     * }
     * ```
     */
    public function chat(array $messages): string
    {
        if (empty($messages)) {
            throw new InvalidArgumentException(
                'OllamaProvider: El array de mensajes no puede estar vacío.'
            );
        }

        $this->validateMessages($messages);

        $url = $this->baseUrl . self::CHAT_ENDPOINT;

        // Construir headers
        $headers = [];
        if ($this->apiKey !== null && $this->apiKey !== '') {
            $headers['Authorization'] = "Bearer {$this->apiKey}";
        }

        $body = [
            'model'    => $this->model,
            'messages' => $messages,
            'stream'   => false, // Siempre false para este SDK (sin streaming)
            'options'  => [
                'temperature' => $this->temperature,
            ],
        ];

        $response = $this->httpClient->post(
            url: $url,
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
        return 'Ollama';
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
                'OllamaProvider: El nombre del modelo no puede estar vacío. '
                . 'Verifica los modelos con: ollama list'
            );
        }

        $this->model = trim($model);
    }

    /**
     * Cambia la URL base del servidor Ollama.
     *
     * Útil para cambiar entre instancias local y remota.
     *
     * @param string $baseUrl Nueva URL base del servidor.
     *
     * @return self Para encadenamiento de métodos.
     */
    public function setBaseUrl(string $baseUrl): self
    {
        $this->baseUrl = rtrim(trim($baseUrl), '/');
        return $this;
    }

    /**
     * Establece la API Key para instancias protegidas.
     *
     * @param string|null $apiKey API Key o null para desactivar autenticación.
     *
     * @return self Para encadenamiento de métodos.
     */
    public function setApiKey(?string $apiKey): self
    {
        $this->apiKey = $apiKey !== null ? trim($apiKey) : null;
        return $this;
    }

    /**
     * Devuelve la URL base configurada del servidor Ollama.
     *
     * @return string URL base del servidor.
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Verifica si el servidor Ollama está disponible.
     *
     * Realiza una petición GET al endpoint de health check.
     *
     * @return bool True si el servidor está disponible y respondiendo.
     */
    public function isServerAvailable(): bool
    {
        try {
            $headers = [];
            if ($this->apiKey !== null) {
                $headers['Authorization'] = "Bearer {$this->apiKey}";
            }

            // Ollama expone el listado de modelos en /api/tags
            $response = $this->httpClient->get($this->baseUrl . '/api/tags', $headers);
            return isset($response['models']);
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * Extrae el texto de la respuesta de la API de Ollama.
     *
     * @param array $response Respuesta decodificada de Ollama.
     *
     * @return string Texto generado por el modelo.
     *
     * @throws RuntimeException Si la respuesta no tiene la estructura esperada.
     */
    private function extractContent(array $response): string
    {
        // Verificar si el modelo completó la generación
        if (isset($response['done']) && $response['done'] === false) {
            throw new RuntimeException(
                'OllamaProvider: La respuesta no está completa (done: false). '
                . 'El modelo puede estar en estado de streaming inesperado.'
            );
        }

        $content = $response['message']['content'] ?? null;

        if ($content === null) {
            $doneReason = $response['done_reason'] ?? 'unknown';
            throw new RuntimeException(
                "OllamaProvider: No se pudo extraer contenido de la respuesta. "
                . "done_reason: {$doneReason}. "
                . "Verifica que el modelo '{$this->model}' esté instalado: ollama pull {$this->model}"
            );
        }

        return (string) $content;
    }

    /**
     * Valida el formato básico del array de mensajes.
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
                    "OllamaProvider: El mensaje en el índice {$index} "
                    . "debe tener 'role' y 'content'."
                );
            }
        }
    }
}
