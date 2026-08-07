<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrmActivity;
use App\Models\CrmProspect;
use Illuminate\Http\Request;

class CrmProspectController extends Controller
{
    private const STAGES = [
        'new', 'contacted', 'interested', 'demo_scheduled',
        'trial_running', 'negotiation', 'converted', 'won', 'lost',
    ];

    private const TERMINAL_STAGES = ['won', 'converted', 'lost'];

    private const CATEGORIES = ['water', 'catering', 'bakery', 'frozen', 'egg', 'wholesale', 'other'];

    private const ACTIVITY_TYPES = ['note', 'call', 'whatsapp', 'email', 'demo'];

    // ── Field rules ──────────────────────────────────────────────────────────

    private function rules(bool $required = true): array
    {
        $req = $required ? 'required' : 'sometimes';
        return [
            'business_name'    => "{$req}|string|max:150",
            'category'         => 'nullable|in:' . implode(',', self::CATEGORIES),
            'city'             => 'nullable|string|max:100',
            'address'          => 'nullable|string|max:300',
            'phone'            => 'nullable|string|max:30',
            'email'            => 'nullable|email|max:150',
            'website'          => 'nullable|url|max:255',
            'instagram'        => 'nullable|string|max:100',
            'contact_person'   => 'nullable|string|max:100',
            'contact_role'     => 'nullable|string|max:80',
            'pipeline_stage'   => 'nullable|in:' . implode(',', self::STAGES),
            'notes'            => 'nullable|string',
            'last_contact_at'  => 'nullable|date',
            'next_followup_at' => 'nullable|date',
        ];
    }

    // ── CRUD ─────────────────────────────────────────────────────────────────

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
        if ($request->filled('q')) {
            $like = '%' . $request->q . '%';
            $query->where(function ($q) use ($like) {
                $q->where('business_name', 'like', $like)
                  ->orWhere('contact_person', 'like', $like)
                  ->orWhere('phone', 'like', $like)
                  ->orWhere('city', 'like', $like);
            });
        }

        $prospects = $query->orderBy('next_followup_at')->orderBy('id', 'desc')->paginate(25);

        return response()->json($prospects);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules(true));
        $data['created_by'] = $request->user()?->id;

        if (empty($data['pipeline_stage'])) {
            $data['pipeline_stage'] = 'new';
        }

        $prospect = CrmProspect::create($data);

        return response()->json(['data' => $prospect], 201);
    }

    public function show(CrmProspect $crmProspect)
    {
        return response()->json(['data' => $crmProspect->load('activities')]);
    }

    public function update(Request $request, CrmProspect $crmProspect)
    {
        $data = $request->validate($this->rules(false));
        $crmProspect->update($data);

        return response()->json(['data' => $crmProspect->fresh()]);
    }

    public function destroy(CrmProspect $crmProspect)
    {
        $crmProspect->delete();
        return response()->json(null, 204);
    }

    // ── Stats ────────────────────────────────────────────────────────────────

    public function stats()
    {
        $counts = CrmProspect::selectRaw('pipeline_stage, count(*) as count')
            ->groupBy('pipeline_stage')
            ->pluck('count', 'pipeline_stage');

        $dueToday = CrmProspect::whereDate('next_followup_at', today())->count();
        $overdue  = CrmProspect::whereDate('next_followup_at', '<', today())
            ->whereNotIn('pipeline_stage', self::TERMINAL_STAGES)
            ->count();

        // Treat both 'won' and 'converted' as successful conversions
        $converted = ($counts['converted'] ?? 0) + ($counts['won'] ?? 0);

        return response()->json([
            'data' => [
                'by_stage'  => $counts,
                'converted' => $converted,
                'due_today' => $dueToday,
                'overdue'   => $overdue,
                'total'     => $counts->sum(),
            ],
        ]);
    }

    // ── Import & Template ────────────────────────────────────────────────────

    public function templateDownload()
    {
        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="hontal-crm-template.csv"',
            'Cache-Control'       => 'no-store',
        ];

        $callback = function () {
            $h = fopen('php://output', 'w');
            fputcsv($h, [
                'Business Name', 'Contact Name', 'Phone', 'Email',
                'Website', 'Address', 'City', 'Industry', 'Notes', 'Status',
            ]);
            fputcsv($h, [
                'UD Tirta Jaya', 'Pak Budi', '08123456789', 'budi@example.com',
                '', 'Jl. Merdeka No. 1', 'Bandung', 'water', 'Lead dari Google Maps', 'new',
            ]);
            fclose($h);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function importPreview(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:4096']);

        ['rows' => $rows, 'skipped' => $skipped, 'errors' => $errors] =
            $this->parseCsv($request->file('file'));

        return response()->json([
            'data' => [
                'preview'    => array_slice($rows, 0, 20),
                'total_rows' => count($rows) + $skipped,
                'valid_rows' => count($rows),
                'skipped'    => $skipped,
                'errors'     => array_slice($errors, 0, 10),
            ],
        ]);
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:4096']);

        try {
            ['rows' => $rows, 'skipped' => $skipped, 'errors' => $errors] =
                $this->parseCsv($request->file('file'));
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Gagal membaca file CSV: ' . $e->getMessage()], 422);
        }

        $created = 0;
        foreach ($rows as $row) {
            $row['created_by'] = $request->user()?->id;
            try {
                CrmProspect::create($row);
                $created++;
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = "Row skipped: {$row['business_name']} — {$e->getMessage()}";
            }
        }

        return response()->json([
            'data' => [
                'created' => $created,
                'skipped' => $skipped,
                'errors'  => array_slice($errors, 0, 10),
            ],
        ]);
    }

    private const CATEGORY_ALIASES = [
        'frozen food'     => 'frozen',
        'frozen foods'    => 'frozen',
        'katering'        => 'catering',
        'katering harian' => 'catering',
        'catering harian' => 'catering',
        'apotek'          => 'other',
        'farmasi'         => 'other',
        'laundry'         => 'other',
        'retail'          => 'wholesale',
        'grosir'          => 'wholesale',
        'sembako'         => 'wholesale',
        'bakery'          => 'bakery',
        'roti'            => 'bakery',
        'cake'            => 'bakery',
        'air'             => 'water',
        'galon'           => 'water',
        'telur'           => 'egg',
        'ayam'            => 'other',
    ];

    private function parseCsv(\Illuminate\Http\UploadedFile $file): array
    {
        $headerMap = [
            'business name'  => 'business_name',
            'business_name'  => 'business_name',
            'contact name'   => 'contact_person',
            'contact_name'   => 'contact_person',
            'contact_person' => 'contact_person',
            'phone'          => 'phone',
            'email'          => 'email',
            'website'        => 'website',
            'address'        => 'address',
            'city'           => 'city',
            'industry'       => 'category',
            'category'       => 'category',
            'notes'          => 'notes',
            'status'         => 'pipeline_stage',
            'pipeline_stage' => 'pipeline_stage',
        ];

        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open uploaded file.');
        }

        $headers = null;
        $rows    = [];
        $skipped = 0;
        $errors  = [];
        $lineNum = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $lineNum++;
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
                $val   = trim($val);
                if ($field && $val !== '') {
                    $mapped[$field] = $val;
                }
            }

            if (empty($mapped['business_name'])) {
                $skipped++;
                $errors[] = "Row {$lineNum}: Business Name is required";
                continue;
            }

            // Normalize pipeline_stage
            $stage = strtolower($mapped['pipeline_stage'] ?? '');
            $mapped['pipeline_stage'] = in_array($stage, self::STAGES) ? $stage : 'new';

            // Normalize category/industry — check aliases then direct match
            $cat = strtolower($mapped['category'] ?? '');
            if (isset(self::CATEGORY_ALIASES[$cat])) {
                $mapped['category'] = self::CATEGORY_ALIASES[$cat];
            } elseif (in_array($cat, self::CATEGORIES)) {
                $mapped['category'] = $cat;
            } else {
                $mapped['category'] = null;
            }

            // Normalize phone — fix Excel scientific notation (e.g. 8.22258E+11 → 822258000000)
            if (!empty($mapped['phone'])) {
                $phone = $mapped['phone'];
                if (preg_match('/^[\d.]+[Ee][+\-]?\d+$/', $phone)) {
                    $phone = number_format((float) $phone, 0, '.', '');
                }
                $mapped['phone'] = substr(preg_replace('/[^\d+]/', '', $phone), 0, 30);
            }

            // Truncate address to fit the column
            if (!empty($mapped['address'])) {
                $mapped['address'] = mb_substr($mapped['address'], 0, 500);
            }

            $rows[] = $mapped;
        }

        fclose($handle);

        return ['rows' => $rows, 'skipped' => $skipped, 'errors' => $errors];
    }

    // ── Activities ───────────────────────────────────────────────────────────

    public function listActivities(CrmProspect $crmProspect)
    {
        $activities = $crmProspect->activities()
            ->with('creator:id,name')
            ->get()
            ->map(fn($a) => [
                'id'           => $a->id,
                'type'         => $a->type,
                'content'      => $a->content,
                'created_by'   => $a->created_by,
                'created_by_name' => $a->creator?->name,
                'created_at'   => $a->created_at->toISOString(),
            ]);

        return response()->json(['data' => $activities]);
    }

    public function storeActivity(Request $request, CrmProspect $crmProspect)
    {
        $data = $request->validate([
            'type'    => 'required|in:' . implode(',', self::ACTIVITY_TYPES),
            'content' => 'required|string|max:2000',
        ]);

        $activity = CrmActivity::create([
            'prospect_id' => $crmProspect->id,
            'type'        => $data['type'],
            'content'     => $data['content'],
            'created_by'  => $request->user()?->id,
        ]);

        return response()->json(['data' => [
            'id'             => $activity->id,
            'type'           => $activity->type,
            'content'        => $activity->content,
            'created_by'     => $activity->created_by,
            'created_by_name'=> $request->user()?->name,
            'created_at'     => $activity->created_at->toISOString(),
        ]], 201);
    }

    public function destroyActivity(CrmProspect $crmProspect, CrmActivity $activity)
    {
        abort_if($activity->prospect_id !== $crmProspect->id, 404);
        $activity->delete();
        return response()->json(null, 204);
    }
}
