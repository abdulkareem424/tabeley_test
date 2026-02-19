<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Amenity;
use App\Models\Offer;
use App\Models\Role;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueImage;
use App\Models\VenueTable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
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

        $query = Venue::query()->select($this->venueSelectColumns());
        $this->applyVenueRelations($query);

        $query->where('is_active', true);

        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        if (! empty($validated['search'])) {
            $query->where('name', 'like', '%' . $validated['search'] . '%');
        }

        $perPage = $validated['per_page'] ?? 10;

        $paginator = $query->orderByDesc('id')->paginate($perPage);
        $data = collect($paginator->items())
            ->map(fn (Venue $venue) => $this->serializeVenue($venue))
            ->values();

        return response()->json([
            'data' => $data,
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
            ->select($this->venueSelectColumns())
            ->where('is_active', true)
            ->findOrFail($id);

        $venue->loadMissing($this->venueRelations());

        return response()->json([
            'data' => $this->serializeVenue($venue),
        ]);
    }

    // CREATE VENUE (vendor only)
    public function store(Request $request)
    {
        $user = $request->user();
        $ownerColumn = Venue::ownerColumn();

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

        $offers = $this->sanitizeOffers($validated['offers'] ?? []);
        $imageUrls = $this->appendUploadedImages($request, $validated['image_urls'] ?? []);
        $venue = Venue::create([
            $ownerColumn => $user->id,
            'name' => $validated['name'],
            'type' => $validated['type'],
            'description' => $validated['description'] ?? null,
            'address_text' => $validated['address_text'] ?? null,
            'lat' => $validated['lat'] ?? 0,
            'lng' => $validated['lng'] ?? 0,
            'is_active' => true,
            'phone' => $validated['phone'] ?? null,
            ...$this->inlineVenuePayload($validated['amenities'] ?? [], $imageUrls, $offers),
        ]);
        $this->syncStructuredVenueData(
            $venue,
            $validated['amenities'] ?? [],
            $imageUrls,
            $offers
        );
        $venue = $this->reloadVenueForResponse($venue->id);

        return response()->json([
            'message' => 'Venue created successfully',
            'venue' => $this->serializeVenue($venue),
        ], 201);
    }

    // LIST VENUES (admin)
    public function adminIndex(Request $request)
    {
        $ownerColumn = Venue::ownerColumn();
        $validated = $request->validate([
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = Venue::query()
            ->select([
                DB::raw($ownerColumn . ' as vendor_id'),
                ...$this->venueSelectColumns(),
            ])
            ->withCount([
                'tables as table_count' => function ($q) {
                    $q->where('is_active', true);
                },
            ]);
        $this->applyVenueRelations($query);

        if (! empty($validated['search'])) {
            $query->where('name', 'like', '%' . $validated['search'] . '%');
        }

        $perPage = $validated['per_page'] ?? 20;
        $paginator = $query->orderByDesc('id')->paginate($perPage);
        $data = collect($paginator->items())
            ->map(fn (Venue $venue) => $this->serializeVenue($venue, true))
            ->values();

        return response()->json([
            'data' => $data,
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
                    'password_hash' => Hash::make($validated['vendor_password']),
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
            $ownerColumn = Venue::ownerColumn();
            $imageUrls = $validated['image_urls'] ?? [];
            $imageUrls = $this->appendUploadedImages($request, $imageUrls);
            $offers = $this->sanitizeOffers($validated['offers'] ?? []);

            $venue = Venue::create([
                $ownerColumn => $vendorId,
                'name' => $validated['name'],
                'type' => $validated['type'],
                'description' => $validated['description'] ?? null,
                'address_text' => $validated['address_text'] ?? null,
                'lat' => $validated['lat'] ?? 0,
                'lng' => $validated['lng'] ?? 0,
                'is_active' => $validated['is_active'] ?? true,
                'phone' => $validated['phone'] ?? null,
                ...$this->inlineVenuePayload($validated['amenities'] ?? [], $imageUrls, $offers),
            ]);
            $this->syncStructuredVenueData(
                $venue,
                $validated['amenities'] ?? [],
                $imageUrls,
                $offers
            );

            $this->syncVenueTables($venue, (int) ($validated['table_count'] ?? 4));
        });
        $venue = $this->reloadVenueForResponse($venue->id);

        return response()->json([
            'message' => 'Venue created successfully',
            'venue' => $this->serializeVenue($venue->loadCount([
                'tables as table_count' => fn ($q) => $q->where('is_active', true),
            ]), true),
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

            $hasAmenityInput = array_key_exists('amenities', $validated);
            $hasOfferInput = array_key_exists('offers', $validated);
            $hasImageInput = array_key_exists('image_urls', $validated) || $request->hasFile('images');
            $resolvedImageUrls = $hasImageInput
                ? $this->appendUploadedImages(
                    $request,
                    array_key_exists('image_urls', $validated)
                        ? ($validated['image_urls'] ?? [])
                        : $this->resolveImageUrls($venue)
                )
                : null;

            $payload = collect($validated)->only([
                'vendor_id',
                'name',
                'type',
                'description',
                'address_text',
                'lat',
                'lng',
                'is_active',
                'phone',
            ])->all();

            if ($this->hasInlineVenueArrays()) {
                if ($hasAmenityInput) {
                    $payload['amenities'] = $validated['amenities'] ?? [];
                }
                if ($hasOfferInput) {
                    $payload['offers'] = $validated['offers'] ?? [];
                }
                if ($hasImageInput) {
                    $payload['image_urls'] = $resolvedImageUrls ?? [];
                }
            }

            $venue->fill($payload);
            $venue->save();
            $this->syncStructuredVenueData(
                $venue,
                $hasAmenityInput ? ($validated['amenities'] ?? []) : null,
                $hasImageInput ? ($resolvedImageUrls ?? []) : null,
                $hasOfferInput ? ($validated['offers'] ?? []) : null
            );

            if (array_key_exists('table_count', $validated)) {
                $this->syncVenueTables($venue, (int) $validated['table_count']);
            }
        });
        $venue = $this->reloadVenueForResponse($venue->id)->loadCount([
            'tables as table_count' => fn ($q) => $q->where('is_active', true),
        ]);

        return response()->json([
            'message' => 'Venue updated successfully',
            'venue' => $this->serializeVenue($venue, true),
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
                    VenueTable::labelColumn() => 'Table ' . $i,
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

    private function venueSelectColumns(): array
    {
        $columns = [
            'id',
            'name',
            'type',
            'description',
            'address_text',
            'lat',
            'lng',
            'is_active',
            'phone',
        ];

        if ($this->hasInlineVenueArrays()) {
            $columns[] = 'amenities';
            $columns[] = 'image_urls';
            $columns[] = 'offers';
        }

        return $columns;
    }

    private function venueRelations(): array
    {
        if (! $this->hasStructuredVenueData()) {
            return [];
        }

        return [
            'images:id,venue_id,url,sort_order',
            'offersRelation:id,venue_id,title,description,image_url,is_active,start_at,end_at',
            'amenitiesRelation:id,name',
        ];
    }

    private function applyVenueRelations($query): void
    {
        $relations = $this->venueRelations();
        if (! empty($relations)) {
            $query->with($relations);
        }
    }

    private function reloadVenueForResponse(int $venueId): Venue
    {
        $query = Venue::query()->select($this->venueSelectColumns());
        $this->applyVenueRelations($query);

        return $query->findOrFail($venueId);
    }

    private function serializeVenue(Venue $venue, bool $includeAdminFields = false): array
    {
        $data = [
            'id' => $venue->id,
            'name' => $venue->name,
            'type' => $venue->type,
            'description' => $venue->description,
            'address_text' => $venue->address_text,
            'lat' => $venue->lat,
            'lng' => $venue->lng,
            'phone' => $venue->phone,
            'is_active' => (bool) $venue->is_active,
            'amenities' => $this->resolveAmenities($venue),
            'image_urls' => $this->resolveImageUrls($venue),
            'offers' => $this->resolveOffers($venue),
        ];

        if ($includeAdminFields) {
            $ownerColumn = Venue::ownerColumn();
            $data['vendor_id'] = $venue->vendor_id ?? $venue->{$ownerColumn} ?? null;
            $data['table_count'] = $venue->table_count ?? null;
        }

        return $data;
    }

    private function resolveAmenities(Venue $venue): array
    {
        if ($this->hasInlineVenueArrays()) {
            return is_array($venue->amenities) ? $venue->amenities : [];
        }

        if ($this->hasStructuredVenueData()) {
            return ($venue->amenitiesRelation ?? collect())
                ->pluck('name')
                ->values()
                ->all();
        }

        return [];
    }

    private function resolveImageUrls(Venue $venue): array
    {
        if ($this->hasInlineVenueArrays()) {
            return is_array($venue->image_urls) ? $venue->image_urls : [];
        }

        if ($this->hasStructuredVenueData()) {
            return ($venue->images ?? collect())
                ->sortBy('sort_order')
                ->pluck('url')
                ->values()
                ->all();
        }

        return [];
    }

    private function resolveOffers(Venue $venue): array
    {
        if ($this->hasInlineVenueArrays()) {
            return is_array($venue->offers) ? $venue->offers : [];
        }

        if ($this->hasStructuredVenueData()) {
            return ($venue->offersRelation ?? collect())
                ->map(fn (Offer $offer) => [
                    'title' => $offer->title,
                    'description' => $offer->description,
                    'image_url' => $offer->image_url,
                    'is_active' => (bool) $offer->is_active,
                ])
                ->values()
                ->all();
        }

        return [];
    }

    private function hasInlineVenueArrays(): bool
    {
        return Schema::hasColumn('venues', 'amenities')
            && Schema::hasColumn('venues', 'image_urls')
            && Schema::hasColumn('venues', 'offers');
    }

    private function hasStructuredVenueData(): bool
    {
        return Schema::hasTable('venue_images')
            && Schema::hasTable('offers')
            && Schema::hasTable('venue_amenities')
            && Schema::hasTable('amenities');
    }

    private function inlineVenuePayload(array $amenities, array $imageUrls, array $offers): array
    {
        if (! $this->hasInlineVenueArrays()) {
            return [];
        }

        return [
            'amenities' => $amenities,
            'image_urls' => $imageUrls,
            'offers' => $offers,
        ];
    }

    private function syncStructuredVenueData(Venue $venue, ?array $amenities, ?array $imageUrls, ?array $offers): void
    {
        if (! $this->hasStructuredVenueData()) {
            return;
        }

        if ($amenities !== null) {
            $amenityIds = collect($amenities)
                ->map(fn ($name) => trim((string) $name))
                ->filter(fn ($name) => $name !== '')
                ->map(fn ($name) => Amenity::firstOrCreate(['name' => $name])->id)
                ->values()
                ->all();
            $venue->amenitiesRelation()->sync($amenityIds);
        }

        if ($imageUrls !== null) {
            VenueImage::query()->where('venue_id', $venue->id)->delete();
            foreach (array_values($imageUrls) as $index => $url) {
                VenueImage::create([
                    'venue_id' => $venue->id,
                    'url' => $url,
                    'sort_order' => $index,
                ]);
            }
        }

        if ($offers !== null) {
            Offer::query()->where('venue_id', $venue->id)->delete();
            foreach ($offers as $offer) {
                Offer::create([
                    'venue_id' => $venue->id,
                    'title' => $offer['title'],
                    'description' => $offer['description'] ?: null,
                    'image_url' => $offer['image_url'] ?: null,
                    'is_active' => $offer['is_active'] ?? true,
                    'start_at' => now(),
                    'end_at' => now()->addDays(30),
                ]);
            }
        }
    }
}
