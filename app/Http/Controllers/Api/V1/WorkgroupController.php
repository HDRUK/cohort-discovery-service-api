<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;

use App\Models\Workgroup;

use App\Http\Controllers\Controller;

use App\Traits\Responses;

class WorkgroupController extends Controller
{
    use Responses;

    /**
     * Intentionally left out of Swagger documentation as this is not a public endpoint.
     */
    public function index(Request $request)
    {
        $workgroup = Workgroup::all();
        return $this->OKResponse($workgroup);
    }

    public function show(Request $request, int $id)
    {
        try {
            $workgroup = Workgroup::findOrFail($id);
        } catch (\Exception $e) {
            return $this->NotFoundResponse();
        }

        return $this->OKResponse($workgroup);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'active' => 'sometimes|boolean',
        ]);

        $workgroup = Workgroup::create($data);

        return $this->CreatedResponse($workgroup);
    }

    public function update(Request $request, int $id)
    {
        try {
            $workgroup = Workgroup::findOrFail($id);
        } catch (\Exception $e) {
            return $this->NotFoundResponse();
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'active' => 'sometimes|boolean',
        ]);

        $workgroup->update($data);
        return $this->OKResponse($workgroup);
    }

    public function destroy(Request $request, int $id)
    {
        try {
            $workgroup = Workgroup::findOrFail($id);
            $workgroup->delete();
        } catch (\Exception $e) {
            return $this->NotFoundResponse();
        }

        return $this->OKResponse([]);
    }
}
