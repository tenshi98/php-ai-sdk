# PHP AI SDK

> SDK profesional en PHP puro para consumir múltiples proveedores de inteligencia artificial.
> Sin dependencias externas. Sin Composer. Compatible con PHP 8.0+.

[![PHP Version](https://img.shields.io/badge/PHP-8.0+-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-GPLv3-blue.svg)](LICENSE)
[![No Dependencies](https://img.shields.io/badge/dependencies-none-brightgreen.svg)](#)

---

## 📋 Tabla de Contenidos

- [Descripción](#descripción)
- [Características](#características)
- [Requisitos](#requisitos)
- [Instalación](#instalación)
- [Estructura del Proyecto](#estructura-del-proyecto)
- [Quickstart](#quickstart)
- [Proveedores](#proveedores)
- [Modos Preconfigurados](#modos-preconfigurados)
- [API Reference](#api-reference)
- [Manejo de Errores](#manejo-de-errores)
- [Cómo Agregar Nuevos Proveedores](#cómo-agregar-nuevos-proveedores)
- [Preguntas Frecuentes](#preguntas-frecuentes)

---

## Descripción

**PHP AI SDK** es un SDK extensible y desacoplado para interactuar con las principales
APIs de inteligencia artificial desde PHP puro, sin necesidad de Composer ni librerías externas.

Implementa el **Strategy Pattern** para que el código cliente sea completamente agnóstico
al proveedor de IA, permitiendo cambiar entre OpenAI, Gemini, Claude, OpenRouter y Ollama
con una sola línea de código.

---

## Características

- ✅ **5 proveedores soportados**: OpenAI, Gemini, Claude, OpenRouter, Ollama
- ✅ **8 modos preconfigurados**: procesamiento de texto, comunicación, soporte, SQL y más
- ✅ **Identidad Personalizable**: Define nombre y tono para tu asistente
- ✅ **Sin dependencias**: Solo PHP 8+ y extensión cURL (estándar)
- ✅ **Tipado fuerte**: `declare(strict_types=1)` en todos los archivos
- ✅ **PHPDoc completo**: Cada clase, método y parámetro documentado
- ✅ **Intercambiable**: Cambia de proveedor sin modificar tu código
- ✅ **Extensible**: Agrega nuevos proveedores implementando una interface
- ✅ **Manejo de errores**: Excepciones descriptivas en cada capa

---

## Requisitos

| Requisito | Versión mínima |
|-----------|---------------|
| PHP       | 8.0+          |
| Extensión cURL | Incluida en PHP estándar |
| Extensión JSON | Incluida en PHP estándar |

---

## Instalación

### 1. Descarga el SDK

```bash
# Opción A: Clonar el repositorio
git clone https://github.com/tenshi98/php-ai-sdk.git

# Opción B: Descargar el ZIP y descomprimir
unzip php-ai-sdk.zip
```

### 2. Incluir el autoloader en tu proyecto

```php
require_once '/ruta/a/php-ai-sdk/autoload.php';
```

### 3. ¡Listo! No se requiere ninguna configuración adicional.

### 4. Probar los Ejemplos (Opcional)
Para comprobar que el SDK funciona correctamente con todos tus proveedores, puedes ejecutar:
```bash
php ejemplos/example.php
php ejemplos/quickstart.php
```
---

## Estructura del Proyecto

```
ai-sdk/
├── autoload.php                      # Autoloader PSR-4 manual
├── README.md                         # Esta documentación
│
├── ejemplos/                         # Archivos de ejemplo
│   ├── example.php                   # Ejemplo completo ejecutable
│   ├── quickstart.php                # Código inicial rápido
│   └── scratch_test.php              # Pruebas de integración
│
└── src/
    ├── AIClient.php                  # Facade principal
    │
    ├── Contracts/
    │   └── AIProviderInterface.php   # Interface + ChatMessage VO
    │
    ├── Http/
    │   └── HttpClient.php            # Cliente HTTP (cURL)
    │
    ├── Modes/
    │   └── ChatModes.php             # Modos y prompts especializados
    │
    └── Providers/
        ├── OpenAIProvider.php
        ├── GeminiProvider.php
        ├── ClaudeProvider.php
        ├── OpenRouterProvider.php
        └── OllamaProvider.php
```

---

## Quickstart

```php
// Configurar identidad (Opcional)
$client->setAiName('Jarvis')
       ->setTone('profesional y conciso');

// Chat General
echo $client->chatGeneral('¿Qué es la programación funcional?');

// Resumir texto
echo $client->summarizeText($textoLargo, 'breve');

// Extraer datos a JSON
$json = $client->extractData('Juan (DNI 123) compró pan', ['nombre', 'dni', 'item']);

// Generar SQL
$sql = $client->generateQuery(
    'users(id, name, email), orders(id, user_id, total)',
    'Usuarios con más de 5 pedidos'
);

// Generar tabla HTML
$data = [['producto' => 'Laptop', 'precio' => 999], ['producto' => 'Mouse', 'precio' => 29]];
echo $client->generateTable($data, 'Catálogo');
```

---

## Proveedores

### OpenAI

```php
use AiSdk\Providers\OpenAIProvider;

$provider = new OpenAIProvider(
    apiKey:      'sk-...', // https://platform.openai.com/api-keys
    model:       'gpt-4o', // gpt-4o | gpt-4o-mini | gpt-4 | gpt-3.5-turbo
    temperature: 0.7,      // 0.0 (determinista) - 2.0 (creativo)
    maxTokens:   4096      // Máximo de tokens en la respuesta
);
```

### Gemini (Google)

```php
use AiSdk\Providers\GeminiProvider;

$provider = new GeminiProvider(
    apiKey:          'AI...', // https://aistudio.google.com/app/apikey
    model:           'gemini-1.5-flash', // gemini-1.5-pro | gemini-1.5-flash | gemini-2.0-flash
    temperature:     0.7,
    maxOutputTokens: 8192
);
```

### Claude (Anthropic)

```php
use AiSdk\Providers\ClaudeProvider;

$provider = new ClaudeProvider(
    apiKey:      'sk-ant-...', // https://console.anthropic.com
    model:       'claude-3-5-sonnet-20241022',
    temperature: 0.7,  // 0.0 - 1.0 (Claude tiene rango diferente)
    maxTokens:   8096
);
```

### OpenRouter

```php
use AiSdk\Providers\OpenRouterProvider;

$provider = new OpenRouterProvider(
    apiKey:      'sk-or-...', // https://openrouter.ai/settings/keys
    model:       'openai/gpt-4o', // Formato: proveedor/modelo
    temperature: 0.7,
    maxTokens:   4096,
    appName:     'Mi App',
    appUrl:      'https://miapp.com'
);
```

**Modelos populares en OpenRouter:**
- `openai/gpt-4o`
- `anthropic/claude-3.5-sonnet`
- `google/gemini-1.5-pro`
- `meta-llama/llama-3.1-405b-instruct`
- `mistralai/mistral-large`
- `deepseek/deepseek-r1`

### Ollama (Local / Cloud)

```php
use AiSdk\Providers\OllamaProvider;

// Modo local (por defecto)
$provider = new OllamaProvider(
    model:       'llama3.2',              // Modelo instalado localmente
    baseUrl:     'http://localhost:11434', // Por defecto
    apiKey:      null,                    // No requerida en modo local
    temperature: 0.7
);

// Verificar disponibilidad
if ($provider->isServerAvailable()) {
    // El servidor está listo
}

// Modo remoto
$providerRemoto = new OllamaProvider(
    model:   'mistral',
    baseUrl: 'https://mi-ollama-cloud.com',
    apiKey:  'mi-api-key'
);
```

**Instalar modelos en Ollama:**
```bash
ollama pull llama3.2
ollama pull mistral
ollama pull gemma2
ollama pull codellama
```

---

## Modos Preconfigurados

### Modo 1: `chat_general`

Para consultas abiertas y conversación general.

```php
// Configuración de Identidad y Tono
$client->setAiName('Jarvis');
$client->setTone('amigable y con un toque de humor');

// Simple
$response = $client->chatGeneral('Hola, ¿quién eres?');

// Con historial de conversación
$historial = [
    ['role' => 'user',      'content' => '¿Qué es PHP?'],
    ['role' => 'assistant', 'content' => 'PHP es un lenguaje de scripting...'],
];
$response = $client->chatWithHistory('¿Y cuáles son sus ventajas?', $historial);
```

**Configuración de Identidad:**
```php
$client->setAiName('Jarvis');
$client->setTone('amigable y servicial');
```

**Características del prompt:**
- Responde en el idioma del usuario
- Utiliza el nombre y tono configurados
- Usa Markdown cuando mejora la legibilidad
- Indica claramente cuando no sabe algo
- Incluye ejemplos prácticos en preguntas técnicas

---

### Modo 2: `generador_queries`

Convierte lenguaje natural en SQL válido y optimizado. Este modo ofrece dos estrategias para cargar el esquema de tu base de datos dependiendo de tus necesidades:

#### Estrategia 1: Carga Única mediante Sesiones (Recomendado)
Para múltiples consultas sobre la misma base de datos, utiliza `startQuerySession`. El esquema se envía al proveedor de IA **solo una vez** al iniciar la sesión. Las siguientes consultas solo enviarán la pregunta en lenguaje natural, lo que ahorra una cantidad significativa de tokens y mejora los tiempos de respuesta.

```php
$schema = <<<SQL
CREATE TABLE users (id INT, name VARCHAR(100), status VARCHAR(20));
CREATE TABLE orders (id INT, user_id INT, total DECIMAL, created_at DATETIME);
SQL;

// El esquema se carga SOLO UNA VEZ al iniciar la sesión
$session = $client->startQuerySession($schema);

// Consulta 1 (Envía esquema + pregunta)
$sql1 = $session->query('Top 10 usuarios por gasto total en 2024');

// Consultas 2 a 5 (SOLO envían la pregunta, sin gastar tokens extra en el esquema)
$sql2 = $session->query('Muestra solo los usuarios que tienen el status inactivo');
$sql3 = $session->query('¿Cuál es el promedio de gasto de esos usuarios inactivos?');
$sql4 = $session->query('Lista los pedidos del último mes de esos mismos usuarios');
$sql5 = $session->query('Agrupa esos pedidos por día y suma el total');
```

#### Estrategia 2: Carga en cada petición (Consulta Puntual)
Para una única consulta rápida, puedes usar `generateQuery`. En este caso, **el esquema se debe enviar siempre** en cada llamada, ya que no hay persistencia de sesión.

```php
// El esquema se envía completo en esta petición
$sql = $client->generateQuery($schema, 'Productos que nunca han sido vendidos');
```

**El SQL generado:**
- **Es seguro por diseño:** Solo genera consultas `SELECT` de solo lectura. Rechaza cualquier intento de modificar datos (`DELETE`, `DROP`, `UPDATE`, etc).
- Es ejecutable directamente (sin bloques de código markdown)
- Usa aliases descriptivos
- Aplica JOINs correctos según el esquema
- Incluye LIMIT cuando es apropiado
- Es compatible con MySQL/MariaDB por defecto

---

### Modo 3: `generador_tablas_graficos`

Transforma datos en HTML visualizable.

```php
use AiSdk\Modes\ChatModes;

$datos = [
    ['mes' => 'Enero',    'ventas' => 1200, 'gastos' => 800],
    ['mes' => 'Febrero',  'ventas' => 1850, 'gastos' => 950],
    ['mes' => 'Marzo',    'ventas' => 2100, 'gastos' => 1100],
];

// Solo tabla HTML
$html = $client->generateTable($datos, 'Reporte Mensual');

// Solo gráfico (configuración Chart.js)
$chartConfig = $client->generateChart($datos, 'Tendencia Mensual');

// Ambos
$result = $client->generateTableAndChart($datos, 'Dashboard Mensual');
echo $result['table']; // HTML de la tabla
echo $result['chart']; // JSON de configuración Chart.js
```

---

### Modo 4: `resumidor`
Genera resúmenes precisos y estructurados en formato Markdown.
```php
$resumen = $client->summarizeText(
    text: $textoLargo,
    detailLevel: 'breve' // Puede ser 'breve', 'medio' o 'completo'
);
```

---

### Modo 5: `extractor_datos`
Extrae información estructurada de un texto libre basado en un esquema.
```php
$json = $client->extractData(
    text: 'Juan compró 3 unidades del producto SKU-1234 por $150.',
    schema: [
        'nombre_cliente' => 'string',
        'sku' => 'string',
        'cantidad' => 'integer',
        'total' => 'float'
    ]
);
```

---

### Modo 6: `redactor_email`
Redacta correos corporativos basados en contexto y puntos clave, aplicando el tono configurado.
```php
$email = $client->draftEmail(
    context: 'Reunión de seguimiento del proyecto Alpha pospuesta.',
    keyPoints: [
        'Disculparse por el aviso tardío.',
        'La nueva fecha es el jueves a las 10 AM.',
        'Pedir que revisen el documento adjunto antes de la reunión.'
    ]
);
```

---

### Modo 7: `traductor`
Traducción profesional con soporte para glosarios técnicos específicos.
```php
$textoTraducido = $client->translateText(
    text: 'The payload must be serialized before dispatching the event.',
    targetLanguage: 'Español',
    glossary: 'payload = carga útil, dispatching = emisión'
);
```

---

### Modo 8: `asistente_soporte`
Asistente que responde basándose únicamente en la base de conocimientos proporcionada.
```php
$baseDeConocimiento = file_get_contents('faq_empresa.txt');

$respuestaSoporte = $client->supportChat(
    knowledgeBase: $baseDeConocimiento,
    userQuestion: '¿Cómo hago para solicitar un reembolso?',
    history: $historialPrevio // Opcional, para conversaciones largas
);
```

**Integración en HTML con Chart.js:**
```html



const config = <?= $chartConfig ?>;
new Chart(document.getElementById('miGrafico'), config);

```

---

## API Reference

### AIClient

| Método | Descripción | Retorno |
|--------|-------------|---------|
| `setAiName(string $name)` | Configura nombre de la IA | `self` |
| `setTone(string $tone)` | Configura tono (formal, amigable...) | `self` |
| `chatGeneral(string $msg)` | Chat conversacional | `string` |
| `chatWithHistory(string $m, array $h)` | Chat con historial | `string` |
| `summarizeText(string $t, string $l)` | Resumir texto | `string` |
| `extractData(string $t, array $s)` | Extraer datos a JSON | `string` |
| `draftEmail(string $c, array $p)` | Redactar correo | `string` |
| `translateText(string $t, string $lang)` | Traducción | `string` |
| `supportChat(string $kb, string $q)` | Soporte con base de conocimiento | `string` |
| `startQuerySession(string $s)` | Sesión SQL con esquema persistente | `QuerySession` |
| `generateQuery(string $s, string $q)` | Generar SQL puntual | `string` |
| `generateTable(array\|string $d, string $t)` | Tabla HTML | `string` |
| `generateChart(array\|string $d, string $t)` | Config Chart.js | `string` |
| `generateTableAndChart(array\|string $d, string $t)` | Genera tabla y gráfico | `array` |
| `chat(array $messages)` | Chat raw (sin sistema de modos) | `string` |
| `setProvider(AIProviderInterface $p)` | Cambiar proveedor | `self` |
| `getProvider()` | Devuelve el proveedor activo | `AIProviderInterface` |
| `getProviderName()` | Nombre del proveedor activo | `string` |
| `getModel()` | Modelo configurado | `string` |
| `isServerAvailable()` | Verifica disponibilidad del proveedor | `bool` |

### ChatMessage (Value Object)

```php
use AiSdk\Contracts\ChatMessage;

$msg = ChatMessage::system('Eres un asistente...');
$msg = ChatMessage::user('¿Cuál es la pregunta?');
$msg = ChatMessage::assistant('La respuesta es...');

// Convertir a array para usar con chat()
$messages = [$msg->toArray()];
```

---

## Manejo de Errores

```php
use AiSdk\AIClient;
use AiSdk\Providers\OpenAIProvider;

$client = new AIClient(new OpenAIProvider('sk-...'));

try {
    $response = $client->chatGeneral('Hola');
    echo $response;
} catch (\InvalidArgumentException $e) {
    // Error de configuración (API Key vacía, parámetros inválidos, etc.)
    echo "Error de configuración: " . $e->getMessage();
} catch (\RuntimeException $e) {
    // Error de red, API no disponible, cuota agotada, etc.
    echo "Error de API: " . $e->getMessage();
} catch (\Throwable $e) {
    // Cualquier otro error inesperado
    echo "Error inesperado: " . $e->getMessage();
}
```

**Errores comunes y soluciones:**

| Error | Causa | Solución |
|-------|-------|----------|
| `HttpClient: Error HTTP 401` | API Key incorrecta | Verifica tu API Key |
| `HttpClient: Error HTTP 429` | Límite de rate excedido | Espera y reintenta |
| `HttpClient: Error HTTP 402` | Cuota agotada | Revisa tu plan |
| `HttpClient: Error de red` | Sin conexión | Verifica conectividad |
| `OllamaProvider: done: false` | Modelo no instalado | `ollama pull modelo` |

---

## Cómo Agregar Nuevos Proveedores

### Paso 1: Crear el archivo del proveedor

```
src/Providers/MiNuevoProvider.php
```

### Paso 2: Implementar AIProviderInterface

```php
model      = $model;
        $this->httpClient = $httpClient ?? new HttpClient();
    }

    public function chat(array $messages): string
    {
        $response = $this->httpClient->post(
            url:     self::API_URL,
            headers: ['Authorization' => "Bearer {$this->apiKey}"],
            body:    ['model' => $this->model, 'messages' => $messages]
        );

        // Adaptar la respuesta al formato esperado por el SDK
        return $response['respuesta']['texto'] ?? throw new \RuntimeException('Respuesta inválida');
    }

    public function getProviderName(): string { return 'MiProveedor'; }
    public function getModel(): string         { return $this->model; }
    public function setModel(string $m): void  { $this->model = $m; }
    public function isServerAvailable(): bool  { return true; }
}
```

### Paso 3: Usar el nuevo proveedor

```php
// ¡Ya está listo! No hay que modificar nada más.
$client = new AIClient(new MiNuevoProvider('mi-api-key'));
$response = $client->chatGeneral('Hola desde mi nuevo proveedor');
```

---

## Preguntas Frecuentes

**¿Por qué no usar Composer?**
El SDK está diseñado para ser portable y usable en cualquier entorno PHP sin necesidad
de configuración adicional. Solo requiere PHP 8+ con cURL.

**¿Puedo usar múltiples proveedores en la misma aplicación?**
Sí. Crea una instancia de `AIClient` por proveedor o cambia el proveedor con `setProvider()`.

**¿El SDK soporta streaming?**
No. El SDK está diseñado para respuestas completas (non-streaming) para maximizar
la simplicidad y compatibilidad.

**¿Cómo manejo respuestas largas?**
Ajusta el parámetro `maxTokens` al crear el proveedor. Los límites dependen de cada API.

**¿Es compatible con PHP 7.x?**
No. El SDK usa características de PHP 8.0+ (named arguments, union types, match expressions,
constructor property promotion, readonly properties).
