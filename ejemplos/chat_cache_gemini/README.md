# Chat SQL con Context Cache de Gemini

Ejemplo que implementa el **ahorro de tokens** usando el **Context Caching** de la API de Gemini junto al modo `generador_queries_estructuradas` de `ChatModes.php`.

## Estructura

```
chat_cache_gemini/
├── backend/
│   ├── init_cache.php   ← Crea el caché en Gemini (system prompt + esquema)
│   └── chat.php         ← Chat que reutiliza el caché activo
├── frontend/
│   └── index.html       ← UI del chat
├── config.example.php   ← Plantilla de configuración
└── README.md
```

## Cómo funciona

### Sin caché (coste normal)
Cada petición envía: `system_prompt + esquema_BD + historial + pregunta`

### Con caché (este ejemplo)
- **Primera vez:** se llama `init_cache.php` → Gemini almacena `system_prompt + esquema` y devuelve un `cache_name`.
- **Peticiones siguientes:** solo se envía `historial + pregunta`. El caché se referencia con `cachedContent`.
- **Ahorro:** entre el 70% y el 90% de tokens por petición en sesiones largas.

## Configuración

1. Copia el archivo de configuración:
   ```bash
   cp config.example.php config.php
   ```

2. Edita `config.php` y añade tu API Key de Google AI Studio:
   - Obtén una en: https://aistudio.google.com/app/apikey

3. Accede al frontend desde tu servidor web:
   ```
   http://localhost/php-ai-sdk/ejemplos/chat_cache_gemini/frontend/index.html
   ```

## Requisitos

- PHP 8.1+
- Extensión cURL habilitada
- API Key de Google AI Studio con acceso a **Gemini 1.5 Flash** o **Gemini 1.5 Pro**
- El modelo debe soportar Context Caching (mínimo ~4k tokens para Flash, ~32k para Pro)

## Flujo de la UI

1. **Pega tu esquema** de BD en el panel lateral (formato DDL o texto minificado).
2. **Haz clic en "Crear Caché en Gemini"** → el backend llama a `init_cache.php`, que construye el system prompt completo con el esquema y lo envía a la API de Gemini para cachearlo.
3. **Haz tus preguntas** en lenguaje natural → el frontend llama a `chat.php` pasando solo la pregunta y el historial acumulado.
4. El medidor de **ahorro de tokens** muestra en tiempo real cuántos tokens están cacheados vs. enviados.

## Respuesta estructurada

El backend devuelve siempre un JSON con la estructura del modo `generador_queries_estructuradas`:

```json
{
  "tipo": 1,
  "query": "SELECT u.nombre, COUNT(p.id) AS total_pedidos FROM usuarios u JOIN pedidos p ON u.id = p.usuario_id GROUP BY u.id",
  "respuesta": "Aquí tienes el total de pedidos por usuario."
}
```

| Tipo | Significado |
|------|-------------|
| 0    | Respuesta simple (sin SQL) |
| 1    | Genera tabla |
| 2    | Genera gráfico |
| 3    | Genera tabla y gráfico |
| 4    | Respuesta inesperada / error |
