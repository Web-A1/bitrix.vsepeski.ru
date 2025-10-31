<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls\Ui;

use B24\Center\Infrastructure\Http\Response;
use B24\Center\Modules\Hauls\Application\Services\DriverLookupService;
use RuntimeException;

final class DriverController
{
    public function __construct(private readonly DriverLookupService $service)
    {
    }

    public function index(): Response
    {
        try {
            $drivers = $this->service->listDrivers();
        } catch (RuntimeException $exception) {
            return Response::json(['error' => $exception->getMessage()], 500);
        }

        return Response::json(['data' => $drivers]);
    }
}
