<?php

namespace App\Http\Controllers;

use App\Http\Resources\VenueResource;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;

class VenueController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(VenueResource::collection(Venue::with('zones')->whereNotNull('slug')->orderBy('id')->get()));
    }
}
