<?php

namespace App\Services\Export\Contracts;

interface ExportServiceInterface
{
    public function execute(array $params, int $exportId): void;
}