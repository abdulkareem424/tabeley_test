<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueTable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class VenueController extends Controller
{
    // LIST VENUES (customer)
    public function index(Request $request)
    {
        $validated = $request->validate([
            'type' => 'nullable|in:restaurant,cafe',
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $query = Venue::query()
            ->select([
                'id',
                'name',
                'type',
                'description',
                'address_text',
                'lat',
                'lng',
                'amenities',
                'image_urls',
                'offers',
            ]);

        $query->where('is_active', true);

        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        if (! empty($validated['search'])) {
            $query->where('name', 'like', '%' . $validated['search'] . '%');
        }

        $perPage = $validated['per_page'] ?? 10;

        $paginator = $query->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    // SHOW VENUE (public)
    public function show(int $id)
    {
        $venue = Venue::query()
            ->select([
                'id',
                'name',
                'type',
                'description',
                'address_text',
                'lat',
                'lng',
                'amenities',
                'image_urls',
                'offers',
            ])
            ->where('is_active', true)
            ->findOrFail($id);

        return response()->json([
            'data' => $venue,
        ]);
    }

    // CREATE VENUE (vendor only)
    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:restaurant,cafe',
            'description' => 'nullable|string',
            'address_text' => 'nullable|string|max:255',
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
            'phone' => 'nullable|string|max:50',
            'amenities' => 'nullable|array',
            'amenities.*' => 'string|max:100',
            'image_urls' => 'nullable|array|max:10',
            'image_urls.*' => 'url|max:2048',
            'offers' => 'nullable|array|max:20',
            'offers.*.title' => 'required_with:offers|string|max:120',
            'offers.*.description' => 'nullable|string|max:500',
            'offers.*.image_url' => 'nullable|url|max:2048',
            'offers.*.is_active' => 'nullable|boolean',
        ]);

        $venue = Venue::create([
            'vendor_id' => $user->id,
            'name' => $validated['name'],
            'type' => $validated['type'],
            'description' => $validated['description'] ?? null,
            'address_text' => $validated['address_text'] ?? null,
            'lat' => $validated['lat'] ?? null,
            'lng' => $validated['lng'] ?? null,
            'is_active' => true,
            'phone' => $validated['phone'] ?? null,
            'amenities' => $validated['amenities'] ?? [],
            'image_urls' => $validated['image_urls'] ?? [],
            'offers' => $this->sanitizeOffers($validated['offers'] ?? []),
        ]);

        return response()->json([
            'message' => 'Venue created successfully',
            'venue' => $venue,
        ], 201);
    }

    // LIST VENUES (admin)
    public function adminIndex(Request $request)
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = Venue::query()
            ->select([
                'id',
                'vendor_id',
                'name',
                'type',
                'description',
                'address_text',
                'lat',
                'lng',
                'is_active',
                'phone',
                'amenities',
                'image_urls',
                'offers',
            ])
            ->withCount([
                'tables as table_count' => function ($q) {
                    $q->where('is_active', true);
                },
            ]);

        if (! empty($validated['search'])) {
            $query->where('name', 'like', '%' . $validated['search'] . '%');
        }

        $perPage = $validated['per_page'] ?? 20;
        $paginator = $query->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    // CREATE VENUE (admin)
    public function adminStore(Request $request)
    {
        $validated = $request->validate([
            'vendor_id' => 'nullable|integer|exists:users,id',
            'name' => 'required|string|max:255',
            'type' => 'required|in:restaurant,cafe',
            'description' => 'nullable|string',
            'address_text' => 'nullable|string|max:255',
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
            'is_active' => 'nullable|boolean',
            'phone' => 'nullable|string|max:50',
            'amenities' => 'nullable|array',
            'amenities.*' => 'string|max:100',
            'image_urls' => 'nullable|array|max:10',
            'image_urls.*' => 'url|max:2048',
            'images' => 'nullable|array|max:10',
            'images.*' => 'file|image|max:5120',
            'offers' => 'nullable|array|max:20',
            'offers.*.title' => 'required_with:offers|string|max:120',
            'offers.*.description' => 'nullable|string|max:500',
            'offers.*.image_url' => 'nullable|url|max:2048',
            'offers.*.is_active' => 'nullable|boolean',
            'table_count' => 'nullable|integer|min:1|max:300',
            'vendor_email' => 'nullable|email|unique:users,email',
            'vendor_phone' => 'nullable|string|unique:users,phone',
            'vendor_password' => 'nullable|string|min:6',
        ]);

        $vendorId = $validated['vendor_id'] ?? null;

        if (! $vendorId) {
            $hasVendorCreds = ! empty($validated['vendor_email']) &&
                ! empty($validated['vendor_phone']) &&
                ! empty($validated['vendor_password']);

            if ($hasVendorCreds) {
                $vendorRole = Role::firstOrCreate(['name' => 'vendor']);
                $vendor = User::create([
                    'first_name' => 'Vendor',
                    'last_name' => 'User',
                    'email' => $validated['vendor_email'],
                    'phone' => $validated['vendor_phone'],
                    'password' => Hash::make($validated['vendor_password']),
                ]);
                $vendor->roles()->syncWithoutDetaching([$vendorRole->id]);
                $vendorId = $vendor->id;
            } else {
                $vendorId = $this->resolveVendorId();
            }
        }

        if (! $vendorId) {
            return response()->json([
                'message' => 'Provide vendor account data (email, phone, password) or vendor_id.',
            ], 422);
        }

        $venue = null;
        DB::transaction(function () use ($validated, $vendorId, &$venue, $request) {
            $imageUrls = $validated['image_urls'] ?? [];
            $imageUrls = $this->appendUploadedImages($request, $imageUrls);

            $venue = Venue::create([
                'vendor_id' => $vendorId,
                'name' => $validated['name'],
                'type' => $validated['type'],
                'description' => $validated['description'] ?? null,
                'address_text' => $validated['address_text'] ?? null,
                'lat' => $validated['lat'] ?? null,
                'lng' => $validated['lng'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
                'phone' => $validated['phone'] ?? null,
                'amenities' => $validated['amenities'] ?? [],
                'image_urls' => $imageUrls,
                'offers' => $this->sanitizeOffers($validated['offers'] ?? []),
            ]);

            $this->syncVenueTables($venue, (int) ($validated['table_count'] ?? 4));
        });

        return response()->json([
            'message' => 'Venue created successfully',
            'venue' => $venue->loadCount([
                'tables as table_count' => function ($q) {
                    $q->where('is_active', true);
                },
            ]),
        ], 201);
    }

    // UPDATE VENUE (admin)
    public function adminUpdate(Request $request, int $id)
    {
        $validated = $request->validate([
            'vendor_id' => 'nullable|integer|exists:users,id',
            'name' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|required|in:restaurant,cafe',
            'description' => 'nullable|string',
            'address_text' => 'nullable|string|max:255',
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
            'is_active' => 'nullable|boolean',
            'phone' => 'nullable|string|max:50',
            'amenities' => 'nullable|array',
            'amenities.*' => 'string|max:100',
            'image_urls' => 'nullable|array|max:10',
            'image_urls.*' => 'url|max:2048',
            'images' => 'nullable|array|max:10',
            'images.*' => 'file|image|max:5120',
            'offers' => 'nullable|array|max:20',
            'offers.*.title' => 'required_with:offers|string|max:120',
            'offers.*.description' => 'nullable|string|max:500',
            'offers.*.image_url' => 'nullable|url|max:2048',
            'offers.*.is_active' => 'nullable|boolean',
            'table_count' => 'nullable|integer|min:1|max:300',
        ]);

        $venue = Venue::findOrFail($id);
        DB::transaction(function () use (&$venue, $validated, $request) {
            if (array_key_exists('offers', $validated)) {
                $validated['offers'] = $this->sanitizeOffers($validated['offers'] ?? []);
            }

            $baseImageUrls = array_key_exists('image_urls', $validated)
                ? ($validated['image_urls'] ?? [])
                : ($venue->image_urls ?? []);
            $validated['image_urls'] = $this->appendUploadedImages($request, $baseImageUrls);

            $venue->fill($validated);
            $venue->save();

            if (array_key_exists('table_count', $validated)) {
                $this->syncVenueTables($venue, (int) $validated['table_count']);
            }
        });

        return response()->json([
            'message' => 'Venue updated successfully',
            'venue' => $venue->loadCount([
                'tables as table_count' => function ($q) {
                    $q->where('is_active', true);
                },
            ]),
        ]);
    }

    // DELETE VENUE (admin)
    public function adminDestroy(int $id)
    {
        $venue = Venue::findOrFail($id);
        $venue->delete();

        return response()->json([
            'message' => 'Venue deleted successfully',
        ]);
    }

    private function resolveVendorId(): ?int
    {
        $vendor = User::query()
            ->whereHas('roles', function ($q) {
                $q->where('name', 'vendor');
            })
            ->first();

        return $vendor?->id;
    }

    private function syncVenueTables(Venue $venue, int $targetCount): void
    {
        $targetCount = max(1, min($targetCount, 300));

        $activeTables = VenueTable::query()
            ->where('venue_id', $venue->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $currentCount = $activeTables->count();

        if ($currentCount < $targetCount) {
            for ($i = $currentCount + 1; $i <= $targetCount; $i++) {
                VenueTable::create([
                    'venue_id' => $venue->id,
                    'seating_area_id' => null,
                    'name' => 'Table ' . $i,
                    'capacity' => 4,
                    'is_active' => true,
                ]);
            }
            return;
        }

        if ($currentCount > $targetCount) {
            $toDisable = $activeTables->slice($targetCount);
            foreach ($toDisable as $table) {
                $table->is_active = false;
                $table->save();
            }
        }
    }

    private function sanitizeOffers(array $offers): array
    {
        return collect($offers)
            ->filter(fn ($offer) => is_array($offer))
            ->map(function (array $offer) {
                return [
                    'title' => trim((string) ($offer['title'] ?? '')),
                    'description' => trim((string) ($offer['description'] ?? '')),
                    'image_url' => trim((string) ($offer['image_url'] ?? '')),
                    'is_active' => array_key_exists('is_active', $offer)
                        ? (bool) $offer['is_active']
                        : true,
                ];
            })
            ->filter(fn (array $offer) => $offer['title'] !== '')
            ->values()
            ->all();
    }

    private function appendUploadedImages(Request $request, array $imageUrls): array
    {
        $urls = collect($imageUrls)
            ->map(fn ($url) => trim((string) $url))
            ->filter(fn ($url) => $url !== '')
            ->values()
            ->all();

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $path = $image->store('venues', 'public');
                $urls[] = rtrim($request->getSchemeAndHttpHost(), '/') . '/storage/' . ltrim($path, '/');
            }
        }

        $urls = collect($urls)->unique()->values()->all();
        if (count($urls) > 10) {
            throw ValidationException::withMessages([
                'images' => ['Maximum 10 images allowed per venue.'],
            ]);
        }

        return $urls;
    }
}
