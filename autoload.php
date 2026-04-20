<?php

declare(strict_types=1);

/**
 * Autoloader PSR-4 para el PHP AI SDK.
 *
 * Implementa el estándar PSR-4 de autoloading sin dependencia de Composer.
 * Mapea el namespace 'AiSdk\' al directorio 'src/' del proyecto.
 *
 * NAMESPACES MAPEADOS:
 * - AiSdk\               → src/
 * - AiSdk\Contracts\     → src/Contracts/
 * - AiSdk\Http\          → src/Http/
 * - AiSdk\Modes\         → src/Modes/
 * - AiSdk\Providers\     → src/Providers/
 *
 * USO:
 * require_once __DIR__ . '/autoload.php';
 *
 * ESTRUCTURA DE DIRECTORIOS ESPERADA:
 * /
 * ├── autoload.php
 * └── src/
 *     ├── AIClient.php
 *     ├── Contracts/
 *     │   └── AIProviderInterface.php
 *     ├── Http/
 *     │   └── HttpClient.php
 *     ├── Modes/
 *     │   └── ChatModes.php
 *     └── Providers/
 *         ├── OpenAIProvider.php
 *         ├── GeminiProvider.php
 *         ├── ClaudeProvider.php
 *         ├── OpenRouterProvider.php
 *         └── OllamaProvider.php
 */

/**
 * Registra el autoloader PSR-4 para el namespace 'AiSdk'.
 *
 * @param string $class Nombre completo de la clase (incluyendo namespace).
 */
spl_autoload_register(function (string $class): void {
    /**
     * Prefijo del namespace raíz del SDK.
     * Todas las clases del SDK comienzan con este prefijo.
     */
    $namespacePrefix = 'AiSdk\\';

    /**
     * Directorio base donde residen los archivos fuente del SDK.
     * Calculado de forma dinámica relativo a este archivo.
     */
    $baseDirectory = __DIR__ . '/src/';

    // Verificar si la clase pertenece al namespace de este SDK
    $namespacePrefixLength = strlen($namespacePrefix);
    if (strncmp($class, $namespacePrefix, $namespacePrefixLength) !== 0) {
        // La clase no pertenece a este namespace, pasar al siguiente autoloader
        return;
    }

    // Extraer la parte del nombre de clase relativa al namespace raíz
    $relativeClass = substr($class, $namespacePrefixLength);

    // Construir la ruta del archivo:
    // Reemplazar separadores de namespace '\' por separadores de directorio '/'
    // y añadir la extensión '.php'
    $filePath = $baseDirectory . str_replace('\\', '/', $relativeClass) . '.php';

    // Cargar el archivo si existe
    if (file_exists($filePath)) {
        require_once $filePath;
        return;
    }

    // Archivo no encontrado: no lanzar excepción aquí,
    // dejar que PHP lo haga con un mensaje más descriptivo.
    // Esto permite que otros autoloaders registrados puedan intentarlo.
});

/**
 * Verificar que la extensión cURL esté disponible al cargar el SDK.
 * Este es un requisito crítico del SDK.
 */
if (!extension_loaded('curl')) {
    throw new RuntimeException(
        'PHP AI SDK requiere la extensión cURL. '
        . 'Por favor, instala o habilita php-curl:'
        . PHP_EOL
        . '  Ubuntu/Debian: sudo apt-get install php-curl'
        . PHP_EOL
        . '  CentOS/RHEL:   sudo yum install php-curl'
        . PHP_EOL
        . '  macOS:         brew install php (incluido por defecto)'
        . PHP_EOL
        . '  Windows:       Descomentar extension=curl en php.ini'
    );
}

/**
 * Verificar que la extensión JSON esté disponible.
 */
if (!extension_loaded('json')) {
    throw new RuntimeException(
        'PHP AI SDK requiere la extensión JSON. '
        . 'Esta extensión es estándar en PHP 8+ y rara vez necesita instalación manual.'
    );
}
