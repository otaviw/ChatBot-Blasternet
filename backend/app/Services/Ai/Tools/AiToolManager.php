<?php

declare(strict_types=1);


namespace App\Services\Ai\Tools;

class AiToolManager
{
    /**
     * @var array<int, AiToolInterface>
     */
    private array $tools;

    /**
     * @var array<string, AiToolInterface>
     */
    private array $toolsByName = [];

    public function __construct(
        CustomerByPhoneTool $customerByPhoneTool
    ) {
        $this->tools = [
            $customerByPhoneTool,
        ];

        foreach ($this->tools as $tool) {
            $name = mb_strtolower(trim($tool->getName()));
            if ($name === '') {
                continue;
            }

            $this->toolsByName[$name] = $tool;
        }
    }

    /**
     * @return array<int, AiToolInterface>
     */
    public function getAvailableTools(): array
    {
        return $this->tools;
    }

    public function findTool(string $toolName): ?AiToolInterface
    {
        $normalized = mb_strtolower(trim($toolName));
        if ($normalized === '') {
            return null;
        }

        return $this->toolsByName[$normalized] ?? null;
    }
}
