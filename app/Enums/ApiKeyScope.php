<?php

namespace App\Enums;

enum ApiKeyScope: string
{
    case TaskMcp = 'task:mcp';
    case MemoryMcp = 'memory:mcp';
    case AgydMcp = 'agyd:mcp';
    case RagMcp = 'rag:mcp';
    case CodegraphMcp = 'codegraph:mcp';
    case TerritoryMcp = 'territory:mcp';

    public function adminOnly(): bool
    {
        return match ($this) {
            self::MemoryMcp, self::AgydMcp, self::TerritoryMcp => true,
            default => false,
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
