<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrmMessageTemplate;
use Illuminate\Http\Request;

class CrmMessageTemplateController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => CrmMessageTemplate::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'     => 'required|string|max:100',
            'content'  => 'required|string',
            'category' => 'nullable|string|max:50',
        ]);

        $data['created_by'] = $request->user()?->id;
        $template = CrmMessageTemplate::create($data);

        return response()->json(['data' => $template], 201);
    }

    public function update(Request $request, CrmMessageTemplate $crmMessageTemplate)
    {
        $data = $request->validate([
            'name'     => 'sometimes|string|max:100',
            'content'  => 'sometimes|string',
            'category' => 'nullable|string|max:50',
        ]);

        $crmMessageTemplate->update($data);

        return response()->json(['data' => $crmMessageTemplate->fresh()]);
    }

    public function destroy(CrmMessageTemplate $crmMessageTemplate)
    {
        $crmMessageTemplate->delete();
        return response()->json(null, 204);
    }

    public function preview(Request $request, CrmMessageTemplate $crmMessageTemplate)
    {
        $vars = $request->validate([
            'business_name'  => 'nullable|string',
            'company_name'   => 'nullable|string',
            'coverage_area'  => 'nullable|string',
            'website'        => 'nullable|string',
            'phone'          => 'nullable|string',
            'city'           => 'nullable|string',
        ]);

        return response()->json([
            'data' => [
                'preview' => $crmMessageTemplate->preview($vars),
            ],
        ]);
    }
}
