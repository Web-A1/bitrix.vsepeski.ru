<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls\Ui;

use B24\Center\Infrastructure\Http\Response;
use B24\Center\Modules\Hauls\Application\Services\DealInfoService;
use RuntimeException;

final class DealInfoController
{
    public function __construct(private readonly DealInfoService $service)
    {
    }

    public function show(int $dealId): Response
    {
        try {
            $deal = $this->service->get($dealId);
        } catch (RuntimeException $exception) {
            return Response::json(['error' => $exception->getMessage()], 404);
        }

        return Response::json(['data' => $deal]);
    }
}
