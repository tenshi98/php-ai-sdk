<?php
require_once __DIR__ . '/../autoload.php';

use AiSdk\AIClient;
use AiSdk\Providers\OpenAIProvider;

// 1. Instanciar proveedor
$provider = new OpenAIProvider(apiKey: 'sk-tu-api-key', model: 'gpt-4o');

// 2. Crear cliente
$client = new AIClient(provider: $provider);

// 3. Chat general
$response = $client->chatGeneral('¿Cuál es la capital de Francia?');
echo $response; // "La capital de Francia es París..."

// 4. Generar SQL
// Esquema cargado UNA sola vez → ahorro de tokens en queries múltiples
$schema  = "users(id, name, email, created_at), orders(id, user_id, total, status)";
$session = $client->startQuerySession($schema);

$sql1 = $session->query('Lista los usuarios con más de 3 pedidos');
$sql2 = $session->query('Pedidos del último mes ordenados por total');
// El esquema NO se reenvía en $sql2 → tokens ahorrados 🎉

// 5. Cambiar proveedor en caliente
$client->setProvider(new \AiSdk\Providers\OllamaProvider(model: 'llama3'));
$response = $client->chatGeneral('Hola desde Ollama');