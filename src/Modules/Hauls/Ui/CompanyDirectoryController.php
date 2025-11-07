<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls\Ui;

use B24\Center\Infrastructure\Http\Request;
use B24\Center\Infrastructure\Http\Response;
use B24\Center\Modules\Hauls\Application\Services\CompanyDirectoryService;
use RuntimeException;

final class CompanyDirectoryController
{
    public function __construct(private readonly CompanyDirectoryService $service)
    {
    }

    public function index(Request $request): Response
    {
        $query = $request->query();
        $type = isset($query['type']) && is_string($query['type'])
            ? strtolower($query['type'])
            : 'supplier';

        try {
            $items = $this->service->listByAlias($type);
        } catch (RuntimeException $exception) {
            return Response::json(['error' => $exception->getMessage()], 400);
        }

        return Response::json(['data' => $items]);
    }
}
