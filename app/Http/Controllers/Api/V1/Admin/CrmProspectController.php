<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrmProspect;
use Illuminate\Http\Request;

class CrmProspectController extends Controller
{
    private const STAGES = ['new', 'contacted', 'demo_scheduled', 'negotiation', 'won', 'lost'];
    private const CATEGORIES = ['water', 'catering', 'bakery', 'frozen', 'egg', 'wholesale', 'other'];

    public function index(Request $request)
    {
        $query = CrmProspect::query();

        if ($request->filled('stage')) {
            $query->where('pipeline_stage', $request->stage);
        }
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        if ($request->filled('city')) {
            $query->where('city', 'like', '%' . $request->city . '%');
        }
        if ($request->filled('followup_before')) {
            $query->where('next_followup_at', '<=', $request->followup_before);
        }

        $prospects = $query->orderBy('next_followup_at')->orderBy('id', 'desc')->paginate(25);

        return response()->json($prospects);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'business_name'    => 'required|string|max:150',
            'category'         => 'nullable|in:' . implode(',', self::CATEGORIES),
            'city'             => 'nullable|string|max:100',
            'address'          => 'nullable|string|max:300',
            'phone'            => 'nullable|string|max:30',
            'website'          => 'nullable|url|max:255',
            'instagram'        => 'nullable|string|max:100',
            'contact_person'   => 'nullable|string|max:100',
            'contact_role'     => 'nullable|string|max:80',
            'pipeline_stage'   => 'nullable|in:' . implode(',', self::STAGES),
            'notes'            => 'nullable|string',
            'last_contact_at'  => 'nullable|date',
            'next_followup_at' => 'nullable|date',
        ]);

        $data['created_by'] = $request->user()?->id;
        $prospect = CrmProspect::create($data);

        return response()->json(['data' => $prospect], 201);
    }

    public function show(CrmProspect $crmProspect)
    {
        return response()->json(['data' => $crmProspect]);
    }

    public function update(Request $request, CrmProspect $crmProspect)
    {
        $data = $request->validate([
            'business_name'    => 'sometimes|string|max:150',
            'category'         => 'nullable|in:' . implode(',', self::CATEGORIES),
            'city'             => 'nullable|string|max:100',
            'address'          => 'nullable|string|max:300',
            'phone'            => 'nullable|string|max:30',
            'website'          => 'nullable|url|max:255',
            'instagram'        => 'nullable|string|max:100',
            'contact_person'   => 'nullable|string|max:100',
            'contact_role'     => 'nullable|string|max:80',
            'pipeline_stage'   => 'nullable|in:' . implode(',', self::STAGES),
            'notes'            => 'nullable|string',
            'last_contact_at'  => 'nullable|date',
            'next_followup_at' => 'nullable|date',
        ]);

        $crmProspect->update($data);

        return response()->json(['data' => $crmProspect->fresh()]);
    }

    public function destroy(CrmProspect $crmProspect)
    {
        $crmProspect->delete();
        return response()->json(null, 204);
    }

    public function stats()
    {
        $counts = CrmProspect::selectRaw('pipeline_stage, count(*) as count')
            ->groupBy('pipeline_stage')
            ->pluck('count', 'pipeline_stage');

        $dueToday = CrmProspect::whereDate('next_followup_at', today())->count();
        $overdue   = CrmProspect::whereDate('next_followup_at', '<', today())
            ->whereNotIn('pipeline_stage', ['won', 'lost'])
            ->count();

        return response()->json([
            'data' => [
                'by_stage'    => $counts,
                'due_today'   => $dueToday,
                'overdue'     => $overdue,
                'total'       => $counts->sum(),
            ],
        ]);
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:2048',
        ]);

        $file    = $request->file('file');
        $handle  = fopen($file->getRealPath(), 'r');
        $headers = null;
        $created = 0;
        $skipped = 0;
        $errors  = [];

        $headerMap = [
            'business name'   => 'business_name',
            'business_name'   => 'business_name',
            'address'         => 'address',
            'phone'           => 'phone',
            'website'         => 'website',
            'notes'           => 'notes',
            'status'          => 'pipeline_stage',
            'pipeline_stage'  => 'pipeline_stage',
            'category'        => 'category',
            'city'            => 'city',
            'contact person'  => 'contact_person',
            'contact_person'  => 'contact_person',
        ];

        while (($row = fgetcsv($handle)) !== false) {
            if ($headers === null) {
                $headers = array_map(fn($h) => strtolower(trim($h)), $row);
                continue;
            }

            if (count($row) !== count($headers)) {
                $skipped++;
                continue;
            }

            $record = array_combine($headers, $row);
            $mapped = [];

            foreach ($record as $col => $val) {
                $field = $headerMap[$col] ?? null;
                if ($field && trim($val) !== '') {
                    $mapped[$field] = trim($val);
                }
            }

            if (empty($mapped['business_name'])) {
                $skipped++;
                continue;
            }

            // Normalize pipeline_stage
            if (isset($mapped['pipeline_stage'])) {
                $stage = strtolower($mapped['pipeline_stage']);
                $mapped['pipeline_stage'] = in_array($stage, self::STAGES) ? $stage : 'new';
            } else {
                $mapped['pipeline_stage'] = 'new';
            }

            // Normalize category
            if (isset($mapped['category'])) {
                $cat = strtolower($mapped['category']);
                $mapped['category'] = in_array($cat, self::CATEGORIES) ? $cat : null;
            }

            $mapped['created_by'] = $request->user()?->id;

            try {
                CrmProspect::create($mapped);
                $created++;
            } catch (\Exception $e) {
                $skipped++;
                $errors[] = "Row skipped: {$mapped['business_name']} — {$e->getMessage()}";
            }
        }

        fclose($handle);

        return response()->json([
            'data' => [
                'created' => $created,
                'skipped' => $skipped,
                'errors'  => array_slice($errors, 0, 10),
            ],
        ]);
    }
}
