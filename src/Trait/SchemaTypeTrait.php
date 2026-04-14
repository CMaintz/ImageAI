<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Trait;

use stdClass;

/**
 * Provides helper methods for building JSON Schema structures.
 *
 * Used by schema strategy classes to create Gemini API response schemas.
 * All methods are static for convenient use as SchemaTypeTrait::string() etc.
 */
trait SchemaTypeTrait
{
    /**
     * Creates an object schema.
     *
     * Automatically handles the Gemini API bug where empty properties
     * must be an object, not an empty array.
     */
    protected static function object(array $properties, array $required = []): array
    {
        $props = empty($properties) ? new stdClass() : $properties;

        return [
            'type' => 'object',
            'properties' => $props,
            'required' => $required,
        ];
    }

    protected static function string(string $description): array
    {
        return [
            'type' => 'string',
            'description' => $description,
        ];
    }

    protected static function number(string $description): array
    {
        return [
            'type' => 'number',
            'description' => $description,
        ];
    }

    protected static function array(array $items, ?string $description = null): array
    {
        $schema = [
            'type' => 'array',
            'items' => $items,
        ];

        if ($description) {
            $schema['description'] = $description;
        }

        return $schema;
    }

    protected static function enum(array $values, string $description): array
    {
        return [
            'type' => 'string',
            'enum' => $values,
            'description' => $description,
        ];
    }
}
