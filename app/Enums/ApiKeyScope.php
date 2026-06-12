<?php

namespace App\Enums;

enum ApiKeyScope: string
{
    case TaskMcp   = 'task:mcp';
    case MemoryMcp = 'memory:mcp';
    case AgydMcp   = 'agyd:mcp';

    public function adminOnly(): bool
    {
        return match ($this) {
            self::MemoryMcp, self::AgydMcp => true,
            default                        => false,
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
