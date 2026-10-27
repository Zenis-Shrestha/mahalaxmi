<?php

namespace App\Http\Controllers\Api;
use Illuminate\Support\Facades\Cache;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;                 // CHANGED: ensure DB facade is imported
use Carbon\Carbon;
use App\Models\BuildingInfo\Building;             // CHANGED: Eloquent model must point to building_info.buildings
use App\Models\BuildingInfo\UseCategory;          // CHANGED: used to map purpose name -> id
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use App\Jobs\ProcessBulkBuildingJob;

class EbpsBuildingController extends Controller
{
    /**
     * Entry point called by your route:
     * /api/building-info/{ebps_id}/{transaction_type}
     */


    public function bulkStatus(string $jobId)
    {
        $status = Cache::get("bulkbuilding:status:$jobId", "processing");
        $result = Cache::get("bulkbuilding:result:$jobId");

        return response()->json([
            'success' => true,
            'status'  => $status,
            'summary' => $result, // null until done/failed
        ]);
    }
    public function storeBulkBuilding(string $transaction_type, Request $request)
    {
        // accept both {records:[...]} or top-level [...]
        $records = $request->input('records');
        if (!is_array($records) || count($records) === 0) {
            $all = $request->all();
            if (is_array($all) && isset($all[0]) && is_array($all[0])) {
                $records = $all;
            }
        }

        if (!is_array($records) || count($records) === 0) {
            return response()->json([
                'success' => false,
                'message' => 'records must be a non-empty array'
            ], 422);
        }

        // ✅ safety: ensure runtime queue is correct (remove later)
        if (config('queue.default') !== 'database') {
            return response()->json([
                'success' => false,
                'message' => 'Queue is not set to database at runtime',
                'runtime_queue' => config('queue.default')
            ], 500);
        }

        $jobId = (string) Str::uuid();

        Cache::put("bulkbuilding:payload:$jobId", [
            'transaction_type' => $transaction_type,
            'records' => $records,
            'submitted_at' => now()->toDateTimeString(),
        ], now()->addHours(6));

        Cache::put("bulkbuilding:status:$jobId", "queued", now()->addHours(6));

        ProcessBulkBuildingJob::dispatch($jobId);

        return response()->json([
            'success' => true,
            'message' => 'Bulk import queued',
            'job_id'  => $jobId
        ], 202);
    }

    public function storeBuildingInfo($transaction_type, Request $request)
    {

        try {
            if ($transaction_type === 'ApplicationForVacantLand') {
                return $this->handleVacantLand( $request);  // CHANGED: thin entry, delegates work
            } elseif ($transaction_type === 'SuperStructure') {
                return $this->handleSuperStructure($request); // TODO
            } elseif ($transaction_type === 'Completion') {
                return $this->handleBuildingComplition( $request); // TODO
            }

            // Unsupported transaction
            return response()->json([
                'success'      => false,
                'responseCode' => '400',
                'error_code'   => 'INVALID_TRANSACTION_TYPE',
                'message'      => "The transaction type '{$transaction_type}' is not supported."
            ], 400);

        } catch (\Throwable $e) {
            \Log::channel('ebps')->error('Failed in storeBuildingInfo method', [
                /* 'ebps_id'         => $ebps_id, */
                'transaction_type'=> $transaction_type,
                'error'           => $e->getMessage(),
                'timestamp'       => Carbon::now()->toDateTimeString()
            ]);

            return response()->json([
                'success'      => false,
                'responseCode' => '500',
                'error_code'   => 'EXCEPTION',
                'message'      => 'Internal Server Error',
                'error_details'=> $e->getMessage(),
            ], 500);
        }
    }

    protected function handleVacantLand(Request $request)
    {
        $data = $request->all();

        // 1) Minimal validation (only the fields we absolutely require) // NEW
        $validationError = $this->validateRequired($data, ['BldgPrmt_TID', 'TransactionType', 'Ward', 'ToleName']);
        /* dd($validationError); */
        if ($validationError) {
            return $validationError; // returns 422
        }
        $this->upsertFlat($data);

        // 4) Main transactional upsert: building + owner                        // NEW
        DB::beginTransaction();
        try {
            // 4a) Upsert building (create BIN if needed, map use_category, build geometry)
            $building = $this->upsertBuildingFromEbps($data);                   // NEW

            // 4b) Upsert owner using SAME BIN
            $this->upsertOwnerFromEbps($building->bin, $data);                  // NEW


            $containmentId = $this->createContainmentAndLink($building->bin, $data);
            DB::commit();
        }

        catch (\Throwable $e) {
            DB::rollBack();
            \Log::channel('ebps')->error('TX failed (building+owner)', [
                'error'   => $e->getMessage(),
                'payload' => $data,
            ]);
            throw $e;
        }

        // 5) Success response                                                    // CHANGED
        return response()->json([
            'success'      => true,
            'responseCode' => '200',
            'content'      => 'OK',
            'received_data'=> [
                'BldgPrmt_TID'     => data_get($data, 'BldgPrmt_TID'),
                'ApplicationNumber'=> data_get($data, 'ApplicationNumber'),
                'Ward'             => data_get($data, 'Ward'),
                'TransactionType'  => data_get($data, 'TransactionType'),
                'HouseOwnerNm'     => data_get($data, 'HouseOwnerNm'),
                'timestamp'        => Carbon::now()->toDateTimeString(),
            ],
        ], 200);
    }

    // NEW: inserts the incoming payload into the flat table (lowercase schema/table)
    protected function insertIntoFlat(array $data): void
    {
        DB::table('ebps_flat_table.ebps_building_info')->insert([
            'bldgprmt_tid'     => data_get($data, 'BldgPrmt_TID'),
            'application_number'=> data_get($data, 'ApplicationNumber'),
            'tax_code'         => data_get($data, 'tax_code'),
            'structure_type'   => data_get($data, 'structureType'),
            'ward'             => data_get($data, 'Ward'),
            'transaction_type' => data_get($data, 'TransactionType'),
            'lat_designer'     => data_get($data, 'Designer_Latitude'),
            'long_designer'    => data_get($data, 'Designer_Longitude'),
            'functional_use'   => data_get($data, 'buildingPurposeNm'),
            'owner'            => data_get($data, 'HouseOwnerNm'),
            'floor_count'      => data_get($data, 'NoOfStorey'),
            'location'         => data_get($data, 'ToleName'),
            'created_at'       => Carbon::now(),
            'updated_at'       => Carbon::now(),
        ]);
    }
    protected function upsertFlat(array $data): void
    {
        $this->upsertLatestFlatRecord(
            data_get($data, 'BldgPrmt_TID')
                ?? data_get($data, 'Bldgprmt_TID')
                ?? data_get($data, 'Bldgprmt_tid'),
            [
                'transaction_type'   => data_get($data, 'TransactionType'),
                'application_number' => data_get($data, 'ApplicationNumber'),
                'tax_code'           => data_get($data, 'tax_code'),
                'structure_type'     => data_get($data, 'structureType'),
                'ward'               => data_get($data, 'Ward'),
                'lat_designer'       => data_get($data, 'Designer_Latitude'),
                'long_designer'      => data_get($data, 'Designer_Longitude'),
                'functional_use'     => data_get($data, 'buildingPurposeNm'),
                'owner'              => data_get($data, 'HouseOwnerNm'),
                'floor_count'        => data_get($data, 'NoOfStorey'),
                'location'           => data_get($data, 'ToleName'),
            ]
        );
    }

    /**
     * Keep one latest-state flat row per EBPS permit.
     *
     * Vacant Land creates the row. SuperStructure and Completion update it.
     * Null and empty values are ignored so later stages do not erase useful
     * values received during an earlier stage.
     */
    protected function upsertLatestFlatRecord($ebpsId, array $values): void
    {
        if ($ebpsId === null || $ebpsId === '') {
            throw new \InvalidArgumentException('EBPS building permit ID is required.');
        }

        // Preserve valid false and 0 values, but ignore null and empty strings.
        $values = array_filter(
            $values,
            static fn ($value) => $value !== null && $value !== ''
        );
        $values['updated_at'] = Carbon::now();

        $query = DB::table('ebps_flat_table.ebps_building_info')
            ->where('bldgprmt_tid', (string) $ebpsId);

        if ($query->exists()) {
            $query->update($values);
            return;
        }

        DB::table('ebps_flat_table.ebps_building_info')->insert(array_merge(
            [
                'bldgprmt_tid' => (string) $ebpsId,
                'created_at'   => Carbon::now(),
            ],
            $values
        ));
    }
    // NEW: upserts a row in building_info.buildings using EBPS payload
    protected function upsertBuildingFromEbps(array $data): Building
    {
        $ebpsId  = (string) data_get($data, 'BldgPrmt_TID');
        $ward    = data_get($data, 'Ward');
        $locality= data_get($data, 'ToleName');
        $taxCode = data_get($data, 'tax_code');
        $floors  = data_get($data, 'NoOfStorey');

        $transactionType = (string) data_get($data, 'TransactionType'); // e.g. "Application for Vacant Land"
        $constructionStatus = null;

        if (!empty($transactionType)) {
            // strips the leading "Application for "
            $constructionStatus = Str::replaceFirst('Application for ', '', $transactionType);
            // For "Application for Vacant Land" -> "Vacant Land"
        }

        // Map "Residential" -> use_category_id (case-insensitive)               // CHANGED
        $useCategoryName = (string) data_get($data, 'buildingPurposeNm');
        $useCategoryId   = $this->resolveUseCategoryIdByName($useCategoryName);  // NEW

        // Convert lon/lat point to a small MultiPolygon (0.5 m buffer)          // CHANGED
        $lon = is_null(data_get($data, 'Designer_Longitude')) ? null : (float) data_get($data, 'Designer_Longitude');
        $lat = is_null(data_get($data, 'Designer_Latitude'))  ? null : (float) data_get($data, 'Designer_Latitude');
        $geomExpr = $this->buildMultiPolygonFromPoint($lon, $lat);               // NEW (returns DB::raw(...) or null)

        // Fetch by ebps_id or create new
        $building = Building::where('ebps_id', $ebpsId)->first();
        if (!$building) {
            $building = new Building();

            // Robust BIN generation                                             // CHANGED
            $maxBIN  = Building::max('bin'); // 'B000123' or null
            $numeric = (int) preg_replace('/\D/', '', $maxBIN ?? '0');
            $building->bin = 'B' . sprintf('%06d', $numeric + 1);
        }

        $building->ebps_id        = $ebpsId;
        $building->ward           = $ward;
        $building->house_locality = $locality;
        $building->tax_code       = $taxCode;
        $building->floor_count    = $floors;
        $building->construction_status = $constructionStatus;

        if ($useCategoryId) {
            $building->use_category_id = $useCategoryId;
        }
        if ($geomExpr) {
            $building->geom = $geomExpr;  // geometry(MultiPolygon,4326) column
        }

        $building->save();

        return $building;
    }

    // NEW: resolve use_category id from its name (case-insensitive)
    protected function resolveUseCategoryIdByName(?string $name): ?int
    {
        if (!$name) return null;

        return UseCategory::query()
            ->whereRaw('LOWER(name) = LOWER(?)', [$name])
            ->value('id');
    }

    // NEW: build a tiny MultiPolygon from lon/lat point; returns DB::raw(...) or null
    protected function buildMultiPolygonFromPoint(?float $lon, ?float $lat)
    {
        if (is_null($lon) || is_null($lat)) {
            return null;
        }

        // Buffer as geography to use meters, cast back to geometry, then ST_Multi to ensure MultiPolygon
        return DB::raw(sprintf(
            "ST_Multi((ST_Buffer(ST_SetSRID(ST_Point(%F,%F),4326)::geography, 0.5))::geometry)",
            $lon, $lat
        ));
    }

    // NEW: upsert owner in building_info.owners using same BIN as building
    protected function upsertOwnerFromEbps(string $bin, array $data): void
    {

        $rawOwner = data_get($data, 'HouseOwnerNm');
        $rawGender = data_get($data, 'gender');
        $rawPhone  = data_get($data, 'contact_no');
        $rawNid    = data_get($data, 'citizenshipNum');

        if (is_array($rawOwner)) {
            $ownerName = implode(', ', array_filter($rawOwner));
        } else {
            $parts = array_map('trim', explode(',', (string)$rawOwner));
            $ownerName = implode(', ', array_filter($parts));
        }
        $normalize = function ($value) {
            if (is_array($value)) {
                return implode(', ', array_filter($value));
            }

            $parts = array_map('trim', explode(',', (string)$value));
            return implode(', ', array_filter($parts));
        };
        $ownerName   = data_get($data, 'HouseOwnerNm');
        $ownerGender = $normalize($rawGender);
        $ownerPhone  = $normalize($rawPhone);
        $nid         = $normalize($rawNid);

        // If there’s an owners model, use it; otherwise, raw table insert/update
        // Here we use raw table to avoid guessing your Eloquent model name.
        $now = Carbon::now();

        // Try update-first; if not exists, insert
        $exists = DB::table('building_info.owners')                         // NOTE: ensure this table exists
            ->where('bin', $bin)
            ->whereNull('deleted_at')                                      // NOTE: if soft-deletes
            ->exists();

        if ($exists) {
            DB::table('building_info.owners')->where('bin', $bin)->update([ // CHANGED
                'owner_name'   => $ownerName,
                'owner_gender' => $ownerGender,
                'owner_contact'=> $ownerPhone,
                'nid'          => $nid,
                'updated_at'   => $now,
            ]);
        } else {
            DB::table('building_info.owners')->insert([                    // NEW
                'bin'          => $bin,
                'owner_name'   => $ownerName,
                'owner_gender' => $ownerGender,
                'owner_contact'=> $ownerPhone,
                'nid'          => $nid,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }
    }

    private function mapContainmentLocation(?string $location): ?string
    {
        if (!$location) {
            return null;
        }

        $location = trim(strtolower($location));

        // Any value containing "inside"
        if (str_contains($location, 'inside')) {
            return 'Inside the house';
        }

        // Any value containing "outside"
        if (str_contains($location, 'outside')) {
            return 'Outside the house';
        }

        // Default fallback (optional)
        return null;
    }

    private function mapOutletPipeToContainmentTypeLabel(?string $outlet): ?string
    {
        $v = strtolower(trim((string)$outlet));

        if ($v === '' ) return null;

        // normalize common variants
        $v = str_replace(['sewer network', 'sewer'], 'sewer network', $v);
        $v = str_replace(['soakpit', 'soak-pit'], 'soak pit', $v);

        return match (true) {
            str_contains($v, 'sewer network') => 'Septic Tank connected to Sewer Network',
            str_contains($v, 'soak pit')      => 'Septic Tank connected to Soak Pit',
            $v === 'none' || str_contains($v, 'without') || str_contains($v, 'no outlet')
                                            => 'Septic Tank without Outlet Connection',
            default                            => null, // unknown -> leave null (or choose "Unknown Outlet Connection")
        };
    }
    private function getContainmentTypeIdByLabel(?string $label): ?int
    {
        if (!$label) return null;

        return DB::table('fsm.containment_types')
            ->where('type', $label)
            ->value('id');
    }

    private function createContainmentAndLink(string $bin, array $data): ?string
    {
        // Skip if no septic fields present
        if (
            is_null(data_get($data, 'SepticTankLength')) &&
            is_null(data_get($data, 'SepticTankWidth')) &&
            is_null(data_get($data, 'SepticTankDepth')) &&
            is_null(data_get($data, 'SepticTankLocation'))
        )
        {
            return null;
        }

        $mappedLocation = $this->mapContainmentLocation(data_get($data, 'SepticTankLocation'));
        $outletLabel = $this->mapOutletPipeToContainmentTypeLabel(data_get($data, 'SepticOutletpipe'));
        $typeId      = $this->getContainmentTypeIdByLabel($outletLabel);
        $now            = now();

        // 🔹 Check if this BIN already has a containment link
        $existingLink = DB::table('building_info.build_contains')
            ->where('bin', $bin)
            ->first();

        if ($existingLink) {
            // 🔹 Update existing containment
            $containmentId = $existingLink->containment_id;

            DB::table('fsm.containments')
                ->where('id', $containmentId)
                ->update([
                    'type_id'      => $typeId,  
                    'location'    => $mappedLocation,
                    'tank_length' => data_get($data, 'SepticTankLength'),
                    'tank_width'  => data_get($data, 'SepticTankWidth'),
                    'depth'       => data_get($data, 'SepticTankDepth'),
                    'updated_at'  => $now,
                ]);

            return $containmentId;
        }

        // 🔹 If no existing link: create new containment + pivot
        $containmentId = $this->nextContainmentId();

        DB::table('fsm.containments')->insert([
            'id'          => $containmentId,
            'type_id'      => $typeId,  
            'location'    => $mappedLocation,
            'tank_length' => data_get($data, 'SepticTankLength'),
            'tank_width'  => data_get($data, 'SepticTankWidth'),
            'depth'       => data_get($data, 'SepticTankDepth'),
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        DB::table('building_info.build_contains')->insert([
            'bin'            => $bin,
            'containment_id' => $containmentId,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        return $containmentId;
    }

    private function nextContainmentId(): string
    {
        $next = DB::table(DB::raw('fsm.containments'))
            ->selectRaw("COALESCE(MAX(REGEXP_REPLACE(id,'\\D','','g')::int),0) + 1 AS n")
            ->value('n');

        return 'C' . str_pad($next, 6, '0', STR_PAD_LEFT);
    }

    // NEW: tiny validator for required keys; returns JSON 422 or null
    protected function validateRequired(array $data, array $requiredKeys){
        $missing = [];
        foreach ($requiredKeys as $key) {
            if (data_get($data, $key) === null || data_get($data, $key) === '') {
                $missing[] = $key;
            }
        }
        if (!empty($missing)) {
            return response()->json([
                'success'      => false,
                'responseCode' => '422',
                'error_code'   => 'VALIDATION_ERROR',
                'error_details'=> 'Missing required fields: ' . implode(', ', $missing)
            ], 422);
        }
        return null;
    }

     // Super Structuture.
    protected function handleSuperStructure( Request $request)
    {
        try {
            $data = $request->all();

            $buildingPermitId = data_get($data, 'Bldgprmt_TID')
            ?? data_get($data, 'BldgPrmt_TID');

            // Add buildingPermitId into data if needed
            $data['Bldgprmt_TID'] = $buildingPermitId;


            $this->insertSSdataInFlatTable($data);

            /* Update in Building */
            $building = $this->upseertSSBuildingFromEBPS($data);
            /* Update in Owner */
            $this->upsertOwnerFromSS($building->bin, $data);
            /* Update in Containment */
            $this->upsertSSContainmentFromEBPS($building->bin, $data);
            /* dd('BUILDING FROM SS', [
                'ebps_id' => $building->ebps_id,
                'bin'     => $building->bin,
            ]); */
            /* Insert in Containment_inspection, question */
            $this->createSSContainmentInspectionAndQuestions($data);

            return response()->json([
                'success' => true,
                'message' => 'Super Structure data saved successfully'
            ], 200);

        } catch (\Throwable $e) {
            \Log::channel('ebps')->error('Failed inserting SS flat table data', [
                'ebps_id' => $buildingPermitId,
                'error'   => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to insert Super Structure data',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    protected function insertSSdataInFlatTable(array $data)
    {
        $ebpsId = $data['Bldgprmt_TID']
            ?? $data['BldgPrmt_TID']
            ?? $data['Bldgprmt_tid']
            ?? null;

        // Update the flat row created during the Vacant Land stage.
        $this->upsertLatestFlatRecord($ebpsId, [
            'transaction_type'    => $data['TransactionType_Nm'] ?? null,
            'road_code'           => $data['RodeCode'] ?? null,
            'application_number'  => $data['ApplicationNumber'] ?? null,
            'sewer_code'          => $data['SewerCode'] ?? null,

            // septic outlet info
            'outlet_pipe_connect_designer'   => $data['OutletPipeConectionDesigner'] ?? null,
            'outlet_pipe_connect_sanitation' => $data['OutletPipeConnectSanitaion'] ?? null,

            // septic dimensions (numeric(10,2))
            'length'             => isset($data['SepticTankLength']) ? (float) $data['SepticTankLength'] : null,
            'width'              => isset($data['SepticTankWidth']) ? (float) $data['SepticTankWidth'] : null,
            'depth'              => isset($data['SepticTankDepth']) ? (float) $data['SepticTankDepth'] : null,

            // date (Postgres will accept null or proper date)
            'construction_date'  => $data['Construction_Date'] ?? null,

            // functional use
            'functional_use'     => $data['buildingPurposeNm'] ?? null,

            // designer location (numeric(10,6) in DB)
            'lat_designer'       => isset($data['Designer_Latitude'])
                                    ? (float) trim($data['Designer_Latitude'])
                                    : null,
            'long_designer'      => isset($data['Designer_Longitude'])
                                    ? (float) trim($data['Designer_Longitude'])
                                    : null,

            // footprints (jsonb) – Laravel will send as text; Postgres will cast if it's valid JSON
            'ward_building_footprint'     => !empty($data['Field_footprint'])
                                                ? $data['Field_footprint']
                                                : null,
            'designer_building_footprint' => !empty($data['Designer_footprint'])
                                                ? $data['Designer_footprint']
                                                : null,

            // owner/contact
            'owner'        => $data['HouseOwnerNm'] ?? null,
            'gender'       => isset($data['gender']) ? trim($data['gender']) : null,
            'contact_no'   => $data['contact_no'] ?? null,
            'floor_count'  => isset($data['NoOfStorey']) ? (int) $data['NoOfStorey'] : null,

            // septic design flags (booleans)
            'issepticsealed'       => $data['IsSepticSealed'] ?? null,
            'issepticcompartments' => $data['IsSepticCompartments'] ?? null,
            'isseptictankdepth'    => $data['IsSepticTankDepth'] ?? null,
            'islengretdesign'      => $data['IsLenGretDesign'] ?? null,
            'iswidgretdesign'      => $data['IsWidGretDesign'] ?? null,
            'islensepticdouble'    => $data['IsLenSepticDouble'] ?? null,
            'ischamberlength'      => $data['IsChamberLength'] ?? null,
            'ispipeinoutletlevel'  => $data['IsPipeInOutletLevel'] ?? null,
            'ispartwalldepth'      => $data['IsPartWallDepth'] ?? null,
            'isinnoutletsection'   => $data['IsInNOutLetSection'] ?? null,
            'isseptictanksealed'   => $data['IsSepticTankSealed'] ?? null,
            'isinletnoutletsection'=> $data['IsInletNOutletSection'] ?? null,

            // inspection date
            'inspecteddate'        => $data['inspectedDate'] ?? null,
        ]);
    }

    protected function upseertSSBuildingFromEBPS(array $data){

        $ebpsId = (string) (
        data_get($data, 'Bldgprmt_TID') ??
        data_get($data, 'BldgPrmt_TID')
        );

        if ($ebpsId === '') {
            throw new \InvalidArgumentException('Bldgprmt_TID (EBPS ID) is required for Super Structure.');
        }
        $transactionType = (string) data_get($data, 'TransactionType'); // e.g. "Application for Vacant Land"
        $constructionStatus = 'Super Structure';

        if (!empty($transactionType)) {
            // strips the leading "Application for "
            $constructionStatus = Str::replaceFirst('ApplicationFor', '', $transactionType);
            // For "Application for Vacant Land" -> "Vacant Land"
        }


        $building = Building::where('ebps_id', $ebpsId)->first();
        // ---------------- CASE 1: Not found → create new building + BIN ----------------
        if (!$building) {
            $building = new Building();

            // Generate next BIN (e.g. B000123 → B000124)
            $maxBIN  = Building::max('bin'); // 'B000123' or null
            $numeric = (int) preg_replace('/\D/', '', $maxBIN ?? '0');
            $building->bin = 'B' . sprintf('%06d', $numeric + 1);

            $building->ebps_id = $ebpsId;
        }

        $building->ebps_id = $ebpsId;
        $building->construction_status = $constructionStatus;
        $ward     = data_get($data, 'Ward');
        $locality = data_get($data, 'ToleName');

        if (!is_null($ward)) {
            $building->ward = $ward;
        }
        if (!is_null($locality)) {
            $building->house_locality = $locality;
        }
        $taxCode = data_get($data, 'tax_code');
        if (!is_null($taxCode)) {
            $building->tax_code = $taxCode;
        }
        $floors = data_get($data, 'NoOfStorey');
        if (!is_null($floors)) {
            $building->floor_count = (int) $floors; // "4" → 4
        }
        // Use category ("Residential", etc.)
        $useCategoryName = (string) data_get($data, 'buildingPurposeNm'); // Residential
        if (!empty($useCategoryName)) {
            $useCategoryId = $this->resolveUseCategoryIdByName($useCategoryName);
            if ($useCategoryId) {
                $building->use_category_id = $useCategoryId;
            }
        }

        //prefer field footprint over designer footprint
        $footprintJson = data_get($data, 'Field_footprint')
        ?: data_get($data, 'Designer_footprint');

        $geomExpr = $this->buildMultiPolygonFromFootprint($footprintJson);

        if ($geomExpr) {

        $building->geom = $geomExpr;
         }

         $building->save();

    return $building;
    }

    protected function buildMultiPolygonFromFootprint(?string $footprintJson)
    {
        if (!$footprintJson) {
            return null;
        }

        // Decode JSON string → PHP array
        $points = json_decode($footprintJson, true);

        if (!is_array($points) || count($points) < 3) {
            // Not enough points to make a polygon
            return null;
        }

        $coords = [];

        foreach ($points as $pt) {
            // Your JSON sometimes uses "latitudeddd" instead of "latitude"
            $lat = $pt['latitude'] ?? $pt['latitudeddd'] ?? null;
            $lon = $pt['longitude'] ?? null;

            if ($lat === null || $lon === null) {
                continue;
            }

            $coords[] = [
                'lat' => (float) $lat,
                'lon' => (float) $lon,
            ];
        }

        // Need at least 3 valid points
        if (count($coords) < 3) {
            return null;
        }

        // Ensure polygon is closed (first point = last point)
        $first = $coords[0];
        $last  = $coords[count($coords) - 1];

        if ($first['lat'] !== $last['lat'] || $first['lon'] !== $last['lon']) {
            $coords[] = $first;
        }

        // Build WKT: POLYGON((lon lat, lon lat, ...))
        $wktCoords = implode(',', array_map(
            fn ($c) => $c['lon'] . ' ' . $c['lat'],
            $coords
        ));

        $wkt = "POLYGON(($wktCoords))";

        // Return expression: geometry(MultiPolygon, 4326)
        return DB::raw("ST_Multi(ST_GeomFromText('{$wkt}', 4326))");
    }

    protected function upsertOwnerFromSS(string $bin, array $data): void
    {
         $normalize = function ($value) {
        if (is_array($value)) {
            return implode(', ', array_filter(array_map('trim', $value)));
        }

        $parts = array_map('trim', explode(',', (string)$value));
        return implode(', ', array_filter($parts));
        };

        $ownerName   = $normalize(data_get($data, 'HouseOwnerNm'));   // supports multiple
        $ownerGender = $normalize(data_get($data, 'gender'));         // supports multiple
        $phone       = $normalize(data_get($data, 'contact_no'));     // supports multiple

        $now         = now();

        // Check if an owner already exists for this BIN (active row)
        $query  = DB::table('building_info.owners')->where('bin', $bin);
        $exists = $query->whereNull('deleted_at')->exists();   // if you use soft deletes

        if ($exists) {
            $query->update([
                'owner_name'    => $ownerName,
                'owner_gender'  => $ownerGender,
                'owner_contact' => $phone,
                'updated_at'    => $now,
            ]);
        } else {
            
            DB::table('building_info.owners')->insert([
                'bin'           => $bin,
                'owner_name'    => $ownerName,
                'owner_gender'  => $ownerGender,
                'owner_contact' => $phone,
                // add nid or other fields if you have them in payload
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        }
    }


   /*  protected function upsertSSContainmentFromEBPS(string $bin, array $data): ?string{

            if (
                is_null(data_get($data, 'SepticTankLength')) &&
                is_null(data_get($data, 'SepticTankWidth')) &&
                is_null(data_get($data, 'SepticTankDepth')) &&
                is_null(data_get($data, 'SepticTankLocationSanitation'))
            ) {
                return null;
            }

            $now          = now();
            $rawLocation  = data_get($data, 'SepticTankLocationSanitation');
            $mappedLocation = $rawLocation;

            $length = data_get($data, 'SepticTankLength');
            $width  = data_get($data, 'SepticTankWidth');
            $depth  = data_get($data, 'SepticTankDepth');

            $existingLink = DB::table('building_info.build_contains')
                ->where('bin', $bin)
                ->first();

      

            if ($existingLink) {
                // -------- UPDATE existing containment --------
                $containmentId = $existingLink->containment_id;

                DB::table('fsm.containments')
                    ->where('id', $containmentId)
                    ->update([
                        'location'    => $mappedLocation,
                        'tank_length' => $length,
                        'tank_width'  => $width,
                        'depth'       => $depth,
                        'updated_at'  => $now,
                    ]);

                return $containmentId;
            }
            else {
                    $containmentId = $this->nextContainmentId();

                    DB::table('fsm.containments')->insert([
                        'id'          => $containmentId,
                        'location'    => $mappedLocation,
                        'tank_length' => $length,
                        'tank_width'  => $width,
                        'depth'       => $depth,
                        'created_at'  => $now,
                        'updated_at'  => $now,
                    ]);

                    DB::table('building_info.build_contains')->insert([
                        'bin'            => $bin,
                        'containment_id' => $containmentId,
                        'created_at'     => $now,
                        'updated_at'     => $now,
                    ]);

                    return $containmentId;
                }

            // 2) No existing link → create new containment + pivot
            $containmentId = $this->nextContainmentId();

            DB::table('fsm.containments')->insert([
                'id'          => $containmentId,
                'location'    => $mappedLocation,
                'tank_length' => $length,
                'tank_width'  => $width,
                'depth'       => $depth,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);

            DB::table('building_info.build_contains')->insert([
                'bin'            => $bin,
                'containment_id' => $containmentId,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);

            return $containmentId;
    } */
   
    private function resolveContainmentTypeIdFromOutlet(?string $outlet): ?int{
        $v = strtolower(trim((string)$outlet));

        $label = match (true) {
            str_contains($v, 'sewer') => 'Septic Tank connected to Sewer Network',
            str_contains($v, 'soak')  => 'Septic Tank connected to Soak Pit',
            $v === 'none' || $v === '' || str_contains($v, 'without')
                                    => 'Septic Tank without Outlet Connection',
            default                   => 'Septic Tank with Unknown Outlet Connection', // safer
        };

        return DB::table('fsm.containment_types')
            ->where('type', $label)
            ->value('id');
    }

    protected function upsertSSContainmentFromEBPS(string $bin, array $data): ?string{
        // Super Structure payload keys
        $length   = data_get($data, 'SepticTankLength');
        $width    = data_get($data, 'SepticTankWidth');
        $depth    = data_get($data, 'SepticTankDepth');
        $location = data_get($data, 'SepticTankLocationSanitation'); // SS key
        $outlet   = data_get($data, 'SepticOutletpipe');             // e.g. "Soak Pit"

        // skip if no septic fields
        if ($length === null && $width === null && $depth === null && $location === null) {
            return null;
        }

        $now    = now();
        $typeId = $this->resolveContainmentTypeIdFromOutlet($outlet); // helper below

        $existingLink = DB::table('building_info.build_contains')
            ->where('bin', $bin)
            ->first();

        if ($existingLink) {
            $containmentId = $existingLink->containment_id;

            DB::table('fsm.containments')
                ->where('id', $containmentId)
                ->update([
                    'type_id'     => $typeId,
                    'location'    => $location,
                    'tank_length' => $length,
                    'tank_width'  => $width,
                    'depth'       => $depth,
                    'updated_at'  => $now,
                ]);

            return (string) $containmentId;
        }

        $containmentId = $this->nextContainmentId();

        DB::table('fsm.containments')->insert([
            'id'          => $containmentId,
            'type_id'     => $typeId,
            'location'    => $location,
            'tank_length' => $length,
            'tank_width'  => $width,
            'depth'       => $depth,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        DB::table('building_info.build_contains')->insert([
            'bin'            => $bin,
            'containment_id' => $containmentId,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        return (string) $containmentId;
    }


    protected function createSSContainmentInspectionAndQuestions(array $data): ?string{
        $ebpsId = data_get($data, 'Bldgprmt_TID');

            if (is_null($ebpsId)) {
                return null;
            }

            // 2) If payload has nothing inspection-related, skip insert
            // (adjust this list depending on what you consider "minimum")
            $allNull = (
                is_null(data_get($data, 'IsSepticTankSealed')) &&
                is_null(data_get($data, 'IsSepticCompartments')) &&
                is_null(data_get($data, 'IsSepticTankDepth')) &&
                is_null(data_get($data, 'IsLenGretDesign')) &&
                is_null(data_get($data, 'IsWidGretDesign')) &&
                is_null(data_get($data, 'IsLenSepticDouble')) &&
                is_null(data_get($data, 'IsChamberLength')) &&
                is_null(data_get($data, 'IsPartWallDepth')) &&
                is_null(data_get($data, 'OutletPipeConectionDesigner')) &&
                is_null(data_get($data, 'SepticTankLocationSanitation')) &&
                is_null(data_get($data, 'OutletPipeConnectSanitaion')) &&
                is_null(data_get($data, 'inspectedDate'))
            );

            if ($allNull) {
                return null;
            }

            $now = now();

            // --- helpers ---
            $toBool = function ($v) {
                if (is_bool($v) || is_null($v)) return $v;

                if (is_string($v)) {
                    $vv = strtolower(trim($v));
                    if ($vv === 'true' || $vv === '1' || $vv === 'yes') return true;
                    if ($vv === 'false' || $vv === '0' || $vv === 'no') return false;
                }

                if (is_numeric($v)) return ((int)$v) === 1;

                return null; // fallback
            };

            $toDate = function ($v) {
                if (empty($v)) return null;
                try {
                    // accepts "2025-11-18T16:34:01.177" or "2025-11-18"
                    return Carbon::parse($v)->toDateString();
                } catch (\Throwable $e) {
                    return null;
                }
            };

            // 3) Map payload -> table columns
            $payload = [
                'ebps_id'                         => (string) $ebpsId,

                'septic_tank_sealed'              => $toBool(data_get($data, 'IsSepticTankSealed')),
                'septic_compartments'             => $toBool(data_get($data, 'IsSepticCompartments')),
                'depth_of_septic_tank'            => $toBool(data_get($data, 'IsSepticTankDepth')),
                'length_in_design'                => $toBool(data_get($data, 'IsLenGretDesign')),
                'width_in_design'                 => $toBool(data_get($data, 'IsWidGretDesign')),
                'range_of_septic_tank'            => $toBool(data_get($data, 'IsLenSepticDouble')),
                'septic_tank_chamber_requirement' => $toBool(data_get($data, 'IsChamberLength')),

                // NOTE: name mismatch in your DB schema vs payload; best-effort mapping:
                'holes_in_partition_wall'         => $toBool(data_get($data, 'IsPartWallDepth')),

                'septic_tank_outlet_pipe'         => data_get($data, 'OutletPipeConectionDesigner'),
                'septic_tank_location'            => data_get($data, 'SepticTankLocationSanitation'),
                'septic_tank_manhole'             => null, // no key in payload
                'date_of_manhole'                 => $toDate(data_get($data, 'inspectedDate')),

                'outlet_connection_design'        => data_get($data, 'OutletPipeConectionDesigner'),
                'outlet_connection_field'         => data_get($data, 'OutletPipeConnectSanitaion'),

                // keep your default false unless you set it elsewhere
                // 'compliance_status'            => false,

                'updated_at'                      => $now,
            ];

            // Remove nulls you don't want to overwrite (optional)
            // If you WANT nulls to overwrite, remove this filter.
            $payloadForUpdate = array_filter($payload, fn($v) => $v !== null);

            // 4) Upsert (update if exists else insert)
            $exists = DB::table('fsm.containment_inspections')
                ->where('ebps_id', (string) $ebpsId)
                ->exists();

            if ($exists) {
                DB::table('fsm.containment_inspections')
                    ->where('ebps_id', (string) $ebpsId)
                    ->update($payloadForUpdate);

                return (string) $ebpsId;
            }

            // Insert requires created_at
            $payload['created_at'] = $now;

            DB::table('fsm.containment_inspections')->insert($payload);

            return (string) $ebpsId;


    }

    protected function handleBuildingComplition(Request $request)
    {
        try {
            $data = $request->all();
            /* dd('DATA RECEIVED', $data); */
            // Accept all EBPS permit-ID spellings currently sent by clients.
            $ebpsId = $data['BldgPrmt_TID']
                ?? $data['Bldgprmt_TID']
                ?? $data['Bldgprmt_tid']
                ?? null;

            /* dd($ebpsId); */

            if (!$ebpsId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Missing BldgPrmt_TID / Bldgprmt_TID / Bldgprmt_tid in payload',
                ], 422);
            }

            // normalize key so the rest of code uses one key
            $data['Bldgprmt_TID'] = $ebpsId;

            // Save flat table + also update building/owner/containment (next step)
            $this->insertCompletionDataInFlatTable($data);
            $building = $this->upsertCompletionBuildingFromEBPS($data);
            $this->saveCompletionImagesByBin(
                $building->bin,
                $data['Photos'] ?? []
            );
            $this->upsertCompletionOwnerFromEBPS($building->bin, $data);
            $this->upsertCompletionContainmentFromEBPS($building->bin, $data);

            return response()->json([
                'success' => true,
                'message' => 'Completion data saved successfully'
            ], 200);

        } catch (\Throwable $e) {
            \Log::channel('ebps')->error('Failed inserting Completion flat table data', [
                'ebps_id' => $data['Bldgprmt_TID'] ?? null,
                'error'   => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to insert Completion data',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    protected function insertCompletionDataInFlatTable(array $data){
    // ---------- 1) EBPS ID & image saving ----------
        $ebpsId = $data['BldgPrmt_TID']
            ?? $data['Bldgprmt_TID']
            ?? $data['Bldgprmt_tid']
            ?? null;

        $image1 = null;
        $image2 = null;
        $image3 = null;

        if ($ebpsId) {
            $folder = 'ebps_photos/' . $ebpsId;

            // Always create folder (even if no valid images)
            Storage::disk('public')->makeDirectory($folder);

            $photos = $data['Photos'] ?? [];

            foreach ($photos as $index => $photo) {
                if ($index > 2) {
                    break; // only up to 3
                }

                $slot    = $index + 1; // 1,2,3
                $base64  = $photo['Base64Image'] ?? null;
                $docFile = $photo['DocImgFile'] ?? null;

                // extension from DocImgFile or default jpg
                $ext = pathinfo($docFile ?? '', PATHINFO_EXTENSION);
                if (!$ext) {
                    $ext = 'jpg';
                }

                $fileName = 'image_' . $slot . '.' . $ext;
                $path     = $folder . '/' . $fileName;

                if ($base64) {
                    try {
                        if (strpos($base64, 'base64,') !== false) {
                            $base64 = explode('base64,', $base64)[1];
                        }

                        $decoded = base64_decode($base64);
                        if ($decoded !== false) {
                            Storage::disk('public')->put($path, $decoded);

                            if ($slot === 1) {
                                $image1 = $path;
                            } elseif ($slot === 2) {
                                $image2 = $path;
                            } elseif ($slot === 3) {
                                $image3 = $path;
                            }
                        }
                    } catch (\Throwable $e) {
                        \Log::channel('ebps')->error('Error saving completion image', [
                            'ebps_id' => $ebpsId,
                            'slot'    => $slot,
                            'error'   => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        // ---------- 2) Update the one latest-state flat row for this permit ----------
        $this->upsertLatestFlatRecord($ebpsId, [
            // Meta
            'transaction_type'   => $data['TransactionType_Nm']
                                        ?? $data['TransactionType']
                                        ?? 'Completion',
            'application_number' => $data['ApplicationNumber'] ?? null,

            // codes
            'road_code'          => $data['RodeCode'] ?? null,
            'sewer_code'         => $data['SewerCode'] ?? null,

            // use & construction
            'functional_use'     => $data['buildingPurposeNm'] ?? null,
            'construction_date'  => $data['Construction_Date'] ?? null,

            // septic dimensions (they’re in payload, even if null)
            'length'             => isset($data['SepticTankLength']) ? (float) $data['SepticTankLength'] : null,
            'width'              => isset($data['SepticTankWidth'])  ? (float) $data['SepticTankWidth']  : null,
            'depth'              => isset($data['SepticTankDepth'])  ? (float) $data['SepticTankDepth']  : null,

            // outlet sanitation
            'outlet_pipe_connect_sanitation' => $data['OutletPipeConnectSanitaion'] ?? null,

            // owner & contact
            'owner'       => $data['HouseOwnerNm'] ?? null,
            'gender'      => isset($data['gender']) ? trim($data['gender']) : null,
            'contact_no'  => $data['contact_no'] ?? null,
            'floor_count' => isset($data['NoOfStorey']) ? (int) $data['NoOfStorey'] : null,

            // location
            'location'    => $data['Location'] ?? null,

            // footprints
            'ward_building_footprint'     => !empty($data['Field_footprint'])
                                                ? $data['Field_footprint']
                                                : null,
            'designer_building_footprint' => !empty($data['Designer_footprint'])
                                                ? $data['Designer_footprint']
                                                : null,

            // images
            'image_1'     => $image1,
            'image_2'     => $image2,
            'image_3'     => $image3,

        ]);
    }

    protected function saveCompletionImagesByBin(string $bin, array $photos): void
    {
        if (empty($photos)) {
            return;
        }

        $folder = 'ebps_photos/' . $bin;
        Storage::disk('public')->makeDirectory($folder);

        foreach ($photos as $index => $photo) {
            if ($index > 2) break; // max 3 images

            $slot    = $index + 1;
            $base64  = $photo['Base64Image'] ?? null;
            $docFile = $photo['DocImgFile'] ?? null;

            if (!$base64) continue;

            $ext = pathinfo($docFile ?? '', PATHINFO_EXTENSION) ?: 'jpg';
            $fileName = 'completion_' . $slot . '.' . $ext;
            $path = $folder . '/' . $fileName;

            try {
                if (str_contains($base64, 'base64,')) {
                    $base64 = explode('base64,', $base64)[1];
                }

                $decoded = base64_decode($base64);
                if ($decoded === false) continue;

                Storage::disk('public')->put($path, $decoded);

            } catch (\Throwable $e) {
                \Log::channel('ebps')->error('Completion image save failed', [
                    'bin'   => $bin,
                    'slot'  => $slot,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function upsertCompletionBuildingFromEBPS(array $data): Building
    {
        $ebpsId = (string) (
        data_get($data, 'Bldgprmt_TID') ??
        data_get($data, 'Bldgprmt_tid') ??
        data_get($data, 'BldgPrmt_TID')
        );

        if ($ebpsId === '') {
        throw new \InvalidArgumentException('Bldgprmt_TID (EBPS ID) is required for Completion.');
        }

        $constructionStatus = 'Completion';
        $building = Building::where('ebps_id', $ebpsId)->first();

        // ---------------- CASE 1: Not found → create new building + BIN ----------------
        if (!$building) {
            $building = new Building();

            $maxBIN  = Building::max('bin'); // 'B000123' or null
            $numeric = (int) preg_replace('/\D/', '', $maxBIN ?? '0');
            $building->bin = 'B' . sprintf('%06d', $numeric + 1);

            $building->ebps_id = $ebpsId;
        }

            // ---------------- CASE 2: Found → update existing building ----------------
            $building->ebps_id = $ebpsId;
            $building->construction_status = $constructionStatus;

            // Completion payload uses Location (not ToleName usually)
            $ward     = data_get($data, 'Ward');      // may be null in completion
            $locality = data_get($data, 'Location')   // completion payload
                    ?? data_get($data, 'ToleName');   // fallback

            if (!is_null($ward)) {
                $building->ward = $ward;
            }
            if (!is_null($locality)) {
                $building->house_locality = $locality;
            }

            $taxCode = data_get($data, 'tax_code');
            if (!is_null($taxCode)) {
                $building->tax_code = $taxCode;
            }

            $floors = data_get($data, 'NoOfStorey');
            if (!is_null($floors)) {
                $building->floor_count = (int) $floors;
            }

            // Use category ("Residential", etc.)
            $useCategoryName = (string) data_get($data, 'buildingPurposeNm');
            if (!empty($useCategoryName)) {
                $useCategoryId = $this->resolveUseCategoryIdByName($useCategoryName);
                if ($useCategoryId) {
                    $building->use_category_id = $useCategoryId;
                }
            }

            // Prefer field footprint over designer footprint
            $footprintJson = data_get($data, 'Field_footprint')
                ?: data_get($data, 'Designer_footprint');

            $geomExpr = $this->buildMultiPolygonFromFootprint($footprintJson);
            if ($geomExpr) {
                $building->geom = $geomExpr;
            }

            $building->save();

            return $building;

    }

    protected function upsertCompletionOwnerFromEBPS(string $bin, array $data): void
    {
        $normalize = function ($value) {
        if ($value === null) return null;

        if (is_array($value)) {
            $parts = array_filter(array_map('trim', $value));
            return $parts ? implode(', ', $parts) : null;
        }

        $parts = array_filter(array_map('trim', explode(',', (string)$value)));
        return $parts ? implode(', ', $parts) : null;
        };

        $ownerName   = $normalize(data_get($data, 'HouseOwnerNm'));
        $ownerGender = $normalize(data_get($data, 'gender'));
        $phone       = $normalize(data_get($data, 'contact_no'));
        $now         = now();

        // If payload has nothing, skip (optional)
        if (is_null($ownerName) && is_null($ownerGender) && is_null($phone)) {
            return;
        }

        $query  = DB::table('building_info.owners')->where('bin', $bin);
        $exists = $query->whereNull('deleted_at')->exists(); // if soft delete

        // Null-safe update (don’t wipe with null)
        $payload = array_filter([
            'owner_name'    => $ownerName,
            'owner_gender'  => $ownerGender,
            'owner_contact' => $phone,
            'updated_at'    => $now,
        ], fn($v) => $v !== null && $v !== '');

        if ($exists) {
            $query->update($payload);
        } else {
            DB::table('building_info.owners')->insert($payload + [
                'bin'        => $bin,
                'created_at' => $now,
            ]);
        }
    }
    protected function upsertCompletionContainmentFromEBPS(string $bin, array $data): ?string
    {
        // Completion often has septic fields null → skip when fully missing
        if (
            is_null(data_get($data, 'SepticTankLength')) &&
            is_null(data_get($data, 'SepticTankWidth')) &&
            is_null(data_get($data, 'SepticTankDepth')) &&
            is_null(data_get($data, 'SepticTankLocationSanitation'))
        ) {
            return null;
        }

        $now = now();

        $rawLocation    = data_get($data, 'SepticTankLocationSanitation');
        $mappedLocation = $rawLocation; // or $this->mapContainmentLocation($rawLocation);

        $length = data_get($data, 'SepticTankLength');
        $width  = data_get($data, 'SepticTankWidth');
        $depth  = data_get($data, 'SepticTankDepth');

        // Check if BIN already linked to containment
        $existingLink = DB::table('building_info.build_contains')
            ->where('bin', $bin)
            ->first();

        // Null-safe update fields
        $update = array_filter([
            'location'    => $mappedLocation,
            'tank_length' => $length,
            'tank_width'  => $width,
            'depth'       => $depth,
            'updated_at'  => $now,
        ], fn($v) => $v !== null && $v !== '');

        if ($existingLink) {
            $containmentId = $existingLink->containment_id;

            DB::table('fsm.containments')
                ->where('id', $containmentId)
                ->update($update);

            return $containmentId;
        }

        // No link → create new containment + link
        $containmentId = $this->nextContainmentId();

        DB::table('fsm.containments')->insert(array_filter([
            'id'          => $containmentId,
            'location'    => $mappedLocation,
            'tank_length' => $length,
            'tank_width'  => $width,
            'depth'       => $depth,
            'created_at'  => $now,
            'updated_at'  => $now,
        ], fn($v) => $v !== null && $v !== ''));

        DB::table('building_info.build_contains')->insert([
            'bin'            => $bin,
            'containment_id' => $containmentId,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        return $containmentId;
    }


    protected function getStoryAdditionData(){
        return response()->json(['success'=>false,'message'=>'Not implemented'],501);
    }

    protected function getBuildingAbhilehikaranData(){
        return response()->json(['success'=>false,'message'=>'Not implemented'],501);
    }



}
