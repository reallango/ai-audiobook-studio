<?php
declare(strict_types=1);

namespace App\Providers\OpenAICompatible;

interface ProviderInterface
{
    public function getCapabilities(): array;
    public function validateConfiguration(): array;
    public function listVoices(): array;
    public function synthesize(array $request, string $outputPath): array;
}
