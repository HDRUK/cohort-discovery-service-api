<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Traits\Responses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PostcodeLookupController extends Controller
{
    use Responses;

    public function lookup(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'q' => ['required', 'string', 'max:8'],
            ]);
        } catch (ValidationException $e) {
            return $this->ValidationErrorResponse($e->errors());
        }

        try {
            $postcode = strtoupper(str_replace(' ', '', $validated['q']));

            $row = DB::selectOne('
                SELECT
                    p.postcode,
                    p.lsoa21cd AS lsoa_code,
                    p.lsoa21nm AS lsoa_name,
                    p.ladcd    AS lad_code,
                    p.ladnm    AS lad_name,
                    c.latitude,
                    c.longitude
                FROM postcode_lookup p
                LEFT JOIN lsoa_centroids c ON c.lsoa_code = p.lsoa21cd
                WHERE p.postcode = ?
            ', [$postcode]);

            if (! $row) {
                return $this->NotFoundResponse();
            }

            return $this->OKResponse([
                'postcode'   => $row->postcode,
                'lsoa_code'  => $row->lsoa_code,
                'lsoa_name'  => $row->lsoa_name,
                'lad_code'   => $row->lad_code,
                'lad_name'   => $row->lad_name,
                'latitude'   => $row->latitude !== null ? (float) $row->latitude : null,
                'longitude'  => $row->longitude !== null ? (float) $row->longitude : null,
                'country'    => $this->deriveCountry((string) ($row->lad_code ?? '')),
            ]);
        } catch (\Exception $e) {
            return $this->ErrorResponse($e->getMessage());
        }
    }

    private function deriveCountry(string $ladCode): string
    {
        return match (strtoupper($ladCode[0] ?? '')) {
            'E' => 'England',
            'W' => 'Wales',
            'S' => 'Scotland',
            'N' => 'Northern Ireland',
            default => 'Unknown',
        };
    }
}
