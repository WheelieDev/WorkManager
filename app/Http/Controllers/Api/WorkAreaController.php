<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkAreaResource;
use App\Models\WorkArea;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WorkAreaController extends Controller
{
    /**
     * List the areas a session can be booked to.
     *
     * Archived areas are left out: they are kept for the history of hours
     * already booked, not offered for new ones.
     */
    public function index(): AnonymousResourceCollection
    {
        return WorkAreaResource::collection(
            WorkArea::query()->active()->orderBy('name')->get(),
        );
    }
}
