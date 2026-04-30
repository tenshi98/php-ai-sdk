<?php

/**
 * Ejemplo: Generador de Queries SQL Estructuradas con Memoria (Contexto)
 * 
 * Este ejemplo demuestra cómo usar el modo MODE_GENERADOR_QUERIES_ESTRUCTURADAS
 * manteniendo un historial conversacional para que la IA "recuerde" el 
 * esquema de la base de datos sin necesidad de enviarlo nuevamente en cada llamada.
 */

require_once __DIR__ . '/../autoload.php';

use AiSdk\AIClient;
use AiSdk\Modes\ChatModes;

use AiSdk\Providers\OllamaProvider;

// 1. Configuración del cliente AI (puedes ajustar el proveedor y modelo según tu entorno)
$provider = new OllamaProvider(
    model: 'llama3:latest',
    baseUrl: 'http://localhost:11434'
);
$client = new AIClient($provider);

// 2. Instanciamos ChatModes para acceder a los métodos de construcción de contexto
$chatModes = new ChatModes();

// 3. Definimos el esquema de la base de datos (esto simula la carga de estructura)
$esquemaDB = "
CREATE TABLE usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100),
    email VARCHAR(100),
    fecha_registro DATE
);
CREATE TABLE pedidos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT,
    total DECIMAL(10,2),
    fecha_pedido DATE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);
";

// 3. Variable para simular el almacenamiento del historial de conversación.
// En un entorno web real, esto vendría del frontend vía AJAX o se guardaría en $_SESSION.
$historialConversacion = [];

echo "========================================================\n";
echo " MODO GENERADOR QUERIES ESTRUCTURADAS (CON MEMORIA)\n";
echo "========================================================\n\n";

// ---------------------------------------------------------
// TURNO 1: INICIO DE LA SESIÓN (Cargamos el esquema)
// ---------------------------------------------------------

$pregunta1 = "Dame una lista de los nombres de usuarios y su correo.";
echo "=> USUARIO (Turno 1): " . $pregunta1 . "\n\n";

// Construimos los mensajes iniciales. Este método inyecta el $esquemaDB en el primer mensaje de usuario.
$mensajesTurno1 = $chatModes->buildQueryEstructuradaSessionInit($esquemaDB, $pregunta1);

echo "[Enviando a la IA con esquema incluido...]\n";
$respuestaIA1 = $client->chat($mensajesTurno1);

echo "\n<= IA RESPUESTA (JSON):\n" . $respuestaIA1 . "\n\n";

// ¡IMPORTANTE! Guardamos el historial.
// Almacenamos el mensaje del usuario que contiene el esquema, seguido de la respuesta de la IA.
$historialConversacion[] = $mensajesTurno1[1]; // Índice 1 es el mensaje de 'user'
$historialConversacion[] = ['role' => 'assistant', 'content' => $respuestaIA1];

echo "--- Guardando historial de la sesión... ---\n\n";


// ---------------------------------------------------------
// TURNO 2: PREGUNTA DE SEGUIMIENTO (Sin cargar esquema de nuevo)
// ---------------------------------------------------------

$pregunta2 = "¿Y cuáles de esos usuarios tienen pedidos de más de 100 dólares? Incluye el total.";
echo "=> USUARIO (Turno 2): " . $pregunta2 . "\n\n";

// Construimos el seguimiento. Le pasamos el $historialConversacion acumulado y la nueva pregunta.
// El sistema NO necesita que le pasemos $esquemaDB explícitamente, ya va dentro del historial.
$mensajesTurno2 = $chatModes->buildQueryEstructuradaFollowUp($historialConversacion, $pregunta2);

echo "[Enviando a la IA enviando historial en lugar del esquema suelto...]\n";
$respuestaIA2 = $client->chat($mensajesTurno2);

echo "\n<= IA RESPUESTA (JSON):\n" . $respuestaIA2 . "\n\n";

// Opcional: Podrías seguir acumulando en el historial si hubieran más turnos
// $historialConversacion[] = ['role' => 'user', 'content' => $pregunta2];
// $historialConversacion[] = ['role' => 'assistant', 'content' => $respuestaIA2];

echo "========================================================\n";
echo " FIN DEL EJEMPLO\n";
echo "========================================================\n";
