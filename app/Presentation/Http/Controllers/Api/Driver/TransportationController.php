<?php

namespace App\Presentation\Http\Controllers\Api\Driver;

use App\Business\Services\DriverService;
use App\Http\Controllers\Controller;

class TransportationController extends Controller
{
    public function __construct(private readonly DriverService $drivers)
    {
    }

    public function index()
    {
        return response()->json([
            'transportations' => $this->drivers->listTransportations(),
        ]);
    }
}
