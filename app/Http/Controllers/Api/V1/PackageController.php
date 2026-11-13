<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PackageResource;
use App\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PackageController extends Controller
{
    /**
     * The packages the key's account can put hosting accounts on.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return PackageResource::collection(Package::query()->handedOutBy($request->user())->orderBy('name')->get());
    }
}
