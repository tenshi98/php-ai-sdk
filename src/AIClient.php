<?php

declare(strict_types=1);

namespace AiSdk;

use AiSdk\Contracts\AIProviderInterface;
use AiSdk\Modes\ChatModes;
use AiSdk\Modes\QuerySession;
use InvalidArgumentException;
use RuntimeException;

/**
 * AIClient
 *
 * Facade principal (punto de entrada) del SDK de IA.
 *
 * Actúa como orquestador entre:
 * - Los proveedores de IA (OpenAI, Gemini, Claude, etc.)
 * - Los modos preconfigurados (chat_general, generador_queries, etc.)
 *
 * El cliente permite cambiar de proveedor en caliente sin modificar
 * el código consumidor, siguiendo el Principio Abierto/Cerrado (OCP).
 *
 * USO BÁSICO:
 * ```php
 * $provider = new OpenAIProvider(apiKey: 'sk-...', model: 'gpt-4o');
 * $client   = new AIClient(provider: $provider);
 *
 * $response = $client->chatGeneral('¿Cuál es la capital de Francia?');
 * ```
 *
 * @package AiSdk
 * @version 1.0.0
 */
final class AIClient
{
    /**
     * Proveedor de IA actualmente configurado.
     * Implementa AIProviderInterface para garantizar la intercambiabilidad.
     *
     * @var AIProviderInterface
     */
    private AIProviderInterface $provider;

    /**
     * Gestor de modos preconfigurados.
     * Encargado de construir los prompts especializados.
     *
     * @var ChatModes
     */
    private ChatModes $chatModes;

    /**
     * Constructor del AIClient.
     *
     * @param AIProviderInterface $provider  Proveedor de IA a usar. Puede cambiarse
     *                                       después con setProvider().
     * @param ChatModes|null      $chatModes Instancia de ChatModes. Si es null,
     *                                       se crea una instancia por defecto.
     */
    public function __construct(
        AIProviderInterface $provider,
        ?ChatModes $chatModes = null
    ) {
        $this->provider = $provider;
        $this->chatModes = $chatModes ?? new ChatModes();
    }

    // ─────────────────────────────────────────────────────────
    // Configuración de Identidad
    // ─────────────────────────────────────────────────────────

    /**
     * Establece el nombre con el que la IA se identificará.
     *
     * @param string $name Nombre (ej: 'Asistente', 'Jarvis').
     * @return self
     */
    public function setAiName(string $name): self
    {
        $this->chatModes->setAiName($name);
        return $this;
    }

    /**
     * Establece el tono de comunicación de la IA.
     *
     * @param string $tone Tono (ej: 'formal', 'amigable', 'serio').
     * @return self
     */
    public function setTone(string $tone): self
    {
        $this->chatModes->setTone($tone);
        return $this;
    }

    // ─────────────────────────────────────────────────────────
    // Modo 1: Chat General
    // ─────────────────────────────────────────────────────────

    /**
     * Envía una consulta en modo chat general.
     *
     * Usa el system prompt optimizado para respuestas generales,
     * informativas y conversacionales.
     *
     * @param string $message Mensaje o pregunta del usuario.
     *
     * @return string Respuesta generada por el modelo de IA.
     *
     * @throws InvalidArgumentException Si el mensaje está vacío.
     * @throws RuntimeException         Si la llamada al proveedor falla.
     *
     * @example
     * ```php
     * $response = $client->chatGeneral('Explícame qué es la programación orientada a objetos');
     * echo $response;
     * ```
     */
    public function chatGeneral(string $message): string
    {
        $messages = $this->chatModes->buildChatGeneral($message);
        return $this->provider->chat($messages);
    }

    /**
     * Envía una conversación completa con historial en modo chat general.
     *
     * Permite mantener el contexto de conversaciones multi-turno.
     * El array de historial debe seguir el formato estándar del SDK.
     *
     * @param string                                            $message Último mensaje del usuario.
     * @param array $history Historial previo de la conversación.
     *                                                                   Cada elemento debe tener 'role' y 'content'.
     *
     * @return string Respuesta generada por el modelo de IA.
     *
     * @throws InvalidArgumentException Si el mensaje o el historial son inválidos.
     * @throws RuntimeException         Si la llamada al proveedor falla.
     *
     * @example
     * ```php
     * $history = [
     *     ['role' => 'user',      'content' => '¿Qué es PHP?'],
     *     ['role' => 'assistant', 'content' => 'PHP es un lenguaje de scripting...'],
     * ];
     * $response = $client->chatWithHistory('¿Y cuáles son sus ventajas?', $history);
     * ```
     */
    public function chatWithHistory(string $message, array $history = []): string
    {
        $systemMessages = $this->chatModes->buildChatGeneral($message);
        $systemPrompt   = $systemMessages[0]; // Extrae el system prompt

        // Construir array final: [system] + [historial] + [mensaje actual]
        $messages = array_merge(
            [$systemPrompt],
            $history,
            [['role' => 'user', 'content' => trim($message)]]
        );

        return $this->provider->chat($messages);
    }

    // ─────────────────────────────────────────────────────────
    // Modo 2: Generador de Queries SQL
    // ─────────────────────────────────────────────────────────

    /**
     * Inicia una sesión de generación de queries SQL con contexto de esquema persistente.
     *
     * Este es el método RECOMENDADO para el modo generador_queries cuando se necesitan
     * múltiples consultas sobre el mismo esquema de base de datos.
     *
     * El esquema se registra UNA SOLA VEZ en la sesión. Las llamadas posteriores a
     * QuerySession::query() no reenvían el esquema, ahorrando tokens en cada petición.
     *
     * AHORRO DE TOKENS:
     * - Sin sesión: cada query envía esquema (~800tk) + consulta (~20tk) = ~820 tokens/llamada
     * - Con sesión: primera query ~820tk, siguientes solo ~20tk + historial acumulado
     *
     * @param string $databaseSchema Esquema de la base de datos. Formatos aceptados:
     *                               - DDL SQL: "CREATE TABLE users (id INT, ...)"
     *                               - Descripción textual: "users(id, name), orders(id, user_id)"
     *                               - JSON Schema
     *
     * @return QuerySession Sesión activa lista para recibir consultas.
     *
     * @throws InvalidArgumentException Si el esquema está vacío.
     *
     * @example
     * ```php
     * $session = $client->startQuerySession($esquema);
     *
     * // El esquema solo viaja en esta primera llamada
     * $sql1 = $session->query('Top 10 productos más vendidos');
     *
     * // Esta llamada NO reenvía el esquema (ahorro de tokens)
     * $sql2 = $session->query('Clientes con más de 5 pedidos en 2024');
     *
     * // Estadísticas de ahorro
     * $stats = $session->getTokenSavingsEstimate();
     * echo "Tokens ahorrados: {$stats['tokens_saved']}";
     * ```
     */
    public function startQuerySession(string $databaseSchema): QuerySession
    {
        return new QuerySession(
            provider:       $this->provider,
            chatModes:      $this->chatModes,
            databaseSchema: $databaseSchema
        );
    }

    /**
     * Genera una query SQL puntual (consulta única, sin sesión persistente).
     *
     * Usa un system prompt especializado en SQL. Envía el esquema completo en cada
     * llamada. Para múltiples consultas sobre el mismo esquema, usar startQuerySession()
     * en su lugar para ahorrar tokens.
     *
     * @param string $databaseSchema       Esquema de la base de datos. Formatos aceptados:
     *                                     - DDL SQL: "CREATE TABLE users (id INT PRIMARY KEY, ...)"
     *                                     - Descripción textual: "users(id, name, email), orders(id, user_id, total)"
     *                                     - JSON Schema
     * @param string $naturalLanguageQuery Descripción de lo que se necesita obtener.
     *                                     Ej: "Lista los 10 usuarios con más pedidos en 2024"
     *
     * @return string Query SQL lista para ejecutar (sin bloques de código markdown).
     *
     * @throws InvalidArgumentException Si alguno de los parámetros está vacío.
     * @throws RuntimeException         Si la llamada al proveedor falla.
     *
     * @see startQuerySession() Para múltiples consultas sobre el mismo esquema (ahorra tokens).
     *
     * @example
     * ```php
     * // Consulta puntual (una sola vez)
     * $sql = $client->generateQuery(
     *     'users(id, name, email), orders(id, user_id, total)',
     *     'Lista los 10 usuarios con más pedidos'
     * );
     * ```
     */
    public function generateQuery(string $databaseSchema, string $naturalLanguageQuery): string
    {
        if (trim($databaseSchema) === '') {
            throw new \InvalidArgumentException(
                'AIClient::generateQuery(): El esquema de la base de datos no puede estar vacío.'
            );
        }

        if (trim($naturalLanguageQuery) === '') {
            throw new \InvalidArgumentException(
                'AIClient::generateQuery(): La consulta en lenguaje natural no puede estar vacía.'
            );
        }

        // Para consultas únicas, iniciar una sesión temporal y ejecutar directamente
        $session = $this->startQuerySession($databaseSchema);
        return $session->query($naturalLanguageQuery);
    }

    // ─────────────────────────────────────────────────────────
    // Modo 3: Generador de Tablas y Gráficos
    // ─────────────────────────────────────────────────────────

    /**
     * Genera una tabla HTML a partir de datos estructurados.
     *
     * @param array|string $data  Datos a representar. Array PHP o JSON string.
     * @param string              $title Título de la tabla.
     *
     * @return string HTML del elemento <table> completo.
     *
     * @throws InvalidArgumentException Si los datos son inválidos o están vacíos.
     * @throws RuntimeException         Si la llamada al proveedor falla.
     *
     * @example
     * ```php
     * $data = [
     *     ['producto' => 'Laptop', 'precio' => 999.99, 'stock' => 45],
     *     ['producto' => 'Mouse',  'precio' => 29.99,  'stock' => 200],
     * ];
     * $html = $client->generateTable($data, 'Inventario de Productos');
     * echo $html; // <table class="ai-table">...</table>
     * ```
     */
    public function generateTable(array|string $data, string $title = 'Tabla de Datos'): string
    {
        $messages = $this->chatModes->buildGeneradorTablasGraficos(
            data: $data,
            outputType: ChatModes::OUTPUT_TABLE,
            title: $title
        );

        return $this->provider->chat($messages);
    }

    /**
     * Genera la configuración JSON de un gráfico Chart.js a partir de datos.
     *
     * La configuración devuelta puede usarse directamente con:
     * ```javascript
     * const config = JSON.parse(phpResponse);
     * new Chart(ctx, config);
     * ```
     *
     * @param array|string $data  Datos a graficar.
     * @param string              $title Título del gráfico.
     *
     * @return string Configuración JSON de Chart.js.
     *
     * @throws InvalidArgumentException Si los datos son inválidos.
     * @throws RuntimeException         Si la llamada al proveedor falla.
     *
     * @example
     * ```php
     * $ventas = [
     *     ['mes' => 'Enero',   'ventas' => 1200],
     *     ['mes' => 'Febrero', 'ventas' => 1850],
     *     ['mes' => 'Marzo',   'ventas' => 2100],
     * ];
     * $chartConfig = $client->generateChart($ventas, 'Ventas Mensuales 2024');
     * ```
     */
    public function generateChart(array|string $data, string $title = 'Gráfico de Datos'): string
    {
        $messages = $this->chatModes->buildGeneradorTablasGraficos(
            data: $data,
            outputType: ChatModes::OUTPUT_CHART,
            title: $title
        );

        return $this->provider->chat($messages);
    }

    /**
     * Genera tanto una tabla HTML como la configuración de un gráfico Chart.js.
     *
     * El resultado puede separarse usando el delimitador '---CHART_CONFIG---'.
     *
     * @param array|string $data  Datos a visualizar.
     * @param string              $title Título para ambas visualizaciones.
     *
     * @return array{table: string, chart: string} Array con 'table' (HTML) y 'chart' (JSON).
     *
     * @throws InvalidArgumentException Si los datos son inválidos.
     * @throws RuntimeException         Si la llamada al proveedor falla.
     *
     * @example
     * ```php
     * $result = $client->generateTableAndChart($ventas, 'Reporte de Ventas');
     * echo $result['table'];  // HTML de la tabla
     * echo $result['chart'];  // JSON de Chart.js
     * ```
     */
    public function generateTableAndChart(array|string $data, string $title = 'Visualización'): array
    {
        $messages = $this->chatModes->buildGeneradorTablasGraficos(
            data: $data,
            outputType: ChatModes::OUTPUT_BOTH,
            title: $title
        );

        $response = $this->provider->chat($messages);

        // Separar tabla y configuración de gráfico usando el delimitador
        $parts = explode('---CHART_CONFIG---', $response, 2);

        return [
            'table' => trim($parts[0] ?? ''),
            'chart' => trim($parts[1] ?? ''),
        ];
    }

    // ─────────────────────────────────────────────────────────
    // Nuevos Modos de Procesamiento y Comunicación
    // ─────────────────────────────────────────────────────────

    /**
     * Resume un texto con el nivel de detalle especificado.
     *
     * @param string $text Texto a resumir.
     * @param string $detailLevel Nivel de detalle ('breve', 'medio', 'completo').
     * @return string Resumen en Markdown.
     */
    public function summarizeText(string $text, string $detailLevel = 'medio'): string
    {
        $messages = $this->chatModes->buildResumidor($text, $detailLevel);
        return $this->provider->chat($messages);
    }

    /**
     * Extrae información estructurada de un texto libre basado en un esquema.
     *
     * @param string $text Texto origen.
     * @param array|string $schema Esquema esperado (array PHP que se convierte a JSON o string).
     * @return string JSON extraído.
     */
    public function extractData(string $text, array|string $schema): string
    {
        $messages = $this->chatModes->buildExtractorDatos($text, $schema);
        return $this->provider->chat($messages);
    }

    /**
     * Redacta un correo electrónico corporativo.
     *
     * @param string $context Contexto o razón del correo.
     * @param array $keyPoints Lista de puntos clave a incluir.
     * @return string Correo redactado.
     */
    public function draftEmail(string $context, array $keyPoints): string
    {
        $messages = $this->chatModes->buildRedactorEmail($context, $keyPoints);
        return $this->provider->chat($messages);
    }

    /**
     * Traduce un texto al idioma especificado.
     *
     * @param string $text Texto original.
     * @param string $targetLanguage Idioma destino.
     * @param string $glossary Opcional. Glosario o indicaciones terminológicas.
     * @return string Texto traducido.
     */
    public function translateText(string $text, string $targetLanguage, string $glossary = ''): string
    {
        $messages = $this->chatModes->buildTraductor($text, $targetLanguage, $glossary);
        return $this->provider->chat($messages);
    }

    /**
     * Asistente de soporte técnico utilizando una base de conocimiento.
     *
     * @param string $knowledgeBase Texto base de la que la IA debe extraer respuestas.
     * @param string $userQuestion Pregunta del usuario.
     * @param array $history Historial de la conversación.
     * @return string Respuesta de soporte.
     */
    public function supportChat(string $knowledgeBase, string $userQuestion, array $history = []): string
    {
        $messages = $this->chatModes->buildAsistenteSoporte($knowledgeBase, $userQuestion, $history);
        return $this->provider->chat($messages);
    }

    // ─────────────────────────────────────────────────────────
    // Método raw: para casos de uso personalizados
    // ─────────────────────────────────────────────────────────

    /**
     * Envía mensajes directamente al proveedor sin ningún sistema de modos.
     *
     * Útil para casos de uso personalizados donde los modos preconfigurados
     * no son suficientes.
     *
     * @param array $messages Array de mensajes en formato estándar.
     *
     * @return string Respuesta del modelo.
     *
     * @throws RuntimeException Si la llamada al proveedor falla.
     */
    public function chat(array $messages): string
    {
        return $this->provider->chat($messages);
    }

    // ─────────────────────────────────────────────────────────
    // Gestión del proveedor
    // ─────────────────────────────────────────────────────────

    /**
     * Cambia el proveedor de IA activo.
     *
     * Permite cambiar de proveedor en caliente sin reinstanciar el cliente.
     * Las llamadas posteriores usarán el nuevo proveedor.
     *
     * @param AIProviderInterface $provider Nuevo proveedor a usar.
     *
     * @return self Para encadenamiento de métodos (fluent interface).
     *
     * @example
     * ```php
     * $client->setProvider(new OllamaProvider(model: 'llama3'))
     *        ->chatGeneral('Hola desde Ollama');
     * ```
     */
    public function setProvider(AIProviderInterface $provider): self
    {
        $this->provider = $provider;
        return $this;
    }

    /**
     * Devuelve el proveedor actualmente configurado.
     *
     * @return AIProviderInterface Proveedor activo.
     */
    public function getProvider(): AIProviderInterface
    {
        return $this->provider;
    }

    /**
     * Devuelve el nombre del proveedor actualmente configurado.
     *
     * @return string Nombre del proveedor (ej: "OpenAI", "Gemini").
     */
    public function getProviderName(): string
    {
        return $this->provider->getProviderName();
    }

    /**
     * Devuelve el modelo actualmente configurado en el proveedor.
     *
     * @return string Identificador del modelo (ej: "gpt-4o").
     */
    public function getModel(): string
    {
        return $this->provider->getModel();
    }

    /**
     * Verifica si el proveedor configurado está disponible.
     *
     * @return bool
     */
    public function isServerAvailable(): bool
    {
        if (method_exists($this->provider, 'isServerAvailable')) {
            return $this->provider->isServerAvailable();
        }

        // Retorno seguro por defecto para proveedores en la nube o implementaciones antiguas
        return true;
    }
}
