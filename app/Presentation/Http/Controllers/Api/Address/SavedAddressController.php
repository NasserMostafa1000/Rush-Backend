<?php

namespace App\Presentation\Http\Controllers\Api\Address;

use App\Business\Services\SavedAddressService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SavedAddressController extends Controller
{
    public function __construct(private readonly SavedAddressService $addresses)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->addresses->listFor($request->user()));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'in:home,work,other'],
            'address' => ['required', 'string', 'max:255'],
            'latitude' => ['required', 'numeric'],
            'longitude' => ['required', 'numeric'],
            'notes' => ['nullable', 'string', 'max:200'],
        ]);

        return response()->json($this->addresses->create($request->user(), $data), 201);
    }

    public function update(Request $request, int $address)
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'in:home,work,other'],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'notes' => ['nullable', 'string', 'max:200'],
        ]);

        return response()->json($this->addresses->update($request->user(), $address, $data));
    }

    public function destroy(Request $request, int $address)
    {
        return response()->json($this->addresses->delete($request->user(), $address));
    }
}
