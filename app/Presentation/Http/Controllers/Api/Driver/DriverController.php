<?php

namespace App\Presentation\Http\Controllers\Api\Driver;

use App\Business\Services\DriverService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DriverController extends Controller
{
    public function __construct(private readonly DriverService $drivers)
    {
    }

    public function show(Request $request)
    {
        return response()->json([
            'driver' => $this->drivers->present($request->user()),
        ]);
    }

    public function updateProfile(Request $request)
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'national_id_number' => ['nullable', 'string', 'max:64'],
            'service_area' => ['nullable', 'string', 'max:120'],
            'emergency_contact_name' => ['nullable', 'string', 'max:120'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:32'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'iban' => ['nullable', 'string', 'max:64'],
            'ziina_payment_link' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(
            $this->drivers->updateIdentity($request->user(), $data)
        );
    }

    public function uploadDocuments(Request $request)
    {
        $request->validate([
            'national_id_front' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'national_id_back' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'personal_photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'driving_license_front' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        return response()->json(
            $this->drivers->updateDocuments(
                $request->user(),
                $request->file('national_id_front'),
                $request->file('national_id_back'),
                $request->file('personal_photo'),
                $request->file('driving_license_front'),
            )
        );
    }

    public function upsertVehicle(Request $request)
    {
        $data = $request->validate([
            // Frontend sends name/code (car|motorcycle); backend maps to service_transportations.id
            'transportation' => ['required', 'string', 'max:32'],
            'model' => ['required', 'string', 'max:120'],
            'color' => ['nullable', 'string', 'max:64'],
            'plate_number' => ['required', 'string', 'max:32'],
            'vehicle_image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'document' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        return response()->json(
            $this->drivers->upsertVehicle(
                $request->user(),
                $data,
                $request->file('vehicle_image'),
                $request->file('document'),
            )
        );
    }

    public function submit(Request $request)
    {
        return response()->json(
            $this->drivers->submitForReview($request->user())
        );
    }
}
