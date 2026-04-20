<?php
declare(strict_types=1);

namespace AiSdk\Http;

use RuntimeException;

/**
 * HttpClient
 *
 * Cliente HTTP responsable de ejecutar solicitudes hacia APIs externas
 * utilizando cURL. Centraliza la lógica de comunicación HTTP, incluyendo:
 * inicialización de conexiones, envío de datos, manejo de errores y
 * procesamiento de respuestas en formato JSON.
 *
 * Este componente abstrae los detalles de bajo nivel de cURL, permitiendo
 * a otras capas del SDK interactuar mediante una interfaz uniforme.
 *
 * @package AiSdk\Http
 * @version 1.0.0
 */
final class HttpClient
{
    /**
     * Timeout en segundos para establecer la conexión inicial.
     */
    private const CONNECT_TIMEOUT = 10;

    /**
     * Timeout en segundos para la ejecución completa de la petición.
     */
    private const REQUEST_TIMEOUT = 120;

    /**
     * Identificador enviado en el encabezado User-Agent.
     */
    private const USER_AGENT = 'PHP-AI-SDK/1.0.0';

    /**
     * Ejecuta una petición HTTP POST enviando datos en formato JSON.
     *
     * Serializa el cuerpo, configura la petición cURL y procesa la respuesta.
     *
     * @param string $url URL del endpoint.
     * @param array $headers Headers HTTP en formato asociativo.
     * @param array $body Datos a enviar como JSON.
     *
     * @return array Respuesta decodificada como array asociativo.
     *
     * @throws RuntimeException Si falla la serialización, la petición o la respuesta.
     */
    public function post(string $url, array $headers, array $body): array
    {
        // Inicializa el recurso cURL con validaciones previas
        $ch = $this->initCurl($url);

        // Serializa el cuerpo a JSON
        $jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Valida errores de serialización JSON
        if ($jsonBody === false) {
            throw new RuntimeException(
                'HttpClient: No se pudo serializar el cuerpo de la petición a JSON. Error: '
                . json_last_error_msg()
            );
        }

        // Formatea los headers al formato requerido por cURL
        $formattedHeaders = $this->formatHeaders($headers);

        // Configura las opciones de la petición POST
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonBody,
            CURLOPT_HTTPHEADER     => $formattedHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        // Ejecuta la petición y retorna la respuesta procesada
        return $this->execute($ch);
    }

    /**
     * Ejecuta una petición HTTP GET.
     *
     * @param string $url URL del endpoint.
     * @param array $headers Headers HTTP opcionales.
     *
     * @return array Respuesta decodificada como array asociativo.
     *
     * @throws RuntimeException Si ocurre un error durante la ejecución.
     */
    public function get(string $url, array $headers = []): array
    {
        // Inicializa el recurso cURL
        $ch = $this->initCurl($url);

        // Configura las opciones de la petición GET
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET        => true,
            CURLOPT_HTTPHEADER     => $this->formatHeaders($headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        // Ejecuta la petición
        return $this->execute($ch);
    }

    /**
     * Inicializa un recurso cURL validando disponibilidad y URL.
     *
     * @param string $url URL del endpoint.
     *
     * @return \CurlHandle Instancia inicializada de cURL.
     *
     * @throws RuntimeException Si cURL no está disponible o la URL es inválida.
     */
    private function initCurl(string $url): \CurlHandle
    {
        // Verifica que la extensión cURL esté disponible
        if (!function_exists('curl_init')) {
            throw new RuntimeException(
                'HttpClient: La extensión cURL no está disponible. '
                . 'Instala o habilita php-curl en tu servidor.'
            );
        }

        // Valida que la URL tenga un formato correcto
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException(
                "HttpClient: URL inválida proporcionada: '{$url}'"
            );
        }

        // Inicializa el recurso cURL
        $ch = curl_init($url);

        // Valida que la inicialización haya sido exitosa
        if ($ch === false) {
            throw new RuntimeException(
                "HttpClient: No se pudo inicializar cURL para la URL: '{$url}'"
            );
        }

        return $ch;
    }

    /**
     * Ejecuta la petición cURL y procesa la respuesta.
     *
     * Incluye validaciones de errores de red, código HTTP y
     * conversión del cuerpo de respuesta desde JSON.
     *
     * @param \CurlHandle $ch Recurso cURL configurado.
     *
     * @return array Respuesta decodificada.
     *
     * @throws RuntimeException Si ocurre un error en cualquier etapa.
     */
    private function execute(\CurlHandle $ch): array
    {
        // Ejecuta la petición HTTP
        $rawResponse = curl_exec($ch);
        $curlError   = curl_error($ch);
        $curlErrno   = curl_errno($ch);
        $httpCode    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // Cierra el recurso cURL
        curl_close($ch);

        // Detecta errores de red o ejecución
        if ($rawResponse === false || $curlErrno !== CURLE_OK) {
            throw new RuntimeException(
                "HttpClient: Error de red cURL [{$curlErrno}]: {$curlError}"
            );
        }

        // Verifica que la respuesta sea un string válido
        if (!is_string($rawResponse)) {
            throw new RuntimeException(
                'HttpClient: La respuesta cURL no es un string válido.'
            );
        }

        // Decodifica el JSON recibido
        $decoded = json_decode($rawResponse, associative: true);

        // Valida errores de parsing JSON
        if (json_last_error() !== JSON_ERROR_NONE) {
            $preview = substr($rawResponse, 0, 200);
            throw new RuntimeException(
                'HttpClient: Respuesta no es JSON válido. '
                . 'Error: ' . json_last_error_msg()
                . ". Preview: {$preview}"
            );
        }

        // Maneja errores HTTP (códigos >= 400)
        if ($httpCode >= 400) {
            $errorMsg = $this->extractErrorMessage($decoded);
            throw new RuntimeException(
                "HttpClient: Error HTTP {$httpCode}: {$errorMsg}"
            );
        }

        // Retorna la respuesta como array
        return (array) $decoded;
    }

    /**
     * Convierte un array asociativo de headers al formato requerido por cURL.
     *
     * @param array $headers Headers en formato clave => valor.
     *
     * @return array Lista de headers en formato "Clave: Valor".
     */
    private function formatHeaders(array $headers): array
    {
        // Header por defecto indicando contenido JSON
        $formatted = ['Content-Type: application/json'];

        // Convierte cada header al formato requerido
        foreach ($headers as $key => $value) {
            $formatted[] = "{$key}: {$value}";
        }

        return $formatted;
    }

    /**
     * Extrae un mensaje de error desde una respuesta de API.
     *
     * Analiza distintas estructuras comunes de error utilizadas por
     * proveedores de IA y retorna el mensaje más descriptivo disponible.
     *
     * @param array|null $response Respuesta decodificada.
     *
     * @return string Mensaje de error interpretado.
     */
    private function extractErrorMessage(?array $response): string
    {
        // Caso de respuesta vacía
        if ($response === null) {
            return 'Respuesta vacía del servidor.';
        }

        // Formato tipo OpenAI / OpenRouter
        if (isset($response['error']['message'])) {
            return (string) $response['error']['message'];
        }

        // Formato tipo Anthropic Claude
        if (isset($response['error']['type'], $response['error']['message'])) {
            return "[{$response['error']['type']}] {$response['error']['message']}";
        }

        // Formato tipo Gemini
        if (isset($response['error']['code'])) {
            $msg    = $response['error']['message'] ?? 'Sin detalle';
            $status = $response['error']['status'] ?? '';
            return "[{$status}] {$msg}";
        }

        // Formato genérico
        if (isset($response['message'])) {
            return (string) $response['message'];
        }

        // Fallback genérico
        return 'Error desconocido del proveedor de IA.';
    }
}