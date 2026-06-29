<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Traits\HelperFunctions;
use App\Traits\Responses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * @OA\Tag(
 *     name="TermDirectory",
 *     description="Backs the Term Directory page: aggregate OMOP concept availability across the collections a user is allowed to see."
 * )
 */
class TermDirectoryController extends Controller
{
    use HelperFunctions;
    use Responses;

    /**
     * Columns the `sort` query parameter may target, mapped to their SQL
     * expression. Whitelisted because a column name cannot be passed as a
     * bound `?` parameter, so this is what keeps the raw ORDER BY injection-safe.
     */
    private const SORTABLE_COLUMNS = [
        'concept_id'   => 'base.concept_id',
        'concept_name' => 'base.concept_name',
        'count'        => 'base.count',
        'ncollections' => 'base.ncollections',
        'domain_id'    => 'base.domain_id',
    ];

    /**
     * @OA\Get(
     *     path="/api/v1/term-directory",
     *     summary="List OMOP concepts available across the collections the user can see",
     *     tags={"TermDirectory"},
     *     @OA\Parameter(
     *         name="domain_id",
     *         in="query",
     *         required=false,
     *         description="Filter by OMOP domain (e.g. Condition, Observation, Measurement)",
     *         @OA\Schema(type="string", example="Condition")
     *     ),
     *     @OA\Parameter(
     *         name="concept_id",
     *         in="query",
     *         required=false,
     *         description="Exact OMOP concept id to match",
     *         @OA\Schema(type="integer", example=1022)
     *     ),
     *     @OA\Parameter(
     *         name="concept_name",
     *         in="query",
     *         required=false,
     *         description="Partial concept name to search for",
     *         @OA\Schema(type="string", example="asthma")
     *     ),
     *     @OA\Parameter(
     *         name="sort",
     *         in="query",
     *         required=false,
     *         description="Sort as column:direction; column one of concept_id, concept_name, count, ncollections, domain_id",
     *         @OA\Schema(type="string", example="count:desc")
     *     ),
     *     @OA\Parameter(name="page", in="query", required=false, @OA\Schema(type="integer", example=1)),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", example=25)),
     *     @OA\Response(
     *         response=200,
     *         description="Paginated concepts with total count and number of contributing collections"
     *     )
     * )
     */
    public function index(Request $request, ActivityLogger $activityLogger): JsonResponse
    {
        try {
            $user = User::find(Auth::id());

            if (!$user) {
                return $this->UnauthorisedResponse();
            }

            $isAdmin = $user->hasRole('admin');

            $perPage = $this->resolvePerPage(100);
            $page    = max(1, (int) $request->query('page', 1));
            $offset  = ($page - 1) * $perPage;

            $domainId    = $this->queryString($request, 'domain_id');
            $conceptId   = trim($this->queryString($request, 'concept_id'));
            $conceptName = trim($this->queryString($request, 'concept_name'));

            // Work out which collections this user may see. Admins see everything;
            // any other user is restricted to their visible collections. A user who
            // can see none gets a guaranteed-empty result via "1 = 0" (you cannot
            // write "IN ()" in SQL), letting the normal query path return 0 rows.
            $where    = ['d.concept_id IS NOT NULL', 'd.concept_id > 0'];
            $bindings = [];

            if (!$isAdmin) {
                $collectionIds = Collection::visibleToUser($user)->pluck('id')->all();

                if (empty($collectionIds)) {
                    $where[] = '1 = 0';
                } else {
                    $placeholders = implode(',', array_fill(0, count($collectionIds), '?'));
                    $where[]  = "d.collection_id IN ({$placeholders})";
                    $bindings = array_merge($bindings, $collectionIds);
                }
            }

            if ($domainId !== '') {
                $where[]    = 'c.domain_id = ?';
                $bindings[] = $domainId;
            }

            if ($conceptId !== '' && ctype_digit($conceptId)) {
                $where[]    = 'd.concept_id = ?';
                $bindings[] = (int) $conceptId;
            }

            if ($conceptName !== '') {
                $where[]    = 'c.concept_name LIKE ?';
                $bindings[] = '%' . $conceptName . '%';
            }

            $whereClause = implode(' AND ', $where);
            $orderBy     = $this->resolveOrderBy($this->queryString($request, 'sort'));

            // The distributions live in the app DB while the OMOP vocabulary
            // (which holds domain_id and the canonical concept_name) lives in the
            // omop DB, so the join is fully qualified across both schemas - the
            // same approach RefreshDistributionConceptsView uses.
            $mysqlDb = config('database.connections.mysql.database');
            $omopDb  = config('database.connections.omop.database');

            $sql = "
                WITH base AS (
                    SELECT
                        d.concept_id,
                        c.concept_name,
                        c.domain_id,
                        SUM(d.count) AS count,
                        COUNT(DISTINCT d.collection_id) AS ncollections
                    FROM `{$mysqlDb}`.`distributions` d
                    INNER JOIN `{$omopDb}`.`concept` c
                        ON c.concept_id = d.concept_id
                    WHERE {$whereClause}
                    GROUP BY d.concept_id, c.concept_name, c.domain_id
                ),
                total AS (
                    SELECT COUNT(*) AS cnt FROM base
                )
                SELECT base.*, total.cnt
                FROM base
                CROSS JOIN total
                ORDER BY {$orderBy}
                LIMIT ? OFFSET ?
            ";

            $rows  = DB::select($sql, array_merge($bindings, [$perPage, $offset]));
            $total = (int) ($rows[0]->cnt ?? 0);

            foreach ($rows as $row) {
                unset($row->cnt);
                $row->concept_id   = (int) $row->concept_id;
                $row->count        = (int) $row->count;
                $row->ncollections = (int) $row->ncollections;
            }

            $paginator = new LengthAwarePaginator(
                $rows,
                $total,
                $perPage,
                $page,
                ['path' => $request->url(), 'query' => $request->query()]
            );

            $activityLogger->viewed('term-directory', null, [
                'filters' => [
                    'page'         => $page,
                    'per_page'     => $perPage,
                    'domain_id'    => $domainId,
                    'concept_id'   => $conceptId,
                    'concept_name' => $conceptName,
                    'sort'         => $request->query('sort'),
                ],
                'result' => [
                    'total'    => $total,
                    'returned' => count($rows),
                ],
            ]);

            return $this->OKResponse($paginator);
        } catch (\Throwable $e) {
            Log::error('TermDirectoryController@index - failed: ' . $e->getMessage());

            return $this->ErrorResponse($e->getMessage());
        }
    }

    /**
     * Read a query parameter as a trimmed-safe string, treating anything that is
     * not a scalar (e.g. an array like ?domain_id[]=x) as absent. Keeps the raw
     * query inputs predictable before they reach the SQL builder.
     */
    private function queryString(Request $request, string $key): string
    {
        $value = $request->query($key);

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Turn the `sort` query parameter (e.g. "count:desc") into a safe ORDER BY,
     * defaulting to the most prevalent concepts first. Unknown columns fall back
     * to the default rather than erroring.
     */
    private function resolveOrderBy(?string $sort): string
    {
        $default = 'base.count DESC, base.concept_id ASC';

        if (!$sort) {
            return $default;
        }

        [$column, $direction] = array_pad(explode(':', $sort, 2), 2, 'asc');

        if (!isset(self::SORTABLE_COLUMNS[$column])) {
            return $default;
        }

        $direction = strtolower($direction) === 'desc' ? 'DESC' : 'ASC';

        return self::SORTABLE_COLUMNS[$column] . ' ' . $direction . ', base.concept_id ASC';
    }
}
