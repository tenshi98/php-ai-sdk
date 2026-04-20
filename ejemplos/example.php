<?php

declare(strict_types=1);

/**
 * example.php
 *
 * Ejemplo completo de uso del PHP AI SDK con interfaz visual (Pico CSS).
 * Todos los proveedores son sometidos a las mismas pruebas.
 */

require_once __DIR__ . '/../autoload.php';

use AiSdk\AIClient;
use AiSdk\Contracts\ChatMessage;
use AiSdk\Modes\ChatModes;
use AiSdk\Providers\ClaudeProvider;
use AiSdk\Providers\GeminiProvider;
use AiSdk\Providers\OllamaProvider;
use AiSdk\Providers\OpenAIProvider;
use AiSdk\Providers\OpenRouterProvider;

?>
<!DOCTYPE html>
<html lang="es" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP AI SDK — Demo</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
    <style>
        :root { --pico-font-size: 90%; }
        pre { background: #1a1a1a; padding: 1rem; border-radius: 8px; border: 1px solid #333; overflow-x: auto; }
        .test-section { margin-top: 3rem; border-bottom: 2px solid #333; padding-bottom: 0.5rem; }
        .result-card { margin-bottom: 2rem; }
        .status-badge { padding: 2px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: bold; }
        .status-skip { background: #5a5a27; color: #fff; }
    </style>
</head>
<body>
<main class="container">
<?php

// ══════════════════════════════════════════════════════════════
// HELPERS
// ══════════════════════════════════════════════════════════════

function printSection(string $title): void
{
    echo "<h3 class='test-section'>🧪 {$title}</h3>";
}

function printResult(string $label, string $result): void
{
    echo "<article class='result-card'>";
    echo "<header><strong>✅ {$label}</strong></header>";
    if (str_contains($result, '<table')) {
        echo $result;
    } else {
        echo "<pre><code>" . htmlspecialchars($result) . "</code></pre>";
    }
    echo "</article>";
}

function printError(string $context, \Throwable $e): void
{
    echo "<article class='result-card' style='border: 1px solid #8a2a2a;'>";
    echo "<header style='background: #8a2a2a; color: white;'><strong>❌ ERROR en [{$context}]</strong></header>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</article>";
}

function hasApiKey(?string $apiKey): bool
{
    return $apiKey !== null && trim($apiKey) !== '';
}

// ══════════════════════════════════════════════════════════════
// CONFIGURACIÓN
// ══════════════════════════════════════════════════════════════

$config = [
    'openai' => [
        'api_key' => '',
        'model'   => 'gpt-4o',
    ],
    'gemini' => [
        'api_key' => '',
        'model'   => 'gemini-1.5-flash',
    ],
    'claude' => [
        'api_key' => '',
        'model'   => 'claude-3-5-sonnet-20241022',
    ],
    'openrouter' => [
        'api_key' => '',
        'model'   => 'google/gemma-4-31b-it:free',
    ],
    'ollama' => [
        'model'    => 'llama3.1:8b',
        'base_url' => 'http://localhost:11434', //http://172.17.0.1:11434
        'api_key'  => null,
    ],
];

$providers = [];

// OpenAI
$providers[] = [
    'name'     => 'OpenAI (' . $config['openai']['model'] . ')',
    'provider' => hasApiKey($config['openai']['api_key']) ? new OpenAIProvider($config['openai']['api_key'], $config['openai']['model']) : null,
    'hasKey'   => hasApiKey($config['openai']['api_key']),
    'isOllama' => false,
];

// Gemini
$providers[] = [
    'name'     => 'Gemini (' . $config['gemini']['model'] . ')',
    'provider' => hasApiKey($config['gemini']['api_key']) ? new GeminiProvider($config['gemini']['api_key'], $config['gemini']['model']) : null,
    'hasKey'   => hasApiKey($config['gemini']['api_key']),
    'isOllama' => false,
];

// Ollama
$providers[] = [
    'name'     => 'Ollama (' . $config['ollama']['model'] . ')',
    'provider' => new OllamaProvider($config['ollama']['model'], $config['ollama']['base_url']),
    'hasKey'   => true,
    'isOllama' => true,
];

// ══════════════════════════════════════════════════════════════
// CABECERA
// ══════════════════════════════════════════════════════════════

echo '<header><h1>🚀 PHP AI SDK</h1><p>Demo Visual de Proveedores e Identidad</p></header>';

echo '<section class="grid">';
echo '<div><h5>📋 Estado</h5><ul>';
foreach ($providers as $p) {
    $estado = $p['hasKey'] ? '✅' : '⚠️';
    echo "<li>{$estado} {$p['name']}</li>";
}
echo '</ul></div></section>';

// ══════════════════════════════════════════════════════════════
// PRUEBAS
// ══════════════════════════════════════════════════════════════

const SLEEP_BETWEEN_TESTS = 2;

foreach ($providers as $entry) {
    $name = $entry['name'];
    $prov = $entry['provider'];

    echo '<hr style="margin: 4rem 0;">';
    echo "<h2>🤖 PROVEEDOR: {$name}</h2>";

    if (!$entry['hasKey']) {
        echo "<p>⚠️ Sin API Key configurada.</p>";
        continue;
    }

    if ($entry['isOllama'] || $prov instanceof OllamaProvider) {
        if (!$prov->isServerAvailable()) {
            echo "<article style='border: 1px solid #5a5a27;'>⚠️ Servidor {$name} no disponible</article>";
            continue;
        }
        echo "<p><ins>✅ Servidor {$name} disponible.</ins></p>";
    }

    $client = new AIClient(provider: $prov);
    $client->setAiName('Jarvis')->setTone('amigable y experto');

    printSection("Chat General & Identidad");
    try {
        $resp = $client->chatGeneral('¿Quién eres y qué puedes hacer?');
        printResult($name, $resp);
    } catch (\Throwable $e) { printError($name, $e); }
    sleep(SLEEP_BETWEEN_TESTS);

    printSection("Resumen de Texto");
    try {
        $texto = "El Strategy Pattern permite definir una familia de algoritmos, encapsular cada uno y hacerlos intercambiables.";
        $resp = $client->summarizeText($texto, 'breve');
        printResult($name, $resp);
    } catch (\Throwable $e) { printError($name, $e); }
    sleep(SLEEP_BETWEEN_TESTS);

    printSection("Extracción de Datos");
    try {
        $resp = $client->extractData("Marta tiene 25 años y vive en Valencia.", ["nombre", "edad", "ciudad"]);
        printResult($name, $resp);
    } catch (\Throwable $e) { printError($name, $e); }
    sleep(SLEEP_BETWEEN_TESTS);

    printSection("Generación SQL");
    try {
        $sql = $client->generateQuery("users(id, name, email)", "Lista todos los usuarios");
        printResult($name, $sql);
    } catch (\Throwable $e) { printError($name, $e); }
}

echo "<footer><article style='background: #1e3a2f; border: none;'><h3>✅ Demo Finalizada</h3></article></footer>";

?>
</main></body></html>
