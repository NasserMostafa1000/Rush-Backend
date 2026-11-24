<?php

namespace App\Presentation\Http\Controllers\Api\Admin;

use App\Business\Services\PromoCodeService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PromoCodeController extends Controller
{
    public function __construct(private readonly PromoCodeService $promos)
    {
    }

    public function index()
    {
        return response()->json($this->promos->list());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'type' => ['required', 'string', 'in:fixed,percent'],
            'value' => ['required', 'numeric', 'min:0'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
        ]);

        return response()->json($this->promos->create($data), 201);
    }
}
