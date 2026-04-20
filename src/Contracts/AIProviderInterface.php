<?php
declare(strict_types=1);

namespace AiSdk\Contracts;

/**
 * AIProviderInterface
 *
 * Contrato (Strategy Interface) que deben implementar todos
 * los proveedores de IA del SDK.
 *
 * Este interface garantiza que el AIClient (Facade) pueda
 * trabajar con cualquier proveedor de forma intercambiable,
 * siguiendo el Principio de Sustitución de Liskov (LSP).
 *
 * Cada implementación es responsable de:
 * - Adaptar el formato de mensajes al esquema del proveedor
 * - Gestionar la autenticación específica de la API
 * - Parsear la respuesta al formato estándar del SDK
 * - Manejar errores específicos del proveedor
 *
 * @package AiSdk\Contracts
 * @version 1.0.0
 */
interface AIProviderInterface {
    /**
     * Envía un array de mensajes al proveedor y obtiene la respuesta.
     *
     * Este es el método principal del SDK. El array $messages debe
     * seguir el formato estándar de OpenAI (compatible con la mayoría
     * de proveedores modernos):
     *
     * ```php
     * [
     *   ['role' => 'system', 'content' => 'Eres un asistente...'],
     *   ['role' => 'user',   'content' => '¿Cuál es la capital de Francia?'],
     *   ['role' => 'assistant', 'content' => 'La capital es París.'], // historial
     *   ['role' => 'user',   'content' => '¿Y su población?'],
     * ]
     * ```
     *
     * Roles válidos:
     * - `system`:    Instrucciones del sistema / comportamiento del modelo
     * - `user`:      Mensaje del usuario final
     * - `assistant`: Respuesta anterior del modelo (para historial)
     *
     * @param array $messages
     *        Array ordenado de mensajes en formato rol/contenido.
     *
     * @return string Texto de respuesta generado por el modelo de IA.
     *
     * @throws \RuntimeException Si la llamada a la API falla por cualquier motivo:
     *                           error de red, autenticación, cuota, etc.
     * @throws \InvalidArgumentException Si los mensajes tienen un formato incorrecto.
     */
    public function chat(array $messages): string;

    /**
     * Devuelve el nombre legible del proveedor.
     *
     * Usado para diagnóstico, logging y display en interfaces.
     *
     * @return string Nombre del proveedor (ej: "OpenAI", "Gemini", "Claude").
     */
    public function getProviderName(): string;

    /**
     * Devuelve el modelo actualmente configurado.
     *
     * @return string Identificador del modelo (ej: "gpt-4o", "gemini-1.5-pro").
     */
    public function getModel(): string;

    /**
     * Cambia el modelo de IA a usar en las siguientes llamadas.
     *
     * Permite reutilizar la misma instancia del proveedor con
     * diferentes modelos sin reinstanciar.
     *
     * @param string $model Identificador del modelo a usar.
     *
     * @return void
     */
    public function setModel(string $model): void;

    /**
     * Verifica si el servicio o servidor del proveedor está disponible.
     *
     * @return bool True si el proveedor responde correctamente.
     */
    public function isServerAvailable(): bool;
}


/**
 * ChatMessage
 *
 * Representa un mensaje individual dentro de una conversación.
 * Este Value Object encapsula la validación del rol y del contenido,
 * asegurando que siempre se construyan mensajes válidos.
 *
 * @package AiSdk\Contracts
 */
final class ChatMessage {
    /**
     * Roles válidos para un mensaje de chat.
     */
    public const ROLE_SYSTEM    = 'system';    // Constante que representa el rol de sistema.
    public const ROLE_USER      = 'user';      // Constante que representa el rol de usuario.
    public const ROLE_ASSISTANT = 'assistant'; // Constante que representa el rol del asistente.

    /**
     * @var string Rol del mensaje (system, user, assistant).
     */
    private string $role;

    /**
     * @var string Contenido textual del mensaje.
     */
    private string $content;

    /**
     * Constructor del Value Object ChatMessage.
     *
     * Valida que el rol sea permitido y que el contenido no esté vacío.
     *
     * @param string $role    Rol del mensaje. Debe ser uno de los valores de las constantes ROLE_*.
     * @param string $content Contenido del mensaje. No puede estar vacío.
     *
     * @throws \InvalidArgumentException Si el rol es inválido o el contenido está vacío.
     */
    public function __construct(string $role, string $content) {

        // Lista de roles válidos permitidos
        $validRoles = [self::ROLE_SYSTEM, self::ROLE_USER, self::ROLE_ASSISTANT];

        // Validación del rol contra los valores permitidos
        if (!in_array($role, $validRoles, true)) {
            throw new \InvalidArgumentException(
                "ChatMessage: Rol inválido '{$role}'. "
                . 'Los roles válidos son: ' . implode(', ', $validRoles)
            );
        }

        // Validación de contenido no vacío
        if (trim($content) === '') {
            throw new \InvalidArgumentException(
                'ChatMessage: El contenido del mensaje no puede estar vacío.'
            );
        }

        // Asignación de propiedades internas
        $this->role    = $role;
        $this->content = $content;
    }

    /**
     * Convierte el objeto a un array estructurado.
     *
     * Este formato es compatible con el estándar utilizado por el SDK
     * para enviar mensajes a los proveedores de IA.
     *
     * @return array{role: string, content: string} Representación del mensaje.
     */
    public function toArray(): array {
        return [
            'role'    => $this->role,
            'content' => $this->content,
        ];
    }

    /**
     * Devuelve el rol del mensaje.
     *
     * @return string
     */
    public function getRole(): string {
        return $this->role;
    }

    /**
     * Devuelve el contenido del mensaje.
     *
     * @return string
     */
    public function getContent(): string {
        return $this->content;
    }

    /**
     * Crea una instancia de mensaje con rol 'system'.
     *
     * Método de fábrica para simplificar la creación de mensajes de sistema.
     *
     * @param string $content Contenido del mensaje.
     *
     * @return self Instancia de ChatMessage.
     */
    public static function system(string $content): self {
        return new self(self::ROLE_SYSTEM, $content);
    }

    /**
     * Crea una instancia de mensaje con rol 'user'.
     *
     * Método de fábrica para mensajes del usuario.
     *
     * @param string $content Contenido del mensaje.
     *
     * @return self Instancia de ChatMessage.
     */
    public static function user(string $content): self {
        return new self(self::ROLE_USER, $content);
    }

    /**
     * Crea una instancia de mensaje con rol 'assistant'.
     *
     * Método de fábrica para respuestas del asistente.
     *
     * @param string $content Contenido del mensaje.
     *
     * @return self Instancia de ChatMessage.
     */
    public static function assistant(string $content): self {
        return new self(self::ROLE_ASSISTANT, $content);
    }
}
