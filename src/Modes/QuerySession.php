<?php

declare(strict_types=1);

namespace AiSdk\Modes;

use AiSdk\Contracts\AIProviderInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * QuerySession
 *
 * Gestiona una sesión de generación de SQL con contexto de esquema persistente.
 *
 * PROBLEMA QUE RESUELVE:
 * En el modo generador_queries tradicional, cada llamada a generateQuery() enviaba
 * el esquema completo de la base de datos junto con cada consulta. Con esquemas
 * grandes esto representaba un gasto innecesario de tokens en cada petición.
 *
 * SOLUCIÓN:
 * QuerySession carga el esquema UNA SOLA VEZ en el primer turno de la conversación
 * y lo mantiene en el historial acumulado. Las consultas posteriores solo añaden
 * la pregunta en lenguaje natural, sin repetir el esquema.
 *
 * FLUJO:
 * ```
 * [system: prompt SQL]
 * [user:   ESQUEMA... + primera consulta]   ← solo este turno lleva el esquema
 * [assistant: SELECT ...]
 * [user:   segunda consulta]                ← solo la consulta, 0 tokens de esquema extra
 * [assistant: SELECT ...]
 * [user:   tercera consulta]
 * ...
 * ```
 *
 * USO:
 * ```php
 * $session = $client->startQuerySession($esquema);
 * $sql1 = $session->query('Top 10 usuarios por ventas');
 * $sql2 = $session->query('Productos sin stock');
 * $sql3 = $session->query('Pedidos del último mes');
 * ```
 *
 * @package AiSdk\Modes
 * @version 1.0.0
 */
final class QuerySession
{
    /**
     * Proveedor de IA activo al momento de crear la sesión.
     * Se usa para ejecutar cada llamada HTTP.
     *
     * @var AIProviderInterface
     */
    private AIProviderInterface $provider;

    /**
     * Instancia de ChatModes para construir los arrays de mensajes.
     *
     * @var ChatModes
     */
    private ChatModes $chatModes;

    /**
     * Historial acumulado de la sesión.
     *
     * Contiene pares [user, assistant] de todos los turnos completados.
     * El primer elemento user contiene el esquema completo.
     * Los turnos siguientes solo contienen la consulta en lenguaje natural.
     *
     * @var array<int, array{role: string, content: string}>
     */
    private array $history = [];

    /**
     * Esquema de base de datos registrado en esta sesión.
     * Se almacena para inspección y para permitir re-inicializar la sesión.
     *
     * @var string
     */
    private string $databaseSchema;

    /**
     * Contador de consultas ejecutadas en esta sesión.
     * Útil para diagnóstico y para saber cuándo se está en la primera llamada.
     *
     * @var int
     */
    private int $queryCount = 0;

    /**
     * Constructor de QuerySession.
     *
     * No se llama directamente: usar AIClient::startQuerySession() para obtener
     * una instancia correctamente configurada.
     *
     * @param AIProviderInterface $provider      Proveedor de IA a utilizar.
     * @param ChatModes           $chatModes     Instancia del gestor de modos.
     * @param string              $databaseSchema Esquema de la base de datos.
     *
     * @throws InvalidArgumentException Si el esquema está vacío.
     */
    public function __construct(
        AIProviderInterface $provider,
        ChatModes $chatModes,
        string $databaseSchema
    ) {
        if (trim($databaseSchema) === '') {
            throw new InvalidArgumentException(
                'QuerySession: El esquema de la base de datos no puede estar vacío.'
            );
        }

        $this->provider       = $provider;
        $this->chatModes      = $chatModes;
        $this->databaseSchema = trim($databaseSchema);
    }

    /**
     * Ejecuta una consulta SQL en lenguaje natural dentro de la sesión activa.
     *
     * Primera llamada: envía [system] + [esquema + consulta].
     * Llamadas siguientes: envía [system] + [historial] + [consulta], sin repetir el esquema.
     *
     * El historial se actualiza automáticamente con cada par [user → assistant].
     *
     * @param string $naturalLanguageQuery Consulta en lenguaje natural.
     *                                     Ej: "Lista los productos con stock menor a 10"
     *
     * @return string Query SQL limpia y lista para ejecutar.
     *
     * @throws InvalidArgumentException Si la consulta está vacía.
     * @throws RuntimeException         Si la llamada al proveedor falla.
     *
     * @example
     * ```php
     * $session = $client->startQuerySession($schema);
     *
     * $sql = $session->query('Top 5 clientes por volumen de compras en 2024');
     * // SELECT c.id, c.name, SUM(o.total) AS total ...
     *
     * $sql = $session->query('¿Cuáles de esos clientes tienen pedidos pendientes?');
     * // SELECT DISTINCT c.id, c.name FROM ... (sin repetir el esquema)
     * ```
     */
    public function query(string $naturalLanguageQuery): string
    {
        if (trim($naturalLanguageQuery) === '') {
            throw new InvalidArgumentException(
                'QuerySession::query(): La consulta en lenguaje natural no puede estar vacía.'
            );
        }

        // Construir el array de mensajes según si es la primera consulta o una de seguimiento
        if ($this->queryCount === 0) {
            // Primera consulta: incluye el esquema completo en el primer mensaje de usuario
            $messages = $this->chatModes->buildQuerySessionInit(
                databaseSchema: $this->databaseSchema,
                naturalLanguageQuery: trim($naturalLanguageQuery)
            );

            // Guardar el mensaje de usuario (con esquema) en el historial
            $userMessage = end($messages); // el último elemento es el user message
        } else {
            // Consultas de seguimiento: solo la pregunta + historial acumulado
            $messages = $this->chatModes->buildQueryFollowUp(
                sessionHistory: $this->history,
                naturalLanguageQuery: trim($naturalLanguageQuery)
            );

            // El mensaje de usuario es la consulta limpia (sin esquema)
            $userMessage = [
                'role'    => 'user',
                'content' => trim($naturalLanguageQuery),
            ];
        }

        // Ejecutar la llamada al proveedor
        $rawResult = $this->provider->chat($messages);

        // Limpiar posibles bloques de código markdown de la respuesta
        $cleanedSql = $this->cleanSqlOutput($rawResult);

        // Acumular el turno en el historial [user + assistant]
        $this->history[] = $userMessage;
        $this->history[] = [
            'role'    => 'assistant',
            'content' => $cleanedSql,
        ];

        $this->queryCount++;

        return $cleanedSql;
    }

    /**
     * Devuelve el número de consultas ejecutadas en esta sesión.
     *
     * @return int Número de llamadas query() realizadas exitosamente.
     */
    public function getQueryCount(): int
    {
        return $this->queryCount;
    }

    /**
     * Devuelve el esquema de base de datos registrado en esta sesión.
     *
     * @return string Esquema tal como fue proporcionado al iniciar la sesión.
     */
    public function getSchema(): string
    {
        return $this->databaseSchema;
    }

    /**
     * Devuelve una copia del historial acumulado de la sesión.
     *
     * El historial contiene pares [user, assistant] de todos los turnos.
     * El primer turno de usuario incluye el esquema; los siguientes, solo la consulta.
     *
     * @return array<int, array{role: string, content: string}> Historial de la sesión.
     */
    public function getHistory(): array
    {
        return $this->history;
    }

    /**
     * Resetea el historial de la sesión manteniendo el esquema cargado.
     *
     * Útil para comenzar una nueva serie de consultas con el mismo esquema
     * sin tener que recrear la sesión completa.
     *
     * @return self Para encadenamiento de métodos (fluent interface).
     */
    public function resetHistory(): self
    {
        $this->history    = [];
        $this->queryCount = 0;

        return $this;
    }

    /**
     * Calcula una estimación del ahorro de tokens acumulado en la sesión.
     *
     * Asume aproximadamente 4 caracteres por token (estimación estándar para inglés/español).
     * El ahorro se calcula como: tokens_esquema × (número_de_consultas - 1),
     * ya que el esquema se habría repetido en cada consulta con el enfoque antiguo.
     *
     * @return array{schema_tokens: int, queries_executed: int, tokens_saved: int, savings_pct: float}
     *         Estadísticas de ahorro de tokens de la sesión.
     */
    public function getTokenSavingsEstimate(): array
    {
        // Estimación: 1 token ≈ 4 caracteres
        $schemaTokens   = (int) ceil(mb_strlen($this->databaseSchema) / 4);
        $queriesAbove1  = max(0, $this->queryCount - 1);
        $tokensSaved    = $schemaTokens * $queriesAbove1;

        // Tokens totales sin sesión (esquema repetido en cada llamada)
        $totalWithoutSession = $schemaTokens * $this->queryCount;
        $savingsPct = $totalWithoutSession > 0
            ? round(($tokensSaved / $totalWithoutSession) * 100, 1)
            : 0.0;

        return [
            'schema_tokens'    => $schemaTokens,
            'queries_executed' => $this->queryCount,
            'tokens_saved'     => $tokensSaved,
            'savings_pct'      => $savingsPct,
        ];
    }

    // ─────────────────────────────────────────────────────────
    // Métodos privados auxiliares
    // ─────────────────────────────────────────────────────────

    /**
     * Elimina bloques de código markdown de la salida SQL del modelo.
     *
     * Algunos modelos añaden ```sql ... ``` aunque el prompt lo prohíba.
     *
     * @param string $output Salida cruda del modelo de IA.
     *
     * @return string SQL limpio sin decoradores markdown.
     */
    private function cleanSqlOutput(string $output): string
    {
        $cleaned = preg_replace('/^```(?:sql|SQL)?\s*/m', '', $output);
        $cleaned = preg_replace('/\s*```\s*$/m', '', $cleaned ?? $output);

        return trim($cleaned ?? $output);
    }
}